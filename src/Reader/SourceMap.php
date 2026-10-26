<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

/**
 * Where things are written in the text a document was read from, as {@see SourcePosition}s: the places JSON
 * pointers name, and the places a refusal is about.
 *
 * Nothing is worked out until it is asked for, and then in one pass over the text for every pointer asked
 * about at once, holding only the places asked for — so a document nobody asks about costs nothing, and one
 * that is asked about costs a walk of its text rather than an index of every node in it. A pointer that
 * names nothing written in the text is placed nowhere: null.
 *
 * @internal
 */
final class SourceMap
{
    private const string BOM = "\u{FEFF}";

    /** As much UTF-8 as a text opens with, by RFC 3629's table of well-formed byte sequences. */
    private const string UTF8 = '/\A(?:[\x00-\x7F]++|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})*+/';

    /** How much text one match reads: PCRE runs out of backtracking long before a document's whole length. */
    private const int SLICE = 65_536;

    /** The text, with every line break a line feed and no byte order mark, so an offset is a line and a column. */
    private readonly string $text;

    public function __construct(string $source)
    {
        if (str_starts_with($source, self::BOM)) {
            $source = substr($source, strlen(self::BOM));
        }

        $this->text = str_contains($source, "\r") ? str_replace(["\r\n", "\r"], "\n", $source) : $source;
    }

    /**
     * Where each of $pointers is written, read as $syntax: null for one that names nothing written there.
     *
     * @param  list<string>  $pointers
     * @return array<string, ?SourcePosition>
     */
    public function positions(Syntax $syntax, array $pointers): array
    {
        $sought = new PointerTrie($pointers);

        if ($syntax === Syntax::Json) {
            JsonPositions::locate($this->text, $sought);
        } else {
            YamlPositions::locate($this->text, $sought);
        }

        $offsets = [];
        foreach ($pointers as $pointer) {
            $offsets[$pointer] = $sought->offset($pointer);
        }

        $positions = $this->at(array_values(array_filter($offsets, is_int(...))));

        return array_map(static fn (?int $offset): ?SourcePosition => $offset === null ? null : $positions[$offset], $offsets);
    }

    public function position(Syntax $syntax, string $pointer): ?SourcePosition
    {
        return $this->positions($syntax, [$pointer])[$pointer];
    }

    /** Where the text first stops being JSON, or null where it does not. */
    public function jsonError(): ?SourcePosition
    {
        return $this->one(JsonPositions::syntaxError($this->text));
    }

    /** Where JSON text first opens an object or a list more than $levels deep, or null where it never does. */
    public function jsonDeeperThan(int $levels): ?SourcePosition
    {
        return $this->one(JsonPositions::deeperThan($this->text, $levels));
    }

    /** Where the first byte that is not UTF-8 is, or null where every one is. */
    public function encodingError(): ?SourcePosition
    {
        $length = strlen($this->text);
        $at = 0;
        while ($at < $length) {
            $slice = substr($this->text, $at, self::SLICE);
            if (preg_match(self::UTF8, $slice, $match) !== 1) {
                return null;
            }

            $read = strlen($match[0]);
            // A character the slice cuts in two is read again, whole, from the start of the next.
            $cut = $at + strlen($slice) < $length && $read > 0 && $read >= strlen($slice) - 3;
            if ($read < strlen($slice) && ! $cut) {
                return $this->one($at + $read);
            }

            $at += $read;
        }

        return null;
    }

    private function one(?int $offset): ?SourcePosition
    {
        return $offset === null ? null : $this->at([$offset])[$offset];
    }

    /**
     * Each offset as a line and a column, counting the lines once across all of them.
     *
     * @param  list<int>  $offsets
     * @return array<int, SourcePosition>
     */
    private function at(array $offsets): array
    {
        $offsets = array_unique($offsets);
        sort($offsets);

        $positions = [];
        $line = 1;
        $counted = 0;
        foreach ($offsets as $offset) {
            $line += substr_count($this->text, "\n", $counted, $offset - $counted);
            $counted = $offset;

            $break = $offset === 0 ? false : strrpos($this->text, "\n", $offset - strlen($this->text) - 1);
            $start = $break === false ? 0 : $break + 1;

            $positions[$offset] = new SourcePosition($line, mb_strlen(substr($this->text, $start, $offset - $start), 'UTF-8') + 1);
        }

        return $positions;
    }
}
