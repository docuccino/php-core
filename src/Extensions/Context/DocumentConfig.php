<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

use Docuccino\Core\Extensions\Contracts\TagMapper;
use Docuccino\Core\Support\Hydrate;
use Docuccino\Core\Support\Json;

/**
 * One document's resolved configuration (design §9). Framework-agnostic: the adapter builds
 * this from `config/docuccino.php`. Typed accessors cover what the pipeline and built-in
 * extensions read; the untouched `raw` bag keeps everything else (representation policies,
 * viewer, tag map …) available without modelling every key here in Phase 3a.
 */
final readonly class DocumentConfig
{
    /**
     * @param  array<string, mixed>  $info  OAS info object (title, version, description …)
     * @param  list<array<string, mixed>>  $servers
     * @param  list<string>  $routeInclude  wildcard patterns of URIs to include
     * @param  list<string>  $routeExclude  wildcard patterns of URIs to exclude
     * @param  callable(RouteDescriptor): bool|null  $routeFilter  optional closure filter
     * @param  string|null  $authMiddleware  wildcard matched against middleware to require auth
     * @param  list<string>  $overlays  glob patterns of Overlay 1.0 documents
     * @param  array<string, mixed>  $security  the `security` config (schemes + document requirement)
     * @param  array<string, mixed>  $tags  the `tags` config (map + mapper class-string + list)
     * @param  array<string, mixed>  $representation  the `representation` policy config
     * @param  array<string, mixed>  $viewer  the `viewer` config (driver/route/gate/source/cdn)
     * @param  string  $versioning  the versioning policy keyword (`semver|date|none`)
     * @param  TagMapper|null  $tagMapper  resolved tag mapper (identity when null)
     * @param  array<string, mixed>  $raw  the full config array for this document
     */
    public function __construct(
        public string $key,
        public array $info,
        public array $servers = [],
        public array $routeInclude = [],
        public array $routeExclude = [],
        public mixed $routeFilter = null,
        // Opt back into documenting routes whose resolved controller file lives under vendor/ (they
        // are excluded by default, mirroring Laravel's `route:list --except-vendor`).
        public bool $includeVendor = false,
        public ?string $authMiddleware = null,
        public string $errorResponses = 'none',
        // How a 422 problem-details body models its `errors`: 'map' (field → messages, the default) or
        // 'pointer-list' (a list of {detail, pointer} objects, RFC 9457 / JSON-Pointer style).
        public string $errorsShape = 'map',
        public array $overlays = [],
        public string $onRouteError = 'skeleton',
        public array $security = [],
        public array $tags = [],
        public array $representation = [],
        public array $viewer = [],
        public string $versioning = 'none',
        public ?TagMapper $tagMapper = null,
        public array $raw = [],
    ) {}

    /** Apply the document's tag mapper (identity when none is configured). */
    public function mapTag(string $tag): string
    {
        return $this->tagMapper?->map($tag) ?? $tag;
    }

    /**
     * How an operation with no `#[Group]` gets its default tag: `controller` (the controller's short
     * name, `Controller` suffix stripped, then run through `tags.map`) or `none` (no default tag).
     * Defaults to `controller` so an untagged API still groups sensibly (`tags.map` can then remap).
     * An unknown value coerces to `controller`; the adapter surfaces that coercion as a config info
     * diagnostic (`config.unknown-tag-strategy`) rather than swallowing it silently.
     */
    public function tagDefaultStrategy(): string
    {
        $strategy = $this->tags['default_strategy'] ?? 'controller';

        return $strategy === 'none' ? 'none' : 'controller';
    }

    /**
     * The per-integration config sub-bag `integrations.<name>` (design §9), or `[]` when absent —
     * the single home for an integration's document-level knobs (Sanctum modes/cookie, Passport
     * url, Query Builder pagination terminals, API-resources wrapping …).
     *
     * @return array<string, mixed>
     */
    public function integration(string $name): array
    {
        $integrations = Hydrate::map($this->raw['integrations'] ?? null);

        return Hydrate::map($integrations[$name] ?? null);
    }

    /**
     * Whether the integration keyed by $name is enabled for this document (design §9). Reads the
     * per-integration `integrations.<name>.enabled` switch, coercing a non-bool to $default and
     * falling back to the per-integration $default when the key is absent — so an integration that
     * ships default-on stays on unless a document opts out, and a sensitive-by-activation integration
     * (permission) stays off unless a document opts in.
     */
    public function integrationEnabled(string $name, bool $default): bool
    {
        $value = $this->integration($name)['enabled'] ?? $default;

        return is_bool($value) ? $value : $default;
    }

    /**
     * Whether the document explicitly set `integrations.<name>.enabled` to a boolean (as opposed to
     * leaving it to the per-integration default). Lets the discoverability diagnostic tell an
     * opt-in-not-yet-taken (default-off, untouched) apart from a deliberate opt-out (`enabled => false`).
     */
    public function integrationEnabledExplicit(string $name): bool
    {
        return is_bool($this->integration($name)['enabled'] ?? null);
    }

    /**
     * A deterministic fingerprint of this document's configuration — the single owner of the
     * config-hash (a fragment-cache key input, design §10, and the document's `configHash`). Folds
     * the whole raw config bag through the order-insensitive {@see Json::stable()} encoder so key
     * order cannot perturb the hash; falls back to the document key when the bag cannot be encoded.
     */
    public function hash(): string
    {
        $stable = Json::stable($this->raw);

        return hash('sha256', $stable === '' ? $this->key : $stable);
    }

    /**
     * Document-level tag definitions from `tags.definitions`: each `{name, description?, weight?}`,
     * sorted deterministically by ascending weight (default 0) then name, ready for the OAS
     * top-level `tags` array. Malformed entries (no string `name`) are skipped.
     *
     * @return list<array{name: string, description?: string}>
     */
    public function tagDefinitions(): array
    {
        $definitions = $this->tags['definitions'] ?? null;
        if (! is_array($definitions)) {
            return [];
        }

        $rows = [];
        foreach ($definitions as $definition) {
            if (! is_array($definition) || ! is_string($definition['name'] ?? null)) {
                continue;
            }

            $entry = ['name' => $definition['name']];
            if (is_string($definition['description'] ?? null)) {
                $entry['description'] = $definition['description'];
            }

            $weight = $definition['weight'] ?? 0;
            $rows[] = ['weight' => is_int($weight) ? $weight : 0, 'entry' => $entry];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['weight'], $a['entry']['name']] <=> [$b['weight'], $b['entry']['name']]);

        return array_map(static fn (array $row): array => $row['entry'], $rows);
    }

    /**
     * The `security.schemes` map (name → OAS security-scheme object) for `components.securitySchemes`.
     * Malformed entries (non-array values) are dropped so a typo can't break the document.
     *
     * @return array<string, array<string, mixed>>
     */
    public function securitySchemes(): array
    {
        return Hydrate::mapOfArrays($this->security['schemes'] ?? null);
    }

    /**
     * The document-level security requirement from `security.document` (an OAS `security` array —
     * a list of `{scheme: scopes[]}` requirement objects), or null when none is configured.
     *
     * @return list<array<string, mixed>>|null
     */
    public function documentSecurity(): ?array
    {
        return Hydrate::listOfMaps($this->security['document'] ?? null);
    }

    /**
     * The default per-operation requirement from `security.default`, applied by the auto-detect
     * middleware layer to routes whose middleware matches {@see $authMiddleware}.
     *
     * @return list<array<string, mixed>>|null
     */
    public function defaultSecurity(): ?array
    {
        return Hydrate::listOfMaps($this->security['default'] ?? null);
    }

    /**
     * The configured narrative-content directory from `content.dir` (the markdown tree the content
     * compiler reads), or null when unset. Framework-agnostic: may be relative (the adapter resolves
     * and confines it against the app base path) or absolute.
     */
    public function contentDir(): ?string
    {
        $content = is_array($this->raw['content'] ?? null) ? $this->raw['content'] : [];
        $dir = $content['dir'] ?? null;

        return is_string($dir) && $dir !== '' ? $dir : null;
    }

    /**
     * The configured export target from `export.path` (the file the `docuccino:export` artifact is
     * written to and the viewer's `artifact` source reads back), defaulting to `docs/openapi.json`.
     * Framework-agnostic: may be relative — the adapter resolves it against the app base path.
     */
    public function exportPath(): string
    {
        $export = is_array($this->raw['export'] ?? null) ? $this->raw['export'] : [];

        return is_string($export['path'] ?? null) ? $export['path'] : 'docs/openapi.json';
    }
}
