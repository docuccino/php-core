<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Support\Arr;
use Docuccino\Core\Support\FormatSamples;
use Docuccino\Core\Support\JsonValue;
use stdClass;

/**
 * Builds one representative value from a JSON Schema, so an exported request body is something a
 * consumer can send rather than an empty box.
 *
 * Everything here is a pure function of the schema. Where a schema offers several answers the choice
 * is fixed in advance and never taken from encounter order: a map of `examples` picks its lowest key,
 * because JSON object order is not an authored fact, while `enum` and `oneOf` pick their first entry,
 * because a list's order IS authored and every other reader of the document shows the same branch.
 *
 * An empty object comes back as a {@see stdClass}, not `[]` ({@see JsonValue} for that convention). The
 * value is serialised into a JSON string for the collection, and an empty PHP array would render as
 * `[]` — a body that lies about its shape.
 *
 * A subschema may be a BOOLEAN, and `false` admits no value at all — so the recursion answers
 * `array{mixed}|null` throughout, `null` meaning "nothing validates here" rather than "the value is
 * null". Every position decides what that means for itself ({@see subschema()}), because an example a
 * schema forbids is the one thing worse than no example: a consumer copies it verbatim and sends it.
 *
 * @internal
 */
final readonly class SchemaExampleFactory
{
    /** Deep enough for any real payload; past it a self-referential schema is the likelier reading. */
    private const int MAX_DEPTH = 8;

    /**
     * @param  array<string, string>  $formatSamples  the document's configured samples, merged over
     *                                                {@see FormatSamples} at the one lookup
     */
    public function __construct(private array $formatSamples = []) {}

    /**
     * The same factory bound to a document's configured samples — `$this` when they change nothing, so
     * an injected instance survives the default case.
     *
     * @param  array<string, string>  $samples
     */
    public function withFormatSamples(array $samples): self
    {
        return $samples === $this->formatSamples ? $this : new self($samples);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $components  the document's `components`, for `$ref` resolution
     * @param  list<string>  $stack  `$ref` pointers already being resolved, guarding cycles
     */
    public function value(array $schema, array $components = [], int $depth = 0, array $stack = []): mixed
    {
        // A schema nothing satisfies has no honest value, and a caller at the top has nowhere to put
        // that fact — so it collapses to `null`, which is already this factory's answer for a schema
        // that told it nothing.
        return ($this->candidate($schema, $components, $depth, $stack) ?? [null])[0];
    }

    /**
     * ONE subschema's value, for a caller listing a schema's members as fields of its own — a form body,
     * a `deepObject` query, a response header. Wrapped, because {@see value()} answers `null` both for a
     * schema that said nothing and for one nothing satisfies, and here the two want opposite things: a
     * `false` forbids the member outright, so the field is not undescribed but disallowed, and the caller
     * leaves it out rather than offering a consumer something the server will reject.
     *
     * @param  array<string, mixed>  $components
     * @return array{mixed}|null null where NO value validates
     */
    public function member(mixed $subschema, array $components = []): ?array
    {
        return $this->subschema($subschema, $components, 0, []);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $components
     * @param  list<string>  $stack
     * @return array{mixed}|null null where NO value validates
     */
    private function candidate(array $schema, array $components, int $depth, array $stack): ?array
    {
        if ($depth > self::MAX_DEPTH) {
            return [$this->empty($schema)];
        }

        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            return $this->fromRef($schema['$ref'], $components, $depth, $stack);
        }

        $stated = $this->stated($schema);
        if ($stated !== null) {
            return $stated;
        }

        $composed = $this->composed($schema, $components, $depth, $stack);
        if ($composed !== null) {
            return $composed[0];
        }

        return $this->byType($schema, $components, $depth, $stack);
    }

    /**
     * ONE subschema, wherever it sits. A boolean is a schema at every 2020-12 subschema position and
     * `true` is the empty schema, so it reads exactly as `{}` does; anything that is no schema at all
     * widens to `{}` the same way {@see Canonicalizer} publishes it. `false` admits nothing, and no
     * value is a value this factory may invent — so it comes back as `null` for the position to answer.
     *
     * @param  array<string, mixed>  $components
     * @param  list<string>  $stack
     * @return array{mixed}|null
     */
    private function subschema(mixed $value, array $components, int $depth, array $stack): ?array
    {
        if ($value === false) {
            return null;
        }

        return $this->candidate(is_array($value) ? Arr::stringKeyed($value) : [], $components, $depth, $stack);
    }

    /**
     * What a Media Type, Parameter or Header Object ILLUSTRATES: its `example`, or the lowest key of its
     * `examples` map. Wrapped so a stated `null` is distinguishable from having stated nothing.
     *
     * Those members sit BESIDE the schema, not in it, so a caller holding one of those objects has to
     * ask here rather than hand the schema over — an author's example is what they said the payload
     * looks like, and it outranks anything derived from the shape.
     *
     * @param  array<string, mixed>  $node
     * @return array{mixed}|null
     */
    public function illustration(array $node): ?array
    {
        if (array_key_exists('example', $node)) {
            return [$node['example']];
        }

        if (isset($node['examples']) && is_array($node['examples']) && $node['examples'] !== []) {
            return [$this->firstExample($node['examples'])];
        }

        return null;
    }

    /**
     * A value the schema states outright, wrapped so `null` is distinguishable from "said nothing".
     *
     * @param  array<string, mixed>  $schema
     * @return array{mixed}|null
     */
    private function stated(array $schema): ?array
    {
        $illustration = $this->illustration($schema);
        if ($illustration !== null) {
            return $illustration;
        }

        if (array_key_exists('default', $schema)) {
            return [$schema['default']];
        }

        if (array_key_exists('const', $schema)) {
            return [$schema['const']];
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && $schema['enum'] !== []) {
            return [reset($schema['enum'])];
        }

        return null;
    }

    /**
     * OAS wraps each named example in an object with a `value` member; JSON Schema 2020-12 uses a bare
     * list. A map is keyed by author-chosen names whose order carries no meaning, so the lowest key
     * wins rather than whichever happened to be written first.
     *
     * @param  array<mixed, mixed>  $examples
     */
    private function firstExample(array $examples): mixed
    {
        if (! array_is_list($examples)) {
            $keys = array_map(strval(...), array_keys($examples));
            sort($keys, SORT_STRING);
            $examples = [$examples[$keys[0]] ?? null];
        }

        $first = $examples[0] ?? null;

        return is_array($first) && array_key_exists('value', $first) ? $first['value'] : $first;
    }

    /**
     * The outer wrapper says whether a composition keyword answered at all; the inner one is the
     * candidate it answered with ({@see candidate()}).
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $components
     * @param  list<string>  $stack
     * @return array{array{mixed}|null}|null
     */
    private function composed(array $schema, array $components, int $depth, array $stack): ?array
    {
        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            $merged = [];
            $scalar = null;

            foreach ($schema['allOf'] as $branch) {
                $value = $this->subschema($branch, $components, $depth, $stack);

                // A conjunction is only as satisfiable as its narrowest branch, wherever that branch
                // sits — so every one is read before any value comes back out.
                if ($value === null) {
                    return [null];
                }

                // Objects compose; a scalar branch simply replaces what came before it.
                if (is_array($value[0]) || $value[0] instanceof stdClass) {
                    $merged = array_merge($merged, (array) $value[0]);

                    continue;
                }

                if ($value[0] !== null) {
                    $scalar ??= $value;
                }
            }

            return [$scalar ?? [$merged === [] ? new stdClass : $merged]];
        }

        foreach (['oneOf', 'anyOf'] as $keyword) {
            $branches = is_array($schema[$keyword] ?? null) ? array_values($schema[$keyword]) : [];

            // `false` is the one branch to walk past: nothing satisfies it, so it is not an alternative
            // any consumer has, and a list of nothing else leaves the union with no value at all.
            $inhabited = array_values(array_filter($branches, static fn (mixed $b): bool => $b !== false));

            if ($branches !== [] && $inhabited === []) {
                return [null];
            }

            // Branch 0 of what remains: the list is authored, and it is the branch every other reader
            // of the document shows.
            if (is_array($inhabited[0] ?? null) || ($inhabited[0] ?? null) === true) {
                return [$this->subschema($inhabited[0], $components, $depth, $stack)];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $components
     * @param  list<string>  $stack
     * @return array{mixed}|null
     */
    private function fromRef(string $ref, array $components, int $depth, array $stack): ?array
    {
        // A pointer already on the stack is a cycle: return the empty shape rather than recursing.
        if (in_array($ref, $stack, true)) {
            return [new stdClass];
        }

        $resolved = $this->resolve($ref, $components);

        // A pointer nothing answers says nothing about what is there, so the empty shape stands. What
        // the pointer DOES answer is read as the subschema it is, boolean included.
        return $resolved === null
            ? [new stdClass]
            : $this->subschema($resolved[0], $components, $depth + 1, [...$stack, $ref]);
    }

    /**
     * The value the pointer addresses, wrapped so a boolean schema there is distinguishable from a
     * pointer that resolves to nothing.
     *
     * @param  array<string, mixed>  $components
     * @return array{mixed}|null
     */
    private function resolve(string $ref, array $components): ?array
    {
        if (! str_starts_with($ref, '#/components/')) {
            return null;
        }

        $cursor = $components;
        foreach (array_slice(explode('/', $ref), 2) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return [$cursor];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $components
     * @param  list<string>  $stack
     * @return array{mixed}|null
     */
    private function byType(array $schema, array $components, int $depth, array $stack): ?array
    {
        return match ($this->type($schema)) {
            'object' => $this->object($schema, $components, $depth, $stack),
            'array' => [$this->list($schema, $components, $depth, $stack)],
            'string' => [$this->string($schema)],
            'integer', 'number' => [$this->number($schema)],
            'boolean' => [true],
            'null' => [null],
            default => [null],
        };
    }

    /**
     * The type to build. A list picks its first non-`null` member (the nullable idiom), and a schema
     * with object keywords but no `type` is an object — the shape it describes is the honest reading.
     *
     * @param  array<string, mixed>  $schema
     */
    private function type(array $schema): ?string
    {
        $type = $schema['type'] ?? null;

        if (is_array($type)) {
            foreach ($type as $candidate) {
                if (is_string($candidate) && $candidate !== 'null') {
                    return $candidate;
                }
            }

            return 'null';
        }

        if (is_string($type)) {
            return $type;
        }

        foreach (['properties', 'required', 'additionalProperties'] as $keyword) {
            if (isset($schema[$keyword])) {
                return 'object';
            }
        }

        return isset($schema['items']) ? 'array' : null;
    }

    /**
     * Every declared property, not only the required ones: a body someone is about to edit should show
     * the whole shape, and hiding the optional half hides the contract. One a `false` forbids is the
     * exception — that is not part of the contract, it is the contract saying no.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $components
     * @param  list<string>  $stack
     * @return array{mixed}|null
     */
    private function object(array $schema, array $components, int $depth, array $stack): ?array
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? array_filter($schema['required'], is_string(...)) : [];

        $keys = array_map(strval(...), array_keys($properties));
        sort($keys, SORT_STRING);

        $out = [];
        foreach ($keys as $key) {
            $property = $this->subschema($properties[$key] ?? null, $components, $depth + 1, $stack);

            // A property nothing satisfies is a property no request may carry, so the example leaves it
            // out — and an object that REQUIRES one has no valid instance at all.
            if ($property === null) {
                if (in_array($key, $required, true)) {
                    return null;
                }

                continue;
            }

            $out[$key] = $property[0];
        }

        return [$out === [] ? new stdClass : $out];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $components
     * @param  list<string>  $stack
     * @return list<mixed>
     */
    private function list(array $schema, array $components, int $depth, array $stack): array
    {
        if (! array_key_exists('items', $schema)) {
            return [];
        }

        $item = $this->subschema($schema['items'], $components, $depth + 1, $stack);

        // Exactly one item, whatever `minItems` says: an example is a shape, not a load test. Where no
        // element can validate — `items: false` — the empty list is the only array that does.
        return $item === null ? [] : [$item[0]];
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function string(array $schema): string
    {
        $format = $schema['format'] ?? null;

        return is_string($format) ? (FormatSamples::for($format, $this->formatSamples) ?? 'string') : 'string';
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function number(array $schema): int|float
    {
        $minimum = $schema['minimum'] ?? null;

        return is_int($minimum) || is_float($minimum) ? $minimum : 0;
    }

    /**
     * The empty shape for a schema we will not descend into (depth cap, cycle).
     *
     * @param  array<string, mixed>  $schema
     */
    private function empty(array $schema): mixed
    {
        return match ($this->type($schema)) {
            'array' => [],
            'string' => '',
            'integer', 'number' => 0,
            'boolean' => false,
            default => new stdClass,
        };
    }
}
