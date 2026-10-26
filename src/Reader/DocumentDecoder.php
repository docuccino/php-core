<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

use DateTimeInterface;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Contract\Pointer;
use JsonException;
use stdClass;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Bytes to an object graph — every map a {@see stdClass}, every sequence a list — so `{}` and `[]` stay
 * two values. JSON is read by `json_decode`; YAML by symfony/yaml with no PHP objects, constants or
 * custom tags, so YAML is held to the values JSON can spell.
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

    private const int YAML_FLAGS = Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | Yaml::PARSE_DATETIME;

    private int $values = 0;

    private int $valueBudget = 0;

    private int $text = 0;

    private int $textBudget = 0;

    private int $length = 0;

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
                        return [$this->admitted(Yaml::parse($bytes, self::YAML_FLAGS), strlen($bytes)), Syntax::Yaml];
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
            $parsed = Yaml::parse($bytes, self::YAML_FLAGS);
        } catch (ParseException $exception) {
            $line = $exception->getParsedLine();

            throw new UnreadableDocumentException(
                ReadFailure::Syntax,
                'The document is not valid YAML: '.self::yamlProblem($exception),
                $line > 0 ? $line : null,
                $exception,
            );
        }

        return [$this->admitted($parsed, strlen($bytes)), Syntax::Yaml];
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
    private function admitted(mixed $parsed, int $length): stdClass
    {
        $this->values = 0;
        $this->text = 0;
        $this->length = $length;
        $this->valueBudget = max(self::MIN_VALUES, $length);
        $this->textBudget = max(self::MIN_TEXT, self::TEXT_PER_BYTE * $length);
        $this->path = [];

        $graph = $this->copy($parsed);

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
     * @throws UnreadableDocumentException
     */
    private function copy(mixed $value): mixed
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
                    $list[] = $this->copy($member);
                    array_pop($this->path);
                }

                return $list;
            }

            $object = new stdClass;
            foreach (is_array($value) ? $value : get_object_vars($value) as $name => $member) {
                $this->spell((string) $name);
                $this->path[] = $name;
                $object->{$name} = $this->copy($member);
                array_pop($this->path);
            }

            return $object;
        }

        if ($value instanceof DateTimeInterface) {
            return $this->spell(self::timestamp($value));
        }

        if (is_float($value) && ! is_finite($value)) {
            throw self::unrepresentable($this->pointer(), 'an infinite or NaN number');
        }

        if (is_string($value)) {
            // Counted before it is read, so an alias naming it again and again is read no further than allowed.
            $this->spell($value);

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
