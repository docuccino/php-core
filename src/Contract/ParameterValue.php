<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

use stdClass;

/**
 * Query, path, header and cookie values arrive as strings whatever the contract says they are, so
 * checking `?page=2` against `type: integer` needs the string read back as the type it stands for.
 *
 * Only a string that unambiguously IS the documented type is converted. `?page=abc` against
 * `type: integer` stays a string, so the failure reads "must match the type: integer" — the real
 * problem — instead of `abc` silently becoming `0`.
 *
 * @internal
 */
final class ParameterValue
{
    /**
     * @param  array<string, mixed>|null  $schema
     */
    public static function coerce(mixed $value, ?array $schema): mixed
    {
        $types = self::types($schema);

        if (is_string($value)) {
            $value = self::fromString($value, $types, $schema);
        }

        if (is_array($value)) {
            return self::fromArray($value, $types, $schema);
        }

        return $value;
    }

    /**
     * @param  list<string>  $types
     * @param  array<string, mixed>|null  $schema
     */
    private static function fromString(string $value, array $types, ?array $schema): mixed
    {
        if (in_array('integer', $types, true) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        if (in_array('number', $types, true) && is_numeric($value)) {
            return (float) $value;
        }

        if (in_array('boolean', $types, true) && in_array($value, ['true', 'false', '1', '0'], true)) {
            return $value === 'true' || $value === '1';
        }

        // `?sort=name,-created_at` is the comma list representation the generator documents by default.
        if (in_array('array', $types, true)) {
            return array_map(
                static fn (string $item): mixed => self::coerce($item, self::items($schema)),
                explode(',', $value),
            );
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $types
     * @param  array<string, mixed>|null  $schema
     */
    private static function fromArray(array $value, array $types, ?array $schema): mixed
    {
        if (array_is_list($value)) {
            $items = self::items($schema);

            return array_map(static fn (mixed $item): mixed => self::coerce($item, $items), $value);
        }

        // A bracketed query parameter (`filter[status]=paid`) arrives as a map: an object to JSON Schema.
        $properties = self::properties($schema);
        $object = new stdClass;

        foreach ($value as $key => $item) {
            $object->{(string) $key} = self::coerce($item, $properties[(string) $key] ?? null);
        }

        return in_array('array', $types, true) ? array_values(get_object_vars($object)) : $object;
    }

    /**
     * @param  array<string, mixed>|null  $schema
     * @return list<string>
     */
    private static function types(?array $schema): array
    {
        $type = $schema['type'] ?? null;

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
     * @param  array<string, mixed>|null  $schema
     * @return array<string, mixed>|null
     */
    private static function items(?array $schema): ?array
    {
        $items = $schema['items'] ?? null;

        /** @var array<string, mixed>|null */
        return is_array($items) ? $items : null;
    }

    /**
     * @param  array<string, mixed>|null  $schema
     * @return array<string, array<string, mixed>>
     */
    private static function properties(?array $schema): array
    {
        $properties = $schema['properties'] ?? null;

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
