<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\PathItem;
use Docuccino\Core\Draft\SchemaKeywords;
use Docuccino\Core\Support\Arr;
use Docuccino\Core\Support\JsonPointer;
use stdClass;

/**
 * A document read at OpenAPI 3.0, restated in the meaning 3.1 and 3.2 give their own spellings, so an
 * export labelled with a newer version says what the 3.0 source said. Every emitter chains off the 3.2
 * one, which runs this first, so the 3.0 downlevel reads the same restatement back and lands where the
 * source started — silently, since a construct that comes back as written changed nothing a reader sees.
 *
 * The 3.0.4 text is the reading: 3.0.x are editorial revisions of one spec. Where its reading and the
 * one 3.0 code generators act on disagree — a `nullable` with no `type` beside it — the wider of the two
 * is published, and said; a 3.0 artifact keeps the source's own spelling, which carries both. A reader
 * that holds values to a 3.0 document reads the same restatement ({@see restate()}).
 *
 * @internal
 */
final class OpenApi30Upgrade
{
    private const string DOCUMENT = 'document';

    private const string COMPONENTS = 'components';

    private const string PATH_ITEM = 'path-item';

    private const string OPERATION = 'operation';

    private const string PARAMETER = 'parameter';

    private const string HEADER = 'header';

    private const string REQUEST_BODY = 'request-body';

    private const string RESPONSE = 'response';

    private const string MEDIA_TYPE = 'media-type';

    private const string ENCODING = 'encoding';

    /** A Callback Object: a map of runtime expressions to Path Items. */
    private const string CALLBACK = 'callback';

    private const string SCHEMA = 'schema';

    /** An Example, Link or Security Scheme Object: no schema inside, but it may be a reference. */
    private const string LEAF = 'leaf';

    /** One child object. */
    private const string ONE = 'one';

    /** A list of child objects. */
    private const string LIST = 'list';

    /** A map, keyed by names the application chose, of child objects. */
    private const string MAP = 'map';

    /** The same, in an Object that also admits `x-` extensions beside the names. */
    private const string EXTENSIBLE_MAP = 'extensible-map';

    /**
     * What each member of each object IS, so the walk reaches every position a Schema Object or a
     * Reference Object can stand at. A Path Item's operations are read off {@see PathItem::METHODS}.
     *
     * @var array<string, array<string, array{string, string}>>
     */
    private const array CHILDREN = [
        self::DOCUMENT => [
            'paths' => [self::EXTENSIBLE_MAP, self::PATH_ITEM],
            'webhooks' => [self::MAP, self::PATH_ITEM],
            'components' => [self::ONE, self::COMPONENTS],
        ],
        self::COMPONENTS => [
            'schemas' => [self::MAP, self::SCHEMA],
            'responses' => [self::MAP, self::RESPONSE],
            'parameters' => [self::MAP, self::PARAMETER],
            'examples' => [self::MAP, self::LEAF],
            'requestBodies' => [self::MAP, self::REQUEST_BODY],
            'headers' => [self::MAP, self::HEADER],
            'securitySchemes' => [self::MAP, self::LEAF],
            'links' => [self::MAP, self::LEAF],
            'callbacks' => [self::MAP, self::CALLBACK],
            'pathItems' => [self::MAP, self::PATH_ITEM],
        ],
        self::PATH_ITEM => [
            'parameters' => [self::LIST, self::PARAMETER],
        ],
        self::OPERATION => [
            'parameters' => [self::LIST, self::PARAMETER],
            'requestBody' => [self::ONE, self::REQUEST_BODY],
            'responses' => [self::EXTENSIBLE_MAP, self::RESPONSE],
            'callbacks' => [self::MAP, self::CALLBACK],
        ],
        self::REQUEST_BODY => [
            'content' => [self::MAP, self::MEDIA_TYPE],
        ],
        self::RESPONSE => [
            'headers' => [self::MAP, self::HEADER],
            'content' => [self::MAP, self::MEDIA_TYPE],
            'links' => [self::MAP, self::LEAF],
        ],
        self::PARAMETER => [
            'schema' => [self::ONE, self::SCHEMA],
            'content' => [self::MAP, self::MEDIA_TYPE],
            'examples' => [self::MAP, self::LEAF],
        ],
        self::HEADER => [
            'schema' => [self::ONE, self::SCHEMA],
            'content' => [self::MAP, self::MEDIA_TYPE],
            'examples' => [self::MAP, self::LEAF],
        ],
        self::MEDIA_TYPE => [
            'schema' => [self::ONE, self::SCHEMA],
            'encoding' => [self::MAP, self::ENCODING],
            'examples' => [self::MAP, self::LEAF],
        ],
        self::ENCODING => [
            'headers' => [self::MAP, self::HEADER],
        ],
    ];

