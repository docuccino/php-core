<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

use Docuccino\Core\Draft\SchemaKeywords;
use stdClass;

/**
 * Query, path, header and cookie values arrive as strings whatever the contract says they are, so
 * checking `?page=2` against `type: integer` needs the string read back as the type it stands for.
 *
 * Only a string that unambiguously IS the documented type is converted. `?page=abc` against
 * `type: integer` stays a string, so the failure reads "must match the type: integer" — the real
 * problem — instead of `abc` silently becoming `0`.
 *
 * What the contract "says" has to be read in the SAME grammar {@see SchemaCheck} hands the validator,
 * which resolves a local `$ref` and unwraps `allOf`/`anyOf`/`oneOf` before it decides anything. A
 * reader that only sees a literal `type` on the node in front of it converts nothing behind a
 * reference or a composition — so the validator checks an integer parameter against the wire string
 * and fails a request that was fine. Every one of those spellings is something the generator itself
 * emits: `representation.nullable = 'anyof'` writes a nullable parameter as an `anyOf`, the 3.0
 * downlevel emitter rewrites a multi-type `type` as one and hoists `$ref` siblings into an `allOf`,
 * and an enum-backed allow-list publishes `items: {$ref: …}`.
 *
 * @internal
 *
 * @phpstan-type Flattened array{types: list<string>, items: array<string, mixed>|null, properties: array<string, array<string, mixed>>, path: list<string>}
 */
final class ParameterValue
{
    /**
     * How far the reader follows a document into itself. One walk counts a composition branch and a
     * step into `items` or a property alike, because it IS one walk: a step into `items` re-reads a
     * schema, and a walk that started that read again at zero would follow `A: {items: {$ref: A}}`
     * until the stack ran out. Past this depth a document is defending itself against a reader rather
     * than one a reader meant to write. {@see Refs} bounds a straight `$ref` chain.
     *
     * Depth is a bound on how DEEP the walk goes and none at all on how MUCH it does, so it is not
     * alone: a branching schema visits k^depth nodes without ever repeating one on a path. The two
     * companions below make the bound one on work — `$seen` cuts a schema that reaches itself at the
     * second visit rather than the ninth level, and the memo walks each (pointer, depth, path) once.
     */
    private const int MAX_DEPTH = 8;

    /**
     * @param  array<string, mixed>|null  $schema
     * @param  array<string, mixed>  $document  the whole contract, so a local `$ref` resolves; the
     *                                          empty default resolves nothing, which is the same
     *                                          well-defined answer as a reference nothing defines
     */
    public static function coerce(mixed $value, ?array $schema, array $document = []): mixed
    {
        $memo = [];

        return self::read($value, $schema, $document, 0, [], $memo);
    }

    /**
     * One node of the walk: what this schema says the value may be, and the value read back as it.
     *
     * The two ways the walk goes deeper differ in whether the value goes with it, and that is what
     * decides whether the path travels too. Stepping into a member of a map or a list CONSUMES the
     * value, so it cannot recur without end whatever the schema says, and forgetting the path there
     * is what lets a self-referential object schema still be read at every level `filter[a][b]`
     * actually has. Splitting a comma list consumes nothing — `explode(',', 'x')` hands back the same
     * string in a one-element list — so that step carries the path, and a list documented as its own
     * items is cut instead of followed until the stack runs out.
     *
     * @param  array<string, mixed>|null  $schema
     * @param  array<string, mixed>  $document
     * @param  list<string>  $seen  the pointers this walk has resolved without the value shrinking, so
     *                              a schema that reaches itself is cut on the second visit
     * @param  array<string, Flattened>  $memo
     */
    private static function read(mixed $value, ?array $schema, array $document, int $depth, array $seen, array &$memo): mixed
    {
        $flat = self::flatten($schema, $document, $depth, $seen, $memo);

        if (is_string($value)) {
            return self::fromString($value, $flat, $document, $depth, $memo);
        }

        if (is_array($value)) {
            return self::fromArray($value, $flat, $document, $depth, $seen, $memo);
        }

        return $value;
    }

