<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Where the pointers a {@see PointerTrie} seeks are written in YAML text, as byte offsets: one walk over its
 * structure, block and flow, that records nothing but the places sought. It reads text symfony/yaml has
 * already read, so it reads indentation, indicators and names, and steps over every value.
 *
 * A member is placed where its entry starts (its name, or the anchor or tag before it), and an item at the
 * `-` of a block sequence or at its first character in a flow one. A pointer that passes through an alias is
 * placed where the alias is written, since that is where everything beneath it is written into the
 * document; a member a merge key (`<<`) brings in is placed at the alias that brings it, or at the map
 * written in its place.
 *
 * Text is read with line feeds alone ({@see SourceMap} turns every break into one). The block walk returns,
 * from every node, the offset of the first line that is not part of it. Where the text has no merge key, no
 * anchor's names are ever needed, so a value off every sought path is stepped over by its indentation alone
 * ({@see skipValue()}).
 *
 * @phpstan-type Merged array{int, list<string>}
 *
 * @internal
 */
final class YamlPositions
{
    /**
     * The name a map is read under when what is wanted is its member names alone, as a merge key's map is:
     * an anchor's name ends at white space, so no anchor the document writes can be this one.
     */
    private const string MERGED = ' merged';

    /** A plain implicit key from its first character: everything up to the first colon followed by a space or a break. */
    private const string PLAIN_KEY = '/\G((?:[^:#\n]|:(?![ \t\n]|\z)|(?<=[^ \t])#)*?)[ \t]*+:(?=[ \t\n]|\z)/';

    /** A name symfony/yaml reads as an integer, so it holds the integer's digits rather than what was written. */
    private const string INTEGER = '/^[-+]?(?:0x[0-9a-fA-F_]+|0o[0-7_]+|[0-9][0-9_]*)$/';

    /** What ends an anchor's or an alias's name. */
    private const string NAME_END = " \t\n,[]{}";

    private readonly int $length;

    /** Whether no merge key can be written in the text, so nothing off a sought path needs reading. */
    private readonly bool $skips;

    /**
     * The member names of each map an anchor names, merged ones included.
     *
     * @var array<string, list<string>>
     */
    private array $anchors = [];

    /** @var list<string>|null the member names of the last map read under an anchor */
    private ?array $names = null;

    private function __construct(private readonly string $text, private readonly PointerTrie $sought)
    {
        $this->length = strlen($text);
        $this->skips = ! str_contains($text, '<<');
    }

    /** Records in $sought where each of its pointers is written in $text, which must be YAML symfony/yaml reads. */
    public static function locate(string $text, PointerTrie $sought): void
    {
        (new self($text, $sought))->document();
    }

    private function document(): void
    {
        $line = $this->contentLine(0);
        while (($this->text[$line] ?? '') === '%') {
            $line = $this->contentLine($this->nextLine($line));
        }

        if ($this->isMarker($line) && $this->text[$line] === '-') {
            $at = $this->skipBlank($line + 3);
            if (! $this->endsLine($at)) {
                $this->sought->record(0, $at);
                $this->value($at, -1, 0);

                return;
            }

            $line = $this->contentLine($this->nextLine($at));
        }

        if ($line < $this->length && ! $this->isMarker($line)) {
            $at = $line + $this->indent($line);
            $this->sought->record(0, $at);
            $this->block($at, -1, 0, null);
        }
    }

    /**
     * A node at $at that may open a block collection — the first thing on its line, or a sequence entry's
     * content — inside a collection indented $parent.
     */
    private function block(int $at, int $parent, ?int $node, ?string $anchor): int
    {
        [$content, $own] = $this->properties($at);
        $anchor = $own ?? $anchor;

        if ($this->endsLine($content)) {
            return $this->below($this->nextLine($content), $parent, $node, $anchor, false);
        }

        if ($this->isEntry($content)) {
            return $this->sequence($content, $this->column($content), $node, $anchor);
        }

        if ($this->key($content) !== null) {
            // Properties before the first key are the map's, so its keys line up under them.
            return $this->mapping($content, $this->column($at), $node, $anchor);
        }

        return $this->single($content, $parent, $node, $anchor);
    }