    /** The objects 3.0 lets a Reference Object stand in for, bar the Schema Object, which takes its own answer. */
    private const array REFERABLE = [self::PARAMETER, self::HEADER, self::REQUEST_BODY, self::RESPONSE, self::CALLBACK, self::LEAF];

    /** What 3.1 lets a Reference Object say beside its `$ref`, overriding the component's own wording. */
    private const array REFERENCE_PROSE = ['summary', 'description'];

    /** @var array<string, bool> each schema {@see untypedNullable()} wrapped, by pointer: true where its branch is a lone `allOf` member */
    private array $wrapped = [];

    /**
     * @param  bool  $for30  whether the restatement is headed for a 3.0 artifact, which spells a no-type `nullable` itself
     * @param  bool  $respell  whether what 3.0 says is spelled as 3.1 does, or only what 3.0 ignores dropped
     */
    private function __construct(private readonly bool $for30, private readonly bool $respell = true) {}

    /** Whether a document stating this `openapi` version is read as 3.0. */
    public static function reads30(string $openapi): bool
    {
        return preg_match('/^3\.0(\.|$)/', $openapi) === 1;
    }

    /**
     * Whether a document's own `openapi` member is read as 3.0.
     *
     * @param  array<string, mixed>  $document
     */
    public static function states30(array $document): bool
    {
        $openapi = $document['openapi'] ?? null;

        return is_string($openapi) && self::reads30($openapi);
    }

