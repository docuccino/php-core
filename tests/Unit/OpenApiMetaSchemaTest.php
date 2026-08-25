<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Tests\Support\EmittedDocument;
use Docuccino\Core\Tests\Support\OpenApiMetaSchema;

/**
 * The meta-schema oracle: every OpenAPI artifact the emitters produce, in both serialisations, answers to
 * the published schema for its own version.
 *
 * Nothing else in the suite reads emitted OpenAPI bytes against an external authority — the goldens pin
 * bytes against a committed copy of themselves, and every YAML assertion was a substring, a two-run
 * self-comparison, or a `Yaml::parse()` round trip that collapses map and sequence to the same PHP array
 * and so cannot see kind at all. `--yaml` shipped `paths: []` for an empty `paths` MAP through all of it.
 *
 * Every subject answers outright — there is no allowance for a violation we already know about. Two were
 * pinned here while they stood: a server variable with no `default`, which every OpenAPI version requires
 * and the emitters passed through, and that empty map. Both are fixed, so an exception list would only be
 * somewhere for the next one to hide. The oracle's own negative path is proved on a hand-built document
 * rather than on a live defect, so nothing was lost by retiring them.
 */

/** @return array<string, array{string, string}> */
function metaSchemaSubjects(): array
{
    $subjects = [];

    foreach (metaSchemaFixtures() as $fixture) {
        foreach (array_keys(OpenApiMetaSchema::SCHEMAS) as $format) {
            $subjects[basename($fixture, '.json').' · '.$format] = [$fixture, $format];
        }
    }

    return $subjects;
}

/**
 * Every UIR fixture in the tree, discovered rather than listed: a fixture added tomorrow is validated
 * without anyone remembering to name it here.
 *
 * @return list<string>
 */
function metaSchemaFixtures(): array
{
    $fixtures = [];

    foreach (glob(dirname(__DIR__).'/Fixtures/*.json') ?: [] as $path) {
        $decoded = json_decode((string) file_get_contents($path), true);

        if (is_array($decoded) && isset($decoded['uir'], $decoded['info'])) {
            $fixtures[] = basename($path);
        }
    }

    sort($fixtures);

    return $fixtures;
}

/** @return array{mixed, mixed} the JSON emission and the YAML emission of one document, both as graphs */
function metaSchemaEmissions(string $fixture, string $format): array
{
    $document = UirDocument::fromArray(loadFixture($fixture));

    return [
        json_decode(Formats::emit($format, $document, new EmitOptions)->output, flags: JSON_THROW_ON_ERROR),
        EmittedDocument::parseYaml(Formats::emit($format, $document, (new EmitOptions)->withYaml())->output),
    ];
}

it('emits JSON that answers to its own OpenAPI meta-schema', function (string $fixture, string $format): void {
    [$json] = metaSchemaEmissions($fixture, $format);

    expect(OpenApiMetaSchema::findings($format, $json))->toBe([]);
})->with(metaSchemaSubjects());

it('emits YAML that answers to its own OpenAPI meta-schema', function (string $fixture, string $format): void {
    [, $yaml] = metaSchemaEmissions($fixture, $format);

    expect(OpenApiMetaSchema::findings($format, $yaml))->toBe([]);
})->with(metaSchemaSubjects());

/**
 * The meta-schemas leave Schema Objects unconstrained in 3.1 and 3.2 (`$defs/schema` is `type: [object,
 * boolean]` and nothing more), so a corrupted `additionalProperties` inside one is invisible to them.
 * This is the assertion that sees it: the same document serialised twice must agree at every position on
 * whether it holds a map, a sequence or a scalar.
 */
it('emits YAML and JSON that agree on every map, sequence and scalar', function (string $fixture, string $format): void {
    [$json, $yaml] = metaSchemaEmissions($fixture, $format);

    expect(EmittedDocument::differences($json, $yaml))->toBe([]);
})->with(metaSchemaSubjects());

/**
 * And on the order those members are written in, which neither oracle above can see: the kind comparison
 * walks maps by NAME and then diffs key SETS, and JSON Schema cannot constrain member order at all. So a
 * writer that emitted `info` before `paths` where its sibling emitted `paths` before `info` produced zero
 * findings from both — a second instance of the class mapping-key quoting belongs to, where the difference
 * exists only in the bytes.
 *
 * Determinism is a product feature here, so that is a real defect, and the YAML goldens cover order for
 * three documents at one version rather than for these subjects at all three.
 */
