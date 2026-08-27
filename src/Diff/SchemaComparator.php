<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

use Docuccino\Core\Draft\SchemaKeywords;
use Docuccino\Core\Support\Arr;
use Docuccino\Core\Support\Hydrate;

/**
 * Field-level schema comparison with breaking-change classification. `$ref`s compare opaquely, by
 * target string — the referenced component's own changes are reported once, where that component
 * is diffed by identity — so nothing double-counts and no ref resolution or cycle handling is
 * needed.
 *
 * Breaking: a type narrowed/changed or a constraint added; an enum value removed or an enum
 * introduced; an enum value added to, or the enum constraint dropped from, a *response* schema —
 * a reader (a generated client above all) meets a value it has no case for, or loses a closed set
 * it typed against; a required property added to a *request* schema; a property removed from a
 * *response* schema; a `format` added or changed on a *request* schema (stricter input).
 * Non-breaking: type widened, an enum value added to a request schema (old writers stay valid),
 * required removed, description edits, property added, a property removed from a request schema
 * (the client just stops sending it), a format removed or any format change on a response.
 * `required` on a response/component schema — usage context unknown — is reported but classed
 * non-breaking; that's a judgment call, not an oversight. An enum value removed, and an enum
 * introduced, stay breaking on BOTH sides: each is safe for a pure reader, but a schema's
 * `request` flag can under-state its audience (a shared component serves both directions), and a
 * downgrade there green-lights a change that rejects a writer's previously valid value.
 *
 * An ANNOTATION keyword is the one class of edit that is never any of the above: it says what a value
 * means and nothing about what it may be, so a change to one is reported — a reviewer asking what moved
 * is owed the answer — and is never breaking under any versioning policy. Which keywords those are is
 * {@see SchemaKeywords::isAnnotationOnly()}'s answer and not a list kept here. Nothing here asks whether
 * an example is VALID: `ExampleSchemaLint` and `RecordedExampleAudit` hold every published example to
 * the schema beside it, on every build, with the same validator — the differ classifies the keyword,
 * the audit owns validity.
 *
 * A subschema may be a BOOLEAN, which is where the classification above stops being enough: `true` is
 * the empty schema and reads as one, but `false` is spelled by no set of keywords, so it is compared as
 * itself ({@see compareSubschema()}) and arriving is breaking on both sides — the tightest narrowing the
 * language has (docs/design/uir-and-extensions.md §1 "The empty-object invariant").
 *
 * Three positions are descended into: `properties`, `items` and `additionalProperties`, which are the
 * object and array contract a generated client types against, and which share a polarity — narrowing
 * the subschema narrows the schema carrying it, so one classification serves all three. The
 * composition and conditional keywords are deliberately not read here and are their own piece of work:
 * their polarity DIFFERS by keyword, so reusing this classification would report the direction
 * backwards. Widening the subschema under `not` narrows the parent; a branch added to `anyOf` widens
 * where the same branch added to `allOf` narrows; and pairing list members needs an identity rule, or
 * reordering `oneOf` reads as rewriting every branch. A differ that is silent there is worse than one
 * that speaks, but not as bad as one that speaks and is wrong.
 */
