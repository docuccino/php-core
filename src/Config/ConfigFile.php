<?php

declare(strict_types=1);

namespace Docuccino\Core\Config;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Support\Arr;
use Docuccino\Core\Support\PlainText;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * One read of the tool's own configuration file: the top-level map it parsed to, why it isn't one when
 * it isn't, and the diagnostics that say so. Every failure is a value here — nothing this class does
 * throws, because a build must survive a config file somebody is halfway through editing.
 *
 * The seam is deliberate. The CALLER supplies the directory, because finding a project root is a
 * question about the host, and this package knows nothing about hosts. Everything downstream of that
 * directory — which name the file has, how it parses, what a broken one degrades to — is the tool's own
 * and lives here. Diagnostics therefore name the file by its root-relative name and never by the path,
 * so two machines building the same commit report the same bytes.
 *
 * No configuration is a legitimate state and gets no diagnostic: a correct document with no config is
 * the product. A file that exists and cannot be read is the opposite, and every one of those states is
 * an {@see $error} with a diagnostic beside it.
 *
 * @internal
 */
final class ConfigFile
{
    /** The one name a project's configuration is read from. */
    public const string NAME = 'docuccino.yaml';

    /**
     * Spellings that are not {@see NAME} but are obviously trying to be, and what each is missing.
     *
     * A configuration file silently ignored because of a character is the worst failure this class has,
     * because the symptom is a document that does not match the file the author edited — so a near miss
     * beside no real file is reported rather than passed over. One name and no fallbacks is the point:
     * two accepted spellings would owe a precedence rule, and precedence between two config files is a
     * thing nobody should have to know.
     *
     * @var array<string, string>
     */
    public const array NEAR_MISSES = [
        'docuccino.yml' => 'the extension is spelled ".yaml" in full',
        '.docuccino.yaml' => 'the name is not a dotfile',
        '.docuccino.yml' => 'the name is not a dotfile, and the extension is spelled ".yaml" in full',
        'docuccino.yaml.dist' => 'the file is read as-is, not from a ".dist" template',
        'docuccino.json' => 'the configuration is YAML',
        'docuccino.neon' => 'the configuration is YAML',
    ];

    /**
     * The parse flags. `PARSE_EXCEPTION_ON_INVALID_TYPE` is the whole list and it is not optional:
     * WITHOUT it, `!php/const`, `!php/enum` and `!php/object` parse to NULL with no word said, which is
     * indistinguishable from a key the author wrote as null — a value silently lost inside a hash that
     * keys the fragment cache. With it they are a {@see ParseException} this class reports. Measured
     * against every spelling in the hostile-scalar corpus, the flag changes nothing else.
     *
     * Notably absent: `PARSE_DATETIME` would hand back `DateTimeImmutable` objects the canonical writer
     * has no form for, and `PARSE_CUSTOM_TAGS` and `PARSE_CONSTANT` would each turn a stricter parse
     * error into a value. Refusing what we cannot represent is the whole design.
     */
    public const int FLAGS = Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE;

    /** The largest magnitude at which a float still names exactly one integer. */
    private const float EXACT_INTEGER_LIMIT = 9007199254740992.0;

    /** No file of that name in the directory. Not an error: zero configuration is a supported state. */
    public const string ABSENT = 'absent';

    /** The file is there and could not be read. */
    public const string UNREADABLE = 'unreadable';

    /**
     * The file is there and is not configuration this can hold — malformed YAML, a duplicate key, a
     * tab, a PHP tag, or a file that parses and expands past {@see MAX_VALUES}.
     */
    public const string INVALID = 'invalid';

