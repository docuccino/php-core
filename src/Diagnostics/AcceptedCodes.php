<?php

declare(strict_types=1);

namespace Docuccino\Core\Diagnostics;

/**
 * The diagnostic codes an application has looked at and accepted. An accepted diagnostic still
 * prints — accepting one is a statement about the build, not a way to stop reading it — it only
 * stops counting towards a severity gate.
 *
 * Acceptance never covers an `error`: an error says the document is wrong or the build lost a whole
 * tier of facts, and a gate that can be talked out of one is not a gate. An accepted code emitted at
 * error severity is {@see refused()} instead, which is the report the reader gets for it.
 *
 * The unit is a whole code rather than a code at a route or a file. A code names one cause, which is
 * the thing a reader can decide about; a list scoped by route would be a second copy of the
 * application's shape, and would go stale on every rename.
 *
 * @internal
 */
final readonly class AcceptedCodes
{
    /**
     * @param  list<string>  $codes  deduped and sorted, so the console reads the same on every run
     */
    private function __construct(public array $codes) {}

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Codes as configured. Anything that isn't a non-empty string is dropped rather than refused —
     * an unusable entry names no code, so it degrades to the same report a stale one gets.
     *
     * @param  array<array-key, mixed>  $codes
     */
    public static function of(array $codes): self
    {
        $accepted = [];

        foreach ($codes as $code) {
            if (is_string($code) && trim($code) !== '') {
                $accepted[] = trim($code);
            }
        }

        $accepted = array_values(array_unique($accepted));
        sort($accepted);

        return new self($accepted);
    }

    /** True when this diagnostic was accepted, so it prints but gates nothing. */
    public function accepts(Diagnostic $diagnostic): bool
    {
        return $diagnostic->severity !== Severity::Error && in_array($diagnostic->code, $this->codes, true);
    }

    /**
     * True when anything NOT accepted was reported at $floor or louder — what `--fail-on` gates on.
     *
     * @param  list<Diagnostic>  $diagnostics
     */
    public function fails(array $diagnostics, Severity $floor): bool
    {
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->severity->atLeast($floor) && ! $this->accepts($diagnostic)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The accepted codes something reported as an error, so their acceptance did not apply.
     *
     * @param  list<Diagnostic>  $diagnostics
     * @return list<string>
     */
    public function refused(array $diagnostics): array
    {
        $refused = [];

        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->severity === Severity::Error && in_array($diagnostic->code, $this->codes, true)) {
                $refused[$diagnostic->code] = true;
            }
        }

        return self::sortedKeys($refused);
    }

    /**
     * The accepted codes none of $reported names — a fixed cause, or a typo. Both are the same
     * answer: the entry does nothing and can go.
     *
     * @param  list<string>  $reported
     * @return list<string>
     */
    public function unused(array $reported): array
    {
        return array_values(array_filter(
            $this->codes,
            static fn (string $code): bool => ! in_array($code, $reported, true),
        ));
    }

    /**
     * How many of $diagnostics each accepted code covered, by code — what the console reports so an
     * acceptance stays visible in the output it is quieting.
     *
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, int>
     */
    public function tally(array $diagnostics): array
    {
        $tally = [];

        foreach ($diagnostics as $diagnostic) {
            if ($this->accepts($diagnostic)) {
                $tally[$diagnostic->code] = ($tally[$diagnostic->code] ?? 0) + 1;
            }
        }

        ksort($tally);

        return $tally;
    }

    /**
     * @param  array<string, true>  $set
     * @return list<string>
     */
    private static function sortedKeys(array $set): array
    {
        $keys = array_keys($set);
        sort($keys);

        return $keys;
    }
}
