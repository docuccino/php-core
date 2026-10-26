<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

use DateTimeInterface;
use Docuccino\Core\Contract\Pointer;
use stdClass;

/**
 * A second text of a YAML document in which symfony/yaml can misread neither a merge key nor a float tag:
 * every `<<` a key could end at stands under a name of its own, and every `!!float` is a `!!str`. Read beside
 * the first, it holds each merge to what YAML merges ({@see view()}) and each float tag to the text it tags
 * ({@see float()}), where the parser alone reads a list named by an alias as the members to copy and casts
 * `!!float abc` to 0.
 *
 * @internal
 */
final class YamlTwin
{
    /** A `<<` a key could end at, as {@see DocumentDecoder}'s scan reads one. */
    private const string MERGE_KEY = '<<(?=[\s\x00\'":,\]}]|$)';

    /** An anchor's or an alias's name, which must stay one name, or a `<<` outside one. */
    private const string NAME_OR_MERGE_KEY = '/(?<![^\s,\[\]{}])[&*][^\s,\[\]{}]*|'.self::MERGE_KEY.'/';

    /** A float as YAML 1.2 spells one, and its spellings of infinity and NaN. */
    private const string FLOAT = '/^[-+]?(?:\.[0-9]+|[0-9]+(?:\.[0-9]*)?)(?:[eE][-+]?[0-9]+)?$/';

    /**
     * What each merge view has been worked out as, by the twin map's object id.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $views = [];

    /**
     * @param  string  $text  the twin's text
     * @param  string  $mark  what the name a `<<` stands under starts and ends with
     * @param  array<int, array{bool, int}>  $merges  whether each `<<` was quoted, and its line
     */
    private function __construct(
        public readonly string $text,
        private readonly string $mark,
        private readonly array $merges,
    ) {}

    /** The twin of $text, or null where it spells neither a merge key nor a float tag, and so needs none. */
    public static function of(string $text): ?self
    {
        $floats = str_contains($text, '!!float');
        if (! $floats && preg_match('/'.self::MERGE_KEY.'/', $text) !== 1) {
            return null;
        }

        $mark = PlainScalars::unusedCharacter($text);
        $merges = [];
        $line = 1;
        $counted = 0;
        $twin = (string) preg_replace_callback(self::NAME_OR_MERGE_KEY, static function (array $match) use ($text, $mark, &$merges, &$line, &$counted): string {
            [$spelt, $offset] = $match[0];
            if ($spelt !== '<<') {
                return $spelt;
            }

            // Lines counted from the last `<<` on, as the parser counts them.
            $line += (int) preg_match_all('/\r\n|\r|\n/', substr($text, $counted, $offset - $counted));
            $counted = $offset;
            // A quote on either side: a key quoted, or one whose quote opens with an escaped line break.
            $merges[] = [$offset > 0 && in_array($text[$offset - 1], ['"', "'"], true) || in_array($text[$offset + 2] ?? '', ['"', "'"], true), $line];

            return $mark.(count($merges) - 1).$mark;
        }, $text, flags: PREG_OFFSET_CAPTURE);

        return new self($floats ? str_replace('!!float', '!!str', $twin) : $twin, $mark, $merges);
    }

    /**
     * The members YAML's merge gives the map the twin read as $map, each beside the twin's reading of its
     * value: the map's own members, then each merged map's in the order they are merged, the first to name a
     * member keeping it. A quoted `<<` is a key and not a merge, as YAML reads it.
     *
     * @param  list<string|int>  $path  where the map is
     * @return array<string, mixed>
     *
     * @throws UnreadableDocumentException where a merge names anything but a map or a list of maps
     */
    public function view(stdClass $map, array $path): array
    {
        $id = spl_object_id($map);
        if (isset($this->views[$id])) {
            return $this->views[$id];
        }

        $members = [];
        $merged = [];
        foreach (get_object_vars($map) as $name => $value) {
            $merge = $this->merge((string) $name);
            if ($merge === null) {
                $members[(string) $name] = $value;
            } elseif ($this->merges[$merge][0]) {
                $members['<<'] = $value;
            } else {
                $merged[] = [$merge, $value];
            }
        }

        foreach ($merged as [$merge, $value]) {
            foreach (is_array($value) ? $value : [$value] as $source) {
                if (! $source instanceof stdClass) {
                    throw new UnreadableDocumentException(ReadFailure::Syntax, sprintf(
                        'The document is not valid YAML: a merge key (<<) takes a map or a list of maps, and the one at %s names %s.',
                        self::pointer($path),
                        is_array($value) ? 'a list holding '.self::what($source) : self::what($source),
                    ), $this->merges[$merge][1]);
                }

                $members += $this->view($source, $path);
            }
        }

        return $this->views[$id] = $members;
    }

