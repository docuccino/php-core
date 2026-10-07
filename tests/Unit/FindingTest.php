<?php

declare(strict_types=1);

use Docuccino\Core\SpecValidation\Finding;

/**
 * A finding is data, and its sentence is the line every spec check printed before it was data. Each
 * shape a check reports is pinned here, because the sentence is what reaches a person — inside a
 * diagnostic message, a test failure, a CI log — and none of those may read differently for the change.
 */
it('reads as the line each check has always printed', function (Finding $finding, string $line): void {
    expect((string) $finding)->toBe($line);
})->with([
    'a meta-schema keyword' => [
        new Finding('/paths', 'type', 'The data (array) must match the type: object', '/$defs/paths'),
        '/paths type: The data (array) must match the type: object (schema /$defs/paths)',
    ],
    'the document root' => [
        new Finding('/', 'required', 'The required properties (info) are missing', ''),
        '/ required: The required properties (info) are missing (schema )',
    ],
    'a rule no schema states' => [
        new Finding('/paths/~1a/get', 'operationId', '"x" is used by /paths/~1a/get, /paths/~1b/get'),
        '/paths/~1a/get operationId: "x" is used by /paths/~1a/get, /paths/~1b/get',
    ],
    'a rule whose message names the member' => [
        new Finding('/paths/~1a/get/responses/404/x-docuccino', null, 'a Reference Object cannot carry "x-docuccino" beside its $ref'),
        '/paths/~1a/get/responses/404/x-docuccino: a Reference Object cannot carry "x-docuccino" beside its $ref',
    ],
]);

it('sorts by its line, so a reader walks the document top to bottom', function (): void {
    $b = new Finding('/paths/~1b', '$ref', 'b');
    $a = new Finding('/paths/~1a', '$ref', 'a');
    $root = new Finding('/info', 'required', 'r', '/$defs/info');

    expect(Finding::sorted([$b, $root, $a]))->toBe([$root, $a, $b]);
});