    /**
     * @param  Flattened  $flat
     * @param  array<string, mixed>  $document
     * @param  array<string, Flattened>  $memo
     */
    private static function fromString(string $value, array $flat, array $document, int $depth, array &$memo): mixed
    {
        $types = $flat['types'];

        // `?sort=name,-created_at` is the comma list representation the generator documents by default.
        // Splitting it is a REPRESENTATION decode rather than a reading of its type, which is why it
        // comes before the string rule below rather than after it: a list serialised into a query
        // string satisfies `type: string` incidentally, and leaving it as the string it arrived as
        // leaves `items` — the allow-list the document publishes — checking nothing at all.
        if (in_array('array', $types, true)) {
            return array_map(
                static fn (string $item): mixed => self::read($item, $flat['items'], $document, $depth + 1, $flat['path'], $memo),
                explode(',', $value),
            );
        }

        // Where the contract permits a STRING, the value that arrived already satisfies it, and reading
        // it back as something else can only take a pass away: `anyOf: [{integer, minimum: 100}, {string}]`
        // accepts `?limit=42` exactly as sent and rejects the integer 42. So a union that admits several
        // scalar types resolves toward the wire — convert only where the string cannot stand as itself.
        if (in_array('string', $types, true)) {
            return $value;
        }

        if (in_array('integer', $types, true) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        if (in_array('number', $types, true) && is_numeric($value)) {
            return (float) $value;
        }

        if (in_array('boolean', $types, true) && in_array($value, ['true', 'false', '1', '0'], true)) {
            return $value === 'true' || $value === '1';
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  Flattened  $flat
     * @param  array<string, mixed>  $document
     * @param  list<string>  $seen
     * @param  array<string, Flattened>  $memo
     */
    private static function fromArray(array $value, array $flat, array $document, int $depth, array $seen, array &$memo): mixed
    {
        if (array_is_list($value)) {
            return array_map(
                static fn (mixed $item): mixed => self::read($item, $flat['items'], $document, $depth + 1, $seen, $memo),
                $value,
            );
        }

        // A bracketed query parameter (`filter[status]=paid`) arrives as a map: an object to JSON Schema.
        $object = new stdClass;

        foreach ($value as $key => $item) {
            $object->{(string) $key} = self::read(
                $item,
                $flat['properties'][(string) $key] ?? null,
                $document,
                $depth + 1,
                $seen,
                $memo,
            );
        }

        // The brackets have already said which of the two this is, so where the contract permits both
        // the value decides: a map is an object. Reading it as a list instead throws the keys away, and
        // `properties`, `required` and the `*Properties` counts then have nothing left to check.
        $list = in_array('array', $flat['types'], true) && ! in_array('object', $flat['types'], true);

        return $list ? array_values(get_object_vars($object)) : $object;
    }

    /**
     * Everything the validator would read off this node about what a value here may be: the types it
     * permits, the `items` it holds each entry of a list to, and the `properties` it names — plus the
     * `path` of pointers that got here, which is what the walk into an `items` or a property carries
     * on with so that step continues this walk rather than starting a new one at zero.
     *
     * One walk rather than three, because three readers of one grammar is the defect this fixes: the
     * `items` behind a `$ref` has to come from the same resolution the types did, or a list documented
     * as `{$ref: IdList}` splits on the comma and then leaves every entry a string.
     *
     * `allOf` is a conjunction and the other two are disjunctions, but all three are read as the UNION
     * of their branches. A union can only ever widen the type set, and widening is safe in the one
     * direction that matters: the extra type it admits is either coercible — in which case the value
     * matches a branch the document does publish — or it is `string`, which stops conversion outright.
     *
     * The composition keywords come from {@see SchemaKeywords}'s schema-list position rather than from
     * a copy of the names, less `prefixItems`: its branches position the ELEMENTS of a tuple rather
     * than state alternative readings of the value itself, so a type read out of one would be the type
     * of a member and not of this.
     *
     * A document may reach itself, and the two ways it does that are bounded here rather than by depth
     * (which {@see MAX_DEPTH} says why it cannot do alone). A pointer already on the path is a cycle,
     * so it reads as nothing on the second visit. A pointer NOT on the path may still be reached along
     * many paths — `A: {allOf: [{$ref: B}, {$ref: B}, …]}` down a few levels is `k^depth` visits of a
     * document a few hundred bytes long — so each (pointer, depth, path) is walked once and answered
     * from the memo after that. The key names every input the answer depends on, which is what makes
     * reusing it the same walk rather than a shortcut through a different one.
     *
     * @param  array<string, mixed>|null  $schema
     * @param  array<string, mixed>  $document
     * @param  list<string>  $seen
     * @param  array<string, Flattened>  $memo
     * @return Flattened
     */
    private static function flatten(?array $schema, array $document, int $depth, array $seen, array &$memo): array
    {
        $empty = ['types' => [], 'items' => null, 'properties' => [], 'path' => $seen];

        if ($schema === null || $depth > self::MAX_DEPTH) {
            return $empty;
        }

        [$node, $segments, $dangling] = Refs::follow($document, $schema, []);

        // A reference the document does not define makes the WHOLE node unreadable — a `type` sibling
        // does not stand in for the half that would not resolve, because the node means "whatever that
        // names AND this", and half of it is unknown. Reading no type here is not a quiet "nothing to
        // check": {@see SchemaCheck} hands the same node to the validator, which cannot resolve it
        // either and throws, and {@see ContractChecker::validate()} turns that into a violation naming
        // the pointer that went nowhere. The check this feeds is already going to fail and say why.
        if ($dangling !== null) {
            return $empty;
        }

        $pointer = $segments === [] ? null : implode('/', $segments);
        $key = null;

        if ($pointer !== null) {
            if (in_array($pointer, $seen, true)) {
                return $empty;
            }

            $key = implode("\0", [$pointer, (string) $depth, ...$seen]);

            if (array_key_exists($key, $memo)) {
                return $memo[$key];
            }

            $seen = [...$seen, $pointer];
        }

        $types = self::declared($node);

        // An `enum` with no `type` beside it still names a closed set, and the members say what type
        // the set is. Members of several types leave `string` in the union, which is the answer that
        // converts nothing — so an ambiguous set needs no case of its own.
        if ($types === []) {
            $types = self::enumTypes($node);
        }

        $items = self::member($node, 'items');
        $properties = self::propertyMap($node);

        foreach (self::compositions() as $keyword) {
            $branches = $node[$keyword] ?? null;

            if (! is_array($branches)) {
                continue;
            }

            foreach ($branches as $branch) {
                if (! is_array($branch)) {
                    continue;
                }

                /** @var array<string, mixed> $branch */
                $inner = self::flatten($branch, $document, $depth + 1, $seen, $memo);

                $types = [...$types, ...$inner['types']];
                // The first branch that names one wins, exactly as the node's own does over a branch's.
                $items ??= $inner['items'];
                $properties += $inner['properties'];
            }
        }

        $flat = [
            'types' => array_values(array_unique($types)),
            'items' => $items,
            'properties' => $properties,
            'path' => $seen,
        ];

        if ($key !== null) {
            $memo[$key] = $flat;
        }

        return $flat;
    }

    /**
     * The keywords whose branches each state a reading of the value in front of us. {@see flatten()}
     * says why `prefixItems` is subtracted from the schema-list position rather than listed with them.
     *
     * @return list<string>
     */
    private static function compositions(): array
    {
        return array_values(array_diff(SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA_LIST), ['prefixItems']));
    }

    /**
     * The types this node states outright: `type: integer`, or `type: [integer, null]`.
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private static function declared(array $node): array
    {
        $type = $node['type'] ?? null;

        if (is_string($type)) {
            return [$type];
        }

        if (! is_array($type)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $one): string => is_string($one) ? $one : '',
            $type,
        ), static fn (string $one): bool => $one !== ''));
    }

    /**
     * The JSON types of an `enum`'s own members. Anything a decoded document cannot hold at all reads
     * as `string`, which is the reading that converts nothing.
     *
     * `SchemaExampleFactory::jsonType()` classifies a value too and answers differently ON PURPOSE —
     * an integral float, and a value it cannot name — so neither may be unified into the other; that
     * docblock states which way each goes and why.
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private static function enumTypes(array $node): array
    {
        $enum = $node['enum'] ?? null;

        if (! is_array($enum)) {
            return [];
        }

        $types = [];

        foreach ($enum as $member) {
            $types[] = match (true) {
                $member === null => 'null',
                is_bool($member) => 'boolean',
                is_int($member) => 'integer',
                is_float($member) => 'number',
                is_array($member) => array_is_list($member) ? 'array' : 'object',
                default => 'string',
            };
        }

        return array_values(array_unique($types));
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private static function member(array $node, string $keyword): ?array
    {
        $value = $node[$keyword] ?? null;

        /** @var array<string, mixed>|null */
        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, array<string, mixed>>
     */
    private static function propertyMap(array $node): array
    {
        $properties = $node['properties'] ?? null;

        if (! is_array($properties)) {
            return [];
        }

        $out = [];
        foreach ($properties as $name => $property) {
            if (is_array($property)) {
                /** @var array<string, mixed> $property */
                $out[(string) $name] = $property;
            }
        }

        return $out;
    }
}