    /**
     * $document restated in 3.1's meaning where it states a 3.0 version, and as it is otherwise. `$for30`
     * says the restatement will be read back into a 3.0 artifact, where a no-type `nullable` keeps its own
     * spelling: the 3.0 document says exactly what the source did, so there is no reading to choose.
     *
     * @param  array<string, mixed>  $document
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    public static function upgrade(array $document, array &$diagnostics, bool $for30 = false): array
    {
        if (! self::states30($document)) {
            return $document;
        }

        return (new self($for30))->walkObject(self::DOCUMENT, $document, '#', $diagnostics);
    }

    /**
     * $document with what 3.0 ignores dropped where it is read as 3.0 — a `$ref`'s siblings, a Reference
     * Object's prose, and an exclusive bound or a `nullable` saying nothing — and every other member as
     * written: what its 3.0 export says, without the round trip through 3.1 that spells it.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function stripped(array $document): array
    {
        if (! self::states30($document)) {
            return $document;
        }

        $diagnostics = [];

        return (new self(true, respell: false))->walkObject(self::DOCUMENT, $document, '#', $diagnostics);
    }

    /**
     * $document restated as a newer export reads it, for a reader that holds values to its schemas, with
     * the way back to where each part was written; null where it is not read as 3.0. Diagnostics are the
     * build's to raise, not a reader's.
     *
     * @param  array<string, mixed>  $document
     * @return array{array<string, mixed>, OpenApi30Restatement}|null
     */
    public static function restate(array $document): ?array
    {
        if (! self::states30($document)) {
            return null;
        }

        $upgrade = new self(false);
        $diagnostics = [];
        $restated = $upgrade->walkObject(self::DOCUMENT, $document, '#', $diagnostics);

        return [$restated, new OpenApi30Restatement($upgrade->wrapped)];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function walkObject(string $kind, array $node, string $pointer, array &$diagnostics): array
    {
        if ($kind === self::SCHEMA) {
            return $this->schema($node, $pointer, $diagnostics);
        }

        if (in_array($kind, self::REFERABLE, true) && is_string($node['$ref'] ?? null)) {
            return $this->reference($node, $pointer, $diagnostics);
        }

        if ($kind === self::CALLBACK) {
            return $this->walkMap(self::PATH_ITEM, $node, $pointer, $diagnostics, extensible: true);
        }

        $children = self::CHILDREN[$kind] ?? [];

        if ($kind === self::PATH_ITEM) {
            $children += array_fill_keys(PathItem::METHODS, [self::ONE, self::OPERATION]);
        }

        foreach ($children as $member => [$shape, $childKind]) {
            $value = $node[$member] ?? null;

            if (! is_array($value)) {
                continue;
            }

            $child = JsonPointer::child($pointer, $member);

            $node[$member] = match ($shape) {
                self::ONE => $this->walkObject($childKind, Arr::stringKeyed($value), $child, $diagnostics),
                self::LIST => $this->walkList($childKind, $value, $child, $diagnostics),
                self::EXTENSIBLE_MAP => $this->walkMap($childKind, $value, $child, $diagnostics, extensible: true),
                default => $this->walkMap($childKind, $value, $child, $diagnostics),
            };
        }

        return $node;
    }

    /**
     * @param  array<mixed, mixed>  $list
     * @param  list<Diagnostic>  $diagnostics
     * @return list<mixed>
     */
    private function walkList(string $kind, array $list, string $pointer, array &$diagnostics): array
    {
        $out = [];

        foreach (array_values($list) as $index => $item) {
            $out[] = is_array($item)
                ? $this->walkObject($kind, Arr::stringKeyed($item), JsonPointer::child($pointer, (string) $index), $diagnostics)
                : $item;
        }

        return $out;
    }

    /**
     * A map keyed by names the application chose; `$extensible` says its Object also admits `x-` members,
     * which are then extensions rather than names.
     *
     * @param  array<mixed, mixed>  $map
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function walkMap(string $kind, array $map, string $pointer, array &$diagnostics, bool $extensible = false): array
    {
        $out = [];

        foreach ($map as $name => $member) {
            $name = (string) $name;

            $out[$name] = ($extensible && str_starts_with($name, 'x-')) || ! is_array($member)
                ? $member
                : $this->walkObject($kind, Arr::stringKeyed($member), JsonPointer::child($pointer, $name), $diagnostics);
        }

        return $out;
    }

    /**
     * A Reference Object outside a schema. 3.0 ignores anything beside its `$ref`, and 3.1 reads a
     * `summary` or `description` there as overriding the component's own, so those two come off: the
     * component's wording is what the 3.0 source says.
     *
     * @param  array<string, mixed>  $node
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function reference(array $node, string $pointer, array &$diagnostics): array
    {
        $dropped = array_values(array_intersect(array_keys($node), self::REFERENCE_PROSE));

        if ($dropped === []) {
            return $node;
        }

        $diagnostics[] = new Diagnostic(
            severity: Severity::Info,
            code: 'upgrade.ref-siblings',
            message: sprintf(
                'Dropped `%s` beside the reference at %s: the document is OpenAPI 3.0, which ignores a `$ref` sibling, so the referenced component\'s own wording is what it says.',
                implode('` and `', $dropped),
                $pointer,
            ),
        );

        return array_diff_key($node, array_flip($dropped));
    }

    /**
     * One Schema Object, its subschemas first so a rewrite here composes branches that are already read.
     * `$height` is how many levels the restated schema nests, as {@see CanonicalJsonSerializer} counts them.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function schema(array $schema, string $pointer, array &$diagnostics, int &$height = 0): array
    {
        if (is_string($schema['$ref'] ?? null)) {
            $schema = $this->schemaReference($schema, $pointer, $diagnostics);
            $height = self::height($schema);

            return $schema;
        }

        $heights = [];

        foreach ($schema as $keyword => $value) {
            $keyword = (string) $keyword;
            $position = is_array($value) ? SchemaKeywords::positionOf($keyword) : null;
            $below = 0;

            if (is_array($value) && $position === SchemaKeywords::POSITION_SCHEMA) {
                $schema[$keyword] = $this->schema(Arr::stringKeyed($value), JsonPointer::child($pointer, $keyword), $diagnostics, $below);
            } elseif (is_array($value) && ($position === SchemaKeywords::POSITION_SCHEMA_MAP || $position === SchemaKeywords::POSITION_SCHEMA_LIST)) {
                $schema[$keyword] = $this->subschemas($value, $position === SchemaKeywords::POSITION_SCHEMA_LIST, JsonPointer::child($pointer, $keyword), $diagnostics, $below);
            } elseif (is_array($value) || $value instanceof stdClass) {
                $below = self::height($value);
            }

            $heights[$keyword] = $below;
        }

        return $this->nullable($this->exclusiveBounds($schema), $heights, $pointer, $diagnostics, $height);
    }

    /**
     * A map or list of subschemas, and through `$height` how many levels it nests.
     *
     * @param  array<mixed, mixed>  $members
     * @param  list<Diagnostic>  $diagnostics
     * @return array<mixed, mixed>
     */
    private function subschemas(array $members, bool $list, string $pointer, array &$diagnostics, int &$height): array
    {
        $out = [];
        $deepest = 0;

        foreach ($list ? array_values($members) : $members as $name => $member) {
            $below = 0;
            $out[$list ? $name : (string) $name] = is_array($member)
                ? $this->schema(Arr::stringKeyed($member), JsonPointer::child($pointer, (string) $name), $diagnostics, $below)
                : $member;
            $deepest = max($deepest, $below);
        }

        $height = $deepest + 1;

        return $out;
    }

    /** How many levels `$value` nests: none for a scalar, and one more than its deepest member for a container. */
    private static function height(mixed $value): int
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }

