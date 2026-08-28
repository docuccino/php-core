<?php

declare(strict_types=1);

use Docuccino\Core\SpecValidation\Validator;

/**
 * The hand-written fixtures are the input most of this suite reasons about, so a fixture the
 * product could never have emitted proves nothing about the product. Anything under Fixtures/
 * that declares a `uir` version is held here to the schema it claims: nothing read the corpus
 * that way before, and 22 node ids sat at the wrong length with the whole suite green.
 */

/**
 * Every fixture file presenting itself as a UIR document, keyed by file name.
 *
 * `uir` is what makes the claim — the OpenAPI and Postman meta-schemas living in the same
 * directory are somebody else's documents and are not held to ours.
 *
 * @return array<string, array<string, mixed>>
 */
$uirFixtures = static function (): array {
    $documents = [];

    foreach (glob(dirname(__DIR__).'/Fixtures/*.json') ?: [] as $path) {
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (is_array($decoded) && array_key_exists('uir', $decoded)) {
            /** @var array<string, mixed> $decoded */
            $documents[basename($path)] = $decoded;
        }
    }

    ksort($documents);

    return $documents;
};

it('holds every fixture that claims to be a UIR document to the UIR schema', function () use ($uirFixtures): void {
    $validator = new Validator;
    $documents = $uirFixtures();

    // A walk that matches nothing must fail rather than pass forever.
    expect($documents)->toHaveKeys(['contract.uir.json', 'kitchen-sink.uir.json'])
        ->and(count($documents))->toBeGreaterThanOrEqual(8);

    $failures = [];
    foreach ($documents as $name => $document) {
        $result = $validator->validate($document);

        if (! $result->isValid()) {
            $failures[$name] = $result->messages();
        }
    }

    expect($failures)->toBe([]);
});

it('holds every fixture node id to the id grammar the schema states', function () use ($uirFixtures): void {
    $schema = json_decode((string) file_get_contents(Validator::defaultSchemaPath()), true, flags: JSON_THROW_ON_ERROR);

    // Read out of the schema rather than restated here, so the guard tracks the grammar it guards.
    $defs = is_array($schema) ? ($schema['$defs'] ?? null) : null;
    $nodeId = is_array($defs) ? ($defs['nodeId'] ?? null) : null;
    $pattern = is_array($nodeId) ? ($nodeId['pattern'] ?? null) : null;

    expect($pattern)->toBeString();

    $delimited = '/'.str_replace('/', '\/', (string) $pattern).'/';

    $offenders = [];
    $seen = 0;
    foreach ($uirFixtures() as $name => $document) {
        foreach (nodeIdsIn($document) as $pointer => $id) {
            $seen++;

            if (preg_match($delimited, $id) !== 1) {
                $offenders[] = $name.$pointer.': '.$id;
            }
        }
    }

    // Schema validation reports one failure at a time, so it would take 22 runs to surface 22 bad
    // ids; this lists them at once. The floor is what stops a walk that has stopped walking.
    expect($seen)->toBeGreaterThanOrEqual(40)
        ->and($offenders)->toBe([]);
});
