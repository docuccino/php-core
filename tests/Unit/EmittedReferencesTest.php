<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Tests\Support\EmittedDocument;
use Docuccino\Core\Tests\Support\EmittedReferences;

/**
 * Every `$ref` an emitted document publishes resolves inside that document.
 *
 * Nothing else in the suite checks it. A golden pins bytes against a committed copy of themselves, so a
 * reference that names nothing is pinned along with everything else; the meta-schemas say only that a
 * `$ref` is a string. That gap is how a 3.0 export shipped `paths./a: {$ref: #/components/pathItems/…}`
 * beside a `components` the same emitter had emptied — a document every validator accepts and every
 * client generator breaks on.
 */

/**
 * Every UIR fixture in the tree, discovered rather than listed, in every OpenAPI format the emitters
 * offer — the subjects the meta-schema oracle reads, checked here for a different kind of lie.
 *
 * @return array<string, array{string, string}>
 */
function referenceSubjects(): array
{
    $formats = array_values(array_filter(Formats::ids(), static fn (string $id): bool => str_starts_with($id, 'openapi-')));
    sort($formats);

    $subjects = [];

    foreach (referenceFixtures() as $fixture) {
        foreach ($formats as $format) {
            $subjects[basename($fixture, '.json').' · '.$format] = [$fixture, $format];
        }
    }

    return $subjects;
}

/** @return list<string> */
function referenceFixtures(): array
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

/** @return array{mixed, mixed} the JSON and YAML emissions of one document, both as graphs */
function referenceEmissions(string $fixture, string $format): array
{
    $document = UirDocument::fromArray(loadFixture($fixture));

    return [
        json_decode(Formats::emit($format, $document, new EmitOptions)->output, flags: JSON_THROW_ON_ERROR),
        EmittedDocument::parseYaml(Formats::emit($format, $document, (new EmitOptions)->withYaml())->output),
    ];
}

it('emits JSON whose every $ref resolves', function (string $fixture, string $format): void {
    [$json] = referenceEmissions($fixture, $format);

    expect(EmittedReferences::dangling($json))->toBe([]);
})->with(referenceSubjects());

it('emits YAML whose every $ref resolves', function (string $fixture, string $format): void {
    [, $yaml] = referenceEmissions($fixture, $format);

    expect(EmittedReferences::dangling($yaml))->toBe([]);
})->with(referenceSubjects());

/**
 * A check that resolves nothing passes forever. These counts are well under what the tree holds and far
 * enough above zero that a fixture glob which stopped matching, or a walk that stopped seeing `$ref`,
 * fails here rather than reporting a clean bill of health over an empty set.
 */
it('resolves a plausible minimum of references', function (): void {
    $references = 0;

    foreach (referenceSubjects() as [$fixture, $format]) {
        [$json, $yaml] = referenceEmissions($fixture, $format);

        $references += count(EmittedReferences::all($json)) + count(EmittedReferences::all($yaml));
    }

    expect(count(referenceSubjects()))->toBeGreaterThanOrEqual(15)
        ->and($references)->toBeGreaterThanOrEqual(60);
});

/**
 * The check's own negative path, on a hand-built document rather than on a live defect: if this passes,
 * a `$ref` naming a member that is not there is a failure the suite can see.
 */
it('reports a reference that names nothing, and nothing when it resolves', function (): void {
    $document = json_decode(<<<'JSON'
        {
          "openapi": "3.0.4",
          "paths": {"/a": {"$ref": "#/components/pathItems/Gone"}},
          "components": {"schemas": {"Thing": {"type": "object"}}}
        }
        JSON, flags: JSON_THROW_ON_ERROR);

    expect(EmittedReferences::dangling($document))
        ->toBe(['/paths/~1a/$ref: $ref names #/components/pathItems/Gone, which the document does not define'])
        ->and(EmittedReferences::all($document))->toBe(['/paths/~1a/$ref' => '#/components/pathItems/Gone']);

    $sound = json_decode('{"a": {"$ref": "#/b/0"}, "b": [{"type": "object"}]}', flags: JSON_THROW_ON_ERROR);

    expect(EmittedReferences::dangling($sound))->toBe([]);
});

it('leaves a reference into another document alone, having nothing to resolve it against', function (): void {
    $document = json_decode('{"paths": {"/a": {"$ref": "shared.yaml#/paths/~1a"}}}', flags: JSON_THROW_ON_ERROR);

    expect(EmittedReferences::all($document))->toBe([])
        ->and(EmittedReferences::dangling($document))->toBe([]);
});

/**
 * The 3.0 downlevel is where a reference goes dangling, because it is the only emitter that removes a
 * member other members point at. Documents here are built inline rather than taken from a fixture: what
 * is being proved is a position, and a fixture would fix one spelling of it.
 */