    /**
     * How many values a configuration file may expand to before it is refused.
     *
     * The expansion is the point, not the file size. A YAML alias repeated inside another anchor
     * multiplies at every level, so 691 bytes over seven levels of ten parse to 4 MB and then cost
     * over 512 MB in {@see settled()} — which REBUILDS the tree, materialising every copy the
     * parser was sharing. The fatal that follows is an out-of-memory, not an exception, so
     * `catch (ParseException)` never sees it and the contract this class is built on — that nothing
     * it does throws, because a build must survive a file somebody is halfway through editing —
     * fails exactly where it matters. Two more walkers downstream rebuild the same tree
     * (`Json::stable()` while hashing the config, and the unknown-setting report), which is why the
     * bound is here, at the one place all three are downstream of, rather than three times over.
     *
     * The number is measured against what a configuration file is: the shipped `docuccino.yaml`
     * writes about 130 values, and a hundred-document application would write a few tens of
     * thousands. This is far above both and far below the expansion that costs anything — a tree
     * this size settles in tens of megabytes, where the refused ones cost hundreds. Nothing anybody
     * wrote by hand reaches it.
     */
    private const int MAX_VALUES = 100_000;

    /** The file parses, and to something other than a map. An empty file is this. */
    public const string NOT_A_MAP = 'not-a-map';

    /**
     * @param  array<string, mixed>  $values
     * @param  list<Diagnostic>  $diagnostics
     */
    private function __construct(
        public readonly array $values,
        public readonly ?string $error,
        public readonly array $diagnostics = [],
    ) {}

    /** Memoised, because the reader accumulates its refusals and a second one would start empty. */
    private ?ConfigValues $reader = null;

    /**
     * Read the configuration out of `$directory`, which is the project root the caller resolved.
     *
     * The path it looked at is not part of the answer: a diagnostic names the file by its root-relative
     * name, and the adapter that watches the file builds the same path from `$directory` and
     * {@see NAME}.
     */
    public static function read(string $directory): self
    {
        $path = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.self::NAME;

        if (! is_file($path)) {
            return new self([], self::ABSENT, self::misnamed($directory));
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return new self([], self::UNREADABLE, [new Diagnostic(
                severity: Severity::Error,
                code: 'config.file-unreadable',
                message: sprintf(
                    '%s is there and could not be read, so the document is built from defaults alone.',
                    self::NAME,
                ),
                help: 'Check the file\'s permissions.',
            )]);
        }

        $parsed = self::parse($contents);

        return new self($parsed->values, $parsed->error, $parsed->diagnostics);
    }

    /**
     * Parse configuration text. Split out from {@see read()} so the parse and the shape can be stated
     * against a string, with no directory in the way.
     */
    public static function parse(string $contents): self
    {
        // A byte-order mark is not content, and a parser has no reason to treat it as any. Left in
        // place it lands INSIDE the first key's name, so `documents:` becomes a key nothing reads and
        // the whole file goes quietly unapplied.
        $contents = self::withoutByteOrderMark($contents);

        try {
            $value = Yaml::parse($contents, self::FLAGS);
        } catch (ParseException $exception) {
            return new self([], self::INVALID, [new Diagnostic(
                severity: Severity::Error,
                code: 'config.file-invalid',
                message: sprintf(
                    '%s is not valid YAML, so the document is built from defaults alone: %s',
                    self::NAME,
                    // The message quotes the line it choked on, so it carries file bytes — and a file
                    // is authored text that can steer a terminal or forge a line of a CI log.
                    PlainText::of($exception->getMessage()),
                ),
                help: 'Fix the line named above and run the build again.',
            )]);
        }

        // An empty file parses to null, and a document is a map or it says nothing. Reading either as
        // an empty array is the failure worth the most care here: the build would then run on every
        // default and produce a plausible document, so the author's file looks applied and is not.
        if (! is_array($value) || array_is_list($value)) {
            return new self([], self::NOT_A_MAP, [new Diagnostic(
                severity: Severity::Error,
                code: 'config.file-not-a-map',
                message: sprintf(
                    '%s holds %s where it must hold a map of settings, so the document is built from defaults alone.',
                    self::NAME,
                    self::described($value),
                ),
                help: 'Write the settings as top-level `key: value` pairs.',
            )]);
        }

        // Before anything WALKS it. Everything below this line rebuilds the tree, and a rebuild is
        // what an alias expansion costs — so the file is measured while it is still the parser's
        // shared structure, by a count that visits members without copying one and stops at the
        // bound rather than at the end.
        if (self::oversized($value)) {
            return new self([], self::INVALID, [new Diagnostic(
                severity: Severity::Error,
                code: 'config.file-invalid',
                message: sprintf(
                    '%s expands to more settings than a configuration can hold, so the document is built from defaults alone.',
                    self::NAME,
                ),
                help: 'This is what a YAML alias (`*name`) repeated inside an anchor does — it multiplies at every level. Write the settings out instead.',
            )]);
        }

        // Absent and present-null stay different all the way through, so nothing is stripped here.
        // A reader downstream distinguishes them — a key nobody named has expressed nothing and takes
        // its documented fallback, while a key present and unreadable has an author behind it and
        // degrades loudly. Dropping nulls here would collapse the two into one, silently.
        $diagnostics = [];
        $settled = self::settled($value, '', $diagnostics);

        return new self(Arr::stringKeyed($settled), null, $diagnostics);
    }