    /**
     * A node written on the lines from $line on: indented past $parent or, for a member's value where
     * $entries, a sequence indented as far as its key. Nothing, where neither follows.
     */
    private function below(int $line, int $parent, ?int $node, ?string $anchor, bool $entries): int
    {
        $line = $this->contentLine($line);
        if ($line < $this->length && ! $this->isMarker($line)) {
            $indent = $this->indent($line);
            if ($indent > $parent) {
                return $this->block($line + $indent, $parent, $node, $anchor);
            }

            if ($entries && $indent === $parent && $this->isEntry($line + $indent)) {
                return $this->sequence($line + $indent, $indent, $node, $anchor);
            }
        }

        $this->notAMap($anchor);

        return $line;
    }

    /** A member's value, from just past its colon, in a map indented $indent. */
    private function value(int $at, int $indent, ?int $node): int
    {
        [$content, $anchor] = $this->properties($this->skipBlank($at));

        if ($this->endsLine($content)) {
            return $this->below($this->nextLine($content), $indent, $node, $anchor, true);
        }

        return $this->single($content, $indent, $node, $anchor);
    }

    /** A node that opens no block collection: an alias, a flow collection, or a scalar of any style. */
    private function single(int $at, int $parent, ?int $node, ?string $anchor): int
    {
        $char = $this->text[$at];

        if ($char === '[' || $char === '{') {
            return $this->nextLine($this->flow($at, $node, $anchor));
        }

        $this->notAMap($anchor);

        if ($char === '*') {
            $this->alias($at, $node);

            return $this->nextLine($at);
        }

        if ($char === '"' || $char === "'") {
            return $this->nextLine($this->quotedEnd($at));
        }

        // A plain scalar's continuation lines and a block scalar's content are both indented past $parent.
        return $this->outdented($this->nextLine($at), $parent);
    }

    private function mapping(int $at, int $indent, ?int $node, ?string $anchor): int
    {
        $names = [];
        $merged = [];

        while (true) {
            $entry = $at;
            [$at] = $this->properties($at);
            $key = $this->key($at);
            if ($key === null) {
                $line = $this->nextLine($entry);

                break;
            }

            [$spelt, $value] = $key;
            if ($spelt === '<<') {
                [$end, $sources] = $this->merge($value, $indent);
                array_push($merged, ...$sources);
            } else {
                $child = null;
                // A name is only read where something needs it: a sought member, or an anchor's list of names.
                if ($node !== null || $anchor !== null) {
                    $names[] = $name = $this->name($spelt, false);
                    $child = $node === null ? null : $this->sought->child($node, $name);
                    if ($child !== null) {
                        $this->sought->record($child, $entry);
                    }
                }

                $end = $this->skips && $child === null ? $this->skipValue($value, $indent, true) : $this->value($value, $indent, $child);
            }

            if ($this->sought->complete()) {
                return $this->length;
            }

            $line = $this->contentLine($end);
            if ($line >= $this->length || $this->isMarker($line) || $this->indent($line) !== $indent || $this->isEntry($line + $indent)) {
                break;
            }

            $at = $line + $indent;
        }

        if ($node !== null && $merged !== []) {
            $this->placeMerged($node, $names, $merged);
        }

        if ($anchor !== null) {
            $this->named($anchor, $names, $merged);
        }

        return $line;
    }