it('emits YAML and JSON that agree on the order they write members in', function (string $fixture, string $format): void {
    [$json, $yaml] = metaSchemaEmissions($fixture, $format);

    expect(EmittedDocument::orderDifferences($json, $yaml))->toBe([]);
})->with(metaSchemaSubjects());

it('vendors a meta-schema for every OpenAPI format the emitters offer', function (): void {
    $emitted = array_values(array_filter(
        Formats::ids(),
        static fn (string $id): bool => str_starts_with($id, 'openapi-'),
    ));

    expect(array_keys(OpenApiMetaSchema::SCHEMAS))->toEqualCanonicalizing($emitted)
        ->and($emitted)->toHaveCount(3);
});

it('pins each vendored meta-schema to the dated URI it was fetched from', function (): void {
    foreach (OpenApiMetaSchema::SCHEMAS as $format => $row) {
        $decoded = OpenApiMetaSchema::decode($format);

        expect($decoded)->toBeInstanceOf(stdClass::class);

        // 3.2 and 3.1 are draft 2020-12 (`$id`); 3.0 is draft-04 (`id`).
        $declared = $decoded->{'$id'} ?? $decoded->id ?? null;

        expect($declared)->toBe($row['published'], $row['file']);
    }

    expect(OpenApiMetaSchema::SCHEMAS)->toHaveCount(3);
});

/**
 * And the pin the id cannot give. A file's declared `$id` is exactly the field an editor of it would leave
 * alone, so identity alone lets 1,650 lines of third-party JSON be edited under a name that still checks
 * out. That matters more here than for an ordinary vendored blob, because the recovered key gates read
 * their patterns OUT of these same files: widening one gate's `^/` to `^.` leaves the declared id
 * untouched, weakens the validator and the recovery together, and the site-count tests do not backstop it
 * — they count sites, not what the patterns at those sites say.
 *
 * Same control as `SchemaShippingTest`'s drift guard over the shipped UIR schema, for the same reason:
 * nobody reads either file line by line, so the bytes answer for themselves.
 */
it('pins each vendored meta-schema by content, not only by the id it declares', function (): void {
    foreach (OpenApiMetaSchema::SCHEMAS as $format => $row) {
        $path = OpenApiMetaSchema::path($format);

        expect(is_file($path))->toBeTrue($row['file'])
            ->and(hash_file('sha256', $path))->toBe($row['sha256'], $row['file'])
            ->and(OpenApiMetaSchema::digest($format))->toBe($row['sha256']);

        // A floor under the pin itself: these are whole published meta-schemas, so a truncated or
        // placeholder file re-pinned to its own new digest fails here rather than passing quietly.
        expect(filesize($path))->toBeGreaterThan(20000, $row['file']);
    }

    expect(OpenApiMetaSchema::SCHEMAS)->toHaveCount(3);
});

/**
 * Counts the schema positions where $matches says yes, walking keyword nodes only — a key inside a
 * `properties`/`$defs`/`patternProperties` map is a NAME, so `{"properties": {"contains": …}}` is a
 * property called `contains` and never the keyword.
 */
function metaSchemaSites(mixed $node, callable $matches, bool $inMap = false): int
{
    if (is_array($node)) {
        return array_sum(array_map(static fn (mixed $v): int => metaSchemaSites($v, $matches), $node));
    }

    if (! $node instanceof stdClass) {
        return 0;
    }

    $vars = get_object_vars($node);
    $found = ! $inMap && $matches($vars) ? 1 : 0;

    foreach ($vars as $key => $value) {
        $names = ! $inMap && in_array($key, ['properties', 'definitions', 'patternProperties', '$defs'], true);
        $found += metaSchemaSites($value, $matches, $names);
    }

    return $found;
}

/**
 * `opisWorkarounds()` drops `contains` with its bounds wherever `minContains: 0` sits beside it, because
 * opis still demands one match. It matches by SHAPE, so a vendored schema that grew a second such site
 * would lose that bound silently and nothing would say so. Exactly one exists — 3.2's "at most one
 * querystring parameter" cap — and none in the other two.
 */
