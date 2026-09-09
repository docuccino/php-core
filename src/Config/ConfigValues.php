<?php

declare(strict_types=1);

namespace Docuccino\Core\Config;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Support\Arr;
use Docuccino\Core\Support\ConfiguredValue;

/**
 * The parsed configuration, read one setting at a time by a reader that REFUSES a value of the wrong
 * type instead of converting it. Each refusal names the setting, what was written, what the setting
 * takes, and the value used in its place; the setting then answers its documented default.
 *
 * Casting is what this class exists to not do, and YAML is why. `version: 1.10` parses to the float
 * 1.1, so `(string)` publishes "1.1" — a different version number in a document somebody's client is
 * generated from. That is not a value to salvage; it is a line to go and fix, and a diagnostic is the
 * only thing that gets the author there. A switch and a closed-set keyword are the same trap one layer
 * over, and each has a reader of its own — `ConfiguredFlag` and `ConfiguredKeyword`. `enabled: no` is
 * the string "no", which anything willing to coerce reads as ON.
 *
 * A typed read answers the setting's default for absent and for present-null alike, because there is
 * no value either way. The two stay distinguishable all the same, off {@see all()}: a key written with
 * an empty value is still a key the author wrote, so whatever walks the parsed map sees it and holds it
 * to the same reporting as any other key.
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

    /** The value exactly as parsed, with nothing rejected and nothing converted. Null when absent. */
    public function raw(string $path): mixed
    {
        return $this->find($path);
    }

    /**
     * This reader's whole map, exactly as parsed — for a caller that type-checks a section itself and
     * would report the same defect twice if it also read that section a key at a time.
     *
     * A section reader made by {@see map()} answers its own keys and nothing else, and a section that
     * was REFUSED answers none, so the refusal and the empty answer stay one fact rather than two.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    public function string(string $path, ?string $default = null): ?string
    {
        $value = $this->find($path);

        if ($value === null || is_string($value)) {
            return $value ?? $default;
        }

        $this->refuse($path, ConfiguredValue::described($value), 'text', self::fallback($default), match (true) {
            is_float($value) => 'Quote it. An unquoted number is read as a number, and a trailing zero does not survive that — `1.10` becomes 1.1.',
            is_bool($value) => 'Quote it. Unquoted `true` and `false` are the only two words YAML reads as booleans.',
            default => 'Quote it, so it is read as text rather than as a number.',
        });

        return $default;
    }

    /**
     * A list, refused when the value is not one. The ENTRIES are the caller's to read: a `servers` entry
     * is an OAS Server Object and an `exclude` entry is a glob, and there is no one reading of both.
     *
     * Listness alone, and deliberately not the entries too, because the fallback has to be one the
     * caller actually takes. Every reader of a configured list answers its own built-in default for a
     * value that is no list, which is what this refusal claims — while a reader that keeps the members
     * it could read does NOT discard the list over one bad entry, so refusing the whole of it here
     * would name a fallback nobody returns.
     *
     * @return list<mixed>|null
     */
    public function entries(string $path): ?array
    {
        $value = $this->find($path);

        if ($value === null) {
            return null;
        }

        // An empty map arrives as an empty LIST, so it comes through as one rather than as a refusal —
        // see section() for why that ambiguity is never resolved by guessing.
        if (is_array($value) && array_is_list($value)) {
            return $value;
        }

        $this->refuse($path, ConfiguredValue::described($value), 'a list', self::fallback(null), 'Write it as a YAML list, one `- entry` per line.');

        return null;
    }

    /**
     * A nested section, as a reader of its own so the section's keys get the same refusals under their
     * full names.
     *
     * An absent section and an empty one answer the same reader over no values, because a section is
     * addressed by its keys and there are none either way.
     */
    public function map(string $path): self
    {
        return new self(
            $this->section($path, $this->find($path)) ?? [],
            $this->prefix.$path.'.',
            $this->root ?? $this,
        );
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
     * The section at `$path` as a map, or null when there is none there — refusing a value that is no
     * section on the way.
     *
     * The one place that decides what a section IS, because two answers to that question is a hole:
     * {@see map()} hands a section back as a reader, and {@see find()} walks THROUGH a section to
     * reach a key under it. A walk that quietly accepted what map() refuses left a whole subtree
     * unread with nothing said — `routes: 'api/*'` emptied a document's route filter, and every route
     * in the application was published.
     *
     * A section written as a LIST is refused with the rest: `documents:` followed by `- name` is a
     * different document from `documents:` followed by `name:`, and reading the first as the second
     * would invent structure the author did not write.
     *
     * @return array<string, mixed>|null
     */
    private function section(string $path, mixed $value): ?array
    {
        if (is_array($value) && ! array_is_list($value)) {
            return Arr::stringKeyed($value);
        }

        // An empty map arrives from YAML as an empty LIST — `{}` and `[]` parse to the same PHP array,
        // and there is nothing left in the parsed value to tell one from the other. So an empty
        // anything reads as an empty section rather than a refusal, because refusing it would name a
        // defect in a file that says exactly what it means.
        if ($value !== null && $value !== []) {
            $this->refuse($path, ConfiguredValue::described($value), 'a map of settings', 'an empty section', 'Write the section as `key: value` pairs, indented under the section name.');
        }

        return null;
    }

    /** The value at a dotted path, or null when nothing is written there. */
    private function find(string $path): mixed
    {
        $segments = explode('.', $path);
        $last = count($segments) - 1;
        $node = $this->values;
        $walked = '';

        // A dot addresses STRUCTURE — `documents.title` is the `title` key of the `documents` section.
        // A key whose own name holds a dot is reached by taking its section with map() and reading the
        // key there, which is how the settings whose keys are author-supplied patterns are read.
        foreach ($segments as $index => $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
            $walked = $walked === '' ? $segment : $walked.'.'.$segment;

            // Every segment but the last addresses a section, so one holding something else is refused
            // under its own name — see section(). The walk stops there either way: there is no key to
            // reach under a value that has none.
            if ($index !== $last && $this->section($walked, $node) === null) {
                return null;
            }
        }

        return $node;
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
     * The answer a refused setting is about to give, named for its author. One place, because both
     * typed reads say it and a second phrasing that disagreed with the first is exactly the drift this
     * wording exists to prevent.
     */
    private static function fallback(?string $default): string
    {
        return $default === null ? 'the built-in default' : ConfiguredValue::rendered($default);
    }
}
