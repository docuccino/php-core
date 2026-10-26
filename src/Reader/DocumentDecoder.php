<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

use DateTimeInterface;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Contract\Pointer;
use Error;
use JsonException;
use stdClass;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;
use Throwable;
use TypeError;

/**
 * Bytes to an object graph — every map a {@see stdClass}, every sequence a list — so `{}` and `[]` stay
 * two values. JSON is read by `json_decode`; YAML by symfony/yaml with no PHP objects, constants or
 * custom tags, so YAML is held to the values JSON can spell, and to what was written: what the parser
 * would misread is marked out of the text first ({@see PlainScalars}) or held to a second reading
 * ({@see YamlTwin}).
 *
 * Every value is copied into a fresh graph on the way out, under three bounds. One is depth, at the
 * canonical writer's own limit, so a document that reads is one that can be written back. The other two
 * are how much the copy may make: YAML aliases share one parsed value however often they are named, so a
 * billion-laughs document parses in microseconds and only explodes when something walks it. The copy is
 * that walk, and it stops — rather than expanding — once it has made more values than the document has
 * bytes, or more text than {@see TEXT_PER_BYTE} bytes for each of them (or {@see MIN_VALUES} and
 * {@see MIN_TEXT}, for a small one). Text is every string and member name, counted wherever an alias puts
 * it: counting values alone admits one long string named over and over, cheap to read and not to write.
 *
 * @internal
 */
final class DocumentDecoder
{
    /** The canonical writer's own bound ({@see CanonicalJsonSerializer}). */
    private const int MAX_DEPTH = 128;

    /** The least a document may expand to, so a small one sharing a block by alias is never refused. */
    private const int MIN_VALUES = 10_000;

    /**
     * The text the copy may spell for each byte of the document. Only aliases come near it: without them
     * JSON spells at most what it is written in, and the most YAML was found to spell is 1.5 (its `\L`
     * escape, three bytes from two); the most any real description measured spells is 1.9.
     */
    private const int TEXT_PER_BYTE = 8;

    /** The least text a document may expand to, so a small one sharing a description by alias is never refused. */
    private const int MIN_TEXT = 1_048_576;

    private const string BOM = "\u{FEFF}";

    /** Why a mapping key starting with a NUL byte is refused, in the words later symfony/yaml releases use. */
    private const string NUL_KEY = 'Mapping keys starting with a NUL byte are not supported.';

    private const int YAML_FLAGS = Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | Yaml::PARSE_DATETIME;

    /**
     * Text that could spell a YAML merge key: a `<<` a key could end at, a `<` written as an escape, or a `<`
     * before an escaped line break, which joins it to the next one. Releases before 7.4.21 and 8.1.9 read a
     * quoted key as a merge too, so every way of writing the two characters counts. A key ends at a colon, at
     * what ends a flow scalar, at a closing quote, or at white space or a NUL, which the parser trims from a
     * key; a `<<` before anything else is part of a longer scalar, as in a PDF's `<</Type` or `<<\r\n`, or a
     * shell's `<<EOF`.
     */
    private const string MERGE_KEY = '/<<(?=[\s\x00\'":,\]}]|$)|<\\\\[\r\n]|\\\\(?:x3c|u003c|u0000003c)/i';

    /** The members symfony/yaml may copy for one document's merge keys ({@see aliasCap()}): 56 MB at the dearest spelling measured. */
    private const int MERGE_COPIES = 524_288;

    /** What stands between two members of one map or list: a comma or a bracket in flow style, a line break in block style. */
    private const string SEPARATORS = ",[]{}\n\r";

    private int $values = 0;

    private int $valueBudget = 0;

    private int $text = 0;

    private int $textBudget = 0;

    private int $length = 0;

    /** What {@see PlainScalars} marked the text with, taken back out of every string and name the copy makes. */
    private ?string $mark = null;

    /** The second reading the copy holds the first to, where the text could spell a merge key or a float tag. */
    private ?YamlTwin $twin = null;

    /**
     * Where the copy is, as segments, spelt as a pointer only when a refusal names it: a pointer for every
     * value repeats each name above it, so one long name over many values would cost their product.
     *
     * @var list<string|int>
     */
    private array $path = [];

