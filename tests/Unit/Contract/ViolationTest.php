<?php

declare(strict_types=1);

use Docuccino\Core\Contract\Violation;

/**
 * The vocabulary a violation is built from. One sentence per defect, minted in one place: a sentence
 * hand-copied to its callers is a sentence that drifts, and two halves of one product then phrase the
 * same finding two ways.
 */
it('says the same thing about an unresolved reference wherever it is met', function (string $location, string $schemaPointer): void {
    $violation = Violation::unresolvedRef('#/components/schemas/Gone', $location, $schemaPointer);

    expect($violation->message)->toBe('is documented at #/components/schemas/Gone, which the contract does not define')
        ->and($violation->location)->toBe($location)
        ->and($violation->pointer)->toBe('')
        ->and($violation->schemaPointer)->toBe($schemaPointer)
        ->and($violation->provenance->isEmpty())->toBeTrue();
})->with([
    'a response' => ['the response', ''],
    'a request body' => ['the request body', ''],
    'a delivered body' => ['the delivered body', ''],
    'a parameter, which knows where the reference stands' => ['?page', '/paths/~1things/get/parameters/0'],
]);

it('answers for the response when the caller names no half', function (): void {
    $violation = Violation::unresolvedRef('#/components/responses/Gone');

    expect($violation->location)->toBe('the response')
        ->and($violation->where())->toBe('the response');
});

it('mints that sentence in one file and copies it into none', function (): void {
    // It had five holders: four in ContractChecker — a response, a request body, a delivered body, a
    // parameter — and a fifth in the example audit, whose docblock claimed to use "the words
    // ContractChecker already uses for it", which was true only by hand-copying. A sixth is what this
    // catches, and the reason the sentence is worth minting rather than typing.
    $sources = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            dirname(__DIR__, 3).'/src',
            FilesystemIterator::SKIP_DOTS,
        )),
        '/\.php$/',
    );

    $files = 0;
    $holders = [];

    foreach ($sources as $file) {
        $path = (string) $file;
        $files++;

        if (str_contains((string) file_get_contents($path), 'is documented at %s, which the contract does not define')) {
            $holders[] = basename($path);
        }
    }

    sort($holders);

    // Anti-vacuity: a scan that stopped reading the source would agree with an empty list.
    expect($files)->toBeGreaterThan(200)
        ->and($holders)->toBe(['Violation.php']);
});
