<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Docuccino\Core\Examples\ExampleRecording;
use Docuccino\Core\Examples\ProcessRecordingLedger;
use Docuccino\Core\Examples\RecordedBody;
use Docuccino\Core\Examples\RecordedExample;
use Docuccino\Core\Examples\RecordingStore;
use Docuccino\Core\Examples\SharedRecordingLedger;
use Docuccino\Core\Examples\UnlockableRecording;

/**
 * The two ledgers, and the one claim that lets the second one exist: a recording is the same file
 * whether one process wrote it or six workers raced for it.
 *
 * The rank is a total order on the bodies themselves, so the best of a set does not depend on which
 * worker met which member of it — these run the orderings and the workers rather than asserting that.
 */
const LEDGER_OPERATION = 'op:v1:abcdefgh12345678';

/**
 * @return array{0: string, 1: string} the recordings directory and the scratch root, both empty
 */
function ledgerDirectories(): array
{
    $base = sys_get_temp_dir().'/docuccino-ledger-'.getmypid().'-'.bin2hex(random_bytes(6));

    mkdir($base.'/recordings', 0777, true);
    mkdir($base.'/scratch', 0777, true);

    return [$base.'/recordings', $base.'/scratch'];
}

function ledgerRemove(string $path): void
{
    foreach (glob($path.'/*') ?: [] as $entry) {
        // A link is unlinked, never followed: one of these tests plants a link to a directory, and
        // descending through it would empty whatever it points at.
        is_dir($entry) && ! is_link($entry) ? ledgerRemove($entry) : @unlink($entry);
    }

    @rmdir($path);
}

/**
 * The candidates a suite offers, deliberately mixed: two shapes, two ranks and a second status, so a
 * reordering that changed the answer would have something to change it to.
 *
 * @return list<RecordedExample>
 */
function ledgerCandidates(): array
{
    return [
        RecordedExample::of('200', 'application/json', ['id' => 1]),
        RecordedExample::of('200', 'application/json', ['id' => 2, 'note' => 'paid']),
        RecordedExample::of('200', 'application/json', ['id' => 3, 'note' => 'part paid']),
        RecordedExample::of('404', 'application/json', ['message' => 'No invoice.']),
    ];
}

/**
 * @param  list<int>  $order
 * @return list<list<int>>
 */
function ledgerOrderings(array $order): array
{
    if (count($order) <= 1) {
        return [$order];
    }

    $out = [];
    foreach ($order as $index => $value) {
        $rest = $order;
        unset($rest[$index]);

        foreach (ledgerOrderings(array_values($rest)) as $tail) {
            $out[] = [$value, ...$tail];
        }
    }

    return $out;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/docuccino-ledger-'.getmypid().'-*') ?: [] as $base) {
        ledgerRemove($base);
    }
});

it('publishes the best of a run whichever process met it, and never the one that arrived last', function (): void {
    [$recordings, $scratch] = ledgerDirectories();
    $store = new RecordingStore($recordings);
    $candidates = ledgerCandidates();

    $serial = new ProcessRecordingLedger($store);
    foreach ($candidates as $candidate) {
        $serial->record(LEDGER_OPERATION, 'GET /api/invoices/{invoice}', $candidate);
    }

    $expected = (string) file_get_contents((string) $store->pathFor(LEDGER_OPERATION));

    foreach (ledgerOrderings([0, 1, 2, 3]) as $run => $ordering) {
        @unlink((string) $store->pathFor(LEDGER_OPERATION));

        // Three workers with the candidates dealt round-robin between them, meeting them in this
        // order — one run of the suite, split differently every time.
        $workers = [];
        foreach ($ordering as $index) {
            $worker = $index % 3;
            $workers[$worker] ??= new SharedRecordingLedger($store, 'run-'.$run, $scratch);
            $workers[$worker]->record(LEDGER_OPERATION, 'GET /api/invoices/{invoice}', $candidates[$index]);
        }

        expect(file_get_contents((string) $store->pathFor(LEDGER_OPERATION)))->toBe($expected);
    }
});

