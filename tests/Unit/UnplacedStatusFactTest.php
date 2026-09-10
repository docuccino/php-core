<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Validation\ResponseDraftApplier;
use Docuccino\Core\Patch\Contribution;

/**
 * The fact that tells a status the build READ from one it filed under because nothing could be read.
 *
 * Stated from the contract, not from the accumulator: a response is a stand-in only while EVERY
 * producer that keyed it stood in. One that read a number has said what the status is, and a mark
 * saying otherwise would be the document contradicting its own evidence — which is worse than the
 * silence it replaces, because a build gate would then refuse a status the code really states.
 *
 * The `and` is also what keeps the answer a function of the SET rather than of the order two producers
 * arrived in. A guarded field could not do it: precedence keeps the first writer at a tie, so two
 * producers meeting at one status would publish whichever the analysis reported first, and adding an
 * unrelated throw would change what the document says about an existing one.
 */
it('says nothing about a response nothing spoke for', function (): void {
    expect((new ResponseDraft('500'))->statusIsUnplaced())->toBeFalse()
        ->and((new ResponseDraft('500'))->freeze()->docuccino)->toBeNull();
});

it('marks a status every producer stood in for', function (): void {
    $draft = new ResponseDraft('500');
    $draft->recordStatusPlacement(true);
    $draft->recordStatusPlacement(true);

    $extension = $draft->freeze()->docuccino?->toArray() ?? [];

    expect($draft->statusIsUnplaced())->toBeTrue()
        ->and($extension['facts'] ?? null)->toBe([ResponseDraft::STATUS_UNPLACED => true]);
});

/**
 * The shape the guard has to refuse, executed rather than asserted: one producer read the status, so
 * the response is not a stand-in whichever order the two are recorded in.
 */
it('refuses to call a status a stand-in once something read it', function (array $order): void {
    $draft = new ResponseDraft('500');
    foreach ($order as $unplaced) {
        $draft->recordStatusPlacement($unplaced);
    }

    expect($draft->statusIsUnplaced())->toBeFalse()
        ->and($draft->freeze()->docuccino)->toBeNull();
})->with([
    'the stand-in first' => [[true, false]],
    'the reading first' => [[false, true]],
    'a reading between two stand-ins' => [[true, false, true]],
]);

/**
 * The merge, which is where two producers actually meet: every response-producing source goes through
 * one applier, so a status one of them read is never left looking like a placeholder — and the answer
 * is the same whichever of them the pipeline ran first.
 */
it('carries the placement across the merge, whichever producer ran first', function (bool $standInFirst): void {
    $standIn = new ResponseDraft('500');
    $standIn->recordStatusPlacement(true);
    $standIn->setDescription('Internal Server Error', Contribution::fallback());

    $read = new ResponseDraft('500');
    $read->recordStatusPlacement(false);
    $read->setDescription('Internal Server Error', Contribution::integration('probe'));

    $operation = new OperationDraft;
    $applier = new ResponseDraftApplier;

    foreach ($standInFirst ? [$standIn, $read] : [$read, $standIn] as $draft) {
        $applier->apply($operation, $draft, $draft === $standIn ? 'fallback' : 'integration:probe');
    }

    expect($operation->response('500')->statusIsUnplaced())->toBeFalse();
})->with(['stand-in first' => [true], 'reading first' => [false]]);

/** And with nothing to contradict it, the merge keeps the mark — or the row above proves nothing. */
it('carries a stand-in nothing contradicted across the merge', function (): void {
    $draft = new ResponseDraft('500');
    $draft->recordStatusPlacement(true);
    $draft->setDescription('Internal Server Error', Contribution::fallback());

    $operation = new OperationDraft;
    (new ResponseDraftApplier)->apply($operation, $draft, 'fallback');

    expect($operation->response('500')->statusIsUnplaced())->toBeTrue();
});
