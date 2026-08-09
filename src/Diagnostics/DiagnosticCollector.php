<?php

declare(strict_types=1);

namespace Docuccino\Core\Diagnostics;

use Docuccino\Core\Extensions\Contracts\DocumentTransformer;

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
