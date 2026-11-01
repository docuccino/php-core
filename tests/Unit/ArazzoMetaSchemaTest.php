<?php

declare(strict_types=1);

use Docuccino\Core\Reader\Arazzo\ArazzoVersion;
use Docuccino\Core\SpecValidation\ArazzoMetaSchema;

/*
 * The Arazzo schemas the package ships to read descriptions with: each pinned by the id it declares and by
 * its content, every file shipped is one the table names, and every version the reader knows has one.
 */

it('pins each shipped Arazzo schema by the id it declares and by its content', function (string $version): void {
    $path = ArazzoMetaSchema::path($version);
    $declared = json_decode((string) file_get_contents($path), flags: JSON_THROW_ON_ERROR);

    expect($declared)->toBeInstanceOf(stdClass::class)
        ->and($declared->{'$id'} ?? null)->toBe(ArazzoMetaSchema::publishedId($version))
        ->and(ArazzoMetaSchema::publishedId($version))->toStartWith('https://spec.openapis.org/arazzo/'.$version.'/schema/')
        ->and(hash_file('sha256', $path))->toBe(ArazzoMetaSchema::digest($version));
})->with(array_keys(ArazzoMetaSchema::SCHEMAS));

it('ships exactly the schemas it pins, one for every version the reader knows', function (): void {
    $shipped = array_map(basename(...), (array) glob(dirname(__DIR__, 2).'/resources/arazzo/*'));
    sort($shipped);

    $pinned = array_column(ArazzoMetaSchema::SCHEMAS, 'file');
    sort($pinned);

    expect($shipped)->toBe($pinned)
        ->and(array_keys(ArazzoMetaSchema::SCHEMAS))->toBe(array_map(static fn (ArazzoVersion $version): string => $version->value, ArazzoVersion::cases()))
        ->and((string) file_get_contents(dirname(__DIR__, 2).'/NOTICE'))->toContain(...array_map(static fn (string $file): string => 'resources/arazzo/'.$file, $pinned));
});

it('refuses a version it has no schema for', function (): void {
    expect(static fn () => ArazzoMetaSchema::path('2.0'))
        ->toThrow(RuntimeException::class, 'No vendored Arazzo schema for version "2.0".');
});