it('publishes the best object-shaped body whichever worker met it', function (): void {
    // The interleavings above deal ARRAY bodies. A JSON object whose keys an array cannot carry — the
    // ordinary keyBy('id') payload — is the case where every candidate once ranked equal, and the
    // survivor was whichever worker merged first.
    [$recordings, $scratch] = ledgerDirectories();
    $store = new RecordingStore($recordings);

    $candidates = array_map(
        static fn (string $json): RecordedExample => RecordedExample::of('200', 'application/json', RecordedBody::decode($json)),
        [
            '{"1":{"id":1}}',
            '{"2":{"id":2,"note":"paid"}}',
            '{"3":{"id":3,"note":"part paid"}}',
        ],
    );

    $serial = new ProcessRecordingLedger($store);
    foreach ($candidates as $candidate) {
        $serial->record(LEDGER_OPERATION, 'GET /api/invoices', $candidate);
    }
    $expected = (string) file_get_contents((string) $store->pathFor(LEDGER_OPERATION));

    foreach (ledgerOrderings([0, 1, 2]) as $run => $ordering) {
        @unlink((string) $store->pathFor(LEDGER_OPERATION));

        $workers = [];
        foreach ($ordering as $index) {
            $worker = $index % 2;
            $workers[$worker] ??= new SharedRecordingLedger($store, 'object-run-'.$run, $scratch);
            $workers[$worker]->record(LEDGER_OPERATION, 'GET /api/invoices', $candidates[$index]);
        }

        expect(file_get_contents((string) $store->pathFor(LEDGER_OPERATION)))->toBe($expected);
    }
});

it('carries an empty object through the session file a second worker reads', function (): void {
    // The session file is how a body reaches the worker that did not record it, and it was read with an
    // associative decode while the committed sidecar beside it went through `RecordedBody`. So `{}` came
    // back as `[]` — and which worker happened to win a slot decided whether the published example kept
    // its shape.
    [$recordings, $scratch] = ledgerDirectories();
    $store = new RecordingStore($recordings);

    $body = RecordedBody::decode('{"id":1,"meta":{},"tags":[]}');

    foreach ([0, 1] as $worker) {
        (new SharedRecordingLedger($store, 'empty-object-run', $scratch))
            ->record(LEDGER_OPERATION, 'GET /api/invoices', RecordedExample::of('200', 'application/json', $body));
    }

    expect(file_get_contents((string) $store->pathFor(LEDGER_OPERATION)))
        ->toContain('"meta": {}')
        ->not->toContain('"meta": []');
});

it('leaves a committed body alone across workers while its shape is unchanged', function (): void {
    [$recordings, $scratch] = ledgerDirectories();
    $store = new RecordingStore($recordings);

    $store->put(ExampleRecording::of(LEDGER_OPERATION, 'GET /api/invoices/{invoice}', [
        RecordedExample::of('200', 'application/json', ['id' => 1, 'note' => 'committed']),
    ]));
    $committed = (string) file_get_contents((string) $store->pathFor(LEDGER_OPERATION));

    // A second worker's session has to start from what was committed too, or the first worker's write
    // would become the thing the shape rule compares against.
    (new SharedRecordingLedger($store, 'run-1', $scratch))
        ->record(LEDGER_OPERATION, 'GET /api/invoices/{invoice}', RecordedExample::of('200', 'application/json', ['id' => 2, 'note' => 'later']));
    (new SharedRecordingLedger($store, 'run-1', $scratch))
        ->record(LEDGER_OPERATION, 'GET /api/invoices/{invoice}', RecordedExample::of('200', 'application/json', ['id' => 3, 'note' => 'later still']));

    expect(file_get_contents((string) $store->pathFor(LEDGER_OPERATION)))->toBe($committed);
});

