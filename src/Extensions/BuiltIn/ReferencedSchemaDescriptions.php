<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\DocumentGraph;
use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;

/**
 * Settles what the responses naming a schema say it IS ({@see ResponseDraft::SCHEMA_DESCRIPTIONS}) onto the
 * schema, once the document is whole. The answer is a function of the set: one sentence is published, and
 * two different ones publish neither and say so — never the one a route order happened to read last. Runs
 * after the shared-error hoist, so a response that became a `$ref` is followed to the component it names.
 *
 * The facts are removed wherever they stand, landed or not: what a reader needs from them is either on
 * the schema now or in the diagnostic.
 *
 * @phpstan-type Claims array<string, array<string, list<string>>>
 */
#[ExtensionOrder(after: [SharedErrorResponses::class])]
final class ReferencedSchemaDescriptions implements DocumentTransformer
{
    private const SCHEMAS = '#/components/schemas/';

    private const RESPONSES = '#/components/responses/';

    public function transform(UirDocumentDraft $document, DocumentContext $context): void
    {
        [$doc, $diagnostics] = self::settle($document->toArray());

        foreach ($diagnostics as $diagnostic) {
            $context->report($diagnostic);
        }

        if ($doc !== $document->toArray()) {
            $document->replace($doc);
        }
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array{0: array<string, mixed>, 1: list<Diagnostic>}
     */
    public static function settle(array $doc): array
    {
        /** @var Claims $claims */
        $claims = [];

        foreach (DocumentGraph::operationSites($doc) as $site) {
            $responses = DocumentGraph::at($doc, [...$site['keys'], 'responses']);
            if (! is_array($responses)) {
                continue;
            }

            $where = $site['signature'] ?? strtoupper((string) end($site['keys'])).' '.implode('.', array_slice($site['keys'], 0, -1));

            foreach ($responses as $status => $response) {
                if (! is_array($response)) {
                    continue;
                }

                $said = self::said($response);
                if ($said === []) {
                    continue;
                }

                $body = self::body($doc, $response);
                foreach ($said as $mediaType => $sentences) {
                    $name = self::schemaNamed($body, $mediaType);
                    if ($name === null) {
                        continue;
                    }

                    foreach ($sentences as $sentence) {
                        $claims[$name][$sentence][] = $where.' '.$status;
                    }
                }

                $doc = DocumentGraph::with($doc, [...$site['keys'], 'responses', (string) $status], self::withoutFact($response));
            }
        }

        ksort($claims, SORT_STRING);

        $diagnostics = [];
        foreach ($claims as $name => $sentences) {
            $schema = DocumentGraph::at($doc, ['components', 'schemas', $name]);
            if (! is_array($schema)) {
                continue;
            }

            if (count($sentences) === 1) {
                $schema['description'] = (string) array_key_first($sentences);
                $doc = DocumentGraph::with($doc, ['components', 'schemas', $name], $schema);

                continue;
            }

            $diagnostics[] = self::conflict($name, $sentences);
        }

        return [$doc, $diagnostics];
    }

    /**
     * The sentences a response's fact holds, media type → sentences; anything not in the shape the fact is
     * written in is no fact at all — an overlay or a hand-written document can put anything anywhere.
     *
     * @param  array<array-key, mixed>  $response
     * @return array<string, list<string>>
     */
    private static function said(array $response): array
    {
        $extension = $response['x-docuccino'] ?? null;
        $facts = is_array($extension) ? ($extension['facts'] ?? null) : null;
        $said = is_array($facts) ? ($facts[ResponseDraft::SCHEMA_DESCRIPTIONS] ?? null) : null;

        $out = [];
        foreach (is_array($said) ? $said : [] as $mediaType => $sentences) {
            foreach (is_array($sentences) ? $sentences : [] as $sentence) {
                if (is_string($sentence) && $sentence !== '') {
                    $out[(string) $mediaType][] = $sentence;
                }
            }
        }

        return $out;
    }

    /**
     * The response that publishes the body: the component a hoisted use points at, else the use itself.
     *
     * @param  array<array-key, mixed>  $doc
     * @param  array<array-key, mixed>  $response
     * @return array<array-key, mixed>
     */
    private static function body(array $doc, array $response): array
    {
        $ref = $response['$ref'] ?? null;
        if (! is_string($ref) || ! str_starts_with($ref, self::RESPONSES)) {
            return $response;
        }

        $component = DocumentGraph::at($doc, ['components', 'responses', substr($ref, strlen(self::RESPONSES))]);

        return is_array($component) ? $component : [];
    }

    /**
     * The schema component a media type's body is, where it is exactly a pointer to one.
     *
     * @param  array<array-key, mixed>  $body
     */
    private static function schemaNamed(array $body, string $mediaType): ?string
    {
        $schema = DocumentGraph::at($body, ['content', $mediaType, 'schema']);
        $ref = is_array($schema) ? ($schema['$ref'] ?? null) : null;

        return is_string($ref) && str_starts_with($ref, self::SCHEMAS) ? substr($ref, strlen(self::SCHEMAS)) : null;
    }

    /**
     * @param  array<array-key, mixed>  $response
     * @return array<array-key, mixed>
     */
    private static function withoutFact(array $response): array
    {
        $extension = is_array($response['x-docuccino'] ?? null) ? $response['x-docuccino'] : [];
        $facts = is_array($extension['facts'] ?? null) ? $extension['facts'] : [];
        unset($facts[ResponseDraft::SCHEMA_DESCRIPTIONS]);

        if ($facts === []) {
            unset($extension['facts']);
        } else {
            $extension['facts'] = $facts;
        }

        $response['x-docuccino'] = $extension;

        return $response;
    }

    /**
     * @param  array<string, list<string>>  $sentences  sentence → where each was said
     */
    private static function conflict(string $name, array $sentences): Diagnostic
    {
        ksort($sentences, SORT_STRING);

        $said = [];
        foreach ($sentences as $sentence => $where) {
            $where = array_values(array_unique($where));
            sort($where, SORT_STRING);
            $said[] = sprintf('"%s" (%s)', $sentence, implode(', ', $where));
        }

        return new Diagnostic(
            severity: Severity::Warning,
            code: 'components.schema-description-conflict',
            message: sprintf('Schema "%s" is described differently by the responses naming it — %s — so it is published with no description.', $name, implode('; ', $said)),
            help: 'Every response naming one schema describes the same thing: write the description on one of them, or the same sentence on each.',
        );
    }
}