it('drops the contains bound at exactly the one site that has it', function (): void {
    $unbounded = static fn (array $vars): bool => ($vars['minContains'] ?? null) === 0 && isset($vars['contains']);

    $sites = [];
    foreach (array_keys(OpenApiMetaSchema::SCHEMAS) as $format) {
        $sites[$format] = metaSchemaSites(OpenApiMetaSchema::decode($format), $unbounded);
    }

    expect($sites)->toBe(['openapi-3.2' => 1, 'openapi-3.1' => 0, 'openapi-3.0' => 0]);
});

/**
 * The other shape-matched rewrite, and the condition that makes it sound rather than merely convenient.
 *
 * `opisWorkarounds()` turns every `$dynamicRef: "#meta"` into the static `$ref: "#/$defs/schema"`. That
 * is what the dynamic reference is SPECIFIED to resolve to only because each file carries exactly one
 * `$dynamicAnchor: "meta"` and it sits at that pointer — with two anchors, the dynamic resolution would
 * depend on the evaluation path and the substitution would silently mis-resolve every Schema Object
 * position in every document, with the suite green. Its sibling rewrite (the `contains` bound above) is
 * guarded by a count; this one was not.
 *
 * Only the anchor is pinned, not the number of `$dynamicRef`s pointing at it: a revision that adds
 * another Schema Object position is ordinary spec growth and harmless to the rewrite, whereas a second
 * anchor is the thing that breaks it.
 */
it('rewrites the dynamic reference against exactly one anchor, at the pointer it substitutes', function (): void {
    $anchors = [];

    foreach (array_keys(OpenApiMetaSchema::SCHEMAS) as $format) {
        $anchors[$format] = metaSchemaSites(
            OpenApiMetaSchema::decode($format),
            static fn (array $vars): bool => ($vars['$dynamicAnchor'] ?? null) === 'meta',
        );
    }

    // 3.0 is draft-04 and has no dynamic references at all, so it needs no rewrite and carries no anchor.
    expect($anchors)->toBe(['openapi-3.2' => 1, 'openapi-3.1' => 1, 'openapi-3.0' => 0]);

    // The one anchor is at `#/$defs/schema` — the pointer the rewrite substitutes.
    foreach (['openapi-3.2', 'openapi-3.1'] as $format) {
        expect(OpenApiMetaSchema::decode($format)->{'$defs'}->schema->{'$dynamicAnchor'})->toBe('meta', $format);
    }
});

/**
 * The key gates `allowUnevaluated => false` disables, counted so the scope recorded beside that option
 * cannot drift from the files. 3.0 has none — its gates close with `additionalProperties`, which is why
 * it rejects most of `OpenApiUnevaluatedScopeTest`'s matrix.
 */
it('counts the unevaluatedProperties sites the disabled keyword takes with it', function (): void {
    $closed = static fn (array $vars): bool => ($vars['unevaluatedProperties'] ?? null) === false;

    $sites = [];
    foreach (array_keys(OpenApiMetaSchema::SCHEMAS) as $format) {
        $sites[$format] = metaSchemaSites(OpenApiMetaSchema::decode($format), $closed);
    }

    expect($sites)->toBe(['openapi-3.2' => 28, 'openapi-3.1' => 28, 'openapi-3.0' => 0]);
});

/**
 * `operationId` uniqueness is a spec rule no meta-schema can carry — JSON Schema cannot express
 * uniqueness across positions — so a duplicate validates clean at every version while a generated client
 * quietly loses a method to the collision. This is the negative path for the check that sees it.
 */
it('reports an operationId two operations share, and nothing when they differ', function (): void {
    $document = static fn (string $second): mixed => json_decode((string) json_encode([
        'openapi' => '3.2.0',
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => [
            '/things' => ['get' => ['operationId' => 'listThings', 'responses' => ['200' => ['description' => 'ok']]]],
            '/others' => ['get' => ['operationId' => $second, 'responses' => ['200' => ['description' => 'ok']]]],
        ],
    ]), flags: JSON_THROW_ON_ERROR);

    expect(OpenApiMetaSchema::findings('openapi-3.2', $document('listThings')))
        ->toBe(['/paths/~1others/get operationId: "listThings" is used by /paths/~1others/get, /paths/~1things/get'])
        ->and(OpenApiMetaSchema::findings('openapi-3.2', $document('listOthers')))->toBe([]);
});

