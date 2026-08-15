<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Extensions\Schema\ComponentNames;
use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Core\Support\Arr;
use Docuccino\Core\Support\Json;

/**
 * Collapses a repeated error body into shared components, in two independent passes: the body SHAPE into
 * `components.schemas`, then — over the rewritten document — the whole RESPONSE into
 * `components.responses`. That order is load-bearing and the two are never alternatives; design §"Shared
 * error components" has the argument, and §2 "Component naming" the names.
 *
 * Identity survives both rewrites: an operation keeps its own response id and provenance beside the
 * `$ref`, and a hoisted component carries an id minted from the bytes it publishes — never a per-route
 * source, which has no business speaking for the other routes sharing it.
 *
 * Deliberately narrow: 4xx/5xx only, only bodies that actually repeat, and only responses carrying
 * `content`. Anything already a `$ref` is left alone, which is what makes a second run a no-op.
 */
final class SharedErrorResponses implements DocumentTransformer
{
    /** Below this a response isn't an error, so a shared error shape is none of its business. */
    private const MIN_STATUS = 400;

    /** The provenance key stripped from a hoisted body and kept on the referring node. */
    private const PROVENANCE = 'x-docuccino';

    /**
     * How many occurrences make a body worth hoisting. Deliberately not a local boundary — a second
     * occurrence promotes the first from inline to `$ref` — which is a ranked trade, not an oversight:
     * design §"Shared error components".
     */
    private const MIN_OCCURRENCES = 2;

    public function transform(UirDocumentDraft $document, DocumentContext $context): void
    {
        if (! RepresentationPolicy::fromConfig($context->config->representation)->errorComponents) {
            return;
        }

        $doc = $document->toArray();
        $paths = $doc['paths'] ?? null;
        if (! is_array($paths)) {
            return;
        }

        $components = is_array($doc['components'] ?? null) ? $doc['components'] : [];

        [$paths, $schemas, $schemaContests] = self::shareShapes($paths, self::bucket($components, 'schemas'));
        [$paths, $responses, $responseContests] = self::shareResponses($paths, self::bucket($components, 'responses'));

        foreach ([...$schemaContests, ...$responseContests] as $collision) {
            $context->report($collision);
        }

        if ($schemas === null && $responses === null) {
            return;
        }

        if ($schemas !== null) {
            $components['schemas'] = $schemas;
        }

        if ($responses !== null) {
            $components['responses'] = $responses;
        }

        $doc['paths'] = $paths;
        $doc['components'] = $components;

        $document->replace($doc);
    }

    /**
     * @param  array<array-key, mixed>  $components
     * @return array<string, mixed>
     */
    private static function bucket(array $components, string $kind): array
    {
        return is_array($components[$kind] ?? null) ? Arr::stringKeyed($components[$kind]) : [];
    }

    /**
     * Pass one: hoist every repeated body shape, rewriting each media type's `schema` to a `$ref`.
     *
     * @param  array<array-key, mixed>  $paths
     * @param  array<string, mixed>  $existing
     * @return array{array<array-key, mixed>, array<string, mixed>|null, list<Diagnostic>}
     */
    private static function shareShapes(array $paths, array $existing): array
    {
        $shapes = self::shareable(self::collect($paths, self::schemaSites(...)));
        if ($shapes === []) {
            return [$paths, null, []];
        }

        $identity = new IdentityGenerator;
        [$names, $schemas, $contests] = self::mint($shapes, $existing, static fn (array $body, string $status): array => [
            self::PROVENANCE => ['id' => $identity->publishedSchemaId($status, Arr::stringKeyed($body))],
        ] + $body);

        return [
            self::rewrite($paths, $names, self::schemaSites(...), '#/components/schemas/'),
            $schemas,
            self::collisions($contests, $names, 'schemas'),
        ];
    }

    /**
     * Pass two: hoist every response the rewritten document now states identically two or more times.
     *
     * @param  array<array-key, mixed>  $paths
     * @param  array<string, mixed>  $existing
     * @return array{array<array-key, mixed>, array<string, mixed>|null, list<Diagnostic>}
     */
    private static function shareResponses(array $paths, array $existing): array
    {
        $responses = self::shareable(self::collect($paths, self::responseSites(...)));
        if ($responses === []) {
            return [$paths, null, []];
        }

        [$names, $bucket, $contests] = self::mint($responses, $existing, static fn (array $body, string $status): array => $body);

        return [
            self::rewrite($paths, $names, self::responseSites(...), '#/components/responses/'),
            $bucket,
            self::collisions($contests, $names, 'responses'),
        ];
    }

