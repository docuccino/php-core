<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

/**
 * The per-document representation policy: separates *what was inferred* from *how it's expressed in
 * the spec*. Every keyword defaults to today's behaviour (see the constructor), so an absent config
 * reproduces the same output byte-for-byte.
 *
 * - `operationId`: `route-name` | `controller-method`.
 * - `enumNaming`: `none` | `x-enumNames` | `x-enum-varnames` — codegen name hints emitted alongside
 *   the enum; the `enum` members themselves never change.
 * - `enumComponents`: whether a reflectable enum hoists to a named component (deduped by FQCN) that
 *   use sites `$ref`, or is inlined at each one. Hoisting is the better output — one canonical,
 *   described enum shared everywhere; `false` restores the inline form byte-for-byte.
 * - `nullable`: how "single type plus null" is expressed — `type-array` (`type: [x, null]`) |
 *   `anyof` (a `{type: null}` branch).
 * - `filterStyle` (Query Builder): `bracketed` (flat `filter[status]` params, `fields[articles]` for
 *   sparse fieldsets) | `deepObject` (one `filter`/`fields` object parameter, `explode: true`).
 * - `listStyle` (Query Builder): how `sort`/`include` lists go out — `comma` (one comma-separated
 *   string) | `array` (`style: form, explode: false` with an `items` enum).
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
        public string $enumNaming = 'none',
        public string $nullable = 'type-array',
        public string $filterStyle = 'bracketed',
        public string $listStyle = 'comma',
        public string $resourceWrap = '',
        public bool $enumComponents = true,
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

        return new self(
            operationId: self::keyword($representation['operation_id'] ?? null, 'route-name'),
            enumNaming: self::keyword($enumNaming, 'none'),
            nullable: self::keyword($representation['nullable'] ?? null, 'type-array'),
            filterStyle: self::keyword($representation['filters'] ?? null, 'bracketed'),
            listStyle: self::keyword($representation['lists'] ?? null, 'comma'),
            resourceWrap: self::normalizeWrap($resourceWrap),
            enumComponents: ! ($enumComponents === false),
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

    /** Whether `sort`/`include` lists are expressed as an exploded array parameter. */
    public function listsAsArray(): bool
    {
        return $this->listStyle === 'array';
    }

    private static function keyword(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }
}
