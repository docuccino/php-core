<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\DiagnosticCollector;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Examples\ExampleRecording;
use Docuccino\Core\Examples\RecordedExample;
use Docuccino\Core\Examples\RecordedExampleAudit;
use Docuccino\Core\Examples\RecordingStore;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Support\ConfinedPath;

/**
 * Every recording diagnostic is raised here, so this is the dataset over every way a committed
 * directory can be wrong. The publishing half applies the same safety rule silently — see the adapter's
 * RecordedExamplesTest for the pairing.
 */
function auditBase(): string
{
    $base = sys_get_temp_dir().'/docuccino-audit-'.getmypid().'-'.bin2hex(random_bytes(6));
    mkdir($base.'/docs/recordings', 0777, true);

    return $base;
}

/**
 * @return list<Diagnostic>
 */
function auditFindings(string $base, array $document = [], ?string $recordings = 'docs/recordings', array $representation = []): array
{
    $config = new DocumentConfig(
        key: 'default',
        info: ['title' => 'T', 'version' => '1'],
        representation: $representation,
        raw: $recordings === null ? [] : ['examples' => ['recordings' => $recordings]],
    );

    $collector = new DiagnosticCollector;
    (new RecordedExampleAudit($base))->transform(
        new UirDocumentDraft($document),
        new DocumentContext($config, 'doc:default', $collector),
    );

    return $collector->all();
}

function auditDocument(string $operationId, array $statuses = []): array
{
    $responses = array_fill_keys($statuses, ['description' => 'x']);

    return ['paths' => ['/api/invoices' => ['get' => ['x-docuccino' => ['id' => $operationId], 'responses' => $responses]]]];
}

it('says nothing at all for a document that names no recordings', function (): void {
    expect(auditFindings(auditBase(), recordings: null))->toBe([]);
});

it('says a configured directory holds nothing yet, once', function (): void {
    $findings = auditFindings(auditBase());

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBe(Severity::Info)
        ->and($findings[0]->code)->toBe('examples.recordings-empty')
        // The action, not the adapter class that performs it: core says what to do, and the framework
        // it is running under is what names the recorder.
        // The action, and the fact that makes it an action: naming a scenario is what asks for a
        // recording, so a suite whose bootstrap is already right can still record nothing.
        ->and($findings[0]->help)->toContain('run your suite with the recorder registered')
        ->and($findings[0]->help)->toContain('recordAs:')
        ->and($findings[0]->help)->not->toContain('Docuccino\\Laravel');
});

it('reports a recordings directory that was refused for leaving the application', function (string $configured): void {
    $findings = auditFindings(auditBase(), recordings: $configured);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBe(Severity::Warning)
        ->and($findings[0]->code)->toBe('examples.recordings-escapes-base')
        // The key the author typed it under, so they know which document config to go and edit — and the
        // consequence, so a refusal never reads as "nothing was recorded yet".
        ->and($findings[0]->message)->toContain('examples.recordings')
        ->and($findings[0]->message)->toContain($configured)
        ->and($findings[0]->message)->toContain('publishes no recorded examples')
        ->and($findings[0]->help)->toBe(ConfinedPath::CONFIG_FILE_ESCAPED_HELP);
})->with([
    'a traversal out of the application' => ['../../etc'],
    'a traversal that lands back on the root' => ['..'],
]);

it('says nothing twice about a recordings directory no filesystem could hold', function (): void {
    // The adapter reports a NUL byte against the config key that held it, for every path key at once, so
    // this must not add a second voice — and it cannot tell that refusal from any other from here.
    expect(auditFindings(auditBase(), recordings: "docs\0/recordings"))->toBe([]);
});

