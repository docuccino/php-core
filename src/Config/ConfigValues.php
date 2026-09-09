<?php

declare(strict_types=1);

namespace Docuccino\Core\Config;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Support\Arr;
use Docuccino\Core\Support\PlainText;

/**
 * The parsed configuration, read one setting at a time by a reader that REFUSES a value of the wrong
 * type instead of converting it. Each refusal names the setting, what was written, what the setting
 * takes, and the value used in its place; the setting then answers its documented default.
 *
 * Casting is what this class exists to not do, and YAML is why. `version: 1.10` parses to the float
 * 1.1, so `(string)` publishes "1.1" — a different version number in a document somebody's client is
 * generated from. `enabled: no` parses to the STRING "no", so a forgiving boolean read makes it TRUE,
 * which is the opposite of what the author wrote. Neither is a value to salvage; both are a line to go
 * and fix, and a diagnostic is the only thing that gets the author there.
 *
 * Absent and present-null are different questions with different answers. {@see has()} answers the
 * first and nothing else does — a reader that needs to tell "the author never mentioned this" from
 * "the author mentioned it and the value did not survive" asks it, and a typed read answers the
 * default for both because there is no value either way.
 *
 * Diagnostics are keyed by setting and handed back in the setting's alphabetical order, so a build
 * reports the same lines however many readers asked and in whatever order they asked.
 *
 * @internal
 */
final class ConfigValues
{
    /**
     * Refusals, keyed by the full setting path. Held only on the reader the build made; a section
     * reader made by {@see map()} records into its root, so the build has one list however deeply the
     * setting that was refused sits.
     *
     * @var array<string, Diagnostic>
     */
    private array $refusals = [];

    /**
     * @param  array<string, mixed>  $values
     * @param  string  $prefix  What to put in front of a setting name so a refusal names it in full.
     * @param  self|null  $root  Where refusals are recorded; null on the reader the build made.
     */
    private function __construct(
        private readonly array $values,
        private readonly string $prefix = '',
        private readonly ?self $root = null,
    ) {}

    /**
     * A reader over a parsed top-level map. The only way in — the prefix and root a section reader
     * carries are plumbing, and a caller who set them by hand would file its refusals under a name
     * nothing in the file has.
     *
     * @param  array<string, mixed>  $values
     */
    public static function of(array $values): self
    {
        return new self($values);
    }

    /**
     * Whether the setting is written in the file AT ALL — true for a key present and null.
     *
     * The distinction is load-bearing rather than pedantic. A key nobody wrote has expressed no
     * intent, and its documented fallback is the right answer; a key written with an unreadable value
     * has an author behind it, and the two readings of some settings are opposites.
     */
    public function has(string $path): bool
    {
        return $this->find($path)[0];
    }

    /** The value exactly as parsed, with nothing rejected and nothing converted. Null when absent. */
    public function raw(string $path): mixed
    {
        return $this->find($path)[1];
    }

    public function string(string $path, ?string $default = null): ?string
    {
        $value = $this->find($path)[1];

        if ($value === null || is_string($value)) {
            return $value ?? $default;
        }

        $this->refuse($path, self::described($value), 'text', self::fallback($default), match (true) {
            is_float($value) => 'Quote it. An unquoted number is read as a number, and a trailing zero does not survive that — `1.10` becomes 1.1.',
            is_bool($value) => 'Quote it. Unquoted `true` and `false` are the only two words YAML reads as booleans.',
            default => 'Quote it, so it is read as text rather than as a number.',
        });

        return $default;
    }

    public function bool(string $path, ?bool $default = null): ?bool
    {
        $value = $this->find($path)[1];

        if ($value === null || is_bool($value)) {
            return $value ?? $default;
        }

        $this->refuse($path, self::described($value), 'true or false', self::fallback($default), match (true) {
            // The one that catches people. YAML reads `no`, `off`, `yes` and `on` as TEXT, so the
            // author who wrote the shortest possible "off" wrote a non-empty string — which anything
            // willing to convert would read as ON.
            is_string($value) && in_array(strtolower($value), ['no', 'off', 'n', 'yes', 'on', 'y'], true) => 'Write `false` or `true`. Those are the only two words read as booleans — `no`, `off`, `yes` and `on` are read as text.',
            default => 'Write `false` or `true`, unquoted.',
        });

        return $default;
    }

    public function int(string $path, ?int $default = null): ?int
    {
        $value = $this->find($path)[1];

        if ($value === null || is_int($value)) {
            return $value ?? $default;
        }

        $this->refuse($path, self::described($value), 'a whole number', self::fallback($default), match (true) {
            is_float($value) => 'Write it without a decimal point.',
            // `0777` and `08` are text, not numbers, because neither is valid in any of YAML's integer
            // notations — which is exactly the spelling somebody reaches for first.
            is_string($value) => 'Write it unquoted, without a leading zero and without thousands separators.',
            default => 'Write a whole number.',
        });

        return $default;
    }