it('starts a later run from the file as it stands, not from what the last one was accumulating', function (): void {
    [$recordings, $scratch] = ledgerDirectories();
    $store = new RecordingStore($recordings);

    (new SharedRecordingLedger($store, 'run-1', $scratch))->record(
        LEDGER_OPERATION,
        'GET /api/invoices/{invoice}',
        RecordedExample::of('200', 'application/json', ['id' => 1, 'note' => 'paid', 'total' => 10]),
    );

    // A worse-ranked body of a different shape: the suite changed, and a run that inherited the last
    // one's winners would go on publishing a response the application no longer gives.
    (new SharedRecordingLedger($store, 'run-2', $scratch))->record(
        LEDGER_OPERATION,
        'GET /api/invoices/{invoice}',
        RecordedExample::of('200', 'application/json', ['id' => 2]),
    );

    expect($store->read(LEDGER_OPERATION)?->find('200', 'application/json')?->body)->toBe(['id' => 2]);
});

it('records a named scenario beside the others it belongs with', function (): void {
    [$recordings, $scratch] = ledgerDirectories();
    $store = new RecordingStore($recordings);

    foreach ([['empty-cart', []], ['full-cart', [['sku' => 'A']]]] as [$name, $items]) {
        (new SharedRecordingLedger($store, 'run-1', $scratch))->record(
            LEDGER_OPERATION,
            'GET /api/carts',
            RecordedExample::of('200', 'application/json', ['items' => $items], $name),
        );
    }

    expect(array_map(
        static fn (RecordedExample $e): string => $e->name,
        $store->read(LEDGER_OPERATION)?->responses ?? [],
    ))->toBe(['empty-cart', 'full-cart']);
});

it('starts again rather than trusting a session file it cannot read', function (): void {
    [$recordings, $scratch] = ledgerDirectories();
    $store = new RecordingStore($recordings);

    (new SharedRecordingLedger($store, 'run-1', $scratch))->record(
        LEDGER_OPERATION,
        'GET /api/invoices/{invoice}',
        RecordedExample::of('200', 'application/json', ['id' => 1]),
    );

    $sessions = glob($scratch.'/*/op-*.json') ?: [];
    expect($sessions)->toHaveCount(1);
    file_put_contents($sessions[0], '{oops');

    (new SharedRecordingLedger($store, 'run-1', $scratch))->record(
        LEDGER_OPERATION,
        'GET /api/invoices/{invoice}',
        RecordedExample::of('200', 'application/json', ['id' => 2, 'note' => 'paid']),
    );

    expect($store->read(LEDGER_OPERATION)?->find('200', 'application/json')?->body)
        ->toBe(['id' => 2, 'note' => 'paid']);
});

it('writes nothing at all when it cannot take the lock', function (): void {
    [$recordings, $scratch] = ledgerDirectories();
    $store = new RecordingStore($recordings);

    // A file where the scratch directory would go: the lock cannot be made, and half-merging is not an
    // answer a recording is allowed to give.
    file_put_contents($scratch.'/blocked', '');

    expect(fn () => (new SharedRecordingLedger($store, 'run-1', $scratch.'/blocked'))->record(
        LEDGER_OPERATION,
        'GET /api/invoices/{invoice}',
        RecordedExample::of('200', 'application/json', ['id' => 1]),
    ))->toThrow(UnlockableRecording::class);

    expect($store->fileNames())->toBe([]);
});

it('refuses a scratch directory somebody else could have left there', function (Closure $plant): void {
    // The path is derivable by anyone on the machine, so a directory found under it is only usable
    // when it is this user's alone. One that is not would put somebody else's bodies into the
    // recordings an author commits, and hand them everything this run recorded.
    [$recordings, $scratch] = ledgerDirectories();
    $store = new RecordingStore($recordings);

    $plant($scratch.'/docuccino-recordings-'.substr(sha1($recordings), 0, 16).'-run-1');

    expect(fn () => (new SharedRecordingLedger($store, 'run-1', $scratch))->record(
        LEDGER_OPERATION,
        'GET /api/invoices/{invoice}',
        RecordedExample::of('200', 'application/json', ['id' => 1]),
    ))->toThrow(UnlockableRecording::class);

    expect($store->fileNames())->toBe([]);
})->with([
    'a link to somewhere else' => [fn (string $path): bool => mkdir($path.'-target') && symlink($path.'-target', $path)],
    'a directory anybody can write to' => [fn (string $path): bool => mkdir($path, 0777, true) && chmod($path, 0777)],
]);