        if (! is_array($value)) {
            return 0;
        }

        $deepest = 0;

        foreach ($value as $member) {
            if ((is_array($member) || $member instanceof stdClass) && ($below = self::height($member)) > $deepest) {
                $deepest = $below;
            }
        }

        return $deepest + 1;
    }

    /**
     * A `$ref` in a schema position is a 3.0 Reference Object, whose siblings "SHALL be ignored"; from 3.1
     * a `$ref` is a JSON Schema keyword and its siblings apply beside it. So every one of them comes off
     * rather than starting to constrain, or describe, what the 3.0 source never did. An `x-` member is
     * tooling's, not the schema's, and stays.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function schemaReference(array $schema, string $pointer, array &$diagnostics): array
    {
        $dropped = array_values(array_filter(
            array_keys($schema),
            static fn (string $key): bool => $key !== '$ref' && ! str_starts_with($key, 'x-'),
        ));

        if ($dropped === []) {
            return $schema;
        }

        $diagnostics[] = new Diagnostic(
            severity: Severity::Info,
            code: 'upgrade.ref-siblings',
            message: sprintf(
                'Dropped `%s` beside the `$ref` at %s: the document is OpenAPI 3.0, which ignores a `$ref` sibling, so the schema it references is all it says there.',
                implode('`, `', $dropped),
                $pointer,
            ),
        );

        return array_diff_key($schema, array_flip($dropped));
    }

    /**
     * 3.0's exclusive bounds are booleans qualifying `minimum`/`maximum`; from 3.1 they are the bound
     * itself. One qualifying no bound, or `false`, says nothing, so it goes.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function exclusiveBounds(array $schema): array
    {
        foreach (['exclusiveMinimum' => 'minimum', 'exclusiveMaximum' => 'maximum'] as $exclusive => $bound) {
            $qualifies = $schema[$exclusive] ?? null;

            if (! is_bool($qualifies)) {
                continue;
            }

            unset($schema[$exclusive]);
            $value = $schema[$bound] ?? null;

            if ($qualifies && (is_int($value) || is_float($value))) {
                if ($this->respell) {
                    $schema[$exclusive] = $value;
                    unset($schema[$bound]);
                } else {
                    $schema[$exclusive] = true;
                }
            }
        }

        return $schema;
    }

    /**
     * `nullable: true` adds `null` to the `type` beside it and, in 3.0.4's words, "only takes effect if
     * `type` is explicitly defined within the same Schema Object" — so beside a type it becomes a `null`
     * member of that type, and nothing else changes: an `enum` without null still refuses it, as "other
     * Schema Object constraints retain their defined behavior".
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, int>  $heights  how many levels each member nests
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function nullable(array $schema, array $heights, string $pointer, array &$diagnostics, int &$height): array
    {
        $nullable = $schema['nullable'] ?? null;

        if (is_bool($nullable)) {
            unset($schema['nullable']);
        }

        if ($nullable === true && (! $this->respell || ($this->for30 && ! array_key_exists('type', $schema)))) {
            return self::measured([...$schema, 'nullable' => true], $heights, $height);
        }

        if ($nullable === true && ! array_key_exists('type', $schema)) {
            return $this->untypedNullable($schema, $heights, $pointer, $diagnostics, $height);
        }

        $type = $schema['type'] ?? null;

        if ($nullable === true && is_string($type) && $type !== 'null') {
            $schema['type'] = [$type, 'null'];
            $heights['type'] = 1;
        } elseif ($nullable === true && is_array($type) && ! in_array('null', $type, true)) {
            $schema['type'] = [...array_values($type), 'null'];
        }

        return self::measured($schema, $heights, $height);
    }

    /**
     * `nullable: true` with no `type` beside it: 3.0.4 gives it no effect, and 3.0 code generators read it
     * as nullable — the `allOf` wrapper round a `$ref` is how 3.0 writes a nullable reference at all. The
     * wider reading is the one that stays true, so null is admitted as a branch of its own beside what the
     * schema asserts, its annotations staying where they describe the whole. A lone `allOf` member is that
     * branch alone, which is the spelling the 3.0 downlevel reads back as this same idiom.
     *
     * The branch sits two levels deeper than the schema did. Where that would nest the document past what
     * can be written out, the schema is read as admitting any value instead, which is wider still and so
     * still true, and said.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, int>  $heights
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function untypedNullable(array $schema, array $heights, string $pointer, array &$diagnostics, int &$height): array
    {
        $whole = [];
        $branch = [];

        foreach ($schema as $keyword => $value) {
            $keyword = (string) $keyword;

            if (str_starts_with($keyword, 'x-') || SchemaKeywords::saysNothingAboutTheInstance($keyword)) {
                $whole[$keyword] = $value;
            } else {
                $branch[$keyword] = $value;
            }
        }

        // Nothing asserted, so null already passes: there is no narrower reading to widen from.
        if ($branch === []) {
            return self::measured($whole, $heights, $height);
        }

        $allOf = $branch['allOf'] ?? null;

        $only = count($branch) === 1 && is_array($allOf) && count($allOf) === 1 ? array_values($allOf)[0] : null;
        $hoisted = is_array($only);

        // The `anyOf` list and the branch in it, over the deepest member the branch holds, or the hoisted
        // `allOf` list's own levels.
        $wrapper = $hoisted ? ($heights['allOf'] ?? 0) : 2 + max([0, ...array_values(array_intersect_key($heights, $branch))]);
        $depth = substr_count($pointer, '/');

        if ($depth + 1 + max($wrapper, 2) > CanonicalJsonSerializer::MAX_DEPTH) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'upgrade.nullable-too-deep',
                message: sprintf(
                    'Read the schema at %s, which has `nullable: true` and no `type`, as admitting any value: admitting null beside what it asserts would nest the document more than %d levels deep, past what can be written out.',
                    $pointer,
                    CanonicalJsonSerializer::MAX_DEPTH,
                ),
            );

            return self::measured($whole, $heights, $height);
        }

        if (is_array($only)) {
            $branch = $only;
        }

        $this->wrapped[$pointer] = $hoisted;

        $diagnostics[] = new Diagnostic(
            severity: Severity::Info,
            code: 'upgrade.nullable-without-type',
            message: sprintf(
                'Read `nullable: true` at %s, which stands beside no `type`, as admitting null: the document is OpenAPI 3.0, whose text from 3.0.3 gives it no effect there while 3.0 code generators read it as nullable, so the wider reading is the one published.',
                $pointer,
            ),
        );

        $height = 1 + max([0, ...array_values(array_intersect_key($heights, $whole)), max($wrapper, 2)]);

        return [...$whole, 'anyOf' => [$branch, ['type' => 'null']]];
    }

    /**
     * `$schema`, with `$height` set to how many levels it nests from what its members do.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, int>  $heights
     * @return array<string, mixed>
     */
    private static function measured(array $schema, array $heights, int &$height): array
    {
        $height = 1 + max([0, ...array_values(array_intersect_key($heights, $schema))]);

        return $schema;
    }
}