it('reports a file that is not a recording it can read', function (string $contents): void {
    $base = auditBase();
    file_put_contents($base.'/docs/recordings/op-v1-abcdefgh12345678.json', $contents);

    $findings = auditFindings($base, auditDocument('op:v1:abcdefgh12345678'));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBe(Severity::Warning)
        ->and($findings[0]->code)->toBe('examples.recording-unreadable')
        ->and($findings[0]->message)->toContain('op-v1-abcdefgh12345678.json');
})->with([
    'truncated json' => ['{"docuccino": "recording/1"'],
    'not json' => ['nope'],
    'an empty file' => [''],
    'a format from the future' => ['{"docuccino":"recording/9","operation":"op:v1:abcdefgh12345678","responses":[]}'],
    'a response with no media type' => ['{"docuccino":"recording/1","operation":"op:v1:abcdefgh12345678","responses":[{"status":"200","body":{}}]}'],
]);

it('reports a recording filed under an id that is not the one it holds', function (): void {
    $base = auditBase();
    $store = new RecordingStore($base.'/docs/recordings');
    $store->put(ExampleRecording::of('op:v1:abcdefgh12345678', 'GET /api/invoices'));
    rename(
        $base.'/docs/recordings/op-v1-abcdefgh12345678.json',
        $base.'/docs/recordings/op-v1-zzzzzzzz12345678.json',
    );

    $findings = auditFindings($base, auditDocument('op:v1:zzzzzzzz12345678'));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->code)->toBe('examples.recording-unreadable')
        ->and($findings[0]->message)->toContain('op:v1:abcdefgh12345678');
});

it('reports a recording for an operation the document no longer has', function (): void {
    $base = auditBase();
    (new RecordingStore($base.'/docs/recordings'))->put(
        ExampleRecording::of('op:v1:abcdefgh12345678', 'GET /api/invoices'),
    );

    $findings = auditFindings($base, auditDocument('op:v1:zzzzzzzz12345678'));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBe(Severity::Warning)
        ->and($findings[0]->code)->toBe('examples.recording-orphaned')
        ->and($findings[0]->message)->toContain('GET /api/invoices')
        ->and($findings[0]->help)->toContain('re-record');
});

it('reports a committed recording that still holds a credential, and never quotes it', function (): void {
    $base = auditBase();
    (new RecordingStore($base.'/docs/recordings'))->put(ExampleRecording::of(
        'op:v1:abcdefgh12345678',
        'GET /api/invoices',
        [RecordedExample::of('200', 'application/json', ['api_key' => 'live-secret-value'], 'listed')],
    ));

    $findings = auditFindings($base, auditDocument('op:v1:abcdefgh12345678'));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBe(Severity::Warning)
        ->and($findings[0]->code)->toBe('examples.recording-unsafe')
        ->and($findings[0]->message)->toContain('/api_key')
        ->and($findings[0]->message)->not->toContain('live-secret-value');
});

it('reports a committed recording whose credential is a number the recorder could not replace', function (): void {
    $base = auditBase();
    (new RecordingStore($base.'/docs/recordings'))->put(ExampleRecording::of(
        'op:v1:abcdefgh12345678',
        'GET /api/invoices',
        [RecordedExample::of('200', 'application/json', ['cvv' => 987, 'token_count' => 4], 'listed')],
    ));

    $findings = auditFindings($base, auditDocument('op:v1:abcdefgh12345678'));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->code)->toBe('examples.recording-unsafe')
        ->and($findings[0]->message)->toContain('/cvv')
        ->and($findings[0]->message)->not->toContain('/token_count')
        ->and($findings[0]->message)->not->toContain('987')
        ->and($findings[0]->help)->toContain('lint.leakage.allow');
});

it('says nothing about a clean, claimed, named recording', function (): void {
    $base = auditBase();
    (new RecordingStore($base.'/docs/recordings'))->put(ExampleRecording::of(
        'op:v1:abcdefgh12345678',
        'GET /api/invoices',
        [RecordedExample::of('200', 'application/json', ['id' => 1], 'listed')],
    ));

    expect(auditFindings($base, auditDocument('op:v1:abcdefgh12345678')))->toBe([]);
});