    private function sequence(int $at, int $indent, ?int $node, ?string $anchor): int
    {
        $this->notAMap($anchor);

        for ($index = 0; ; $index++) {
            $child = $node === null ? null : $this->sought->child($node, $index);
            if ($child !== null) {
                $this->sought->record($child, $at);
            }

            $content = $this->skipBlank($at + 1);
            $end = match (true) {
                $this->skips && $child === null => $this->skipValue($at + 1, $indent, false),
                $this->endsLine($content) => $this->below($this->nextLine($content), $indent, $child, null, false),
                default => $this->block($content, $indent, $child, null),
            };

            if ($this->sought->complete()) {
                return $this->length;
            }

            $line = $this->contentLine($end);
            if ($line >= $this->length || $this->isMarker($line) || $this->indent($line) !== $indent || ! $this->isEntry($line + $indent)) {
                return $line;
            }

            $at = $line + $indent;
        }
    }

    /**
     * The maps a merge key's value names, from just past its colon in a map indented $indent: an alias, a map
     * written in place, or a list of either, in block or flow style.
     *
     * @return array{int, list<Merged>}
     */
    private function merge(int $at, int $indent): array
    {
        $at = $this->skipBlank($at);

        if ($this->endsLine($at)) {
            $line = $this->contentLine($this->nextLine($at));
            $column = $this->indent($line);
            $entries = $line < $this->length && $this->isEntry($line + $column);
            if ($line >= $this->length || $this->isMarker($line) || $column < $indent || ($column === $indent && ! $entries)) {
                return [$line, []];
            }

            if (! $entries) {
                [$end, $source] = $this->mergedMap($line + $column, $indent, null);

                return [$end, [$source]];
            }

            $sources = [];
            while (true) {
                [$end, $sources[]] = $this->mergedMap($this->skipBlank($line + $column + 1), $column, null);
                $line = $this->contentLine($end);
                if ($line >= $this->length || $this->isMarker($line) || $this->indent($line) !== $column || ! $this->isEntry($line + $column)) {
                    return [$line, $sources];
                }
            }
        }

        if ($this->text[$at] !== '[') {
            [$end, $source] = $this->mergedMap($at, $indent, null);

            return [$end, [$source]];
        }

        [$end, $sources] = $this->flowMerge($at, ']');

        return [$this->nextLine($end), $sources];
    }

    /**
     * One map a merge key names, at $at: where it ends, and where it is written with the names it holds. Inside
     * a flow collection, $closer is the bracket that closes it; in block style, null.
     *
     * @return array{int, Merged}
     */
    private function mergedMap(int $at, int $parent, ?string $closer): array
    {
        if (($this->text[$at] ?? '') === '*') {
            $length = strcspn($this->text, self::NAME_END, $at + 1);
            $names = $this->anchors[substr($this->text, $at + 1, $length)] ?? [];

            return [$closer === null ? $this->nextLine($at) : $this->plainFlowEnd($at, $closer), [$at, $names]];
        }

        $this->names = null;
        $end = $closer === null ? $this->block($at, $parent, null, self::MERGED) : $this->flow($at, null, self::MERGED, $closer);
        unset($this->anchors[self::MERGED]);

        return [$end, [$at, $this->names ?? []]];
    }

    /**
     * Places each sought member of the map at $node that its own entries do not hold at the merged map that
     * brings it: the first, in the order they are merged, to hold it.
     *
     * @param  list<string>  $own
     * @param  list<Merged>  $merged
     */
    private function placeMerged(int $node, array $own, array $merged): void
    {
        foreach ($this->sought->children($node) as $segment => $child) {
            if (in_array((string) $segment, $own, true)) {
                continue;
            }

            foreach ($merged as [$at, $names]) {
                if (in_array((string) $segment, $names, true)) {
                    $this->sought->record($child, $at);
                    $this->sought->recordBelow($child, $at);

                    break;
                }
            }
        }
    }

    /**
     * @param  list<string>  $names
     * @param  list<Merged>  $merged
     */
    private function named(string $anchor, array $names, array $merged): void
    {
        foreach ($merged as [, $more]) {
            array_push($names, ...$more);
        }

        $this->anchors[$anchor] = $this->names = array_values(array_unique($names));
    }

    /** An anchor on anything but a map names nothing a merge key could take members from. */
    private function notAMap(?string $anchor): void
    {
        if ($anchor !== null) {
            unset($this->anchors[$anchor]);
        }
    }

