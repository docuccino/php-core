<?php

declare(strict_types=1);

namespace Docuccino\Core\Draft;

use Docuccino\Core\Support\Arr;

/**
 * {@see DescriptionNotes} for a schema still held as an array: sentences stating a fact no keyword can
 * carry ("Required when …", "Must be a date after …"), kept beside the schema's `description` rather
 * than inside it, so prose written over the description later replaces the prose and not the facts.
 *
 * They ride under `x-docuccino` until the schema is published, which renders them beneath whatever
 * description stands, joined into one paragraph — a component when it is registered, an operation's
 * body when the operation freezes. A writer handing a schema to a draft one keyword at a time takes
 * the notes off first ({@see take()}) and declares them on that draft.
 */
final class SchemaNotes
{
    private const string MEMBER = 'notes';

    /**
     * `$schema` carrying `$notes` beneath its description, after any it already carried.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $notes
     * @return array<string, mixed>
     */
    public static function onto(array $schema, array $notes): array
    {
        if ($notes === []) {
            return $schema;
        }

        $extension = is_array($schema['x-docuccino'] ?? null) ? $schema['x-docuccino'] : [];
        $extension[self::MEMBER] = [...self::of($schema), ...$notes];
        $schema['x-docuccino'] = $extension;

        return $schema;
    }

    /**
     * The notes `$schema` itself carries, in the order they were stated.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    public static function of(array $schema): array
    {
        $extension = $schema['x-docuccino'] ?? null;
        $notes = is_array($extension) ? ($extension[self::MEMBER] ?? null) : null;

        return is_array($notes) ? array_values(array_filter($notes, is_string(...))) : [];
    }

    /**
     * `$schema` without the notes it carries itself, everything else where it stood.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function without(array $schema): array
    {
        $extension = $schema['x-docuccino'] ?? null;
        if (! is_array($extension) || ! array_key_exists(self::MEMBER, $extension)) {
            return $schema;
        }

        unset($extension[self::MEMBER]);
        if ($extension === []) {
            unset($schema['x-docuccino']);
        } else {
            $schema['x-docuccino'] = $extension;
        }

        return $schema;
    }

    /**
     * `$schema` without its own notes, and those notes as the one paragraph they publish as — null where
     * it carries none. Notes deeper in it are rendered where they stand, since only this level's are
     * handed back.
     *
     * @param  array<array-key, mixed>  $schema
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    public static function take(array $schema): array
    {
        // A schema's members are named, whatever a decoded array's keys say.
        return self::taken(Arr::stringKeyed($schema));
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private static function taken(array $schema): array
    {
        $notes = self::of($schema);
        $schema = self::without($schema);

        foreach ($schema as $keyword => $value) {
            if (! is_array($value)) {
                continue;
            }

            $schema[$keyword] = match (SchemaKeywords::positionOf($keyword)) {
                SchemaKeywords::POSITION_SCHEMA => self::renderedNode($value),
                SchemaKeywords::POSITION_SCHEMA_MAP, SchemaKeywords::POSITION_SCHEMA_LIST => array_map(self::renderedNode(...), $value),
                default => $value,
            };
        }

        return [$schema, $notes === [] ? null : implode(' ', $notes)];
    }

    /**
     * `$schema` with the notes at every depth published beneath their descriptions. Where nothing wrote
     * a description the notes are the whole of it, and stand where they were carried.
     *
     * @param  array<array-key, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function rendered(array $schema): array
    {
        $schema = Arr::stringKeyed($schema);
        [$taken, $paragraph] = self::taken($schema);

        if ($paragraph === null) {
            return $taken;
        }

        if (array_key_exists('description', $taken)) {
            $description = $taken['description'];
            $taken['description'] = DescriptionAppender::joined(is_string($description) ? $description : null, $paragraph);

            return $taken;
        }

        $out = [];
        foreach (array_keys($schema) as $keyword) {
            if ($keyword === 'x-docuccino') {
                $out['description'] = $paragraph;
            }
            if (array_key_exists($keyword, $taken)) {
                $out[$keyword] = $taken[$keyword];
            }
        }

        return $out;
    }

    /** A subschema rendered; a boolean schema, or anything else, as it stands. */
    private static function renderedNode(mixed $node): mixed
    {
        return is_array($node) ? self::rendered($node) : $node;
    }
}
