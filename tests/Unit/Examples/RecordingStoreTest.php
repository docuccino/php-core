<?php

declare(strict_types=1);

use Docuccino\Core\Examples\ExampleRecording;
use Docuccino\Core\Examples\RecordedExample;
use Docuccino\Core\Examples\RecordingStore;
use Docuccino\Core\Extensions\Context\DocumentConfig;

/**
 * The directory of committed recordings: the filename ↔ id mapping both directions, every way a file
 * can fail to be a recording, and the confinement the configured path goes through.
 */
function recordingStoreDir(): string
{
    $dir = sys_get_temp_dir().'/docuccino-recordings-'.getmypid().'-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    return $dir;
}

function recordingStoreConfig(mixed $recordings): DocumentConfig
{
    return new DocumentConfig(
        key: 'default',
        info: ['title' => 'T', 'version' => '1'],
        raw: $recordings === null ? [] : ['examples' => ['recordings' => $recordings]],
    );
}

it('names a file after the operation it records, and reads the id back out of it', function (): void {
    expect(RecordingStore::fileNameFor('op:v1:abcdefgh12345678'))->toBe('op-v1-abcdefgh12345678.json')
        ->and(RecordingStore::operationIdFor('op-v1-abcdefgh12345678.json'))->toBe('op:v1:abcdefgh12345678');
});

it('refuses an id that is not an operation id', function (string $id): void {
    expect(RecordingStore::fileNameFor($id))->toBeNull();
})->with([
    'a schema id' => ['sch:v1:abcdefgh12345678'],
    'a response id' => ['res:v1:abcdefgh12345678'],
    'a document id' => ['doc:default'],
    'a hash of the wrong length' => ['op:v1:abcdefgh'],
    'a hash outside the alphabet' => ['op:v1:ABCDEFGH12345678'],
    'a traversal attempt' => ['op:v1:../../etc/passwd'],
    'nothing at all' => [''],
]);

it('refuses a filename that is not a recording filename', function (string $file): void {
    expect(RecordingStore::operationIdFor($file))->toBeNull();
})->with([
    'another package\'s json' => ['package.json'],
    'the right name with the wrong extension' => ['op-v1-abcdefgh12345678.yaml'],
    'a hash of the wrong length' => ['op-v1-abcdefgh.json'],
    'a different kind of node' => ['sch-v1-abcdefgh12345678.json'],
    'a dotfile' => ['.gitkeep'],
]);

it('writes a recording and reads it back', function (): void {
    $store = new RecordingStore(recordingStoreDir());
    $recording = ExampleRecording::of('op:v1:abcdefgh12345678', 'GET /api/invoices', [
        RecordedExample::of('200', 'application/json', ['id' => 1]),
    ]);

    expect($store->put($recording))->toBeTrue()
        ->and($store->read('op:v1:abcdefgh12345678')?->toArray())->toBe($recording->toArray())
        ->and($store->fileNames())->toBe(['op-v1-abcdefgh12345678.json']);
});

it('creates the directory it was pointed at', function (): void {
    $store = new RecordingStore(recordingStoreDir().'/nested/deeper');

    expect($store->put(ExampleRecording::of('op:v1:abcdefgh12345678', 'GET /x')))->toBeTrue()
        ->and($store->fileNames())->toBe(['op-v1-abcdefgh12345678.json']);
});

it('writes the same bytes twice without touching the file', function (): void {
    $store = new RecordingStore(recordingStoreDir());
    $recording = ExampleRecording::of('op:v1:abcdefgh12345678', 'GET /x', [
        RecordedExample::of('200', 'application/json', ['id' => 1]),
    ]);

    $store->put($recording);
    $path = $store->directory.'/op-v1-abcdefgh12345678.json';
    touch($path, 1_000_000_000);
    clearstatcache();

    expect($store->put($recording))->toBeTrue()
        ->and(filemtime($path))->toBe(1_000_000_000);
});

it('reads an empty directory as no recordings rather than as a failure', function (): void {
    $store = new RecordingStore(recordingStoreDir());

    expect($store->fileNames())->toBe([])
        ->and($store->read('op:v1:abcdefgh12345678'))->toBeNull();
});

it('reads a directory that is not there as no recordings', function (): void {
    $store = new RecordingStore(recordingStoreDir().'/never-created');

    expect($store->fileNames())->toBe([])
        ->and($store->read('op:v1:abcdefgh12345678'))->toBeNull();
});

it('lists its files in a fixed order whatever the filesystem hands back', function (): void {
    $store = new RecordingStore(recordingStoreDir());
    foreach (['op-v1-zzzzzzzz12345678.json', 'op-v1-aaaaaaaa12345678.json', 'notes.md'] as $file) {
        file_put_contents($store->directory.'/'.$file, '{}');
    }

    expect($store->fileNames())->toBe(['op-v1-aaaaaaaa12345678.json', 'op-v1-zzzzzzzz12345678.json']);
});

it('reads a file that is not a recording as no recording', function (string $contents): void {
    $store = new RecordingStore(recordingStoreDir());
    file_put_contents($store->directory.'/op-v1-abcdefgh12345678.json', $contents);

    expect($store->read('op:v1:abcdefgh12345678'))->toBeNull();
})->with([
    'truncated json' => ['{"docuccino": "recording/1", "operation":'],
    'not json at all' => ['operation: op:v1:abcdefgh12345678'],
    'an empty file' => [''],
    'a json list' => ['[]'],
    'a json scalar' => ['"recording"'],
    'an empty json object' => ['{}'],
    'a document from some other tool' => ['{"openapi": "3.1.0"}'],
]);

it('takes the recordings directory from the document config', function (): void {
    $base = recordingStoreDir();

    expect(RecordingStore::for(recordingStoreConfig('docs/recordings'), $base)?->directory)
        ->toBe($base.'/docs/recordings');
});

it('has no store when the document names no recordings', function (mixed $configured): void {
    expect(RecordingStore::for(recordingStoreConfig($configured), '/app'))->toBeNull();
})->with([
    'unset' => [null],
    'empty' => [''],
    'not a string' => [['docs/recordings']],
]);

it('refuses a recordings directory outside the application', function (): void {
    expect(RecordingStore::for(recordingStoreConfig('../../etc'), '/app/base'))->toBeNull();
});

it('takes an absolute recordings directory as given', function (): void {
    expect(RecordingStore::for(recordingStoreConfig('/var/recordings'), '/app')?->directory)
        ->toBe('/var/recordings');
});

it('has nowhere to write a recording whose id is not an operation id', function (): void {
    $store = new RecordingStore(recordingStoreDir());

    expect($store->pathFor('doc:default'))->toBeNull()
        ->and($store->put(ExampleRecording::of('doc:default', 'GET /x')))->toBeFalse();
});
