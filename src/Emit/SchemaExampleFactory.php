<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

use Docuccino\Core\Support\Arr;
use Docuccino\Core\Support\FormatSamples;
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
 * An empty object comes back as {@see stdClass}, not `[]`. The value is serialised into a JSON string
 * for the collection, and an empty PHP array would render as `[]` — a body that lies about its shape.
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
        if ($depth > self::MAX_DEPTH) {
            return $this->empty($schema);
        }

        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            return $this->fromRef($schema['$ref'], $components, $depth, $stack);
        }

        $stated = $this->stated($schema);
        if ($stated !== null) {
            return $stated[0];
        }

        $composed = $this->composed($schema, $components, $depth, $stack);
        if ($composed !== null) {
            return $composed[0];
        }

        return $this->byType($schema, $components, $depth, $stack);
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
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $components
     * @param  list<string>  $stack
     * @return array{mixed}|null
     */
    private function composed(array $schema, array $components, int $depth, array $stack): ?array
    {
        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            $merged = [];
            foreach ($schema['allOf'] as $branch) {
                $value = is_array($branch) ? $this->value(Arr::stringKeyed($branch), $components, $depth, $stack) : null;

                // Objects compose; a scalar branch simply replaces what came before it.
                if (is_array($value) || $value instanceof stdClass) {
                    $merged = array_merge($merged, (array) $value);

                    continue;
                }

                if ($value !== null) {
                    return [$value];
                }
            }

            return [$merged === [] ? new stdClass : $merged];
        }

        foreach (['oneOf', 'anyOf'] as $keyword) {
            // Branch 0: the list is authored, and it is the branch every other reader shows.
            if (isset($schema[$keyword]) && is_array($schema[$keyword]) && is_array($schema[$keyword][0] ?? null)) {
                return [$this->value(Arr::stringKeyed($schema[$keyword][0]), $components, $depth, $stack)];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $components
     * @param  list<string>  $stack
     */
    private function fromRef(string $ref, array $components, int $depth, array $stack): mixed
    {
        // A pointer already on the stack is a cycle: return the empty shape rather than recursing.
        if (in_array($ref, $stack, true)) {
            return new stdClass;
        }

        $resolved = $this->resolve($ref, $components);

        return $resolved === null
            ? new stdClass
            : $this->value($resolved, $components, $depth + 1, [...$stack, $ref]);
    }

    /**
     * @param  array<string, mixed>  $components
     * @return array<string, mixed>|null
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

        /** @var array<string, mixed>|null $out */
        $out = is_array($cursor) ? $cursor : null;

        return $out;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $components
     * @param  list<string>  $stack
     */
    private function byType(array $schema, array $components, int $depth, array $stack): mixed
    {
        return match ($this->type($schema)) {
            'object' => $this->object($schema, $components, $depth, $stack),
            'array' => $this->list($schema, $components, $depth, $stack),
            'string' => $this->string($schema),
            'integer', 'number' => $this->number($schema),
            'boolean' => true,
            'null' => null,
            default => null,
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
     * the whole shape, and hiding the optional half hides the contract.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $components
     * @param  list<string>  $stack
     */
    private function object(array $schema, array $components, int $depth, array $stack): mixed
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        $keys = array_map(strval(...), array_keys($properties));
        sort($keys, SORT_STRING);

        $out = [];
        foreach ($keys as $key) {
            $property = $properties[$key] ?? null;
            $out[$key] = is_array($property) ? $this->value(Arr::stringKeyed($property), $components, $depth + 1, $stack) : null;
        }

        return $out === [] ? new stdClass : $out;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $components
     * @param  list<string>  $stack
     * @return list<mixed>
     */
    private function list(array $schema, array $components, int $depth, array $stack): array
    {
        // Exactly one item, whatever `minItems` says: an example is a shape, not a load test.
        return is_array($schema['items'] ?? null)
            ? [$this->value(Arr::stringKeyed($schema['items']), $components, $depth + 1, $stack)]
            : [];
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
