<?php

declare(strict_types=1);

namespace Docuccino\Core\Diagnostics;

/**
 * Where a diagnostic code is written up, so a reader who met one in their terminal can go and read
 * what it means without searching for it.
 *
 * The reference groups codes by theme rather than one heading each, so the deepest thing there is to
 * link to is a section — and a code's prefix says which section, unambiguously. A prefix nobody has
 * mapped still gets the page, which is right rather than merely safe: the page lists every code, so
 * the reader arrives somewhere true either way.
 */
final class DiagnosticDocs
{
    /** The reference itself. Hardcoded for the same reason the UIR schema's `$id` is: a URL a document publishes cannot come from the machine that built it. */
    public const string PAGE = 'https://docs.docuccino.app/laravel/reference/diagnostics/';

    /**
     * Code prefix → the section anchor that documents it.
     *
     * @var array<string, string>
     */
    private const SECTIONS = [
        'attribute' => 'attributes',
        'components' => 'routes-operations-and-names',
        'config' => 'configuration',
        'content' => 'narrative-content',
        'description-file' => 'attributes',
        'docblock' => 'docblock-tags',
        'document' => 'routes-operations-and-names',
        'downlevel' => 'emitting-openapi-31-and-30',
        'eloquent' => 'package-integrations',
        'engine' => 'the-engine-and-inference',
        'example-file' => 'attributes',
        'examples' => 'recorded-examples',
        'inference' => 'the-engine-and-inference',
        'inferred-handler' => 'responses-recovered-from-your-code',
        'inferred-response' => 'responses-recovered-from-your-code',
        'integration' => 'configuration',
        'json-api-paginate' => 'package-integrations',
        'lint' => 'lint-rules',
        'overlay' => 'overlays',
        'postman' => 'postman-collections',
        'query-builder' => 'package-integrations',
        'rate-limit' => 'package-integrations',
        'route' => 'routes-operations-and-names',
        'route-binding' => 'routes-operations-and-names',
        'server' => 'servers',
        'spatie-data' => 'package-integrations',
        'tags' => 'routes-operations-and-names',
        'validation' => 'responses-recovered-from-your-code',
        'webhook' => 'webhooks',
    ];

    /**
     * The prefixes that carry a section of their own, for the test that holds this map to the page.
     *
     * @return list<string>
     */
    public static function prefixes(): array
    {
        return array_keys(self::SECTIONS);
    }

    /** Where to read about `$code`. A pure function of the code, so a build's output is the same bytes every run. */
    public static function urlFor(string $code): string
    {
        $prefix = str_contains($code, '.') ? strstr($code, '.', true) : $code;
        $anchor = self::SECTIONS[$prefix] ?? null;

        return $anchor === null ? self::PAGE : self::PAGE.'#'.$anchor;
    }
}