    /**
     * Where the parser's reading of a map parts from what YAML merges into it: null where it does not.
     *
     * @param  list<string>  $read  the names the parser gave the map
     * @param  array<string, mixed>  $view  {@see view()}
     * @param  list<string|int>  $path
     */
    public function misread(array $read, array $view, stdClass $map, array $path): ?UnreadableDocumentException
    {
        $expected = array_map('strval', array_keys($view));
        if (count($read) === count($expected) && array_diff($read, $expected) === []) {
            return null;
        }

        $line = null;
        $quoted = false;
        foreach (array_keys(get_object_vars($map)) as $name) {
            $merge = $this->merge((string) $name);
            if ($merge !== null) {
                $line ??= $this->merges[$merge][1];
                $quoted = $quoted || $this->merges[$merge][0];
            }
        }

        if ($quoted && ! in_array('<<', $read, true)) {
            return new UnreadableDocumentException(ReadFailure::Syntax, sprintf(
                'The document has a quoted "<<" key at %s, which YAML reads as a key and symfony/yaml before 7.4.21 and 8.1.9 reads as a merge key. Upgrade symfony/yaml, or name the key otherwise.',
                self::pointer($path),
            ), $line);
        }

        return new UnreadableDocumentException(ReadFailure::Syntax, sprintf(
            'The document merges into the map at %s in a way symfony/yaml reads otherwise than YAML does. Write each map to merge in place, as <<: *map or <<: [*first, *second].',
            self::pointer($path),
        ), $line);
    }

    /**
     * $text as YAML 1.2 reads a float: a number, or its spellings of infinity and NaN.
     *
     * @param  list<string|int>  $path  where the tag is
     *
     * @throws UnreadableDocumentException where it reads none
     */
    public static function float(string $text, array $path): float
    {
        $number = trim($text);

        return match (true) {
            preg_match(self::FLOAT, $number) === 1 => (float) $number,
            in_array($number, ['.inf', '.Inf', '.INF', '+.inf', '+.Inf', '+.INF'], true) => INF,
            in_array($number, ['-.inf', '-.Inf', '-.INF'], true) => -INF,
            in_array($number, ['.nan', '.NaN', '.NAN'], true) => NAN,
            default => throw new UnreadableDocumentException(ReadFailure::Syntax, sprintf(
                'The document is not valid YAML: the !!float tag at %s is on "%s", which is not a number.',
                self::pointer($path),
                $number,
            )),
        };
    }

    /** $text with the name each `<<` stood under written as `<<` again, for what is said about the document. */
    public function unmarked(string $text): string
    {
        $mark = preg_quote($this->mark, '/');

        return preg_replace('/'.$mark.'\d+'.$mark.'/u', '<<', $text) ?? $text;
    }

    /** Which merge key $name stands for, or null where it is a name the document wrote. */
    private function merge(string $name): ?int
    {
        $mark = $this->mark;
        if (! str_starts_with($name, $mark) || ! str_ends_with($name, $mark) || strlen($name) <= 2 * strlen($mark)) {
            return null;
        }

        $index = substr($name, strlen($mark), -strlen($mark));

        return ctype_digit($index) && isset($this->merges[(int) $index]) ? (int) $index : null;
    }

    /** @param list<string|int> $path */
    private static function pointer(array $path): string
    {
        return $path === [] ? '/' : Pointer::of($path);
    }

    private static function what(mixed $value): string
    {
        return match (true) {
            is_array($value) => 'a list',
            is_string($value) => 'a string',
            $value === null => 'nothing',
            is_bool($value) => 'a boolean',
            $value instanceof DateTimeInterface => 'a date',
            is_object($value) => 'a tagged value',
            default => 'a number',
        };
    }
}