    /**
     * Every hoistable node of one response, as `[pointer into the response, body]`. A schema pass reads
     * one per media type; a response pass reads the response itself.
     *
     * @param  array<array-key, mixed>  $response
     * @return list<array{list<array-key>, array<array-key, mixed>}>
     */
    private static function schemaSites(array $response): array
    {
        /** @var array<array-key, mixed> $content */
        $content = $response['content'];

        $out = [];
        foreach ($content as $mediaType => $media) {
            $schema = is_array($media) ? ($media['schema'] ?? null) : null;
            if (is_array($schema) && self::isHoistable($schema)) {
                $out[] = [['content', $mediaType, 'schema'], $schema];
            }
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $response
     * @return list<array{list<array-key>, array<array-key, mixed>}>
     */
    private static function responseSites(array $response): array
    {
        return [[[], $response]];
    }

    /**
     * Count what every hoistable node states, keyed by its status and canonical content.
     *
     * @param  array<array-key, mixed>  $paths
     * @param  callable(array<array-key, mixed>): list<array{list<array-key>, array<array-key, mixed>}>  $sites
     * @return array<string, array{status: string, body: array<array-key, mixed>, count: int}>
     */
    private static function collect(array $paths, callable $sites): array
    {
        $out = [];

        foreach ($paths as $operations) {
            if (! is_array($operations)) {
                continue;
            }

            foreach ($operations as $operation) {
                if (! is_array($operation) || ! is_array($operation['responses'] ?? null)) {
                    continue;
                }

                foreach ($operation['responses'] as $status => $response) {
                    if (! is_array($response) || ! self::isShareable($status, $response)) {
                        continue;
                    }

                    foreach ($sites($response) as [, $body]) {
                        $stripped = self::stripProvenance($body);
                        $key = self::key((string) $status, $stripped);

                        $out[$key] ??= ['status' => (string) $status, 'body' => $stripped, 'count' => 0];
                        $out[$key]['count']++;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * The bodies worth hoisting: the ones that repeat.
     *
     * @param  array<string, array{status: string, body: array<array-key, mixed>, count: int}>  $bodies
     * @return array<string, array{status: string, body: array<array-key, mixed>, count: int}>
     */
    private static function shareable(array $bodies): array
    {
        return array_filter($bodies, static fn (array $body): bool => $body['count'] >= self::MIN_OCCURRENCES);
    }

    /**
     * The published name of every shared body, the bucket it was hoisted into, and the names that were
     * contested.
     *
     * The naming is {@see ComponentNames}'s, not this transformer's: every path that mints a component
     * name owes it that invariant, and a second implementation of "plain name, then content hash, then
     * a numeric tail" is how the two would come to disagree. Each body states a claim with no identity
     * to carry, so the bytes stand in for one and the ladder degenerates to exactly that pair.
     *
     * So `Error<status>` belongs to a status only while ONE body claims it: two make it contested and
     * each takes a name derived from its own content, and a third arriving later disturbs neither. A
     * component already holding a name with a DIFFERENT body is `$taken` and cannot move — this pass
     * runs after the registry's names are published — so the shared body climbs past it instead. One
     * holding an IDENTICAL body is not taken, which is what keeps a rebuild over a restored document
     * byte-identical.
     *
     * @param  array<string, array{status: string, body: array<array-key, mixed>, count: int}>  $bodies
     * @param  array<string, mixed>  $existing
     * @param  callable(array<array-key, mixed>, string): array<array-key, mixed>  $publish
     * @return array{array<string, string>, array<string, mixed>, array<string, list<string>>}
     */
    private static function mint(array $bodies, array $existing, callable $publish): array
    {
        $claims = [];
        $published = [];
        foreach ($bodies as $key => $body) {
            $claims[$key] = ['base' => 'Error'.$body['status'], 'identity' => null, 'content' => $key];
            $published[$key] = $publish($body['body'], $body['status']);
        }

        $taken = [];
        foreach ($existing as $name => $body) {
            if (! in_array($body, $published, true)) {
                $taken[] = $name;
            }
        }

        [$names, $contests] = ComponentNames::mint($claims, $taken);

        // Filed in content order, so even the bucket's INSERTION order — which survives `toArray()`
        // and is only sorted away on emit — is a function of the bodies rather than of the order the
        // document walk met them.
        $ordered = $names;
        ksort($ordered);

        $bucket = $existing;
        foreach ($ordered as $key => $name) {
            $bucket[$name] = $published[$key];
        }

        return [$names, $bucket, $contests];
    }

    /**
     * One warning per name more than one thing asked for. `Error404` is a name a client's generated
     * type is called after, and a second 404 shape retires it and repoints every operation that
     * referenced it — a real change to what the document publishes, and the trade this transformer
     * makes deliberately (naming the common single-shape case `Error404_a1b2c3d4` to spare a few
     * documents a one-time rename would be worse for everyone). What it must not be is silent.
     *
     * @param  array<string, list<string>>  $contests  asked name → the bodies that asked for it
     * @param  array<string, string>  $names  body → the name it was published under
     * @return list<Diagnostic>
     */
    private static function collisions(array $contests, array $names, string $bucket): array
    {
        ksort($contests);

        $out = [];
        foreach ($contests as $asked => $claimants) {
            $published = array_map(static fn (string $key): string => $names[$key] ?? $key, $claimants);
            sort($published);

            $out[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'components.name-collision',
                message: sprintf(
                    'Component name "%s" is claimed by more than one shape, so each shared error body that asked for it was published under a name derived from its own content (%s) in components.%s.',
                    $asked,
                    implode(', ', $published),
                    $bucket,
                ),
                help: 'The plain name belongs to a status while one shape holds it and is retired when a second arrives. Nothing to do if the shapes really do differ; otherwise have the operations state one body and the plain name comes back.',
            );
        }

        return $out;
    }

    /**
     * Points every shared body at its component, keeping the body's own provenance beside the `$ref` —
     * a per-route fact the hoisted component cannot state.
     *
     * @param  array<array-key, mixed>  $paths
     * @param  array<string, string>  $names
     * @param  callable(array<array-key, mixed>): list<array{list<array-key>, array<array-key, mixed>}>  $sites
     * @return array<array-key, mixed>
     */
    private static function rewrite(array $paths, array $names, callable $sites, string $prefix): array
    {
        foreach ($paths as $path => $operations) {
            if (! is_array($operations)) {
                continue;
            }

            foreach ($operations as $method => $operation) {
                if (! is_array($operation) || ! is_array($operation['responses'] ?? null)) {
                    continue;
                }

                $responses = $operation['responses'];
                $rewrote = false;

                foreach ($responses as $status => $response) {
                    if (! is_array($response) || ! self::isShareable($status, $response)) {
                        continue;
                    }

                    foreach ($sites($response) as [$pointer, $body]) {
                        $name = $names[self::key((string) $status, self::stripProvenance($body))] ?? null;
                        if ($name === null) {
                            continue;
                        }

                        $reference = ['$ref' => $prefix.$name];
                        if (array_key_exists(self::PROVENANCE, $body)) {
                            $reference = [self::PROVENANCE => $body[self::PROVENANCE]] + $reference;
                        }

                        $response = self::place($response, $pointer, $reference);
                        $rewrote = true;
                    }

                    $responses[$status] = $response;
                }

                if ($rewrote) {
                    $operation['responses'] = $responses;
                    $operations[$method] = $operation;
                    $paths[$path] = $operations;
                }
            }
        }

        return $paths;
    }

    /**
     * Write `$value` at `$pointer` within `$node`; an empty pointer replaces the node itself.
     *
     * @param  array<array-key, mixed>  $node
     * @param  list<array-key>  $pointer
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function place(array $node, array $pointer, array $value): array
    {
        if ($pointer === []) {
            return $value;
        }

        $head = array_shift($pointer);

        if ($pointer === []) {
            $node[$head] = $value;

            return $node;
        }

        $child = $node[$head] ?? null;
        if (is_array($child)) {
            $node[$head] = self::place($child, $pointer, $value);
        }

        return $node;
    }

    /**
     * An error response with a real body that isn't already a reference. A response stating BOTH a
     * `$ref` and a body is left alone too: a Reference Object defines no `content`, so whatever built it
     * is saying something this transformer cannot safely rewrite.
     *
     * @param  array-key  $status
     * @param  array<array-key, mixed>  $response
     */
    private static function isShareable(int|string $status, array $response): bool
    {
        return ! isset($response['$ref'])
            && is_array($response['content'] ?? null)
            && $response['content'] !== []
            && ctype_digit((string) $status)
            && (int) $status >= self::MIN_STATUS;
    }

    /**
     * A body worth hoisting: one that states something, and isn't already pointing somewhere else.
     *
     * @param  array<array-key, mixed>  $body
     */
    private static function isHoistable(array $body): bool
    {
        return $body !== [] && ! isset($body['$ref']);
    }

    /**
     * The dedupe identity of a body: its status and everything it states, with provenance already
     * removed and keys sorted so two bodies assembled in different orders still collapse together.
     * List order is NOT normalised — `required: [a, b]` and `required: [b, a]` emit different bytes, so
     * treating them as one body would have to pick which bytes to publish.
     *
     * @param  array<array-key, mixed>  $body
     */
    private static function key(string $status, array $body): string
    {
        return $status."\0".Json::stable($body);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function stripProvenance(array $value): array
    {
        unset($value[self::PROVENANCE]);

        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::stripProvenance($v);
            }
        }

        return $value;
    }
}