    /** Everything sought beneath the alias at $at is written where the alias is. */
    private function alias(int $at, ?int $node): void
    {
        if ($node !== null) {
            $this->sought->recordBelow($node, $at);
        }
    }

    /**
     * A flow node at $at, its properties included: the offset just past it. A plain scalar ends at the first
     * comma or $closer, the bracket that closes the collection holding it.
     */
    private function flow(int $at, ?int $node, ?string $anchor = null, string $closer = ''): int
    {
        if ($node === null && $this->skips && ($this->text[$at] === '[' || $this->text[$at] === '{')) {
            return $this->skipFlow($at);
        }

        [$at, $own] = $this->flowProperties($at);
        $anchor = $own ?? $anchor;
        $char = $this->text[$at] ?? '';

        if ($char === '[') {
            return $this->flowSequence($at, $node, $anchor);
        }

        if ($char === '{') {
            return $this->flowMapping($at, $node, $anchor);
        }

        $this->notAMap($anchor);

        if ($char === '"' || $char === "'") {
            return $this->quotedEnd($at);
        }

        if ($char === '*') {
            $this->alias($at, $node);
        }

        return $this->plainFlowEnd($at, $closer);
    }

    /**
     * Read as symfony/yaml reads one: an item may follow another with no comma between them, and a comma
     * with no item before it is an item of its own, null — so an index here is the index it reads.
     */
    private function flowSequence(int $at, ?int $node, ?string $anchor): int
    {
        $this->notAMap($anchor);
        $index = 0;
        $separated = true;
        $at++;

        while (true) {
            $at = $this->flowSpace($at);
            $char = $this->text[$at] ?? '';
            if ($char === ']' || $char === '') {
                break;
            }

            if ($char === ',') {
                if ($separated) {
                    $index++;
                }

                $separated = true;
                $at++;

                continue;
            }

            $child = $node === null ? null : $this->sought->child($node, $index);
            if ($child !== null) {
                $this->sought->record($child, $at);
            }

            $at = $this->flowItem($at, $child);
            $separated = false;
            $index++;
        }

        return $char === ']' ? $at + 1 : $at;
    }

    /**
     * One item of a flow sequence: a node, or — where a plain one holds `: ` — a map of the one member
     * symfony/yaml reads it as.
     */
    private function flowItem(int $at, ?int $node): int
    {
        [$content] = $this->flowProperties($at);
        $char = $this->text[$content] ?? '';
        if ($char === '[' || $char === '{' || $char === '"' || $char === "'" || $char === '*' || $node === null) {
            return $this->flow($at, $node, null, ']');
        }

        $end = $this->plainFlowEnd($content, ']');
        $colon = strpos(substr($this->text, $content, $end - $content), ': ');
        if ($colon !== false) {
            $pair = $this->sought->child($node, $this->name(substr($this->text, $content, strcspn($this->text, ": \t\n", $content)), true));
            if ($pair !== null) {
                $this->sought->record($pair, $at);
            }
        }

        return $end;
    }

