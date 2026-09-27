<?php

declare(strict_types=1);

namespace Docuccino\Core\Document;

use Docuccino\Core\Draft\SchemaKeywords;
use stdClass;

/**
 * How a walk over a whole document reads a member: as DATA, which holds no node at all; as a map of
 * NAMES, where every key is a name rather than a keyword; or as a node whose keys are keywords. A walk
 * that reads members here never answers by the name of the key holding a node — `properties.default` is
 * a property and `responses.default` a Response Object, not a literal. `docs/design/defect-classes.md`
 * names the walks that read it.
 *
 * @internal
 */
final class DocumentMembers
{
    /**
     * Keyword-position members whose value is arbitrary data. `default`, `const` and `enum` are a Schema
     * Object's literals; `example`, and an Example Object's `value` and `dataValue`, are what an author
     * wrote for a consumer to copy.
     */
    private const array LITERALS = ['example', 'default', 'const', 'enum', 'value', 'dataValue'];

    /**
     * OpenAPI's members whose value is a map of names, as a union across versions and positions: one that
     * is a LIST where it appears (`parameters` in an operation) is walked as a list, whose items are nodes.
     */
    private const array OPENAPI_NAME_MAPS = [
        'additionalOperations', 'callbacks', 'content', 'encoding', 'examples', 'headers', 'links', 'mapping',
        'mediaTypes', 'parameters', 'paths', 'pathItems', 'requestBodies', 'responses', 'schemas',
        'scopes', 'securitySchemes', 'variables', 'webhooks',
    ];

    /**
     * The name maps whose Object also admits `x-` extensions beside the names. Read by key alone, so
     * `components.responses`, which admits none, is read like an operation's: an `x-` component
     * response is skipped, never misread.
     */
    private const array EXTENSIBLE_NAME_MAPS = ['paths', 'responses'];

    /**
     * Whether the member $key holds data rather than nodes, read inside the name map $inNameMap (null at
     * a keyword position). A Schema Object's `examples` is a list of literals and a Media Type Object's a
     * map of Example Objects, so the value's own kind tells them apart.
     */
    public static function holdsData(string $key, mixed $value, ?string $inNameMap): bool
    {
        if ($inNameMap !== null) {
            return str_starts_with($key, 'x-') && in_array($inNameMap, self::EXTENSIBLE_NAME_MAPS, true);
        }

        return in_array($key, self::LITERALS, true)
            || str_starts_with($key, 'x-')
            || ($key === 'examples' && ! $value instanceof stdClass && ! (is_array($value) && ! array_is_list($value)));
    }

    /** The name map $key's value opens — $key itself — or null where its keys are keywords. */
    public static function nameMap(string $key, ?string $inNameMap): ?string
    {
        if ($inNameMap !== null) {
            return null;
        }

        $position = SchemaKeywords::positionOf($key);

        return in_array($key, self::OPENAPI_NAME_MAPS, true)
            || $position === SchemaKeywords::POSITION_SCHEMA_MAP
            || $position === SchemaKeywords::POSITION_STRING_LIST_MAP
            ? $key : null;
    }
}
