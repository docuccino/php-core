<?php

declare(strict_types=1);

namespace Docuccino\Core\SpecValidation;

use Opis\JsonSchema\Validator;
use RuntimeException;
use stdClass;

/**
 * The Arazzo schemas, vendored per version (`1.0`, `1.1`), and every place a description fails the one
 * for the version it declares.
 *
 * Each file is byte-exact as `spec.openapis.org` serves it at the dated, immutable URI in {@see SCHEMAS},
 * pinned twice for the reasons {@see OpenApiMetaSchema} gives: the `published` id is identity, the `sha256`
 * content, and `ArazzoMetaSchemaTest` holds both. Each references one schema from outside itself, the
 * JSON Schema 2020-12 meta-schema a workflow's `inputs` is held to, which is registered as the schema that
 * accepts everything so nothing is fetched: what this checks is the Arazzo around the inputs.
 *
 * Read as published, with no rewriting: opis reads the `unevaluatedProperties: false` every Arazzo object
 * closes itself with correctly here, which `ArazzoMetaSchemaTest` shows over a description using every
 * object. `format` is not asserted, as for OpenAPI.
 *
 * @internal
 *
 * @phpstan-type VendoredArazzoSchema array{file: string, published: string, sha256: string}
 */
final class ArazzoMetaSchema
{
    /**
     * Arazzo major.minor => the vendored file, the id it must declare, and its SHA-256.
     *
     * @var array<string, VendoredArazzoSchema>
     */
    public const array SCHEMAS = [
        '1.0' => [
            'file' => 'arazzo-v1.0.schema.json',
            'published' => 'https://spec.openapis.org/arazzo/1.0/schema/2025-10-15',
            'sha256' => 'b8715bd824fffcb2accf5077977d37c9e7a15be60d785e7a3a51cf600fd46ad4',
        ],
        '1.1' => [
            'file' => 'arazzo-v1.1.schema.json',
            'published' => 'https://spec.openapis.org/arazzo/1.1/schema/2026-04-15',
            'sha256' => '37be908409bdb2f7bffe61fa23685c7e84cbeebfafac475a1d01dbc50ff7ab9e',
        ],
    ];

    /** The one schema the files reference from outside themselves. */
    private const string JSON_SCHEMA_DIALECT = 'https://json-schema.org/draft/2020-12/schema';

    /** @var array<string, Validator> */
    private static array $validators = [];

    /** The vendored file for $version, package-relative so it resolves from a `vendor/` install. */
    public static function path(string $version): string
    {
        return dirname(__DIR__, 2).'/resources/arazzo/'.self::row($version)['file'];
    }

    public static function publishedId(string $version): string
    {
        return self::row($version)['published'];
    }

    public static function digest(string $version): string
    {
        return self::row($version)['sha256'];
    }

    /**
     * Every way $instance — an object graph, as {@see OpenApiMetaSchema::findings()} wants one — fails the
     * schema for $version. Empty means valid.
     *
     * @return list<Finding>
     */
    public static function findings(string $version, mixed $instance): array
    {
        return SchemaFindings::of(self::validator($version), $instance, self::publishedId($version));
    }

    /** @return VendoredArazzoSchema */
    private static function row(string $version): array
    {
        return self::SCHEMAS[$version] ?? throw new RuntimeException(sprintf('No vendored Arazzo schema for version "%s".', $version));
    }

    private static function validator(string $version): Validator
    {
        if (isset(self::$validators[$version])) {
            return self::$validators[$version];
        }

        $schema = json_decode((string) file_get_contents(self::path($version)), flags: JSON_THROW_ON_ERROR);
        if (! $schema instanceof stdClass) {
            throw new RuntimeException('Vendored Arazzo schema is not a JSON object: '.self::path($version));
        }

        $validator = SchemaFindings::collecting(new Validator);

        // As for OpenAPI: never write a schema's `default` into the document being read, read the file as
        // plain JSON Schema rather than with opis's own extensions, and treat `format` as the annotation
        // 2020-12 makes it.
        $validator->parser()->setOption('allowDefaults', false);
        foreach (['allowFilters', 'allowMappers', 'allowTemplates', 'allowGlobals', 'allowSlots', 'allowPragmas', 'allowDataKeyword', 'allowKeywordValidators', 'allowFormats'] as $option) {
            $validator->parser()->setOption($option, false);
        }

        $validator->resolver()?->registerRaw(true, self::JSON_SCHEMA_DIALECT);
        $validator->resolver()?->registerRaw($schema, self::publishedId($version));

        return self::$validators[$version] = $validator;
    }
}
