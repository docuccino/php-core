<?php

declare(strict_types=1);

namespace Docuccino\Core\Content;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Provenance\Source;

/**
 * Resolves live-reference directives embedded in page markdown against the assembled document, so
 * prose can't silently drift from the code it documents.
 *
 * Authored (leaf-directive) syntax:
 *   ::operation{id="forms.index"}      — id is an operationId, or a "METHOD /path" signature
 *   ::schema{name="FormData"}          — name is a component schema name
 *
 * Output keeps the authored attributes verbatim and appends one `ref` carrying the stable UIR
 * identity — `::operation{id="forms.index" ref="op:v1:mfz3q8k2w9r7t1ua"}`. Viewers link on `ref`,
 * the authored selector stays legible, and appending exactly one attribute keeps the rewrite
 * deterministic.
 *
 * Nothing fails silently: a known directive resolving to nothing errors and is left unresolved; an
 * unknown directive name warns and passes through untouched, so third-party directives degrade.
 *
 * @internal
 */
final class DirectiveResolver
{
    /**
     * A leaf directive at a token boundary: `::name{attrs}`. Strict on purpose — single line, no
     * nested braces — so prose containing `::` is never mistaken for a directive.
     */
    private const string DIRECTIVE = '/(?<![\p{L}\p{N}_:])::([a-zA-Z][a-zA-Z0-9-]*)\{([^}\n]*)\}/u';

    /**
     * Rewrite every directive in $body, resolving `::operation`/`::schema` refs against $index.
     *
     * @return array{0: string, 1: list<Diagnostic>}
     */
    public function resolve(string $body, string $slug, string $sourceFile, DocumentIndex $index): array
    {
        /** @var list<Diagnostic> $diagnostics */
        $diagnostics = [];
        $source = new Source($sourceFile);

        $rewritten = preg_replace_callback(
            self::DIRECTIVE,
            function (array $match) use ($slug, $source, $index, &$diagnostics): string {
                $name = $match[1];
                $attrsRaw = $match[2];
                $attrs = $this->parseAttributes($attrsRaw);

                return match ($name) {
                    'operation' => $this->resolveReference(
                        $match[0], $name, 'id', $attrs, $attrsRaw,
                        static fn (string $ref): ?string => $index->resolveOperation($ref),
                        $slug, $source, $diagnostics,
                    ),
                    'schema' => $this->resolveReference(
                        $match[0], $name, 'name', $attrs, $attrsRaw,
                        static fn (string $ref): ?string => $index->resolveSchema($ref),
                        $slug, $source, $diagnostics,
                    ),
                    default => $this->unknown($match[0], $name, $slug, $source, $diagnostics),
                };
            },
            $body,
        );

        return [$rewritten ?? $body, $diagnostics];
    }

    /**
     * @param  array<string, string>  $attrs
     * @param  callable(string): ?string  $lookup
     * @param  list<Diagnostic>  $diagnostics
     */
    private function resolveReference(
        string $original,
        string $directive,
        string $selector,
        array $attrs,
        string $attrsRaw,
        callable $lookup,
        string $slug,
        Source $source,
        array &$diagnostics,
    ): string {
        // Never trust a pre-existing ref (hand-written, or stale and committed): strip it and
        // re-derive from the selector, so one pointing at a dead id surfaces as
        // content.unresolved-directive instead of drifting. Re-resolving is idempotent.
        if (isset($attrs['ref'])) {
            $attrsRaw = $this->stripRef($attrsRaw);
        }

        $reference = $attrs[$selector] ?? null;
        if ($reference === null || $reference === '') {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Error,
                code: 'content.unresolved-directive',
                message: sprintf('The ::%s directive on page "%s" is missing its "%s" attribute.', $directive, $slug, $selector),
                source: $source,
            );

            return $original;
        }

        $resolved = $lookup($reference);
        if ($resolved === null) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Error,
                code: 'content.unresolved-directive',
                message: sprintf('The ::%s directive on page "%s" references %s="%s", which resolves to nothing.', $directive, $slug, $selector, $reference),
                source: $source,
                help: 'Point it at a documented '.($directive === 'operation' ? 'operationId or "METHOD /path"' : 'component schema name').'.',
            );

            return $original;
        }

        return sprintf('::%s{%s ref="%s"}', $directive, $attrsRaw, $resolved);
    }

    /**
     * @param  list<Diagnostic>  $diagnostics
     */
    private function unknown(string $original, string $name, string $slug, Source $source, array &$diagnostics): string
    {
        $diagnostics[] = new Diagnostic(
            severity: Severity::Warning,
            code: 'content.unknown-directive',
            message: sprintf('Unknown directive ::%s on page "%s" was left unresolved.', $name, $slug),
            source: $source,
            help: 'Docuccino resolves ::operation and ::schema; other directives pass through untouched.',
        );

        return $original;
    }

    /** Remove any `ref="..."` attribute from a raw attribute string so a fresh one can be appended. */
    private function stripRef(string $attrsRaw): string
    {
        return trim((string) preg_replace('/\s*\bref="[^"]*"/', '', $attrsRaw));
    }

    /**
     * @return array<string, string>
     */
    private function parseAttributes(string $raw): array
    {
        preg_match_all('/([a-zA-Z][a-zA-Z0-9-]*)="([^"]*)"/', $raw, $matches, PREG_SET_ORDER);

        $attrs = [];
        foreach ($matches as $match) {
            $attrs[$match[1]] = $match[2];
        }

        return $attrs;
    }
}