final class SchemaComparator
{
    /**
     * Two Schema Objects at one position. `mixed`, because a Schema Object may be a BOOLEAN at every
     * position OpenAPI puts one — a media type's `schema` as much as an `items` inside it.
     *
     * @return list<Change>
     */
    public function compare(mixed $old, mixed $new, string $path, string $id, bool $request): array
    {
        return $this->compareSubschema($old, $new, $path, $id, $request);
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return list<Change>
     */
    private function compareKeywords(array $old, array $new, string $path, string $id, bool $request): array
    {
        $changes = [];

        $this->compareRef($old, $new, $path, $id, $changes);
        $this->compareAnnotations($old, $new, $path, $id, $changes);
        $this->compareType($old, $new, $path, $id, $changes);
        $this->compareFormat($old, $new, $path, $id, $request, $changes);
        $this->compareEnum($old, $new, $path, $id, $request, $changes);
        $this->compareRequired($old, $new, $path, $id, $request, $changes);
        $this->compareProperties($old, $new, $path, $id, $request, $changes);
        $this->compareItems($old, $new, $path, $id, $request, $changes);
        $this->compareAdditionalProperties($old, $new, $path, $id, $request, $changes);

        return $changes;
    }

    /**
     * Two subschemas at one position, either of which may be a boolean. A boolean IS a schema, and at
     * these positions it is the most load-bearing value there is: `true` is the empty schema and
     * normalises to one, so every comparison above reads it correctly, while `false` is satisfied by
     * nothing and is the one value compared as itself.
     *
     * An ABSENT subschema reads as the empty one, because that is what it means at all three positions
     * — no `items` constrains no element, and `additionalProperties` defaults to `true`. So is a value
     * that is no schema at all, which is the widening the canonicaliser publishes for it.
     *
     * @return list<Change>
     */
    private function compareSubschema(mixed $old, mixed $new, string $path, string $id, bool $request): array
    {
        $wasNothing = $old === false;
        $isNothing = $new === false;

        if ($wasNothing === $isNothing) {
            // Two schemas that admit nothing are the same schema, whatever else either says.
            return $isNothing ? [] : $this->compareKeywords(self::asSchema($old), self::asSchema($new), $path, $id, $request);
        }

        // Breaking on BOTH sides when it arrives: a request now rejects every value a writer used to
        // send, and a response can no longer carry the value a reader was reading. Going is the exact
        // inverse — nothing was valid, now something is — and widens like any dropped constraint.
        return [$isNothing
            ? $this->change(ChangeKind::Changed, $id, $path, true, 'schema.always-invalid-added', null, null, null)
            : $this->change(ChangeKind::Changed, $id, $path, false, 'schema.always-invalid-removed', null, null, null)];
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
     * Every annotation-only keyword either side carries, compared by the fingerprint {@see valueKey()}
     * rather than by `===`: two `stdClass` standing for one JSON object are never the identical one.
     * Non-breaking by construction, and the only place in this class where that is not a judgment about
     * the two values.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<Change>  $changes
     */
    private function compareAnnotations(array $old, array $new, string $path, string $id, array &$changes): void
    {
        foreach (Arr::sortedUnion(array_keys($old), array_keys($new)) as $keyword) {
            if (! SchemaKeywords::isAnnotationOnly($keyword)) {
                continue;
            }

            $before = $old[$keyword] ?? null;
            $after = $new[$keyword] ?? null;

            if (self::valueKey($before) === self::valueKey($after)) {
                continue;
            }

            $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.'.$keyword, false, 'schema.annotation-changed', $keyword, $before, $after);
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

        // On a request, a format added or changed tightens what the API accepts. Removing one
        // widens; on a response format is only descriptive.
        $breaking = $request && $newFormat !== null;

        $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.format', $breaking, 'schema.format-changed', 'format', $oldFormat, $newFormat);
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<Change>  $changes
     */
    private function compareEnum(array $old, array $new, string $path, string $id, bool $request, array &$changes): void
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
            // Dropping the constraint widens what a request accepts; on a response it turns a
            // closed set a reader typed against into anything at all.
            $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.enum', ! $request, 'schema.enum-removed', 'enum', $oldEnum, null);

            return;
        }

        $removed = self::valueDiff($oldEnum, $newEnum);
        $added = self::valueDiff($newEnum, $oldEnum);

        if ($removed !== []) {
            $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.enum', true, 'schema.enum-value-removed', 'enum', $oldEnum, $newEnum);

            return;
        }

        if ($added !== []) {
            // A new value widens what a request accepts; a response can now return something no
            // existing reader has a case for, and a strongly-typed generated client fails outright.
            $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.enum', ! $request, 'schema.enum-value-added', 'enum', $oldEnum, $newEnum);
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
            $oldProperty = $oldProps[$name] ?? null;
            $newProperty = $newProps[$name] ?? null;

            // A property declared `false` is FORBIDDEN, not declared — so it appearing or disappearing
            // is a constraint changing rather than a property changing, and never the `property-added`
            // a consumer can start reading. Reported as the narrowing it is, at either end.
            if ($oldProperty === false || $newProperty === false) {
                foreach ($this->compareSubschema($oldProperty, $newProperty, $propPath, $id, $request) as $child) {
                    $changes[] = $child;
                }

                continue;
            }

            // `array_key_exists`, not `isset`: a property declared null publishes as the empty schema,
            // and reading it as a missing member reported the property removed when it was still there.
            if (! array_key_exists($name, $newProps)) {
                // Losing a response property breaks whoever read it; on a request the client
                // just stops sending it.
                $changes[] = $this->change(ChangeKind::Removed, $id, $propPath, ! $request, 'schema.property-removed', null, null, null);

                continue;
            }

            if (! array_key_exists($name, $oldProps)) {
                $changes[] = $this->change(ChangeKind::Added, $id, $propPath, false, 'schema.property-added', null, null, null);

                continue;
            }

            foreach ($this->compareSubschema($oldProperty, $newProperty, $propPath, $id, $request) as $child) {
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
        if (! array_key_exists('items', $old) && ! array_key_exists('items', $new)) {
            return;
        }

        foreach ($this->compareSubschema($old['items'] ?? null, $new['items'] ?? null, $path.'.items', $id, $request) as $child) {
            $changes[] = $child;
        }
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<Change>  $changes
     */
    private function compareAdditionalProperties(array $old, array $new, string $path, string $id, bool $request, array &$changes): void
    {
        if (! array_key_exists('additionalProperties', $old) && ! array_key_exists('additionalProperties', $new)) {
            return;
        }

        $child = $path.'.additionalProperties';

        foreach ($this->compareSubschema($old['additionalProperties'] ?? null, $new['additionalProperties'] ?? null, $child, $id, $request) as $change) {
            $changes[] = $change;
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
     * The declared properties, each RAW: what a member is worth is {@see compareSubschema()}'s question,
     * and a reader that keeps only the members it recognises as maps drops the rest — a comparison that
     * never sees a member reports it added or removed, which is how a property declared `false` read as
     * a property that had gone.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function properties(array $schema): array
    {
        // Through `mapOrNull`, not `is_array`: `properties: {}` — no declared properties, which is what
        // an object of unknown shape publishes — arrives as a stdClass from any faithful read of an
        // artifact, and a null here would have every property read as added.
        return Hydrate::mapOrNull($schema['properties'] ?? null) ?? [];
    }

    /**
     * One subschema as the keyword map the comparisons want. `true`, an absent member and anything that
     * is no schema at all are the empty schema, which is what each of them means and publishes.
     *
     * @return array<string, mixed>
     */
    private static function asSchema(mixed $value): array
    {
        return Hydrate::mapOrNull($value) ?? [];
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

    /**
     * A value's identity, as its JSON text — which is what makes `{}` and `[]` two values and two
     * `stdClass` standing for one JSON object one. Where JSON cannot spell it at all (a string that is
     * not valid UTF-8, an `INF`, a `NAN`) `serialize()` answers instead, faithfully: the fallback was
     * `gettype()`, under which every un-encodable value shared one key, so a removed enum value read as
     * still present and the breaking change went unreported. The prefixes keep the two spaces apart.
     */
    private static function valueKey(mixed $value): string
    {
        $encoded = json_encode($value);

        return $encoded === false ? 'php:'.serialize($value) : 'json:'.$encoded;
    }
}