    /**
     * Read as symfony/yaml reads one: a plain name ends at its first colon or space, and its value starts
     * after the next colon.
     */
    private function flowMapping(int $at, ?int $node, ?string $anchor): int
    {
        $names = [];
        $merged = [];
        $at++;

        while (true) {
            $at = $this->flowSpace($at);
            $char = $this->text[$at] ?? '';
            if ($char === ',') {
                $at++;

                continue;
            }

            if ($char === '}' || $char === '') {
                break;
            }

            $entry = $at;
            $quoted = $char === '"' || $char === "'";
            $end = $quoted ? $this->quotedEnd($at) : $at + strcspn($this->text, ": \t\n", $at);
            $colon = strpos($this->text, ':', $end);
            if ($colon === false) {
                $at = $this->length;

                break;
            }

            $spelt = substr($this->text, $at, $end - $at);
            $value = $this->flowSpace($colon + 1 + strspn($this->text, ':', $colon + 1));
            $char = $this->text[$value] ?? '';
            $empty = $char === ',' || $char === '}' || $char === '';

            if (! $quoted && $spelt === '<<') {
                [$at, $sources] = $empty ? [$value, []] : $this->flowMerge($value, '}');
                array_push($merged, ...$sources);

                continue;
            }

            $child = null;
            if ($node !== null || $anchor !== null) {
                $names[] = $name = $this->name($spelt, true);
                $child = $node === null ? null : $this->sought->child($node, $name);
                if ($child !== null) {
                    $this->sought->record($child, $entry);
                }
            }

            $at = $empty ? $value : $this->flow($value, $child, null, '}');
        }

        if ($node !== null && $merged !== []) {
            $this->placeMerged($node, $names, $merged);
        }

        if ($anchor !== null) {
            $this->named($anchor, $names, $merged);
        }

        return ($this->text[$at] ?? '') === '}' ? $at + 1 : $at;
    }

    /**
     * The maps a merge key names inside a flow mapping, from its value at $at: where the value ends, and each map.
     *
     * @return array{int, list<Merged>}
     */
    private function flowMerge(int $at, string $closer): array
    {
        if ($this->text[$at] !== '[') {
            [$end, $source] = $this->mergedMap($at, -1, $closer);

            return [$end, [$source]];
        }

        $sources = [];
        $at++;
        while (true) {
            $at = $this->flowSpace($at);
            $char = $this->text[$at] ?? '';
            if ($char === ']' || $char === '') {
                break;
            }

            if ($char === ',') {
                $at++;

                continue;
            }

            [$at, $sources[]] = $this->mergedMap($at, -1, ']');
        }

        return [$char === ']' ? $at + 1 : $at, $sources];
    }

    /**
     * The implicit key at $at, if one starts there: as it is spelt (quotes included, so only a plain `<<` is a
     * merge key), and the offset just past its colon. An implicit key is written on one line.
     *
     * @return array{string, int}|null
     */
    private function key(int $at): ?array
    {
        $char = $this->text[$at] ?? '';

        if ($char === '"' || $char === "'") {
            $end = $this->quotedEnd($at);
            if ($end > $this->lineEnd($at)) {
                return null;
            }

            $colon = $this->skipBlank($end);
            if (($this->text[$colon] ?? '') !== ':' || ! $this->isBlankOrEnd($colon + 1)) {
                return null;
            }

            return [substr($this->text, $at, $end - $at), $colon + 1];
        }

        if ($char === '' || str_contains("[]{}#&*!|>%@`,\n", $char) || (str_contains('-?:', $char) && $this->isBlankOrEnd($at + 1))) {
            return null;
        }

        if (preg_match(self::PLAIN_KEY, $this->text, $match, 0, $at) !== 1) {
            return null;
        }

        return [rtrim($match[1], " \t"), $at + strlen($match[0])];
    }

    /** A name as written — plain, or quoted — as symfony/yaml reads it into the document. */
    private function name(string $spelt, bool $flow): string
    {
        $first = $spelt[0] ?? '';

        if ($first === "'") {
            return str_replace("''", "'", substr($spelt, 1, -1));
        }

        if ($first === '"') {
            return str_contains($spelt, '\\') ? self::read($spelt, $spelt) : substr($spelt, 1, -1);
        }

        // An implicit key sits on one line, but a flow key may still break before its colon.
        if ($flow && str_contains($spelt, "\n")) {
            $spelt = (string) preg_replace('/[ \t]*\n[ \t]*/', ' ', $spelt);
        }

        if (($first >= '0' && $first <= '9') || $first === '+' || $first === '-') {
            if (preg_match(self::INTEGER, $spelt) === 1) {
                return self::read($flow ? '{'.$spelt.': ~}' : $spelt.': ~', $spelt);
            }
        }

        return $spelt;
    }

