<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

use Docuccino\Core\Extensions\Schema\EnumDecoration;

/**
 * The per-document representation policy: separates *what was inferred* from *how it's expressed in
 * the spec*. Each keyword defaults to the shape most consumers handle best (see the constructor); an
 * absent config yields exactly those defaults.
 *
 * - `operationId`: `route-name` | `controller-method`.
 * - `enumNaming`: `names` (the default) | `none` | `x-enumNames` | `x-enum-varnames` — which SDK
 *   member-name hints ride alongside the enum; the `enum` members themselves never change.
 *   {@see EnumDecoration} owns what each keyword emits.
 * - `errorComponents`: whether an error response body repeated across operations hoists to one shared
 *   `components.responses` entry each operation `$ref`s; `false` inlines every copy.
 * - `enumComponents`: whether a reflectable enum hoists to a named component (deduped by FQCN) that
 *   use sites `$ref`, or is inlined at each one. Hoisting is the better output — one canonical,
 *   described enum shared everywhere; `false` restores the inline form byte-for-byte.
 * - `nullable`: how "single type plus null" is expressed — `type-array` (`type: [x, null]`) |
 *   `anyof` (a `{type: null}` branch).
 * - `filterStyle` (Query Builder): `bracketed` (flat `filter[status]` params, `fields[articles]` for
 *   sparse fieldsets) | `deepObject` (one `filter`/`fields` object parameter, `explode: true`).
 * - `listStyle` (Query Builder): accepted for compatibility — both keywords now express `sort`/
 *   `include` the same way, a comma-serialised (`form`, `explode: false`) array whose items enum
 *   the allow-list.
 * - `resourceWrap` (API Resources): document-level override of Laravel's top-level resource `data`
 *   wrapping (`integrations.api_resources.wrap`). `''` defers to each resource's static `$wrap`;
 *   `'disabled'` unwraps everything — the escape hatch for a global
 *   `JsonResource::withoutWrapping()`, which isn't statically visible; anything else forces that
 *   wrap key. Only the top-level resource is ever wrapped, never nested ones.
 */
final readonly class RepresentationPolicy
{
    /** The `resourceWrap` sentinel meaning "no wrapping" (a global `withoutWrapping()` escape hatch). */
    public const WRAP_DISABLED = 'disabled';

    public function __construct(
        public string $operationId = 'route-name',
        public string $enumNaming = 'names',
        public string $nullable = 'type-array',
        public string $filterStyle = 'bracketed',
        public string $listStyle = 'comma',
        public string $resourceWrap = '',
        public bool $enumComponents = true,
        public bool $errorComponents = true,
    ) {}

    /**
     * @param  array<string, mixed>  $representation  the document's `representation` config
     * @param  mixed  $resourceWrap  `integrations.api_resources.wrap`: `false`/`true` toggle,
     *                               a string wrap key, or null/unset (defer to each resource)
     */
    public static function fromConfig(array $representation, mixed $resourceWrap = null): self
    {
        $enums = $representation['enums'] ?? null;
        $enumNaming = is_array($enums) ? ($enums['naming'] ?? null) : null;
        $enumComponents = is_array($enums) ? ($enums['components'] ?? null) : null;

        $errors = $representation['errors'] ?? null;
        $errorComponents = is_array($errors) ? ($errors['components'] ?? null) : null;

        return new self(
            operationId: self::keyword($representation['operation_id'] ?? null, 'route-name'),
            enumNaming: self::keyword($enumNaming, 'names'),
            nullable: self::keyword($representation['nullable'] ?? null, 'type-array'),
            filterStyle: self::keyword($representation['filters'] ?? null, 'bracketed'),
            listStyle: self::keyword($representation['lists'] ?? null, 'comma'),
            resourceWrap: self::normalizeWrap($resourceWrap),
            enumComponents: ! ($enumComponents === false),
            errorComponents: ! ($errorComponents === false),
        );
    }

    /**
     * `integrations.api_resources.wrap` → the `resourceWrap` keyword: `false` → disabled, `true` →
     * Laravel's default `data`, a non-empty string → that key, anything else → `''` (defer).
     */
    private static function normalizeWrap(mixed $wrap): string
    {
        return match (true) {
            $wrap === false => self::WRAP_DISABLED,
            $wrap === true => 'data',
            is_string($wrap) && $wrap !== '' => $wrap,
            default => '',
        };
    }

    /** Whether filter/field maps are expressed as a single deep-object parameter. */
    public function filtersDeepObject(): bool
    {
        return $this->filterStyle === 'deepObject';
    }

    private static function keyword(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }
}