    /**
     * Whether the parse holds more than {@see MAX_VALUES} values.
     *
     * Deliberately not a rebuild and deliberately not recursive: members are pushed onto a stack as
     * the references the parser handed back, so an alias shared ten times costs ten pointers rather
     * than ten copies of its subtree, and the count stops the moment it passes the bound rather than
     * measuring how far past it the file goes. What is counted is the EXPANSION — every occurrence of
     * an aliased subtree, since every occurrence is what a walker downstream pays for.
     *
     * @param  array<mixed, mixed>  $value
     */
    private static function oversized(array $value): bool
    {
        $counted = 0;
        $pending = [$value];

        while ($pending !== []) {
            $node = array_pop($pending);

            foreach ($node as $member) {
                if (++$counted > self::MAX_VALUES) {
                    return true;
                }

                if (is_array($member)) {
                    $pending[] = $member;
                }
            }
        }

        return false;
    }

    /**
     * The parse with every numeric reading the PARSER was free to choose settled to one answer.
     *
     * Which is not a hypothetical. Across the range of `symfony/yaml` this package allows, one patch
     * release apart, `+1` reads as the int 1 or as the float 1.0, and `.nan` reads as NAN or as INF.
     * A parsed value is a fragment-cache key input and a published byte, so a reading that depends on
     * which patch a machine's lockfile resolved is a determinism break arriving from a dependency.
     *
     * Two settlements, and they are different in kind:
     *
     * An integer and its integral-float twin become the INT. This is not the reader inventing a
     * convention — `Json::stable()` fingerprints 1 and 1.0 to the same bytes, and the canonical
     * writer documents an integral float losing its decimal point as a property of the canonical
     * form. Both layers downstream already hold that these are one value;
     * the reader was the only one of the three that disagreed, which is how the same setting came to
     * be accepted on one patch release and refused on the next.
     *
     * A non-finite float becomes NULL, with a diagnostic. `.nan` and `.inf` are the one reading whose
     * VALUE — not just its type — differs across the range, so no type-shaped guard would see it, and
     * they are the reading that survives into a hash as a bare word rather than a number. They are
     * also not values a document can carry at all: the canonical writer refuses them outright. Null is
     * the honest answer rather than a hole, because present-and-null is already exactly what this
     * reader means by "an intent expressed and unreadable" — so the key stays written, every typed
     * read answers its documented default, and the value that reaches a hash is the same either way.
     *
     * Nothing else is touched. Non-integral floats, strings that look like numbers and absent keys all
     * come through as parsed, because none of them is a choice the parser made for us.
     *
     * @param  array<mixed, mixed>  $value
     * @param  list<Diagnostic>  $diagnostics
     * @return array<mixed, mixed>
     */
    private static function settled(array $value, string $path, array &$diagnostics): array
    {
        $out = [];

        foreach ($value as $key => $member) {
            $child = $path === '' ? (string) $key : $path.'.'.$key;

            if (is_array($member)) {
                $out[$key] = self::settled($member, $child, $diagnostics);

                continue;
            }

            if (! is_float($member)) {
                $out[$key] = $member;

                continue;
            }

            if (! is_finite($member)) {
                $diagnostics[] = new Diagnostic(
                    severity: Severity::Warning,
                    code: 'config.value-not-finite',
                    message: sprintf(
                        // Deliberately NOT distinguishing not-a-number from an infinity. `.nan` reads
                        // as one on some versions in the range and as the other on others, so a
                        // message that told them apart would put a dependency's patch level into the
                        // build's report — the same leak as the value, one layer out.
                        '%s is not a finite number, which is not a value a document can carry, so the setting is read as empty.',
                        $child,
                    ),
                    help: 'Write a finite number, or drop the key.',
                );

                $out[$key] = null;

                continue;
            }

            $out[$key] = self::asInteger($member);
        }

        return $out;
    }