/**
 * And the floor under it: the check is worth nothing if the documents it runs over carry no `operationId`
 * at all, which would make "no duplicates" true and vacuous.
 */
it('runs the operationId check over documents that actually carry operationIds', function (): void {
    $ids = 0;

    foreach (metaSchemaSubjects() as [$fixture, $format]) {
        [$json] = metaSchemaEmissions($fixture, $format);

        $ids += metaSchemaSites($json, static fn (array $vars): bool => isset($vars['operationId']));
    }

    expect($ids)->toBeGreaterThanOrEqual(50);
});

/**
 * The oracle's own negative path. If these two pass, an empty map written as a sequence is a failure the
 * suite can see — which is the whole reason this file exists.
 */
it('reports an empty map written as a sequence, and nothing when it is written as a map', function (): void {
    $skeleton = <<<'YAML'
        openapi: 3.2.0
        info:
          title: Oracle
          version: 1.0.0
        paths: %s
        YAML;

    $broken = EmittedDocument::parseYaml(sprintf($skeleton, '[]'));
    $sound = EmittedDocument::parseYaml(sprintf($skeleton, '{}'));

    expect(OpenApiMetaSchema::findings('openapi-3.2', $broken))
        ->toBe(['/paths type: The data (array) must match the type: object (schema /$defs/paths)'])
        ->and(OpenApiMetaSchema::findings('openapi-3.2', $sound))->toBe([])
        ->and(EmittedDocument::differences($sound, $broken))->toBe(['/paths: json is map, yaml is sequence'])
        ->and(EmittedDocument::emptyMaps($sound))->toBe(['/paths'])
        ->and(EmittedDocument::emptyMaps($broken))->toBe([]);
});

/**
 * An oracle may not touch what it reads. opis applies schema `default`s INTO the instance unless
 * `allowDefaults` is off, so a validated 3.2 document silently gained a `jsonSchemaDialect` and a
 * `servers: [{url: "/"}]` it never emitted — and every assertion after it compared against the mutation
 * rather than the emission. The option is set; this is what proves it still is.
 *
 * Both graphs, because they are built by different readers: `json_decode` for one and a kind-preserving
 * `Yaml::parse()` for the other. Proving purity over the JSON graph alone proves it for one of the two
 * things every assertion in this file goes on to compare.
 *
 * The encode is order-sensitive on purpose: it is what makes an injected member visible wherever opis
 * chose to put it.
 */
it('leaves the instances it validated exactly as it found them', function (string $fixture, string $format): void {
    [$json, $yaml] = metaSchemaEmissions($fixture, $format);

    $before = [json_encode($json, JSON_THROW_ON_ERROR), json_encode($yaml, JSON_THROW_ON_ERROR)];

    OpenApiMetaSchema::findings($format, $json);
    OpenApiMetaSchema::findings($format, $yaml);

    expect([json_encode($json, JSON_THROW_ON_ERROR), json_encode($yaml, JSON_THROW_ON_ERROR)])->toBe($before);
})->with(metaSchemaSubjects());

/**
 * The other half of the oracle's negative path, on a REAL emission. `postman-surface` emits a genuinely
 * null `closedAt` at three example positions, and a member DROPPED at one of them used to read as a member
 * written null — the comparison answered "no differences" while the YAML had lost a member the JSON
 * carries. Nothing else sees it: the meta-schema is asserted here to stay silent on the same mutation,
 * because an example's members are exactly what it leaves unconstrained.
 */
