<?php

declare(strict_types=1);

use Docuccino\Core\Support\ReasonPhrase;

/**
 * A response is described by the reason phrase of the status it sits under and no other: a registered code
 * by its registered name, an unregistered one by its class (RFC 9110 §15), a range key by its class, and a
 * key naming no status only as a response — never another code's phrase.
 */
it('names every registered code as the registry does, whichever way the code is spelled', function (int $code, string $phrase): void {
    expect(ReasonPhrase::of($code))->toBe($phrase)
        ->and(ReasonPhrase::of((string) $code))->toBe($phrase);
})->with(function (): array {
    $rows = [];
    foreach (ReasonPhrase::registered() as $code => $phrase) {
        $rows[$code.' '.$phrase] = [$code, $phrase];
    }

    return $rows;
});

it('covers the registry, not a sample of it', function (): void {
    // A dataset only proves the rows it lists, so the table is held against a copy of the IANA HTTP status
    // code registry's permanent entries, checked in beside this test. The departures are stated here rather
    // than read back from the table: the two "(Unused)" codes name nothing, 510's "(OBSOLETED)" is a note on
    // the entry rather than its name, and 422 keeps its RFC 4918 name, the one every published error
    // response already carries.
    $rows = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), array_slice(file(dirname(__DIR__).'/Fixtures/http/status-code-registry.csv', FILE_IGNORE_NEW_LINES) ?: [], 1));
    $registry = [];
    foreach ($rows as [$code, $name]) {
        if ($name !== '(Unused)') {
            $registry[(int) $code] = str_replace(' (OBSOLETED)', '', (string) $name);
        }
    }
    $registry[422] = 'Unprocessable Entity';

    expect(count($rows))->toBeGreaterThan(60)
        ->and(ReasonPhrase::registered())->toBe($registry);
});

it('names an unregistered code, or a range, by its class', function (int|string $key, string $class): void {
    expect(ReasonPhrase::of($key))->toBe($class);
})->with([
    [199, 'Informational'],
    [299, 'Successful'],
    [399, 'Redirection'],
    [499, 'Client Error'],
    [599, 'Server Error'],
    ['2XX', 'Successful'],
    ['4XX', 'Client Error'],
]);

it('calls a key that names no status only a response', function (int|string $key): void {
    // Never "OK": that is a claim about a status the key does not name.
    expect(ReasonPhrase::of($key))->toBe('Response');
})->with(['default', '6XX', '', '20', '2000', '099', 600, 99, 0, -200]);
