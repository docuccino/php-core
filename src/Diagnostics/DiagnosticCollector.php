<?php

declare(strict_types=1);

namespace Docuccino\Core\Diagnostics;

use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Provenance\Source;

/**
 * The build's diagnostics sink. Returns them in insertion order ({@see all()}) or in a byte-stable
 * order ({@see sorted()}) — never time-based — so CLI output and any `--embed-diagnostics` payload
 * don't reorder between runs.
 *
 * It also backs the per-document diagnostics channel {@see DocumentTransformer}s get via
 * DocumentContext, since a whole-document transformer runs after assembly and would otherwise have
 * nowhere to report. Held by reference on a readonly context, so transformers can report without
 * making the context mutable.
 */
final class DiagnosticCollector
{
    /**
     * @var list<Diagnostic>
     */
    private array $diagnostics = [];

    public function add(Diagnostic $diagnostic): void
    {
        $this->diagnostics[] = $diagnostic;
    }

    /**
     * @param  iterable<Diagnostic>  $diagnostics
     */
    public function addAll(iterable $diagnostics): void
    {
        foreach ($diagnostics as $diagnostic) {
            $this->diagnostics[] = $diagnostic;
        }
    }

    /**
     * The diagnostics in insertion order.
     *
     * @return list<Diagnostic>
     */
    public function all(): array
    {
        return $this->diagnostics;
    }

    /**
     * Grouped by route signature, then severity, code and message. Nothing time-based.
     *
     * The key is TOTAL — it runs on to `source` and `help` — because two diagnostics agreeing down to
     * the message are ordinary: one `#[DescriptionFromFile]` escape per controller says the same thing
     * about a different file, and neither carries a route signature. A key that could not tell them
     * apart left their order to insertion, which is discovery order.
     *
     * @return list<Diagnostic>
     */
    public function sorted(): array
    {
        $diagnostics = $this->diagnostics;

        usort($diagnostics, static fn (Diagnostic $a, Diagnostic $b): int => self::key($a) <=> self::key($b));

        return $diagnostics;
    }

    /**
     * @return list<mixed>
     */
    private static function key(Diagnostic $diagnostic): array
    {
        $source = $diagnostic->source ?? new Source('');

        return [
            $diagnostic->routeSignature ?? '',
            self::rank($diagnostic->severity),
            $diagnostic->code,
            $diagnostic->message,
            $source->file,
            $source->line ?? -1,
            $source->symbol ?? '',
            $diagnostic->help ?? '',
        ];
    }

    private static function rank(Severity $severity): int
    {
        return match ($severity) {
            Severity::Error => 0,
            Severity::Warning => 1,
            Severity::Info => 2,
            Severity::Hint => 3,
        };
    }
}