    /**
     * The name symfony/yaml reads $yaml to — a quoted name with an escape, or a map of one member whose name it
     * reads as an integer — so it is read exactly as the document was, rather than read again here.
     */
    private static function read(string $yaml, string $spelt): string
    {
        try {
            $read = Yaml::parse($yaml);
        } catch (ParseException) {
            return $spelt;
        }

        return match (true) {
            is_string($read) => $read,
            is_array($read) && $read !== [] => (string) array_key_first($read),
            default => $spelt,
        };
    }

    /**
     * $at past any anchor and tag, and the anchor's name.
     *
     * @return array{int, ?string}
     */
    private function properties(int $at): array
    {
        $anchor = null;
        while (($char = $this->text[$at] ?? '') === '&' || $char === '!') {
            $length = strcspn($this->text, self::NAME_END, $at + 1);
            if ($char === '&') {
                $anchor = substr($this->text, $at + 1, $length);
            }

            $at = $this->skipBlank($at + 1 + $length);
        }

        return [$at, $anchor];
    }

    /**
     * The same inside a flow collection, where white space may break a line.
     *
     * @return array{int, ?string}
     */
    private function flowProperties(int $at): array
    {
        $anchor = null;
        while (($char = $this->text[$at] ?? '') === '&' || $char === '!') {
            $length = strcspn($this->text, self::NAME_END, $at + 1);
            if ($char === '&') {
                $anchor = substr($this->text, $at + 1, $length);
            }

            $at = $this->flowSpace($at + 1 + $length);
        }

        return [$at, $anchor];
    }

    /** Just past the quoted scalar whose opening quote is at $at, which may span lines. */
    private function quotedEnd(int $at): int
    {
        $quote = $this->text[$at];
        $from = $at + 1;
        while (($close = strpos($this->text, $quote, $from)) !== false) {
            if ($quote === "'") {
                if (($this->text[$close + 1] ?? '') !== "'") {
                    return $close + 1;
                }

                $from = $close + 2;

                continue;
            }

            $slashes = 0;
            while ($this->text[$close - 1 - $slashes] === '\\') {
                $slashes++;
            }

            if ($slashes % 2 === 0) {
                return $close + 1;
            }

            $from = $close + 1;
        }

        return $this->length;
    }

    /**
     * The end of the plain scalar at $at inside a flow collection: where symfony/yaml ends it, at the first comma
     * or $closer outside a comment.
     */
    private function plainFlowEnd(int $at, string $closer): int
    {
        while (($at += strcspn($this->text, ',#'.$closer, $at)) < $this->length) {
            if ($this->text[$at] !== '#') {
                return $at;
            }

            $at = $this->startsToken($at) ? $this->lineEnd($at) : $at + 1;
        }

        return $this->length;
    }

    /**
     * Steps over a value nothing is sought in, from just past its key's colon or its entry's `-`, in a
     * collection indented $indent: every line indented past $indent is the value's, and so — for a member's
     * value starting on the next line, where $entries — is a sequence entry indented as far as the key. A
     * quoted or flow value starting on the key's line is read to its end, since symfony/yaml lets its next
     * lines sit as far in as the key itself.
     */
    private function skipValue(int $at, int $indent, bool $entries): int
    {
        $at = $this->skipBlank($at);
        $char = $this->text[$at] ?? '';
        if ($char === '[' || $char === '{') {
            return $this->nextLine($this->skipFlow($at));
        }

        if ($char === '"' || $char === "'") {
            return $this->nextLine($this->quotedEnd($at));
        }

        $entries = $entries && $this->endsLine($at);
        $line = $this->nextLine($at);
        while ($line < $this->length) {
            $spaces = strspn($this->text, ' ', $line);
            $first = $line + $spaces + strspn($this->text, " \t", $line + $spaces);
            $char = $this->text[$first] ?? '';
            if ($spaces <= $indent && $char !== "\n" && $char !== '#' && $char !== '' && ! ($entries && $spaces === $indent && $this->isEntry($first))) {
                return $line;
            }

            $line = $this->nextLine($first);
        }

        return $this->length;
    }

