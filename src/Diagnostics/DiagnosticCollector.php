<?php

declare(strict_types=1);

namespace Docuccino\Core\Diagnostics;

use Docuccino\Core\Extensions\Contracts\DocumentTransformer;

/**
 * The build's diagnostics aggregator: a small mutable sink that accepts diagnostics and returns
 * them either in insertion order ({@see all()}) or in a deterministic, byte-stable order
 * ({@see sorted()}, design §5: never time-based) — grouped by route signature, then by severity,
 * code and message — so CLI output and any `--embed-diagnostics` payload never reorder across runs.
 * Deterministic ordering is a core concern, so the whole aggregator lives here (it was formerly the
 * adapter-side `Pipeline\DiagnosticBag`; the two collapsed into this one type).
 *
 * It also backs the per-document diagnostics channel handed to {@see DocumentTransformer}s (via
 * DocumentContext): a whole-document transformer (e.g. the data-leakage lint) runs after assembly
 * and would otherwise have nowhere to report. Held (by reference) on a readonly context so a
 * transformer can report without the context itself becoming mutable.
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
     * The diagnostics in deterministic order (design §5: never time-based) — grouped by route
     * signature, then by severity, code and message.
     *
     * @return list<Diagnostic>
     */
    public function sorted(): array
    {
        $diagnostics = $this->diagnostics;

        usort($diagnostics, static function (Diagnostic $a, Diagnostic $b): int {
            return [$a->routeSignature ?? '', self::rank($a->severity), $a->code, $a->message]
                <=> [$b->routeSignature ?? '', self::rank($b->severity), $b->code, $b->message];
        });

        return $diagnostics;
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
