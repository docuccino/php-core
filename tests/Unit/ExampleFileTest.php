<?php

declare(strict_types=1);

use Docuccino\Core\Support\ExampleFile;

/**
 * The reader behind `#[Example(file: …)]`: the formats it decodes, and every way a read can fail. A
 * failure keeps the resolved path wherever there was one, because the caller registers it as a cache
 * dependency either way — a file that isn't there yet must still rebuild the route when it appears.
 */
beforeEach(function (): void {
    $this->base = sys_get_temp_dir().'/docuccino-example-file-'.uniqid('', true);
    mkdir($this->base.'/nested', 0755, true);
});

afterEach(function (): void {
    foreach ((array) glob($this->base.'/{,nested/}*', GLOB_BRACE) as $file) {
        @unlink((string) $file);
    }
    @rmdir($this->base.'/nested');
    @rmdir($this->base);
});

it('decodes every format it lists', function (string $extension, string $contents, mixed $expected): void {
    file_put_contents($this->base.'/example.'.$extension, $contents);

    $read = ExampleFile::read($this->base, 'example.'.$extension);

    expect($read->ok())->toBeTrue()
        ->and($read->error)->toBeNull()
        ->and($read->value)->toBe($expected)
        ->and($read->path)->toBe($this->base.'/example.'.$extension);
})->with([
    'json' => ['json', '{"id": 1, "name": "Sprocket"}', ['id' => 1, 'name' => 'Sprocket']],
    'yaml' => ['yaml', "id: 1\nname: Sprocket\n", ['id' => 1, 'name' => 'Sprocket']],
    'yml' => ['yml', "id: 1\nname: Sprocket\n", ['id' => 1, 'name' => 'Sprocket']],
]);

it('lists exactly the formats it decodes', function (): void {
    expect(ExampleFile::FORMATS)->toBe(['json', 'yaml', 'yml']);
});

it('refuses a format it does not list, naming what it was given', function (string $name, string $detail): void {
    file_put_contents($this->base.'/'.$name, 'hello');

    $read = ExampleFile::read($this->base, $name);

    expect($read->ok())->toBeFalse()
        ->and($read->error)->toBe(ExampleFile::INVALID)
        ->and($read->detail)->toContain($detail)
        ->and($read->path)->toBe($this->base.'/'.$name);
})->with([
    'a text file' => ['example.txt', '".txt" is not a format'],
    'no extension at all' => ['example', 'no extension'],
]);

it('reports a file that is not there rather than guessing at one', function (): void {
    $read = ExampleFile::read($this->base, 'nested/absent.json');

    expect($read->ok())->toBeFalse()
        ->and($read->error)->toBe(ExampleFile::MISSING)
        ->and($read->value)->toBeNull()
        // Confined and named, so the caller can still depend on it: creating it must rebuild.
        ->and($read->path)->toBe($this->base.'/nested/absent.json');
});

it('reports a payload it cannot parse instead of half-decoding it', function (string $name, string $contents): void {
    file_put_contents($this->base.'/'.$name, $contents);

    $read = ExampleFile::read($this->base, $name);

    expect($read->ok())->toBeFalse()
        ->and($read->error)->toBe(ExampleFile::INVALID)
        ->and($read->value)->toBeNull()
        ->and($read->detail)->not->toBe('');
})->with([
    'truncated json' => ['broken.json', '{"id": '],
    'a yaml tab' => ['broken.yaml', "root:\n\t- indented with a tab\n"],
]);

it('escapes a parser message rather than letting file contents steer a terminal', function (): void {
    file_put_contents($this->base.'/broken.yaml', "root:\n\t- \x1b[31mred\n");

    $read = ExampleFile::read($this->base, 'broken.yaml');

    expect($read->error)->toBe(ExampleFile::INVALID)
        ->and($read->detail)->not->toContain("\x1b")
        ->and($read->detail)->not->toContain("\n");
});

it('rejects a path that escapes the base without reading anything', function (): void {
    $read = ExampleFile::read($this->base, '../../etc/passwd');

    expect($read->ok())->toBeFalse()
        ->and($read->error)->toBe(ExampleFile::ESCAPED)
        ->and($read->path)->toBeNull()
        ->and($read->value)->toBeNull();
});

it('decodes a document that holds nothing as nothing, and says so through the value', function (): void {
    file_put_contents($this->base.'/null.json', 'null');

    $read = ExampleFile::read($this->base, 'null.json');

    // The read itself worked — there is simply no example in it, which is the caller's call to make.
    expect($read->ok())->toBeTrue()
        ->and($read->value)->toBeNull();
});

it('refuses a value that parses but no JSON document can hold', function (string $contents): void {
    // YAML spells things JSON has no form for. Handing one back left the canonical writer to find it at
    // emit time, where it threw naming neither the file nor the attribute — a dead build with nothing in
    // the message to act on.
    file_put_contents($this->base.'/example.yaml', $contents);

    $read = ExampleFile::read($this->base, 'example.yaml');

    expect($read->ok())->toBeFalse()
        ->and($read->error)->toBe(ExampleFile::INVALID)
        ->and($read->value)->toBeNull()
        ->and($read->detail)->toContain('non-finite')
        // Still named, so the route still depends on it and fixing the file rebuilds.
        ->and($read->path)->toBe($this->base.'/example.yaml');
})->with([
    'not a number' => ["ratio: .nan\n"],
    'an infinity' => ["ceiling: .inf\n"],
    'a negative infinity' => ["floor: -.inf\n"],
    'a bare infinity' => [".inf\n"],
]);

it('decodes an unquoted date the way YAML does, as a timestamp', function (): void {
    // Not something to fix here: YAML says an unquoted `2020-01-01` is a date, and the alternative
    // spellings are all worse than telling an author to quote it. Pinned so it cannot change silently.
    file_put_contents($this->base.'/example.yaml', "when: 2020-01-01\n");

    expect(ExampleFile::read($this->base, 'example.yaml')->value)->toBe(['when' => 1577836800])
        ->and(ExampleFile::read($this->base, 'example.yaml')->ok())->toBeTrue();
});

it('decodes a scalar and a list as readily as an object', function (mixed $expected, string $contents): void {
    file_put_contents($this->base.'/example.json', $contents);

    expect(ExampleFile::read($this->base, 'example.json')->value)->toBe($expected);
})->with([
    'a string' => ['SKU-4711', '"SKU-4711"'],
    'a number' => [42, '42'],
    'a list' => [[1, 2, 3], '[1, 2, 3]'],
    'false' => [false, 'false'],
]);
