<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Draft\SchemaKeywords;
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
 * Two keywords say what a value may NOT be, and both are read here rather than left for a consumer to
 * discover by sending: `not` forbids whatever its subschema admits, and `contains` forbids an array with
 * no element matching its subschema. Neither is satisfiable by construction, so both ask {@see admits()}
 * — and where that cannot PROVE the constraint met, the value is absent rather than published beside a
 * schema that rejects it.
 *
 * Those two are in scope because they can refuse a value built faithfully from the members beside them,
 * with the schema saying nothing contradictory: `{type: string, not: {const: 'x'}}` and
 * `{items: {type: string}, contains: {const: 'wanted'}}` are both consistent, and what runs into the
 * constraint is a CHOICE THIS FACTORY MADE — which sample a format gets, that an array is built one
 * element long. `not` is minted by a producer (a `not_in:` rule) and `contains` by none, so the second
 * is here on the mechanism rather than on a count: they are the same single-position assertion, one
 * about the value and one about a list's elements, and either is author-writable through an overlay
 * (a first-class input at precedence 45) or a hand-authored component.
 *
 * The other nine subschema positions are deliberately not read, and the same line is what excludes
 * them, so the next reader inherits a decision rather than an oversight. Two cannot refuse anything
 * this factory publishes at all: `unevaluatedProperties` and `unevaluatedItems` are satisfied by a
 * value built out of declared members. The remaining seven — `patternProperties`, `propertyNames`,
 * `prefixItems`, `dependentSchemas`, `if`, `then`, `else` — can refuse one, but only where the author
 * wrote a schema contradicting the members beside it: a `propertyNames` refusing a name `properties`
 * declares, a `prefixItems` refusing what `items` produces. There the document already lies to its
 * consumer before any example is built, and no example this factory withholds fixes that.
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
     * The candidate the schema's shape produces, held to the one keyword that speaks about whatever that
     * turns out to be. `not` is read here rather than inside {@see byType()} for exactly that reason: it
     * refuses a stated `const` and a `$ref`'s resolved value the same way it refuses a built one.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $components
     * @param  list<string>  $stack
     * @return array{mixed}|null null where NO value validates
     */
    private function candidate(array $schema, array $components, int $depth, array $stack): ?array
    {
        $candidate = $this->unconstrained($schema, $components, $depth, $stack);

        if ($candidate === null || ! array_key_exists('not', $schema)) {
            return $candidate;
        }

        // A `not` the value provably ESCAPES constrains nothing. Anything else — the value satisfies it,
        // or this factory cannot tell — and there is no value here it can honestly publish.
        return $this->admits($schema['not'], $candidate[0]) === false ? $candidate : null;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $components
     * @param  list<string>  $stack
     * @return array{mixed}|null null where NO value validates
     */
    private function unconstrained(array $schema, array $components, int $depth, array $stack): ?array
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
            'array' => $this->list($schema, $components, $depth, $stack),
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
     * @return array{list<mixed>}|null null where NO array validates
     */
    private function list(array $schema, array $components, int $depth, array $stack): ?array
    {
        $item = array_key_exists('items', $schema)
            ? $this->subschema($schema['items'], $components, $depth + 1, $stack)
            : null;

        if (SchemaKeywords::containsAsserts($schema)) {
            return $this->matching($schema, $item, $components, $depth, $stack);
        }

        // Exactly one item, whatever `minItems` says: an example is a shape, not a load test. Where no
        // element can validate — `items: false`, or no `items` at all — the empty list is the only array
        // this factory knows validates.
        return [$item === null ? [] : [$item[0]]];
    }

    /**
     * The one-element array that satisfies `contains`, or null where this factory can build none — an
     * array with no matching element is exactly the request the server rejects.
     *
     * One element is the only length this factory builds, so the keyword's own bounds decide before any
     * element does. A floor above 1 wants an array of repeats, which is exactly what a `uniqueItems`
     * beside it forbids — and this factory does not read `uniqueItems`, so it cannot prove such an array
     * validates. A cap below 1 forbids the one match it could prove and leaves only an array whose
     * elements provably do NOT match — where the elements come from `items` and from `contains` itself,
     * the two shapes it can prove nothing of the sort about, and the empty array that would satisfy the
     * cap shows a consumer no shape at all. Between the two, ONE proven match answers every bound there
     * is: never fewer than a floor of 0 or 1, never more than a cap of 1 or above.
     *
     * The `items` element is tried first: `items` speaks about every element, so that one is admissible
     * by construction, and where it also matches `contains` the array is the one the shape would have
     * produced anyway. Otherwise the element is built from `contains` itself, and published only where
     * `items` is known to admit it.
     *
     * @param  array<string, mixed>  $schema
     * @param  array{mixed}|null  $item  the element `items` produced, or null where it admits none
     * @param  array<string, mixed>  $components
     * @param  list<string>  $stack
     * @return array{list<mixed>}|null
     */
    private function matching(array $schema, ?array $item, array $components, int $depth, array $stack): ?array
    {
        $atMost = SchemaKeywords::maxContains($schema);

        if (SchemaKeywords::minContains($schema) > 1 || ($atMost !== null && $atMost < 1)) {
            return null;
        }

        $contains = $schema['contains'] ?? null;

        if ($item !== null && $this->admits($contains, $item[0]) === true) {
            return [[$item[0]]];
        }

        $match = $this->subschema($contains, $components, $depth + 1, $stack);

        // A `contains` this factory cannot build a provable match for — `false`, or a constraint it does
        // not check — leaves no array it can publish.
        if ($match === null || $this->admits($contains, $match[0]) !== true) {
            return null;
        }

        // `items` speaks about every element, so the match ships only where it is PROVABLY admitted
        // there — undecidable reads as refusal here exactly as it does at every other position.
        return ! array_key_exists('items', $schema) || $this->admits($schema['items'], $match[0]) === true
            ? [[$match[0]]]
            : null;
    }

    /**
     * Whether `$subschema` admits `$value`: true where every keyword it states is one this factory checks
     * and none refuses, false where one provably refuses, null where it states a keyword this factory
     * does not check.
     *
     * Deliberately three-valued and deliberately shallow. `not` and `contains` both need to know whether
     * ONE value satisfies ONE subschema, and answering that in general is a validator, which this is not
     * — so undecidable is a common answer, and both callers read it the same way they read "refuses":
     * absent, never a value the document forbids.
     */
    private function admits(mixed $subschema, mixed $value): ?bool
    {
        if (is_bool($subschema)) {
            return $subschema;
        }

        // Anything that is no schema at all widens to `{}`, exactly as {@see subschema()} widens it, and
        // the empty schema admits everything.
        if (! is_array($subschema)) {
            return true;
        }

        $decided = true;

        foreach (Arr::stringKeyed($subschema) as $keyword => $constraint) {
            $verdict = $this->keyword($keyword, $constraint, $value);

            if ($verdict === false) {
                return false;
            }

            $decided = $decided && $verdict === true;
        }

        return $decided ? true : null;
    }

    /**
     * ONE keyword's verdict on one value. A schema is a conjunction, so a single refusal settles the
     * whole subschema whatever else stands beside it — which is what makes these three enough for the
     * cases that occur: a value set (`not_in:` writes `not: {enum: […]}`) and a type that cannot be the
     * value's type. Everything else is undecidable, bar a keyword that says nothing about the instance
     * at all ({@see SchemaKeywords::saysNothingAboutTheInstance()}).
     */
    private function keyword(string $keyword, mixed $constraint, mixed $value): ?bool
    {
        return match ($keyword) {
            'type' => $this->typed($constraint, $value),
            'const' => $this->equal($constraint, $value),
            'enum' => $this->listed($constraint, $value),
            default => SchemaKeywords::saysNothingAboutTheInstance($keyword) ? true : null,
        };
    }

    /**
     * Whether the value is one of an `enum`'s members. One member it provably equals settles it; short of
     * that every member has to be provably different, or the answer is that this factory cannot tell.
     */
    private function listed(mixed $constraint, mixed $value): ?bool
    {
        if (! is_array($constraint) || $constraint === []) {
            return null;
        }

        $decided = true;

        foreach ($constraint as $member) {
            $verdict = $this->equal($member, $value);

            if ($verdict === true) {
                return true;
            }

            $decided = $decided && $verdict === false;
        }

        return $decided ? false : null;
    }

    /**
     * Whether the value's JSON type is one `type` names. An integral number is an `integer` in JSON
     * Schema and every integer is also a `number`, so the value's type is matched as the set it belongs
     * to rather than as one word.
     */
    private function typed(mixed $constraint, mixed $value): ?bool
    {
        $named = match (true) {
            is_string($constraint) => [$constraint],
            is_array($constraint) => array_values(array_filter($constraint, is_string(...))),
            default => [],
        };

        $actual = $this->jsonType($value);

        if ($named === [] || $actual === null) {
            return null;
        }

        return array_intersect($actual === 'integer' ? ['integer', 'number'] : [$actual], $named) !== [];
    }

    /**
     * Whether two JSON values are the same instance, or null where this factory will not say. Numbers
     * compare by value, because `1` and `1.0` are one JSON instance. Two composites are never compared:
     * object member order is not an authored fact, and a wrong "different" here publishes a value the
     * schema forbids — while a composite against a scalar is different whatever is inside it.
     */
    private function equal(mixed $a, mixed $b): ?bool
    {
        $composite = static fn (mixed $value): bool => is_array($value) || is_object($value);

        if ($composite($a) || $composite($b)) {
            return $composite($a) && $composite($b) ? null : false;
        }

        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return (float) $a === (float) $b;
        }

        return $a === $b;
    }

    /**
     * The JSON type of a value this factory built. The empty object is a {@see stdClass} here and never
     * `[]` ({@see JsonValue}), so a keyed array is an object and every other array a list.
     *
     * `ParameterValue::enumTypes()` asks the same question of an authored `enum` and answers it
     * differently ON PURPOSE, so neither may be unified into the other: an integral float is an
     * `integer` here because JSON Schema's `type` is what the answer is held against, and a `number`
     * there because a PHP float is what the coercion has to produce; a value no JSON document can hold
     * is `null` here, because a type this cannot name may not be proven to satisfy anything, and
     * `string` there, because that is the reading that converts nothing.
     */
    private function jsonType(mixed $value): ?string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => is_finite($value) && $value === floor($value) ? 'integer' : 'number',
            is_string($value) => 'string',
            is_array($value) => array_is_list($value) ? 'array' : 'object',
            $value instanceof stdClass => 'object',
            default => null,
        };
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
