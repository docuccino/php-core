<?php

declare(strict_types=1);

namespace Docuccino\Core\Diagnostics;

/**
 * The diagnostics an application has looked at and accepted. An accepted diagnostic still prints —
 * accepting one is a statement about the build, not a way to stop reading it — it only stops counting
 * towards a severity gate.
 *
 * Acceptance never covers an `error`: an error says the document is wrong or the build lost a whole
 * tier of facts, and a gate that can be talked out of one is not a gate. An accepted code emitted at
 * error severity is {@see refused()} instead, which is the report the reader gets for it.
 *
 * An entry is a whole code, or a code at one operation. A whole code fits a cause that is one decision
 * for the whole application; where a code is right at one route and a defect at the next, the entry
 * names the finding's own route signature, exactly as the console prints it. Either kind that stops
 * matching is {@see unused()}, so a rename cannot leave one behind unseen.
 *
 * @phpstan-type Entry array{code: string, operation: string|null}
 *
 * @internal
 */
final readonly class AcceptedCodes
{
    /**
     * @param  list<Entry>  $entries  deduped and sorted by code, a whole code before its operations, so
     *                                the console reads the same on every run
     */
    private function __construct(public array $entries) {}

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Entries as configured: a code, or `{code, operation}`. Anything else is dropped rather than read
     * wider than it was written — a map with a key this does not know may be a scope misspelled, and
     * accepting its whole code would quiet findings nobody chose to.
     *
     * @param  array<array-key, mixed>  $entries
     */
    public static function of(array $entries): self
    {
        $accepted = [];

        foreach ($entries as $entry) {
            $read = self::entry($entry);

            if ($read !== null) {
                $accepted[$read['code']."\0".($read['operation'] ?? '')] = $read;
            }
        }

        ksort($accepted, SORT_STRING);

        return new self(array_values($accepted));
    }

    /** True when this diagnostic was accepted, so it prints but gates nothing. */
    public function accepts(Diagnostic $diagnostic): bool
    {
        return $diagnostic->severity !== Severity::Error && self::anyEntryMatches($this->entries, $diagnostic);
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
     * The entries something reported as an error, so their acceptance did not apply.
     *
     * @param  list<Diagnostic>  $diagnostics
     * @return list<Entry>
     */
    public function refused(array $diagnostics): array
    {
        $errors = array_filter(
            $diagnostics,
            static fn (Diagnostic $diagnostic): bool => $diagnostic->severity === Severity::Error,
        );

        return array_values(array_filter(
            $this->entries,
            static fn (array $entry): bool => self::matchesAny($entry, $errors),
        ));
    }

    /**
     * The entries nothing in $reported matches, at any severity — a fixed cause, a renamed route, or a
     * typo. All three are the same answer: the entry does nothing and can go.
     *
     * @param  list<Diagnostic>  $reported
     * @return list<Entry>
     */
    public function unused(array $reported): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (array $entry): bool => ! self::matchesAny($entry, $reported),
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

    /** @param  list<Entry>  $entries */
    private static function anyEntryMatches(array $entries, Diagnostic $diagnostic): bool
    {
        foreach ($entries as $entry) {
            if (self::matches($entry, $diagnostic)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Entry  $entry
     * @param  array<array-key, Diagnostic>  $diagnostics
     */
    private static function matchesAny(array $entry, array $diagnostics): bool
    {
        foreach ($diagnostics as $diagnostic) {
            if (self::matches($entry, $diagnostic)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An operation matches exactly what it spells: a scope that quietly widened would accept a finding
     * nobody reviewed, which is the one thing a scope is there to prevent.
     *
     * @param  Entry  $entry
     */
    private static function matches(array $entry, Diagnostic $diagnostic): bool
    {
        return $entry['code'] === $diagnostic->code
            && ($entry['operation'] === null || $entry['operation'] === $diagnostic->routeSignature);
    }

    /** @return Entry|null */
    private static function entry(mixed $entry): ?array
    {
        if (is_string($entry)) {
            $code = self::text($entry);

            return $code === null ? null : ['code' => $code, 'operation' => null];
        }

        if (! is_array($entry) || array_diff(array_keys($entry), ['code', 'operation']) !== []) {
            return null;
        }

        $code = self::text($entry['code'] ?? null);
        $scoped = array_key_exists('operation', $entry);
        $operation = $scoped ? self::text($entry['operation']) : null;

        if ($code === null || ($scoped && $operation === null)) {
            return null;
        }

        return ['code' => $code, 'operation' => $operation];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