it('reports a committed body no assertion named, once per file', function (): void {
    $base = auditBase();
    (new RecordingStore($base.'/docs/recordings'))->put(ExampleRecording::of(
        'op:v1:abcdefgh12345678',
        'GET /api/invoices',
        [
            RecordedExample::of('200', 'application/json', ['id' => 1]),
            RecordedExample::of('404', 'application/json', ['message' => 'No invoice.']),
        ],
    ));

    $findings = auditFindings($base, auditDocument('op:v1:abcdefgh12345678', ['200', '404']));

    // A recording is asked for one assertion at a time and the name is the asking, so an unnamed body
    // came from a suite recorded before that was true. It still publishes — an upgrade takes no example
    // out of a document — but nothing will ever refresh it, and that is the part an author cannot see.
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBe(Severity::Info)
        ->and($findings[0]->code)->toBe('examples.recording-unnamed')
        ->and($findings[0]->message)->toContain('op-v1-abcdefgh12345678.json')
        ->and($findings[0]->message)->toContain('200 application/json, 404 application/json')
        ->and($findings[0]->message)->toContain('no run can re-record them')
        ->and($findings[0]->help)->toContain('recordAs:');
});

it('says nothing when the configured directory is not there', function (): void {
    expect(auditFindings(auditBase(), recordings: 'docs/nowhere'))->toHaveCount(1)
        ->and(auditFindings(auditBase(), recordings: 'docs/nowhere')[0]->code)->toBe('examples.recordings-empty');
});

it('reports the same thing whatever order the filesystem lists the files in', function (): void {
    $base = auditBase();
    $store = new RecordingStore($base.'/docs/recordings');
    foreach (['zzzzzzzz12345678', 'aaaaaaaa12345678'] as $hash) {
        $store->put(ExampleRecording::of('op:v1:'.$hash, 'GET /api/invoices'));
    }

    $codes = array_map(
        static fn (Diagnostic $d): string => $d->message,
        auditFindings($base, auditDocument('op:v1:mmmmmmmm12345678')),
    );

    expect($codes)->toHaveCount(2)
        ->and($codes[0])->toContain('op-v1-aaaaaaaa12345678.json')
        ->and($codes[1])->toContain('op-v1-zzzzzzzz12345678.json');
});

it('reports the names an error response cannot carry, once per media type', function (): void {
    $base = auditBase();
    (new RecordingStore($base.'/docs/recordings'))->put(ExampleRecording::of('op:v1:abcdefgh12345678', 'GET /api/invoices', [
        RecordedExample::of('403', 'application/json', ['code' => 'a'], 'expired'),
        RecordedExample::of('403', 'application/json', ['code' => 'b'], 'missing'),
        RecordedExample::of('200', 'application/json', ['id' => 1], 'paid'),
    ]));

    $findings = auditFindings($base, auditDocument('op:v1:abcdefgh12345678', ['200', '403']));

    // Info rather than a warning: naming is now how a body is asked for at all, so every recorded
    // error body reaches this, and the one remaining remedy is a document-wide setting.
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBe(Severity::Info)
        ->and($findings[0]->code)->toBe('examples.recording-name-unpublished')
        ->and($findings[0]->message)->toContain('expired, missing')
        ->and($findings[0]->help)->toContain('representation.errors.components');
});

it('says nothing about a name the document can carry after all', function (array $statuses, array $representation): void {
    $base = auditBase();
    (new RecordingStore($base.'/docs/recordings'))->put(ExampleRecording::of('op:v1:abcdefgh12345678', 'GET /api/invoices', [
        RecordedExample::of('403', 'application/json', ['code' => 'a'], 'expired'),
    ]));

    expect(auditFindings($base, auditDocument('op:v1:abcdefgh12345678', $statuses), representation: $representation))->toBe([]);
})->with([
    'a document that shares no error components' => [['403'], ['errors' => ['components' => false]]],
    'a status the document does not document' => [['200'], []],
]);
