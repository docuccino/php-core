<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

use Docuccino\Core\Extensions\Schema\EnumDecoration;
use Docuccino\Core\Support\ConfiguredFlag;
use Docuccino\Core\Support\ConfiguredKeyword;
use Docuccino\Core\Support\Hydrate;

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
 * - `paginationComponents`: whether a page-of-X envelope hoists to one named component per item type
 *   and paginator kind that each paginated operation `$ref`s — and its `links`/`meta` to one component
 *   per shape, which the page then `$ref`s in turn — or is restated inline on every one of them.
 *   Hoisting is the better output — an SDK generator mints one page type per item type instead of one
 *   per operation, over one set of envelope members instead of one per page; `false` restores the
 *   inline form byte-for-byte.
 * - `nullable`: how "single type plus null" is expressed — `type-array` (`type: [x, null]`) |
 *   `anyof` (a `{type: null}` branch).
 * - `filterStyle` (Query Builder): `bracketed` (flat `filter[status]` params, `fields[articles]` for
 *   sparse fieldsets) | `deepObject` (one `filter`/`fields` object parameter, `explode: true`).
 * - `formatSamples` (`examples.formats`): JSON Schema `format` → the value a synthesized example uses
 *   for it, MERGED over {@see FormatSamples} per format. A configured sample is still validated against
 *   the field's finished keywords before publication, exactly as a derived one is; one that fails falls
 *   back to the built-in sample with a diagnostic rather than disappearing.
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

    /**
     * The closed sets the four keyword members take, and the default each answers — declared here
     * because this is what reads them, so the reading, the constructor's default and the diagnostic
     * that names the set are one statement rather than three literals that can drift.
     *
     * Ordered as the shipped configuration lists them, which is the order a refusal reads them back in.
     *
     * @var non-empty-list<string>
     */
    public const array OPERATION_IDS = ['route-name', 'controller-method'];

    /** @var non-empty-list<string> */
    public const array ENUM_NAMINGS = ['names', 'none', 'x-enumNames', 'x-enum-varnames'];

    /** @var non-empty-list<string> */
    public const array NULLABLE_STYLES = ['type-array', 'anyof'];

    /** @var non-empty-list<string> */
    public const array FILTER_STYLES = ['bracketed', 'deepObject'];

    public const string DEFAULT_OPERATION_ID = 'route-name';

    public const string DEFAULT_ENUM_NAMING = 'names';

    public const string DEFAULT_NULLABLE = 'type-array';

    public const string DEFAULT_FILTER_STYLE = 'bracketed';

    /**
     * @param  array<string, string>  $formatSamples  JSON Schema `format` => the configured sample
     */
    public function __construct(
        public string $operationId = self::DEFAULT_OPERATION_ID,
        public string $enumNaming = self::DEFAULT_ENUM_NAMING,
        public string $nullable = self::DEFAULT_NULLABLE,
        public string $filterStyle = self::DEFAULT_FILTER_STYLE,
        public string $resourceWrap = '',
        public bool $enumComponents = true,
        public bool $errorComponents = true,
        public bool $paginationComponents = true,
        public array $formatSamples = [],
    ) {}

    /**
     * @param  array<string, mixed>  $representation  the document's `representation` config
     * @param  mixed  $resourceWrap  `integrations.api_resources.wrap`: `false`/`true` toggle,
     *                               a string wrap key, or null/unset (defer to each resource)
     */
    public static function fromConfig(array $representation, mixed $resourceWrap = null): self
    {
        $enums = Hydrate::map($representation['enums'] ?? null);

        $examples = $representation['examples'] ?? null;

        return new self(
            operationId: self::keyword($representation, 'operation_id', self::DEFAULT_OPERATION_ID, self::OPERATION_IDS),
            enumNaming: self::keyword($enums, 'naming', self::DEFAULT_ENUM_NAMING, self::ENUM_NAMINGS),
            nullable: self::keyword($representation, 'nullable', self::DEFAULT_NULLABLE, self::NULLABLE_STYLES),
            filterStyle: self::keyword($representation, 'filters', self::DEFAULT_FILTER_STYLE, self::FILTER_STYLES),
            resourceWrap: self::normalizeWrap($resourceWrap),
            enumComponents: ConfiguredFlag::read($enums, 'components', true)->on,
            errorComponents: ConfiguredFlag::read(Hydrate::map($representation['errors'] ?? null), 'components', true)->on,
            paginationComponents: ConfiguredFlag::read(Hydrate::map($representation['pagination'] ?? null), 'components', true)->on,
            formatSamples: self::formatSamples(is_array($examples) ? ($examples['formats'] ?? null) : null),
        );
    }

    /**
     * `examples.formats` as data: strings keyed by format, everything else dropped. A `format` keyword is
     * a string keyword, so a non-string sample could never be published — the adapter reports the drop
     * rather than this guessing at a coercion.
     *
     * @return array<string, string>
     */
    private static function formatSamples(mixed $configured): array
    {
        $samples = [];
        foreach (is_array($configured) ? $configured : [] as $format => $sample) {
            if (is_string($sample)) {
                $samples[(string) $format] = $sample;
            }
        }

        return $samples;
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

    /**
     * One keyword member, refused rather than coerced — a value outside the set answers the default,
     * which is what a `match` over an unrecognised keyword was already going to publish. The adapter's
     * keyword catalogue reads the same paths again to REPORT the refusal, because nothing here has
     * anywhere to put a diagnostic.
     *
     * @param  array<string, mixed>  $bag
     * @param  non-empty-list<string>  $accepted
     */
    private static function keyword(array $bag, string $key, string $default, array $accepted): string
    {
        return ConfiguredKeyword::read($bag, $key, $default, $accepted)->keyword;
    }
}
