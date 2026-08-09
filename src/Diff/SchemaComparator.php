<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

use Docuccino\Core\Support\Arr;
use Docuccino\Core\Support\Hydrate;

/**
 * Field-level schema comparison with breaking-change classification (design/plan breaking
 * rules). `$ref`s are compared opaquely (by target string) — a referenced component schema's
 * internal changes are reported once, where that component is diffed by identity — so nothing
 * is double-counted and no ref resolution or cycle handling is needed.
 *
 * Breaking here: a type narrowed/changed or constraint added; an enum value removed or an enum
 * constraint introduced; a required property added to a *request* schema; a property removed from a
 * *response* schema (a field consumers relied on receiving vanishes); a `format` tightened on a
 * *request* schema (added or changed — stricter input validation). Non-breaking: type widened, enum
 * value added, required removed, description edits, property added, a property removed from a request
 * schema (the client simply stops sending it), a format removed or any format change on a response.
 * `required` on non-request schemas (response/component, usage context unknown) is reported but
 * classed non-breaking — a documented judgment call.
 */
final class SchemaComparator
{
    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return list<Change>
     */
    public function compare(array $old, array $new, string $path, string $id, bool $request): array
    {
        $changes = [];

        $this->compareRef($old, $new, $path, $id, $changes);
        $this->compareType($old, $new, $path, $id, $changes);
        $this->compareFormat($old, $new, $path, $id, $request, $changes);
        $this->compareEnum($old, $new, $path, $id, $changes);
        $this->compareRequired($old, $new, $path, $id, $request, $changes);
        $this->compareProperties($old, $new, $path, $id, $request, $changes);
        $this->compareItems($old, $new, $path, $id, $request, $changes);

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<Change>  $changes
     */
    private function compareRef(array $old, array $new, string $path, string $id, array &$changes): void
    {
        $oldRef = Hydrate::stringOrNull($old['$ref'] ?? null);
        $newRef = Hydrate::stringOrNull($new['$ref'] ?? null);

        if ($oldRef !== null && $newRef !== null && $oldRef !== $newRef) {
            $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.$ref', false, 'schema.ref-changed', '$ref', $oldRef, $newRef);
        }
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<Change>  $changes
     */
    private function compareType(array $old, array $new, string $path, string $id, array &$changes): void
    {
        $oldSet = self::typeSet($old);
        $newSet = self::typeSet($new);

        if ($oldSet === $newSet) {
            return;
        }

        $oldValue = $old['type'] ?? null;
        $newValue = $new['type'] ?? null;

        if ($oldSet === []) {
            $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.type', true, 'schema.type-added', 'type', $oldValue, $newValue);

            return;
        }

        if ($newSet === []) {
            $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.type', false, 'schema.type-removed', 'type', $oldValue, $newValue);

            return;
        }

        if (self::isSuperset($newSet, $oldSet)) {
            $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.type', false, 'schema.type-widened', 'type', $oldValue, $newValue);

            return;
        }

        $code = self::isSuperset($oldSet, $newSet) ? 'schema.type-narrowed' : 'schema.type-changed';
        $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.type', true, $code, 'type', $oldValue, $newValue);
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<Change>  $changes
     */
    private function compareFormat(array $old, array $new, string $path, string $id, bool $request, array &$changes): void
    {
        $oldFormat = $old['format'] ?? null;
        $newFormat = $new['format'] ?? null;

        if ($oldFormat === $newFormat) {
            return;
        }

        // A format added or changed on a request schema tightens the values the API will accept —
        // breaking. Removing a format widens (non-breaking); on a response, format is descriptive.
        $breaking = $request && $newFormat !== null;

        $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.format', $breaking, 'schema.format-changed', 'format', $oldFormat, $newFormat);
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<Change>  $changes
     */
    private function compareEnum(array $old, array $new, string $path, string $id, array &$changes): void
    {
        $hasOld = array_key_exists('enum', $old) && is_array($old['enum']);
        $hasNew = array_key_exists('enum', $new) && is_array($new['enum']);

        if (! $hasOld && ! $hasNew) {
            return;
        }

        $oldEnum = $hasOld ? array_values($old['enum']) : [];
        $newEnum = $hasNew ? array_values($new['enum']) : [];

        if (! $hasOld) {
            $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.enum', true, 'schema.enum-added', 'enum', null, $newEnum);

            return;
        }

        if (! $hasNew) {
            $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.enum', false, 'schema.enum-removed', 'enum', $oldEnum, null);

            return;
        }

        $removed = self::valueDiff($oldEnum, $newEnum);
        $added = self::valueDiff($newEnum, $oldEnum);

        if ($removed !== []) {
            $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.enum', true, 'schema.enum-value-removed', 'enum', $oldEnum, $newEnum);

            return;
        }

        if ($added !== []) {
            $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.enum', false, 'schema.enum-value-added', 'enum', $oldEnum, $newEnum);
        }
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<Change>  $changes
     */
    private function compareRequired(array $old, array $new, string $path, string $id, bool $request, array &$changes): void
    {
        $oldRequired = Hydrate::stringList($old['required'] ?? null);
        $newRequired = Hydrate::stringList($new['required'] ?? null);

        $added = array_values(array_diff($newRequired, $oldRequired));
        $removed = array_values(array_diff($oldRequired, $newRequired));

        if ($added !== []) {
            $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.required', $request, 'schema.required-added', 'required', $oldRequired, $newRequired);
        }

        if ($removed !== []) {
            $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.required', false, 'schema.required-removed', 'required', $oldRequired, $newRequired);
        }
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<Change>  $changes
     */
    private function compareProperties(array $old, array $new, string $path, string $id, bool $request, array &$changes): void
    {
        $oldProps = self::properties($old);
        $newProps = self::properties($new);

        $names = Arr::sortedUnion(array_keys($oldProps), array_keys($newProps));

        foreach ($names as $name) {
            $propPath = $path.'.properties.'.$name;

            if (! isset($newProps[$name])) {
                // Removing a property a response used to return breaks consumers reading it; on a
                // request schema the client simply stops sending it (non-breaking).
                $changes[] = $this->change(ChangeKind::Removed, $id, $propPath, ! $request, 'schema.property-removed', null, null, null);

                continue;
            }

            if (! isset($oldProps[$name])) {
                $changes[] = $this->change(ChangeKind::Added, $id, $propPath, false, 'schema.property-added', null, null, null);

                continue;
            }

            foreach ($this->compare($oldProps[$name], $newProps[$name], $propPath, $id, $request) as $child) {
                $changes[] = $child;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<Change>  $changes
     */
    private function compareItems(array $old, array $new, string $path, string $id, bool $request, array &$changes): void
    {
        $oldItems = $old['items'] ?? null;
        $newItems = $new['items'] ?? null;

        if (is_array($oldItems) && is_array($newItems)) {
            foreach ($this->compare(Arr::stringKeyed($oldItems), Arr::stringKeyed($newItems), $path.'.items', $id, $request) as $child) {
                $changes[] = $child;
            }
        }
    }

    private function change(ChangeKind $kind, string $id, string $path, bool $breaking, string $code, ?string $field, mixed $old, mixed $new): Change
    {
        $fields = $field === null ? [] : [new FieldChange($field, $old, $new)];

        return new Change($kind, ChangeTarget::Schema, $id, $path, $breaking, $code, $fields);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, true>
     */
    private static function typeSet(array $schema): array
    {
        $type = $schema['type'] ?? null;

        $set = [];
        if (is_string($type)) {
            $set[$type] = true;
        } elseif (is_array($type)) {
            foreach ($type as $item) {
                if (is_string($item)) {
                    $set[$item] = true;
                }
            }
        }

        return $set;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, array<string, mixed>>
     */
    private static function properties(array $schema): array
    {
        $properties = $schema['properties'] ?? null;

        if (! is_array($properties)) {
            return [];
        }

        $out = [];
        foreach ($properties as $name => $value) {
            if (is_array($value)) {
                $out[(string) $name] = Arr::stringKeyed($value);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, true>  $superset
     * @param  array<string, true>  $subset
     */
    private static function isSuperset(array $superset, array $subset): bool
    {
        foreach ($subset as $key => $_) {
            if (! isset($superset[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<mixed>  $from
     * @param  list<mixed>  $against
     * @return list<mixed>
     */
    private static function valueDiff(array $from, array $against): array
    {
        $againstKeys = [];
        foreach ($against as $value) {
            $againstKeys[self::valueKey($value)] = true;
        }

        $out = [];
        foreach ($from as $value) {
            if (! isset($againstKeys[self::valueKey($value)])) {
                $out[] = $value;
            }
        }

        return $out;
    }

    private static function valueKey(mixed $value): string
    {
        $encoded = json_encode($value);

        return $encoded === false ? gettype($value) : $encoded;
    }
}