    /**
     * @return array{stdClass, Syntax}
     *
     * @throws UnreadableDocumentException
     */
    public function decode(string $bytes, ?string $mediaType): array
    {
        $this->mark = null;
        $this->twin = null;

        if (str_starts_with($bytes, self::BOM)) {
            $bytes = substr($bytes, strlen(self::BOM));
        }

        if (preg_match('//u', $bytes) !== 1) {
            throw new UnreadableDocumentException(ReadFailure::Encoding, 'The document is not UTF-8 text.');
        }

        if (trim($bytes) === '') {
            throw new UnreadableDocumentException(ReadFailure::Syntax, 'The document is empty.');
        }

        $declared = self::syntaxOf($mediaType);
        $syntax = $declared ?? self::sniff($bytes);

        if ($syntax === Syntax::Json) {
            try {
                $parsed = json_decode($bytes, false, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                // Text that only LOOKED like JSON may be YAML's flow style (`{openapi: 3.1.0}`); a media type
                // that said JSON is taken at its word.
                if ($declared === null) {
                    try {
                        return [$this->yaml($bytes), Syntax::Yaml];
                    } catch (ParseException) {
                        // Report it as the JSON it looked like.
                    }
                }

                if ($exception->getCode() === JSON_ERROR_DEPTH) {
                    throw self::tooDeep(null, $exception);
                }

                throw new UnreadableDocumentException(
                    ReadFailure::Syntax,
                    sprintf('The document is not valid JSON: %s.', rtrim($exception->getMessage(), '.')),
                    previous: $exception,
                );
            }

            return [$this->admitted($parsed, strlen($bytes)), Syntax::Json];
        }

        try {
            return [$this->yaml($bytes), Syntax::Yaml];
        } catch (ParseException $exception) {
            $line = $exception->getParsedLine();
            $aliases = self::aliasCap($bytes);

            // symfony's own words for its cap ask the reader to raise it, which only the parser's caller can do.
            if ($aliases !== PHP_INT_MAX && str_starts_with($exception->getMessage(), 'Maximum number of collection aliases')) {
                throw new UnreadableDocumentException(ReadFailure::AliasExpansion, sprintf(
                    'The document names more than %d YAML aliases to a map or a list, the most a document of its size may name where its text could spell a merge key (<<): each merge copies the whole map it names while the document is parsed. Remove its merge keys, or upload it as JSON.',
                    $aliases,
                ), $line > 0 ? $line : null, $exception);
            }

            throw new UnreadableDocumentException(
                ReadFailure::Syntax,
                'The document is not valid YAML: '.self::yamlProblem($exception),
                $line > 0 ? $line : null,
                $exception,
            );
        }
    }

    /**
     * $bytes read as YAML and copied: symfony/yaml's reading of the text with what it would misread marked
     * out ({@see PlainScalars}), held to a second reading where it could misread a merge key or a float tag
     * ({@see YamlTwin}).
     *
     * @throws ParseException where symfony/yaml cannot read the text
     * @throws UnreadableDocumentException where what it reads cannot be the document
     */
    private function yaml(string $bytes): stdClass
    {
        [$text, $this->mark] = PlainScalars::marked($bytes);
        $aliases = self::aliasCap($bytes);

        try {
            $parsed = self::parse($text, $aliases);
            $this->twin = YamlTwin::of($text);
            $twin = $this->twin === null ? null : self::parse($this->twin->text, $aliases);
        } catch (ParseException $exception) {
            // Neither the marks nor the twin's names are anything the document wrote, so none of them may reach
            // what is said about it.
            $problem = PlainScalars::unmarked(self::yamlProblem($exception), $this->mark);

            throw new ParseException($this->twin === null ? $problem : $this->twin->unmarked($problem), $exception->getParsedLine(), previous: $exception);
        }

        return $this->admitted($parsed, strlen($bytes), $twin);
    }

    /**
     * symfony/yaml's reading of $text, resolving at most $aliases aliases to a map or a list
     * ({@see aliasCap()}). Releases before 7.4.21 and 8.1.9 throw something other than a
     * {@see ParseException} for two kinds of text, and each is thrown here as one, so it is refused like any
     * other text the parser cannot read: a TypeError for a merge key inside a flow mapping (`{<<: *base}`),
     * and an Error for a mapping key starting with a NUL byte. Anything else goes on as it was thrown. 7.4
     * also reads past a key written twice whose first value is null, saying so only in a deprecation, where
     * 8.1 refuses it: it is refused here as 8.1 refuses it.
     *
     * @throws ParseException
     */
    private static function parse(string $text, int $aliases): mixed
    {
        // The only deprecation symfony/yaml raises while it parses is this one, so none is lost by keeping it.
        $duplicate = null;
        set_error_handler(static function (int $level, string $message) use (&$duplicate): bool {
            if (preg_match('/Duplicate key "(.*)" detected on line (\d+) whilst parsing YAML/s', $message, $match) === 1) {
                $duplicate ??= [$match[1], (int) $match[2]];
            }

            return true;
        }, E_USER_DEPRECATED);

        try {
            $parsed = Yaml::parse($text, self::YAML_FLAGS, maxAliasesForCollections: $aliases);
        } catch (Throwable $exception) {
            throw match (true) {
                $exception instanceof TypeError && str_starts_with($exception->getMessage(), 'Unsupported operand types: array + ') => new ParseException('A merge key (<<) inside a flow mapping is not supported; write that mapping in block style.', previous: $exception),
                $exception instanceof Error && str_starts_with($exception->getMessage(), 'Cannot access property starting with "\0"') => new ParseException(self::NUL_KEY, previous: $exception),
                default => $exception,
            };
        } finally {
            restore_error_handler();
        }

        if ($duplicate !== null) {
            throw new ParseException(sprintf('Duplicate key "%s" detected.', $duplicate[0]), $duplicate[1]);
        }

        return $parsed;
    }

    /**
     * How many aliases to a map or a list symfony/yaml may resolve in $bytes: all of them, unless the text
     * could spell a merge key ({@see MERGE_KEY}), and there as many as keep what merges copy within
     * {@see MERGE_COPIES}, up to the parser's own 128.
     *
     * An alias costs the parser no more than its spelling: it hands back the value already built, and the copy
     * expands it under budgets of its own. A merge copies every member of the map it names while the parser
     * reads, before any budget sees it, so merges cost their number times their members. The cap bounds the
     * first and the separators the second, since no map or list holds more members than its text spells
     * separators: `[,,,,]`, merged as a map of its positions, is the dearest spelling, and 128 merges of it
     * cost 9 GB at 1 MB. Where no merge could be spelt, a cap only refuses real descriptions, which alias a
     * shared response at every operation that returns it.
     */
    private static function aliasCap(string $bytes): int
    {
        if (preg_match(self::MERGE_KEY, $bytes) === 0) {
            return PHP_INT_MAX;
        }

        $counts = count_chars($bytes, 1);
        $separators = 0;
        foreach (str_split(self::SEPARATORS) as $separator) {
            $separators += $counts[ord($separator)] ?? 0;
        }

        return min(Parser::DEFAULT_MAX_ALIASES_FOR_COLLECTIONS, intdiv(self::MERGE_COPIES, $separators + 1));
    }

    /**
     * The syntax a media type names, or null where it names neither (or nothing), which leaves the bytes
     * to say. Parameters (`; charset=utf-8`) are ignored.
     */
    private static function syntaxOf(?string $mediaType): ?Syntax
    {
        if ($mediaType === null) {
            return null;
        }

        $type = strtolower(trim(explode(';', $mediaType, 2)[0]));

        return match (true) {
            $type === 'application/json', str_ends_with($type, '+json') => Syntax::Json,
            in_array($type, ['application/yaml', 'application/x-yaml', 'text/yaml', 'text/x-yaml', 'application/vnd.oai.openapi'], true),
            str_ends_with($type, '+yaml') => Syntax::Yaml,
            default => null,
        };
    }

    /** JSON when the first thing in the text opens an object or an array; YAML otherwise. */
    private static function sniff(string $bytes): Syntax
    {
        $first = ltrim($bytes)[0] ?? '';

        return $first === '{' || $first === '[' ? Syntax::Json : Syntax::Yaml;
    }

    /**
     * @throws UnreadableDocumentException
     */
    private function admitted(mixed $parsed, int $length, mixed $twin = null): stdClass
    {
        $this->values = 0;
        $this->text = 0;
        $this->length = $length;
        $this->valueBudget = max(self::MIN_VALUES, $length);
        $this->textBudget = max(self::MIN_TEXT, self::TEXT_PER_BYTE * $length);
        $this->path = [];

        $graph = $this->copy($parsed, $twin);

        if (! $graph instanceof stdClass) {
            throw new UnreadableDocumentException(ReadFailure::NotAnObject, sprintf(
                'The document is %s, not an object: an OpenAPI document is an object at its root.',
                match (true) {
                    is_array($graph) => 'a list',
                    is_string($graph) => 'a single string',
                    $graph === null => 'null',
                    default => 'a single '.get_debug_type($graph),
                },
            ));
        }

        return $graph;
    }

    /**
     * @param  mixed  $twin  the twin's reading of the same place ({@see YamlTwin}), or null where there is none
     *
     * @throws UnreadableDocumentException
     */
    private function copy(mixed $value, mixed $twin = null): mixed
    {
        if (++$this->values > $this->valueBudget) {
            throw new UnreadableDocumentException(ReadFailure::AliasExpansion, sprintf(
                'The document expands to more than %d values from %d bytes: its YAML aliases repeat a block far more often than any document needs, so it was refused rather than expanded.',
                $this->valueBudget,
                $this->length,
            ));
        }

        if ($value instanceof stdClass || is_array($value)) {
            if (count($this->path) >= self::MAX_DEPTH) {
                throw self::tooDeep($this->pointer());
            }

            // A YAML sequence is a list. Anything else symfony hands back as an array is a map it built
            // itself, so it is read as one rather than renumbered.
            if (is_array($value) && array_is_list($value)) {
                $list = [];
                foreach ($value as $index => $member) {
                    $this->path[] = $index;
                    $list[] = $this->copy($member, is_array($twin) ? ($twin[$index] ?? null) : null);
                    array_pop($this->path);
                }

                return $list;
            }

            $members = is_array($value) ? $value : get_object_vars($value);
            $view = $this->twin !== null && $twin instanceof stdClass ? $this->merged($this->twin, $members, $twin) : [];

            $object = new stdClass;
            foreach ($members as $name => $member) {
                // A name no object can hold. Only YAML hands one over, and only from a flow mapping: from a
                // block one, the releases that do not refuse it fail while parsing ({@see parse()}).
                if (str_starts_with((string) $name, "\0")) {
                    throw new UnreadableDocumentException(ReadFailure::Syntax, 'The document is not valid YAML: '.self::NUL_KEY);
                }

                // Two names the parser told apart only by a mark are one name written twice.
                $key = PlainScalars::unmarked((string) $name, $this->mark);
                if ($this->mark !== null && property_exists($object, $key)) {
                    throw new UnreadableDocumentException(ReadFailure::Syntax, sprintf('The document is not valid YAML: Duplicate key "%s" detected.', $key));
                }

                $this->spell($key);
                $this->path[] = $key;
                $object->{$key} = $this->copy($member, $view[(string) $name] ?? null);
                array_pop($this->path);
            }

            return $object;
        }

        if ($value instanceof TaggedValue && in_array($value->getTag(), ['!str', '!float'], true) && is_string($value->getValue())) {
            // Releases before 7.4.21 and 8.1.9 hand a block scalar back still tagged, where later ones read it.
            $value = $value->getTag() === '!str' ? $value->getValue() : YamlTwin::float(PlainScalars::unmarked($value->getValue(), $this->mark), $this->path);
        } elseif (is_float($value) && is_string($twin)) {
            // Only a float tag reads as a float in one reading and a string in the other, and symfony/yaml casts
            // what it tags (`abc` to 0, `.inf` to 0) where YAML reads it.
            $value = YamlTwin::float(PlainScalars::unmarked($twin, $this->mark), $this->path);
        }

        if ($value instanceof DateTimeInterface) {
            return $this->spell(self::timestamp($value));
        }

        if (is_float($value) && ! is_finite($value)) {
            throw self::unrepresentable($this->pointer(), 'an infinite or NaN number');
        }

        if (is_string($value)) {
            // Counted before it is read, so an alias naming it again and again is read no further than allowed.
            $value = $this->spell(PlainScalars::unmarked($value, $this->mark));

            if (preg_match('//u', $value) !== 1) {
                throw self::unrepresentable($this->pointer(), 'a string that is not UTF-8 text');
            }
        }

        if (is_object($value)) {
            throw self::unrepresentable($this->pointer(), 'a '.get_debug_type($value));
        }

        return $value;
    }

    /**
     * What YAML merges into the map $twins read as $twin ({@see YamlTwin::view()}), where the parser read it
     * with $members: refused where the two part.
     *
     * @param  array<int|string, mixed>  $members
     * @return array<string, mixed>
     *
     * @throws UnreadableDocumentException
     */
    private function merged(YamlTwin $twins, array $members, stdClass $twin): array
    {
        $view = $twins->view($twin, $this->path);

        $misread = $twins->misread(array_map('strval', array_keys($members)), $view, $twin, $this->path);
        if ($misread !== null) {
            throw $misread;
        }

        return $view;
    }

    /**
     * Counts $text against what the copy may spell, and hands it back.
     *
     * @throws UnreadableDocumentException
     */
    private function spell(string $text): string
    {
        $this->text += strlen($text);

        if ($this->text > $this->textBudget) {
            throw new UnreadableDocumentException(ReadFailure::AliasExpansion, sprintf(
                'The document expands to more than %d bytes of text from %d bytes: its YAML aliases repeat text far more often than any document needs, so it was refused rather than expanded.',
                $this->textBudget,
                $this->length,
            ));
        }

        return $text;
    }

    /** Where the copy is, as a pointer: `/` for the root. */
    private function pointer(): string
    {
        return $this->path === [] ? '/' : Pointer::of($this->path);
    }

    /**
     * A YAML timestamp back as the text it was written as, near enough to mean the same thing: symfony
     * reads an unquoted `2024-01-01` as a date where JSON (and YAML 1.2) read a string, and without
     * `PARSE_DATETIME` it would be an integer count of seconds — a different value altogether.
     *
     * A zone the text did not state reads back as UTC; that and only that is a date with no time when its
     * time is midnight, so `2024-01-01T00:00:00Z` stays a date-time.
     */
    private static function timestamp(DateTimeInterface $value): string
    {
        $zone = $value->getTimezone()->getName();
        $fraction = rtrim($value->format('u'), '0');
        $time = $value->format('H:i:s').($fraction === '' ? '' : '.'.$fraction);

        if ($zone === 'UTC' && $time === '00:00:00') {
            return $value->format('Y-m-d');
        }

        return $value->format('Y-m-d').'T'.$time.match ($zone) {
            'UTC' => '',
            'Z' => 'Z',
            default => $value->format('P'),
        };
    }

    /**
     * What symfony says is wrong, as a sentence, without the line it appends — the exception carries that
     * as a number — or the excerpt of the document it quotes beside it.
     */
    private static function yamlProblem(ParseException $exception): string
    {
        $message = preg_replace('/ at line \d+(?: \(near ".*"\))?\.?$/s', '', $exception->getMessage()) ?? $exception->getMessage();

        return preg_match('/[.?!]$/', $message) === 1 ? $message : $message.'.';
    }

    /** @param  ?string  $pointer  where the limit was met, when the reader that met it can say */
    private static function tooDeep(?string $pointer, ?JsonException $previous = null): UnreadableDocumentException
    {
        return new UnreadableDocumentException(ReadFailure::TooDeep, sprintf(
            'The document nests more than %d levels deep%s, past what can be written back out.',
            self::MAX_DEPTH,
            $pointer === null ? '' : ' at '.$pointer,
        ), previous: $previous);
    }

    private static function unrepresentable(string $pointer, string $what): UnreadableDocumentException
    {
        return new UnreadableDocumentException(ReadFailure::Unrepresentable, sprintf(
            'The document holds %s at %s, which JSON cannot represent.',
            $what,
            $pointer,
        ));
    }
}