it('sees a null-valued member the YAML dropped, where the meta-schema cannot', function (): void {
    [$json, $yaml] = metaSchemaEmissions('postman-surface.uir.json', 'openapi-3.2');

    $example = $yaml->paths->{'/accounts'}->post->responses->{'201'}->content->{'application/json'}->example;

    expect(property_exists($example, 'closedAt'))->toBeTrue()
        ->and($example->closedAt)->toBeNull()
        ->and(EmittedDocument::differences($json, $yaml))->toBe([]);

    unset($example->closedAt);

    expect(EmittedDocument::differences($json, $yaml))
        ->toBe(['/paths/~1accounts/post/responses/201/content/application~1json/example/closedAt: json carries a member yaml does not'])
        ->and(OpenApiMetaSchema::findings('openapi-3.2', $yaml))->toBe([]);
});

/**
 * The order detector's own negative path, on the mutation that motivated it: one serialisation writes
 * `info` then `paths`, the other writes them the other way round, and the same swap one level down.
 * Members, kinds and scalars are identical, so `differences()` reports nothing and both meta-schemas
 * accept both documents — and the encoded bytes are not the same bytes.
 */
it('reports a member-order divergence both other oracles read past', function (): void {
    $ordered = json_decode('{"openapi":"3.2.0","info":{"title":"T","version":"1.0.0"},"paths":{}}', flags: JSON_THROW_ON_ERROR);
    $swapped = json_decode('{"openapi":"3.2.0","paths":{},"info":{"version":"1.0.0","title":"T"}}', flags: JSON_THROW_ON_ERROR);

    // What the two existing oracles say about the pair: nothing, from either side.
    expect(EmittedDocument::differences($ordered, $swapped))->toBe([])
        ->and(OpenApiMetaSchema::findings('openapi-3.2', $ordered))->toBe([])
        ->and(OpenApiMetaSchema::findings('openapi-3.2', $swapped))->toBe([]);

    // What the bytes say, and what the detector says.
    expect(json_encode($swapped, JSON_THROW_ON_ERROR))->not->toBe(json_encode($ordered, JSON_THROW_ON_ERROR));

    expect(EmittedDocument::orderDifferences($ordered, $swapped))->toBe([
        '/: json orders openapi, info, paths, yaml orders openapi, paths, info',
        '/info: json orders title, version, yaml orders version, title',
    ]);

    // And silence on a document compared with itself, so the detector is not simply always positive.
    expect(EmittedDocument::orderDifferences($ordered, $ordered))->toBe([])
        ->and(EmittedDocument::orderedMaps($ordered))->toBe(2);
});

/**
 * Order is only order. A differing key SET belongs to `differences()`, and reporting it in both places
 * would make one defect read as two — so the detector stays silent on a dropped member and the other
 * comparison keeps answering for it.
 */
it('leaves a differing member set to the comparison that owns it', function (): void {
    $full = json_decode('{"openapi":"3.2.0","info":{"title":"T","version":"1.0.0"},"paths":{}}', flags: JSON_THROW_ON_ERROR);
    $short = json_decode('{"openapi":"3.2.0","info":{"title":"T"},"paths":{}}', flags: JSON_THROW_ON_ERROR);

    expect(EmittedDocument::orderDifferences($full, $short))->toBe([])
        ->and(EmittedDocument::differences($full, $short))
        ->toBe(['/info/version: json carries a member yaml does not']);
});

/**
 * A scan that finds nothing must fail. These are the counts the assertions above are worth: well under
 * what the tree holds today, far enough above zero that a fixture glob which stopped matching, or an
 * emitter that started returning empty output, fails here instead of passing on nothing.
 */
it('validates a plausible minimum of documents and positions', function (): void {
    $documents = 0;
    $positions = 0;
    $orderedMaps = 0;

    foreach (metaSchemaSubjects() as [$fixture, $format]) {
        [$json, $yaml] = metaSchemaEmissions($fixture, $format);

        $documents += 2;
        $positions += EmittedDocument::nodes($json) + EmittedDocument::nodes($yaml);

        // The floor the order assertion needs: a map with fewer than two members has no order to get
        // wrong, so a subject set that had lost its multi-member maps would satisfy it on nothing.
        $orderedMaps += EmittedDocument::orderedMaps($json);
    }

    expect(count(metaSchemaFixtures()))->toBeGreaterThanOrEqual(5)
        ->and($documents)->toBeGreaterThanOrEqual(30)
        ->and($positions)->toBeGreaterThanOrEqual(5000)
        ->and($orderedMaps)->toBeGreaterThanOrEqual(300);
});
