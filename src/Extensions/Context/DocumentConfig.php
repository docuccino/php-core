<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Extensions\Contracts\TagMapper;
use Docuccino\Core\Support\ConfinedPath;
use Docuccino\Core\Support\Fqcn;
use Docuccino\Core\Support\Hydrate;
use Docuccino\Core\Support\Json;

/**
 * One document's resolved configuration (design §9). Framework-agnostic — the adapter builds it
 * from `config/docuccino.php`. Typed accessors cover what the pipeline and built-in extensions
 * read; the untouched `raw` bag carries everything else, so not every key needs modelling here.
 */
final readonly class DocumentConfig
{
    /** The `info.version` a document that names none falls back to — a placeholder, never a real one. */
    public const string DEFAULT_VERSION = '1.0.0';

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
        // Opt back into routes whose controller lives under vendor/ — excluded by default, mirroring
        // Laravel's `route:list --except-vendor`.
        public bool $includeVendor = false,
        public ?string $authMiddleware = null,
        public string $errorResponses = 'none',
        // How a 422 problem-details body models `errors`: 'map' (field → messages) or 'pointer-list'
        // ({detail, pointer} objects, RFC 9457 style).
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
     * How an operation with no `#[Group]` gets its default tag: `controller` (short controller name,
     * `Controller` stripped, then through `tags.map`) or `none`. Defaults to `controller` so an
     * untagged API still groups sensibly. An unknown value coerces to `controller` and the adapter
     * reports that as a `config.unknown-tag-strategy` diagnostic rather than swallowing it.
     */
    public function tagDefaultStrategy(): string
    {
        $strategy = $this->tags['default_strategy'] ?? 'controller';

        return $strategy === 'none' ? 'none' : 'controller';
    }

    /**
     * The tag a route on `$actionClass` groups under when it carries no `#[Group]`: the controller's
     * short name with a trailing `Controller` stripped, then through `tags.map` like any raw tag.
     * Null for the `none` strategy or a closure route. Two controllers of the same short name in
     * different namespaces therefore answer the same tag, which the assembler reports.
     */
    public function defaultTag(?string $actionClass): ?string
    {
        if ($this->tagDefaultStrategy() !== 'controller' || $actionClass === null) {
            return null;
        }

        $short = preg_replace('/Controller$/', '', Fqcn::short($actionClass));

        return $short === null || $short === '' ? null : $this->mapTag($short);
    }

    /**
     * The `integrations.<name>` sub-bag, or `[]` when absent — the one home for an integration's
     * document-level knobs (Sanctum modes, Passport url, API-resources wrapping …).
     *
     * @return array<string, mixed>
     */
    public function integration(string $name): array
    {
        $integrations = Hydrate::map($this->raw['integrations'] ?? null);

        return Hydrate::map($integrations[$name] ?? null);
    }

    /**
     * Whether `integrations.<name>.enabled` is on, falling back to (and coercing a non-bool to)
     * $default. So a default-on integration stays on until a document opts out, and a sensitive one
     * like permissions stays off until a document opts in.
     */
    public function integrationEnabled(string $name, bool $default): bool
    {
        $value = $this->integration($name)['enabled'] ?? $default;

        return is_bool($value) ? $value : $default;
    }

    /**
     * Whether the document set `integrations.<name>.enabled` to a boolean itself. Lets the
     * discoverability diagnostic tell an untaken opt-in from a deliberate `enabled => false`.
     */
    public function integrationEnabledExplicit(string $name): bool
    {
        return is_bool($this->integration($name)['enabled'] ?? null);
    }

    /**
     * A deterministic fingerprint of the config that SHAPES this document — the sole owner of the
     * config-hash (a fragment-cache key input and the document's `configHash`). Goes through
     * {@see Json::stable()} so key order can't perturb it; falls back to the document key if the bag
     * won't encode.
     *
     * `export` is excluded on purpose: it says where artifacts are written, never what they contain.
     * Folding it in would make adding a second export target rewrite the document's `configHash` —
     * changing emitted bytes, and cold-busting every cached fragment — over a filename. Nothing a
     * fragment holds can read an export destination, so this is not under-keying.
     */
    public function hash(): string
    {
        $shaping = $this->raw;
        unset($shaping['export']);

        $stable = Json::stable($shaping);

        return hash('sha256', $stable === '' ? $this->key : $stable);
    }

    /** Optional OAS 3.2 Tag Object members carried verbatim from a definition, in canonical order. */
    private const array TAG_MEMBERS = ['summary', 'description', 'parent', 'kind'];

    /**
     * Tag definitions from `tags.definitions` (`{name, summary?, description?, parent?, kind?,
     * weight?}`), sorted by ascending weight then name for the OAS top-level `tags` array. Entries
     * with no string `name` are skipped.
     *
     * A `parent` naming no defined tag, or one that would close a cycle, is dropped so the emitted
     * hierarchy is always a forest — {@see tagParentIssues()} reports each drop. Sorting happens
     * before that pass, so neither the tags nor the drops depend on the order the definitions
     * happen to be written in.
     *
     * @return list<array{name: string, summary?: string, description?: string, parent?: string, kind?: string}>
     */
    public function tagDefinitions(): array
    {
        return $this->resolveTags()['tags'];
    }

    /**
     * The parent links {@see tagDefinitions()} dropped — `cycle` separates a link that closed a loop
     * from one naming a tag the document never defines. The adapter reports these as config
     * diagnostics rather than failing the build.
     *
     * @return list<array{tag: string, parent: string, cycle: bool}>
     */
    public function tagParentIssues(): array
    {
        return $this->resolveTags()['issues'];
    }

    /**
     * The tag forest as `x-tagGroups`: one group per parentless tag — itself first, then every
     * descendant — in {@see tagDefinitions()} order. Empty when no tag declares a parent, so a flat
     * document changes not a byte. The projection is total or absent: a viewer rendering groups hides
     * any tag outside them, so a childless root still gets its singleton group rather than vanishing.
     *
     * @return list<array{name: string, tags: list<string>}>
     */
    public function tagGroups(): array
    {
        $tags = $this->tagDefinitions();

        $children = [];
        $parented = [];
        foreach ($tags as $tag) {
            $parent = $tag['parent'] ?? null;
            if ($parent !== null) {
                $children[$parent][] = $tag['name'];
                $parented[$tag['name']] = true;
            }
        }

        if ($children === []) {
            return [];
        }

        $groups = [];
        foreach ($tags as $tag) {
            if (! isset($parented[$tag['name']])) {
                $groups[] = ['name' => $tag['name'], 'tags' => self::descendantTags($tag['name'], $children)];
            }
        }

        return $groups;
    }

    /**
     * @param  array<string, list<string>>  $children
     * @return list<string>
     */
    private static function descendantTags(string $name, array $children): array
    {
        $out = [$name];
        foreach ($children[$name] ?? [] as $child) {
            $out = [...$out, ...self::descendantTags($child, $children)];
        }

        return $out;
    }

    /**
     * @return array{tags: list<array{name: string, summary?: string, description?: string, parent?: string, kind?: string}>, issues: list<array{tag: string, parent: string, cycle: bool}>}
     */
    private function resolveTags(): array
    {
        $rows = [];
        foreach (Hydrate::listOfMaps($this->tags['definitions'] ?? null) ?? [] as $definition) {
            if (! is_string($definition['name'] ?? null)) {
                continue;
            }

            $entry = ['name' => $definition['name']];
            foreach (self::TAG_MEMBERS as $member) {
                $value = $definition[$member] ?? null;
                if (is_string($value)) {
                    $entry[$member] = $value;
                }
            }

            $weight = $definition['weight'] ?? 0;
            $rows[] = ['weight' => is_int($weight) ? $weight : 0, 'entry' => $entry];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['weight'], $a['entry']['name']] <=> [$b['weight'], $b['entry']['name']]);

        /** @var list<array{name: string, summary?: string, description?: string, parent?: string, kind?: string}> $tags */
        $tags = array_map(static fn (array $row): array => $row['entry'], $rows);

        return $this->linkTagParents($tags);
    }

    /**
     * Keeps a `parent` only when it names a defined tag and does not close a cycle against the links
     * already kept — walking up the accepted chain, which is acyclic by construction, decides that.
     *
     * @param  list<array{name: string, summary?: string, description?: string, parent?: string, kind?: string}>  $tags
     * @return array{tags: list<array{name: string, summary?: string, description?: string, parent?: string, kind?: string}>, issues: list<array{tag: string, parent: string, cycle: bool}>}
     */
    private function linkTagParents(array $tags): array
    {
        $defined = [];
        foreach ($tags as $tag) {
            $defined[$tag['name']] = true;
        }

        $issues = [];
        /** @var array<string, string> $accepted child name → parent name */
        $accepted = [];

        foreach ($tags as $index => $tag) {
            $parent = $tag['parent'] ?? null;
            if ($parent === null) {
                continue;
            }

            $cycle = $this->reachesTag($parent, $tag['name'], $accepted);

            if (! $cycle && isset($defined[$parent])) {
                $accepted[$tag['name']] = $parent;

                continue;
            }

            unset($tags[$index]['parent']);
            $issues[] = ['tag' => $tag['name'], 'parent' => $parent, 'cycle' => $cycle];
        }

        return ['tags' => $tags, 'issues' => $issues];
    }

    /**
     * Whether walking up from `$from` through the accepted links reaches `$target` (`$from` itself
     * counts — a tag parented to itself is the shortest cycle there is).
     *
     * @param  array<string, string>  $accepted
     */
    private function reachesTag(string $from, string $target, array $accepted): bool
    {
        while (true) {
            if ($from === $target) {
                return true;
            }

            if (! isset($accepted[$from])) {
                return false;
            }

            $from = $accepted[$from];
        }
    }

    /**
     * The `security.schemes` map for `components.securitySchemes`. Non-array values are dropped so a
     * typo can't break the document.
     *
     * @return array<string, array<string, mixed>>
     */
    public function securitySchemes(): array
    {
        return Hydrate::mapOfArrays($this->security['schemes'] ?? null);
    }

    /**
     * The document-level requirement from `security.document` — an OAS `security` array of
     * `{scheme: scopes[]}` objects — or null when unconfigured.
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
     * A configured filesystem path this document names, or null when it names none this build can use:
     * absent, not a string, empty, or holding a NUL byte, which is a path no filesystem call can accept
     * ({@see ConfinedPath::holdable()}). All four are "the document has none" to every reader, which is
     * a shape they all already handle — and the last of them would otherwise reach a `glob()` or a
     * `file_get_contents()` and take the whole build with it. The raw bag keeps the value either way, so
     * the `configHash` still describes what was configured and the adapter can report the refusal.
     */
    private static function configuredPath(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? ConfinedPath::holdable($value) : null;
    }

    /**
     * The markdown tree the content compiler reads, from `content.dir`, or null when unset. May be
     * relative — the adapter resolves and confines it against the app base path.
     */
    public function contentDir(): ?string
    {
        $content = is_array($this->raw['content'] ?? null) ? $this->raw['content'] : [];
        $dir = $content['dir'] ?? null;

        return self::configuredPath($dir);
    }

    /**
     * The directory `#[Webhook]` classes are discovered under, from `webhooks.dir`, or null when
     * unset — in which case the document publishes no webhooks. May be relative; the adapter resolves
     * and confines it against the app base path, the same as {@see contentDir()}.
     */
    public function webhooksDir(): ?string
    {
        $webhooks = is_array($this->raw['webhooks'] ?? null) ? $this->raw['webhooks'] : [];
        $dir = $webhooks['dir'] ?? null;

        return self::configuredPath($dir);
    }

    /**
     * Whether this document declares itself an API VERSION, by carrying an `api_version` bag at all.
     * Which version it is, is {@see apiVersion()} — `info.version`, because a document's version is
     * the one OAS already models and a second key naming it again could only ever disagree with it.
     */
    public function declaresApiVersion(): bool
    {
        return self::declaresVersion($this->raw);
    }

    /**
     * The API version this document IS, or null when it declares none, or declares one and states no
     * `info.version` at all. The build says so with a diagnostic rather than deriving a version from
     * nothing.
     */
    public function apiVersion(): ?string
    {
        return self::statedVersion($this->raw);
    }

    /**
     * Whether a document's raw config declares itself an API version.
     *
     * @param  array<string, mixed>  $raw
     */
    private static function declaresVersion(array $raw): bool
    {
        return is_array($raw['api_version'] ?? null);
    }

    /**
     * The API version a document's raw config states, read off `info.version`. Static and public
     * because the adapter reads the same fact off every configured document to enumerate the closed
     * set of versions, and one rule about what counts as stated must answer both.
     *
     * Written or not written is the whole test, {@see DEFAULT_VERSION} included: an API whose first
     * published version really is `1.0.0` is the likeliest first semver version there is, and a rule
     * that read it as unstated would leave that application unable to use the feature at all. The
     * shipped `api_version` block is commented out, so a document carrying one has said what it is.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function statedVersion(array $raw): ?string
    {
        if (! self::declaresVersion($raw)) {
            return null;
        }

        $version = Hydrate::map($raw['info'] ?? null)['version'] ?? null;
        $version = is_string($version) ? trim($version) : '';

        return $version === '' ? null : $version;
    }

    /**
     * The directory the API version-change classes are discovered under, from
     * `api_version.changes.dir`, or null when the document names none — in which case the version
     * publishes the head shape. Confined against the app base path by the adapter, the same as
     * {@see webhooksDir()}.
     */
    public function apiVersionChangesDir(): ?string
    {
        $changes = Hydrate::map($this->apiVersionConfig()['changes'] ?? null);

        return self::configuredPath($changes['dir'] ?? null);
    }

    /**
     * The request header a client pins a version with, from `api_version.header`. Defaults to
     * `X-Api-Version`, which is what the document publishes on every operation.
     */
    public function apiVersionHeader(): string
    {
        $header = $this->apiVersionConfig()['header'] ?? null;

        return is_string($header) && trim($header) !== '' ? trim($header) : 'X-Api-Version';
    }

    /**
     * @return array<string, mixed>
     */
    private function apiVersionConfig(): array
    {
        return Hydrate::map($this->raw['api_version'] ?? null);
    }

    /**
     * The directory of committed response recordings, from `examples.recordings`, or null when the
     * document publishes none. May be relative; the adapter confines it against the app base path the
     * same as {@see contentDir()}.
     *
     * Reading files out of it is the whole of what a build does with a test suite's traffic — nothing
     * is executed and nothing is fetched. Absent is the default, so a document publishes a recorded
     * example only once someone has said where the recordings live.
     */
    public function recordingsDir(): ?string
    {
        $examples = is_array($this->raw['examples'] ?? null) ? $this->raw['examples'] : [];
        $dir = $examples['recordings'] ?? null;

        return self::configuredPath($dir);
    }

    /**
     * The directory the contract-coverage recorder writes to and `docuccino:coverage` merges, from
     * `coverage.log`, or null when the document names none — in which case the adapter picks
     * `storage/docuccino/coverage`.
     *
     * Nothing in the build reads it. It is config rather than a call-site argument because the test
     * suite writing the logs and the command gating on them have to arrive at the same directory, and
     * a value only the suite knew would leave the command guessing.
     */
    public function coverageLogDir(): ?string
    {
        $coverage = is_array($this->raw['coverage'] ?? null) ? $this->raw['coverage'] : [];
        $dir = $coverage['log'] ?? null;

        return self::configuredPath($dir);
    }

    /**
     * Where `docuccino:export` writes and the viewer's `artifact` source reads, from `export.path`,
     * defaulting to `docs/openapi.json`. May be relative — the adapter resolves it against the app
     * base path.
     *
     * The one-target shorthand. {@see exportTargets()} is the full answer, and reads this when
     * `export.targets` is absent.
     */
    public function exportPath(): string
    {
        return self::configuredPath($this->export()['path'] ?? null) ?? 'docs/openapi.json';
    }

    /**
     * The member the OpenAPI emitters project a schema's `x-docuccino.mock.faker` onto, from
     * `export.mock_faker_key` — conventionally `x-faker`. Null (the default) drops mock hints, so a
     * bare export stays pure OpenAPI; the UIR carries them either way.
     *
     * It sits under `export` because it shapes the projection and never the document, which is also
     * what keeps it out of {@see hash()}.
     */
    public function mockFakerKey(): ?string
    {
        $key = $this->export()['mock_faker_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Every artifact this document writes: `export.targets` when it holds at least one usable entry,
     * else the one-target {@see exportPath()} shorthand.
     *
     * Malformed entries are dropped rather than guessed at — {@see exportTargetIssues()} reports each
     * one, and the adapter refuses to build until they are fixed, so nothing here ships a half-read
     * target list.
     *
     * @return list<ExportTarget>
     */
    public function exportTargets(): array
    {
        $targets = [];

        foreach ($this->rawTargets() as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $format = $entry['format'] ?? null;
            $path = self::configuredPath($entry['path'] ?? null);

            if (is_string($format) && $format !== '' && $path !== null) {
                $targets[] = new ExportTarget($format, $path);
            }
        }

        return $targets === []
            ? [new ExportTarget(Formats::DEFAULT, $this->exportPath())]
            : $targets;
    }

    /**
     * Everything wrong with `export.targets`, as structured problems the adapter names and phrases.
     * `index` is -1 for a problem with the list as a whole.
     *
     * @return list<array{index: int, problem: string, detail: string}>
     */
    public function exportTargetIssues(): array
    {
        $raw = $this->export()['targets'] ?? null;

        if ($raw === null) {
            return [];
        }

        if (! is_array($raw) || ! array_is_list($raw) || $raw === []) {
            return [['index' => -1, 'problem' => 'empty', 'detail' => '']];
        }

        $issues = [];
        foreach ($raw as $index => $entry) {
            $issues = [...$issues, ...$this->entryIssues((int) $index, $entry)];
        }

        return [...$issues, ...$this->setIssues()];
    }

    /**
     * @return list<array{index: int, problem: string, detail: string}>
     */
    private function entryIssues(int $index, mixed $entry): array
    {
        if (! is_array($entry)) {
            return [['index' => $index, 'problem' => 'shape', 'detail' => get_debug_type($entry)]];
        }

        $format = $entry['format'] ?? null;
        // A path holding a NUL is as unusable as an absent one and reports as the same `shape` problem:
        // the adapter refuses to build on either, which is the whole point of reporting them here.
        $path = self::configuredPath($entry['path'] ?? null);

        if (! is_string($format) || $format === '' || $path === null) {
            return [['index' => $index, 'problem' => 'shape', 'detail' => '']];
        }

        if (! Formats::supports($format)) {
            return [['index' => $index, 'problem' => 'unknown-format', 'detail' => $format]];
        }

        $target = new ExportTarget($format, $path);

        return $target->yamlUnsupported()
            ? [['index' => $index, 'problem' => 'yaml-unsupported', 'detail' => $format.' => '.$path]]
            : [];
    }

    /**
     * Problems only the whole target set shows: two targets writing one file, two naming one format
     * (which is what keeps `--format` and the viewer's pick order-independent), and an `export.path`
     * left behind next to a list that supersedes it.
     *
     * @return list<array{index: int, problem: string, detail: string}>
     */
    private function setIssues(): array
    {
        $issues = [];
        /** @var array<string, true> $seenPaths */
        $seenPaths = [];
        /** @var array<string, true> $seenFormats */
        $seenFormats = [];

        foreach ($this->exportTargets() as $index => $target) {
            if (isset($seenPaths[$target->path])) {
                $issues[] = ['index' => $index, 'problem' => 'duplicate-path', 'detail' => $target->path];
            }
            if (isset($seenFormats[$target->format])) {
                $issues[] = ['index' => $index, 'problem' => 'duplicate-format', 'detail' => $target->format];
            }

            $seenPaths[$target->path] = true;
            $seenFormats[$target->format] = true;
        }

        if (is_string($this->export()['path'] ?? null)) {
            $issues[] = ['index' => -1, 'problem' => 'path-ignored', 'detail' => $this->exportPath()];
        }

        return $issues;
    }

    /**
     * @return array<string, mixed>
     */
    private function export(): array
    {
        return Hydrate::map($this->raw['export'] ?? null);
    }

    /**
     * @return list<mixed>
     */
    private function rawTargets(): array
    {
        $raw = $this->export()['targets'] ?? null;

        return is_array($raw) && array_is_list($raw) ? $raw : [];
    }
}
