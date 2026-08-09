<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

/**
 * The per-document representation policy (design §Representation policies): it separates *what was
 * inferred* from *how it is expressed in the spec*. Each field is a keyword with a behaviour-
 * preserving default, so an absent config reproduces today's output byte-for-byte:
 *
 * - `operationId`: `route-name` (default) | `controller-method`.
 * - `enumNaming`: `none` (default) | `x-enumNames` | `x-enum-varnames` — codegen name hints
 *   emitted alongside the enum, never changing the `enum` member itself.
 * - `enumComponents`: `true` (default) | `false` — whether a reflectable enum hoists to a named
 *   `components.schemas` entry (deduped by FQCN identity) that properties and query-parameter item
 *   schemas `$ref`, vs inlining its `type`/`enum`/`x-enumDescriptions` at every use site. Hoisting is
 *   the better output (one canonical, described enum shared everywhere); `false` restores the inline
 *   expression byte-for-byte.
 * - `nullable`: `type-array` (default, `type: [x, null]`) | `anyof` (a `{type: null}` branch) —
 *   how a "single type plus null" union is expressed.
 * - `filterStyle` (Query Builder): `bracketed` (default — one flat `filter[status]` param each, and
 *   `fields[articles]` for sparse fieldsets) | `deepObject` (a single `filter` / `fields` object
 *   parameter, `style: deepObject, explode: true`) — how filter/field maps are expressed.
 * - `listStyle` (Query Builder): `comma` (default — a single comma-separated string parameter) |
 *   `array` (`style: form, explode: false`, `items` enum) — how `sort`/`include` lists are expressed.
 * - `resourceWrap` (API Resources): the document-level override of Laravel's top-level resource
 *   `data` wrapping (`integrations.api_resources.wrap`). `''` (default) defers to each resource's own
 *   static `$wrap`; `'disabled'` unwraps everything (the escape hatch for a global
 *   `JsonResource::withoutWrapping()`, which is not statically visible); any other value forces that
 *   wrap key. Only the top-level resource is ever wrapped — nested resources stay unwrapped.
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
     * Normalise the `integrations.api_resources.wrap` config to the `resourceWrap` keyword:
     * `false` → disabled, `true` → the Laravel default `data`, a non-empty string → that key,
     * anything else (null/unset) → `''` (defer to each resource's static `$wrap`).
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
