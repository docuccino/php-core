<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;

/**
 * Hoists an error response body repeated across operations into one `components.responses` entry each
 * operation `$ref`s. An app with a uniform error contract states that contract once instead of a thousand
 * times, which is most of a large document's bytes — and most of its git churn, since editing one shared
 * shape then moves one hunk rather than every path it appears under.
 *
 * Identity survives the rewrite. Each operation keeps its own `x-docuccino` (response id + provenance)
 * beside the `$ref`, so the id-based semantic diff still sees a distinct response per operation; only the
 * duplicated shape moves. The hoisted component itself carries no provenance, because provenance is a
 * per-route fact and one route's source file has no business speaking for the others.
 *
 * The example is part of the identity: two bodies differing only in their example are two components
 * (`Error403`, `Error403_2`, …), each stating one it can honestly claim. Keeping one component and
 * intersecting the group's examples instead measures badly — on a real 159-route app 196 of 199 403s fold a
 * true `type` and 3 cannot, so the intersection deletes a correct value from 98.5% of the document to avoid
 * over-claiming for 1.5%, while grouping by example costs about two components per busy status.
 *
 * Deliberately narrow: 4xx/5xx only, only bodies that actually repeat, and only responses that carry
 * `content` — a description-only response is already small, and `$ref`-ing it would cost readability for
 * no bytes. Anything already a `$ref` (the Problem Details preset's own hoists) is left alone.
 */
final class SharedErrorResponses implements DocumentTransformer
{
    /** Below this a response isn't an error, so a shared error shape is none of its business. */
    private const MIN_STATUS = 400;

    /** The provenance key stripped from a hoisted body and kept on the referring operation. */
    private const PROVENANCE = 'x-docuccino';

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

        $shared = self::shared($paths);
        if ($shared === []) {
            return;
        }

        /** @var array<string, mixed> $existing */
        $existing = is_array($doc['components'] ?? null) && is_array($doc['components']['responses'] ?? null)
            ? $doc['components']['responses']
            : [];

        [$paths, $responses] = self::rewrite($paths, $shared, $existing);

        if ($responses === $existing) {
            return;
        }

        $components = is_array($doc['components'] ?? null) ? $doc['components'] : [];
        $components['responses'] = $responses;

        $doc['paths'] = $paths;
        $doc['components'] = $components;

        $document->replace($doc);
    }

    /**
     * The canonical body keys worth sharing — the ones 2+ operations state identically.
     *
     * @param  array<array-key, mixed>  $paths
     * @return array<string, true>
     */
    private static function shared(array $paths): array
    {
        /** @var array<string, int<1, max>> $counts */
        $counts = [];
        foreach (self::errorResponses($paths) as $response) {
            $key = self::key($response);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $shared = [];
        foreach ($counts as $key => $count) {
            if ($count > 1) {
                $shared[$key] = true;
            }
        }

        return $shared;
    }

    /**
     * Replaces every shareable body with a `$ref`, registering each shape's component as it's first met.
     * Path order is the document's own, so the name a shape claims — and any collision suffix — is stable
     * across builds.
     *
     * @param  array<array-key, mixed>  $paths
     * @param  array<string, true>  $shared  the canonical keys to hoist
     * @param  array<string, mixed>  $components
     * @return array{array<array-key, mixed>, array<string, mixed>}
     */
    private static function rewrite(array $paths, array $shared, array $components): array
    {
        /** @var array<string, string> $names canonical key → component name */
        $names = [];

        foreach ($paths as $path => $operations) {
            if (! is_array($operations)) {
                continue;
            }

            foreach ($operations as $method => $operation) {
                if (! is_array($operation)) {
                    continue;
                }

                $responses = $operation['responses'] ?? null;
                if (! is_array($responses)) {
                    continue;
                }

                $rewrote = false;
                foreach ($responses as $status => $response) {
                    if (! is_array($response) || ! self::isShareable($status, $response)) {
                        continue;
                    }

                    $key = self::key($response);
                    if (! isset($shared[$key])) {
                        continue;
                    }

                    if (! isset($names[$key])) {
                        $body = self::stripProvenance($response);
                        $name = self::name((string) $status, $components, $body);
                        $components[$name] = $body;
                        $names[$key] = $name;
                    }

                    $entry = ['$ref' => '#/components/responses/'.$names[$key]];
                    if (array_key_exists(self::PROVENANCE, $response)) {
                        $entry = [self::PROVENANCE => $response[self::PROVENANCE]] + $entry;
                    }

                    $responses[$status] = $entry;
                    $rewrote = true;
                }

                if ($rewrote) {
                    $operation['responses'] = $responses;
                    $operations[$method] = $operation;
                    $paths[$path] = $operations;
                }
            }
        }

        return [$paths, $components];
    }

    /**
     * Every error response a body could be shared from.
     *
     * @param  array<array-key, mixed>  $paths
     * @return list<array<array-key, mixed>>
     */
    private static function errorResponses(array $paths): array
    {
        $out = [];

        foreach ($paths as $operations) {
            if (! is_array($operations)) {
                continue;
            }

            foreach ($operations as $operation) {
                if (! is_array($operation)) {
                    continue;
                }

                $responses = $operation['responses'] ?? null;
                if (! is_array($responses)) {
                    continue;
                }

                foreach ($responses as $status => $response) {
                    if (is_array($response) && self::isShareable($status, $response)) {
                        $out[] = $response;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * An error response with a real body that isn't already a reference.
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
     * The dedupe identity of a body: everything it documents, examples included, with provenance removed and
     * keys sorted so two responses assembled in different orders still collapse together.
     *
     * @param  array<array-key, mixed>  $response
     */
    private static function key(array $response): string
    {
        return json_encode(self::sorted(self::stripProvenance($response))) ?: '';
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

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sorted(array $value): array
    {
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::sorted($v);
            }
        }

        ksort($value);

        return $value;
    }

    /**
     * `Error<status>`, or that name suffixed when a different shape already holds it — two distinct 422
     * bodies in one document are two components, never one merged wrong. An identical existing component
     * (a rebuild over a restored snapshot) is reused rather than duplicated.
     *
     * @param  array<string, mixed>  $components
     * @param  array<array-key, mixed>  $body
     */
    private static function name(string $status, array $components, array $body): string
    {
        $base = 'Error'.$status;

        for ($n = 1; ; $n++) {
            $name = $n === 1 ? $base : $base.'_'.$n;
            $taken = $components[$name] ?? null;

            if ($taken === null || $taken === $body) {
                return $name;
            }
        }
    }
}