    /**
     * A float that is some integer exactly, as that integer; anything else unchanged.
     *
     * Capped at 2^53 rather than at PHP_INT_MAX because past 2^53 a float cannot hold consecutive
     * integers, so there is no single integer it is the twin OF — and `(float) PHP_INT_MAX` rounds up
     * past PHP_INT_MAX, which makes the obvious range check admit a value the cast then mangles.
     */
    private static function asInteger(float $value): int|float
    {
        return $value === floor($value) && abs($value) <= self::EXACT_INTEGER_LIMIT
            ? (int) $value
            : $value;
    }

    public function ok(): bool
    {
        return $this->error === null;
    }

    /**
     * The parsed settings, as a reader that refuses a wrong type rather than converting it.
     *
     * The same reader every time: it collects the refusals it has made, and handing back a fresh one
     * per call would lose every refusal but the last caller's.
     */
    public function values(): ConfigValues
    {
        return $this->reader ??= ConfigValues::of($this->values);
    }

    /**
     * A near-miss name sitting beside no real file, as one diagnostic. Nothing when the author has
     * simply not written a configuration file, which is most projects.
     *
     * @return list<Diagnostic>
     */
    private static function misnamed(string $directory): array
    {
        $root = rtrim($directory, '/\\').DIRECTORY_SEPARATOR;
        $found = [];

        // Iterated over the constant rather than the directory, so the answer is the table's order and
        // not the filesystem's.
        foreach (self::NEAR_MISSES as $name => $reason) {
            if (is_file($root.$name)) {
                $found[] = sprintf('%s (%s)', $name, $reason);
            }
        }

        if ($found === []) {
            return [];
        }

        return [new Diagnostic(
            severity: Severity::Warning,
            code: 'config.file-misnamed',
            message: sprintf(
                'There is no %s, so the document is built from defaults alone — but %s %s, which is not a name the configuration is read from: %s.',
                self::NAME,
                count($found) === 1 ? 'there is a' : 'there are',
                count($found) === 1 ? 'file named' : 'files named',
                implode(', ', $found),
            ),
            help: sprintf('Rename it to %s.', self::NAME),
        )];
    }

    /** What the root turned out to be, in words an author can match against what they wrote. */
    private static function described(mixed $value): string
    {
        return match (true) {
            $value === null => 'nothing (the file is empty, or its only content is a comment)',
            // `{}` and `[]` parse to the same empty PHP array, so there is nothing left to tell an
            // empty map from an empty list. The wording has to be true of whichever was written.
            $value === [] => 'no settings at all',
            is_array($value) => 'a list',
            is_bool($value) => sprintf('the single value %s', $value ? 'true' : 'false'),
            is_string($value) => 'a single line of text',
            default => 'a single number',
        };
    }

    private static function withoutByteOrderMark(string $contents): string
    {
        return str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents;
    }
}