describe('a $ref to a shared path item', function (): void {
    /**
     * @param  array<string, mixed>  $document
     * @return array{array<string, mixed>, list<string>, list<string>}
     */
    $emit = static function (array $document): array {
        $result = (new OpenApi30DownlevelEmitter)->emitWithReport(UirDocument::fromArray([
            'uir' => '1.0.0',
            'openapi' => '3.2.0',
            'info' => ['title' => 'API', 'version' => '1.0.0'],
            ...$document,
        ]));

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);

        return [
            $decoded,
            array_map(static fn ($d): string => $d->code, $result->report->diagnostics),
            array_map(static fn ($d): string => $d->message, $result->report->diagnostics),
        ];
    };

    /** One shared path item, and the document that would reference it. */
    $shared = ['Shared' => ['get' => ['operationId' => 'shared.get', 'responses' => ['200' => ['description' => 'ok']]]]];

    it('inlines it where it stands, keeping the operation and the reference\'s own wording', function () use ($emit, $shared): void {
        [$decoded, $codes] = $emit([
            'paths' => ['/a' => ['$ref' => '#/components/pathItems/Shared', 'summary' => 'The use site\'s own words']],
            'components' => ['pathItems' => $shared],
        ]);

        // 3.1 reads a `summary` beside such a `$ref` as overriding the one the shared item publishes, so
        // the use site's wording is what survives the inline.
        expect($decoded['paths']['/a'])->toBe([
            'summary' => 'The use site\'s own words',
            'get' => ['operationId' => 'shared.get', 'responses' => ['200' => ['description' => 'ok']]],
        ])
            ->and($codes)->toBe(['downlevel.component-path-items', 'downlevel.path-item-ref'])
            ->and(EmittedReferences::dangling(json_decode(json_encode($decoded, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR)))->toBe([]);
    });

    it('inlines the same one at every site that names it', function () use ($emit, $shared): void {
        [$decoded, $codes] = $emit([
            'paths' => [
                '/a' => ['$ref' => '#/components/pathItems/Shared'],
                '/b' => ['$ref' => '#/components/pathItems/Shared'],
            ],
            'components' => ['pathItems' => $shared],
        ]);

        expect($decoded['paths']['/a'])->toBe($decoded['paths']['/b'])
            ->and($decoded['paths']['/a']['get']['operationId'])->toBe('shared.get')
            ->and(array_count_values($codes)['downlevel.path-item-ref'])->toBe(2);
    });

    it('reaches a path item a callback maps, which references the same bucket', function () use ($emit, $shared): void {
        [$decoded, $codes] = $emit([
            'paths' => ['/a' => ['post' => [
                'responses' => ['202' => ['description' => 'Accepted']],
                'callbacks' => ['onDone' => ['{$request.body#/cb}' => ['$ref' => '#/components/pathItems/Shared']]],
            ]]],
            'components' => ['pathItems' => $shared],
        ]);

        expect($decoded['paths']['/a']['post']['callbacks']['onDone']['{$request.body#/cb}']['get']['operationId'])->toBe('shared.get')
            ->and($codes)->toContain('downlevel.path-item-ref');
    });

    it('follows a chain of them, naming every hop it took', function () use ($emit): void {
        [$decoded, $codes, $messages] = $emit([
            'paths' => ['/a' => ['$ref' => '#/components/pathItems/First']],
            'components' => ['pathItems' => [
                'First' => ['$ref' => '#/components/pathItems/Second'],
                'Second' => ['get' => ['operationId' => 'second.get', 'responses' => ['200' => ['description' => 'ok']]]],
            ]],
        ]);

        expect($decoded['paths']['/a']['get']['operationId'])->toBe('second.get')
            ->and($codes)->toContain('downlevel.path-item-ref')
            ->and(implode(' ', $messages))->toContain('`First` → `Second`');
    });

    /*
     * A dropped path is a 3.0 consumer losing an endpoint, and an inlined one is nothing anybody has to
     * act on. Both were `downlevel.path-item-ref`, and `diagnostics.accept` suppresses by code — so a
     * reader silencing the quiet half silenced the half that says an endpoint disappeared. They are two
     * codes, and the loud one is the new name: an accept entry aimed at the quiet half still names it.
     */
    it('reports a drop under a code of its own, so silencing the quiet half cannot silence it', function () use ($emit, $shared): void {
        [, $dropped] = $emit([
            'paths' => ['/a' => ['$ref' => '#/components/pathItems/Missing']],
            'components' => ['pathItems' => $shared],
        ]);
        [, $inlined] = $emit([
            'paths' => ['/a' => ['$ref' => '#/components/pathItems/Shared']],
            'components' => ['pathItems' => $shared],
        ]);

        expect($dropped)->toContain('downlevel.path-item-unresolved')
            ->and($dropped)->not->toContain('downlevel.path-item-ref')
            ->and($inlined)->toContain('downlevel.path-item-ref')
            ->and($inlined)->not->toContain('downlevel.path-item-unresolved');
    });

    it('drops the path when the bucket defines nothing by that name, naming both halves', function () use ($emit): void {
        // The one answer that is not available: publishing a `$ref` to a member this emitter removed.
        [$decoded, $codes, $messages] = $emit([
            'paths' => ['/a' => ['$ref' => '#/components/pathItems/Missing'], '/b' => ['get' => ['responses' => ['204' => ['description' => 'None']]]]],
            'components' => ['pathItems' => ['Other' => ['get' => ['responses' => ['200' => ['description' => 'ok']]]]]],
        ]);

        expect($decoded['paths'])->not->toHaveKey('/a')
            ->and($decoded['paths'])->toHaveKey('/b')
            ->and($codes)->toContain('downlevel.path-item-unresolved')
            ->and(implode(' ', $messages))->toContain('#/paths/~1a')
            ->and(implode(' ', $messages))->toContain('`Missing`')
            ->and(implode(' ', $messages))->toContain('this document does not define');
    });

    it('names the hop a chain gave up at, not the one it started from', function () use ($emit): void {
        // The interpolated name used to be the first hop, so a two-hop chain sent the author to a
        // component that was perfectly well defined.
        [, , $messages] = $emit([
            'paths' => ['/a' => ['$ref' => '#/components/pathItems/First']],
            'components' => ['pathItems' => ['First' => ['$ref' => '#/components/pathItems/Absent']]],
        ]);

        expect(implode(' ', $messages))->toContain('`First` → `Absent`')
            ->and(implode(' ', $messages))->toContain('this document does not define');
    });

    it('says a chain closed on itself rather than that nothing defines it', function () use ($emit): void {
        // The document DOES define `Loop`, so "define the shared path item" sent the author to create
        // something that was already there. Both halves of the cycle are named, in the order taken.
        [$decoded, $codes, $messages] = $emit([
            'paths' => ['/a' => ['$ref' => '#/components/pathItems/A']],
            'components' => ['pathItems' => [
                'A' => ['$ref' => '#/components/pathItems/B'],
                'B' => ['$ref' => '#/components/pathItems/A'],
            ]],
        ]);

        expect($decoded['paths'])->toBe([])
            ->and($codes)->toContain('downlevel.path-item-unresolved')
            ->and(implode(' ', $messages))->toContain('`A` → `B` → `A`')
            ->and(implode(' ', $messages))->toContain('returns to `A`')
            ->and(implode(' ', $messages))->not->toContain('this document does not define');
    });

    it('drops a path item that references its way back into its own chain', function () use ($emit): void {
        [$decoded, $codes, $messages] = $emit([
            'paths' => ['/a' => ['$ref' => '#/components/pathItems/Loop']],
            'components' => ['pathItems' => ['Loop' => ['$ref' => '#/components/pathItems/Loop']]],
        ]);

        expect($decoded['paths'])->toBe([])
            ->and(array_filter($codes, static fn (string $c): bool => $c === 'downlevel.path-item-unresolved'))->toHaveCount(1)
            ->and(implode(' ', $messages))->toContain('returns to `Loop`');
    });

    it('drops a $ref back to the item being inlined rather than inlining forever', function () use ($emit): void {
        // Reached by a different mechanism — the name is OPEN while its own body is walked, not absent
        // from the bucket — and it is still a cycle, so it must not read as a name nothing defines.
        [$decoded, $codes, $messages] = $emit([
            'paths' => ['/a' => ['$ref' => '#/components/pathItems/Self']],
            'components' => ['pathItems' => ['Self' => ['post' => [
                'responses' => ['202' => ['description' => 'Accepted']],
                'callbacks' => ['again' => ['{$request.body#/cb}' => ['$ref' => '#/components/pathItems/Self']]],
            ]]]],
        ]);

        expect($decoded['paths']['/a']['post']['callbacks']['again'])->toBe([])
            ->and($codes)->toContain('downlevel.path-item-unresolved')
            ->and(implode(' ', $messages))->toContain('returns to `Self`')
            ->and(implode(' ', $messages))->not->toContain('this document does not define');
    });

    it('still resolves a sibling reference to a name an ancestor is inlining', function () use ($emit, $shared): void {
        // Open, not removed: a name being inlined is out of reach INSIDE its own body only.
        [$decoded, $codes] = $emit([
            'paths' => [
                '/a' => ['$ref' => '#/components/pathItems/Shared'],
                '/b' => ['$ref' => '#/components/pathItems/Shared'],
            ],
            'components' => ['pathItems' => $shared],
        ]);

        expect($decoded['paths']['/a'])->toBe($decoded['paths']['/b'])
            ->and($codes)->not->toContain('downlevel.path-item-unresolved');
    });
});