it('creates its scratch directory private to this user', function (): void {
    [$recordings, $scratch] = ledgerDirectories();
    $store = new RecordingStore($recordings);

    (new SharedRecordingLedger($store, 'run-1', $scratch))->record(
        LEDGER_OPERATION,
        'GET /api/invoices/{invoice}',
        RecordedExample::of('200', 'application/json', ['id' => 1]),
    );

    $created = glob($scratch.'/docuccino-recordings-*') ?: [];

    expect($created)->toHaveCount(1)
        ->and(fileperms($created[0]) & 0o777)->toBe(0o700);
});

it('ignores an operation id no recording could be filed under', function (): void {
    [$recordings, $scratch] = ledgerDirectories();
    $store = new RecordingStore($recordings);

    (new SharedRecordingLedger($store, 'run-1', $scratch))->record(
        'not-an-operation-id',
        'GET /api/invoices/{invoice}',
        RecordedExample::of('200', 'application/json', ['id' => 1]),
    );

    expect($store->fileNames())->toBe([])
        ->and(glob($scratch.'/*') ?: [])->toBe([]);
});

it('is the same file when four processes really do race for it', function (): void {
    [$recordings, $scratch] = ledgerDirectories();
    $store = new RecordingStore($recordings);

    $workers = 4;
    $each = 3;
    $candidates = [];
    for ($worker = 0; $worker < $workers; $worker++) {
        for ($index = 0; $index < $each; $index++) {
            $candidates[] = RecordedExample::of('200', 'application/json', [
                'id' => $worker * $each + $index,
                'note' => str_repeat('n', $index),
            ]);
        }
    }

    $serial = new ProcessRecordingLedger($store);
    foreach ($candidates as $candidate) {
        $serial->record(LEDGER_OPERATION, 'GET /api/invoices/{invoice}', $candidate);
    }
    $expected = (string) file_get_contents((string) $store->pathFor(LEDGER_OPERATION));
    @unlink((string) $store->pathFor(LEDGER_OPERATION));

    $autoload = dirname((string) (new ReflectionClass(ClassLoader::class))->getFileName(), 2).'/autoload.php';
    $script = $scratch.'/worker.php';
    file_put_contents($script, <<<'WORKER'
    <?php

    require $argv[1];

    [$recordings, $scratch, $worker, $each, $startAt] = array_slice($argv, 2);

    $ledger = new Docuccino\Core\Examples\SharedRecordingLedger(
        new Docuccino\Core\Examples\RecordingStore($recordings),
        'race',
        $scratch,
    );

    // Every worker starts writing on the same clock tick, so the lock is genuinely contended.
    while (microtime(true) < (float) $startAt) {
        usleep(200);
    }

    for ($index = 0; $index < (int) $each; $index++) {
        $ledger->record('op:v1:abcdefgh12345678', 'GET /api/invoices/{invoice}', Docuccino\Core\Examples\RecordedExample::of(
            '200',
            'application/json',
            ['id' => (int) $worker * (int) $each + $index, 'note' => str_repeat('n', $index)],
        ));
    }
    WORKER);

    $startAt = microtime(true) + 0.5;
    $processes = [];
    for ($worker = 0; $worker < $workers; $worker++) {
        $process = proc_open(
            [PHP_BINARY, $script, $autoload, $recordings, $scratch, (string) $worker, (string) $each, (string) $startAt],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        expect($process)->not->toBeFalse();
        $processes[] = [$process, $pipes];
    }

    foreach ($processes as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        expect(proc_close($process))->toBe(0, $output);
    }

    expect(file_get_contents((string) $store->pathFor(LEDGER_OPERATION)))->toBe($expected);
});