    /**
     * Just past the flow collection opening at $at, found as symfony/yaml's lexer finds it: by counting brackets,
     * stepping over a quoted scalar or a comment whole wherever one starts a token.
     */
    private function skipFlow(int $at): int
    {
        $depth = 0;
        while (($at += strcspn($this->text, '[]{}"\'#', $at)) < $this->length) {
            $char = $this->text[$at];
            if ($char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ']' || $char === '}') {
                if (--$depth === 0) {
                    return $at + 1;
                }
            } elseif ($this->startsToken($at)) {
                $at = $char === '#' ? $this->lineEnd($at) : $this->quotedEnd($at);

                continue;
            }

            $at++;
        }

        return $this->length;
    }

    /**
     * Whether the quote or `#` at $at starts a token in a flow collection, as symfony/yaml's lexer reads one: after
     * white space or an indicator. Anywhere else it is part of a plain scalar, as in `it's`.
     */
    private function startsToken(int $at): bool
    {
        return $at === 0 || str_contains(" \t\n,:[]{}", $this->text[$at - 1]);
    }

    /** From the line at $line on, the first line holding something and indented $parent or less. */
    private function outdented(int $line, int $parent): int
    {
        while ($line < $this->length) {
            $indent = $this->indent($line);
            $first = $line + $indent + strspn($this->text, " \t", $line + $indent);
            if ($indent <= $parent && ($this->text[$first] ?? "\n") !== "\n") {
                return $line;
            }

            $line = $this->nextLine($first);
        }

        return $this->length;
    }

    /** From the line at $line on, the first line holding more than white space and a comment. */
    private function contentLine(int $line): int
    {
        while ($line < $this->length) {
            $first = $this->skipBlank($line);
            $char = $this->text[$first] ?? '';
            if ($char !== "\n" && $char !== '#' && $char !== '') {
                return $line;
            }

            $line = $this->nextLine($first);
        }

        return $this->length;
    }

    /** Past white space, line breaks and comments inside a flow collection. */
    private function flowSpace(int $at): int
    {
        while (true) {
            $at += strspn($this->text, " \t\n", $at);
            if (($this->text[$at] ?? '') !== '#') {
                return $at;
            }

            $at = $this->lineEnd($at);
        }
    }

    private function skipBlank(int $at): int
    {
        return $at + strspn($this->text, " \t", $at);
    }

    /** Whether nothing but white space and a comment follows $at on its line. */
    private function endsLine(int $at): bool
    {
        $char = $this->text[$this->skipBlank($at)] ?? '';

        return $char === '' || $char === "\n" || $char === '#';
    }

    private function isBlankOrEnd(int $at): bool
    {
        $char = $this->text[$at] ?? '';

        return $char === '' || $char === ' ' || $char === "\t" || $char === "\n";
    }

    /** Whether a block sequence entry starts at $at. */
    private function isEntry(int $at): bool
    {
        return ($this->text[$at] ?? '') === '-' && $this->isBlankOrEnd($at + 1);
    }

    /** Whether the line at $line is a document marker, `---` or `...`. */
    private function isMarker(int $line): bool
    {
        $marker = substr($this->text, $line, 3);

        return ($marker === '---' || $marker === '...') && $this->isBlankOrEnd($line + 3);
    }

    private function indent(int $line): int
    {
        return strspn($this->text, ' ', $line);
    }

    private function column(int $at): int
    {
        $break = $at === 0 ? false : strrpos($this->text, "\n", $at - $this->length - 1);

        return $break === false ? $at : $at - $break - 1;
    }

    private function lineEnd(int $at): int
    {
        $break = strpos($this->text, "\n", $at);

        return $break === false ? $this->length : $break;
    }

    private function nextLine(int $at): int
    {
        $break = strpos($this->text, "\n", $at);

        return $break === false ? $this->length : $break + 1;
    }
}