    /**
     * A list of text, refused WHOLE when any member is not text.
     *
     * Dropping the offending member instead would be the quiet kind of wrong: a list of paths or
     * patterns short by one silently changes what the build looks at, and the document that comes out
     * is missing things rather than visibly broken.
     *
     * @param  list<string>|null  $default
     * @return list<string>|null
     */
    public function strings(string $path, ?array $default = null): ?array
    {
        $value = $this->find($path)[1];

        if ($value === null) {
            return $default;
        }

        if (! is_array($value) || ! array_is_list($value)) {
            $this->refuse($path, self::described($value), 'a list of text', self::fallback($default), 'Write it as a YAML list, one `- entry` per line.');

            return $default;
        }

        $strings = [];

        foreach ($value as $index => $member) {
            if (is_string($member)) {
                $strings[] = $member;

                continue;
            }

            $this->refuse(
                $path,
                sprintf('a list whose entry %d is %s', $index + 1, self::described($member)),
                'a list of text',
                self::fallback($default),
                'Quote that entry, or remove it. One entry the build cannot read makes the whole list untrustworthy, so none of it is used.',
            );

            return $default;
        }

        return $strings;
    }

    /**
     * A nested section, as a reader of its own so the section's keys get the same refusals under their
     * full names.
     *
     * An absent section and an empty one answer the same reader over no values, because a section is
     * addressed by its keys and there are none either way. A section written as a LIST is refused:
     * `documents:` followed by `- name` is a different document from `documents:` followed by
     * `name:`, and reading the first as the second would invent structure the author did not write.
     */
    public function map(string $path): self
    {
        $value = $this->find($path)[1];
        $root = $this->root ?? $this;

        if (is_array($value) && ! array_is_list($value)) {
            return new self(Arr::stringKeyed($value), $this->prefix.$path.'.', $root);
        }

        // An empty map arrives from YAML as an empty LIST — `{}` and `[]` parse to the same PHP array,
        // and there is nothing left in the parsed value to tell one from the other. So an empty
        // anything reads as an empty section rather than a refusal, because refusing it would name a
        // defect in a file that says exactly what it means.
        if ($value !== null && $value !== []) {
            $this->refuse($path, self::described($value), 'a map of settings', 'an empty section', 'Write the section as `key: value` pairs, indented under the section name.');
        }

        return new self([], $this->prefix.$path.'.', $root);
    }

    /**
     * Every refusal this reader has made, in setting order.
     *
     * @return list<Diagnostic>
     */
    public function diagnostics(): array
    {
        // Read off the root, so asking a section reader gives the build's answer rather than the
        // handful of refusals that happened to be filed through that particular child.
        $refusals = ($this->root ?? $this)->refusals;
        ksort($refusals, SORT_STRING);

        return array_values($refusals);
    }

    /**
     * @return array{0: bool, 1: mixed} [written in the file, the value]
     */
    private function find(string $path): array
    {
        $node = $this->values;

        // A dot addresses STRUCTURE — `documents.title` is the `title` key of the `documents` section.
        // A key whose own name holds a dot is reached by taking its section with map() and reading the
        // key there, which is how the settings whose keys are author-supplied patterns are read.
        foreach (explode('.', $path) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return [false, null];
            }

            $node = $node[$segment];
        }

        return [true, $node];
    }

    /**
     * Record one refusal. `$used` is the value the caller is about to answer instead, so the message
     * and the return value cannot drift apart — a diagnostic that names a fallback the reader does not
     * actually return is worse than no diagnostic, because it is checkable and wrong.
     *
     * First refusal per setting wins. Two readers asking the same bad setting is one defect with one
     * line to fix, and reporting it twice would also make the count depend on how many asked.
     */
    private function refuse(string $path, string $found, string $required, string $used, string $help): void
    {
        $root = $this->root ?? $this;
        $name = $this->prefix.$path;

        $root->refusals[$name] ??= new Diagnostic(
            severity: Severity::Warning,
            code: 'config.value-type',
            message: sprintf(
                '%s is %s, where the setting takes %s — %s is used instead.',
                $name,
                $found,
                $required,
                $used,
            ),
            help: $help,
        );
    }

    /**
     * The answer a refused setting is about to give, named for its author. One place, because four
     * typed readers say it and a fifth answer that disagreed with the other four is exactly the drift
     * this phrasing exists to prevent.
     */
    private static function fallback(mixed $default): string
    {
        return $default === null || $default === [] ? 'the built-in default' : self::rendered($default);
    }

    /** What was written, as a phrase naming both the type and — for a scalar — the value itself. */
    private static function described(mixed $value): string
    {
        return match (true) {
            $value === null => 'empty',
            is_bool($value) => sprintf('the boolean %s', $value ? 'true' : 'false'),
            is_int($value) => sprintf('the whole number %s', self::rendered($value)),
            is_float($value) => sprintf('the decimal number %s', self::rendered($value)),
            is_string($value) => sprintf('the text %s', self::rendered($value)),
            is_array($value) => array_is_list($value) ? 'a list' : 'a map',
            default => 'a value of a kind YAML has no notation for',
        };
    }

    /**
     * A value as it should be read back to its author.
     *
     * Through {@see PlainText} because every byte here came out of a file: a setting can hold anything
     * somebody typed, and a diagnostic goes to a terminal and to CI logs.
     */
    private static function rendered(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return PlainText::of($json === false ? '(unprintable)' : $json);
    }
}
