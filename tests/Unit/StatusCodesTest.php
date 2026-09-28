<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\PayloadStatusT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\StatusCodes;

/**
 * The statuses a response type's status argument states. `$ok ? 200 : 503` is two responses the server can
 * send, so a union of codes is each of them; anything that is not wholly codes states none, and the reader
 * deciding what to publish there is not this one.
 */
it('reads every code a status argument states', function (?DType $status, ?array $codes): void {
    expect(StatusCodes::of($status))->toBe($codes);
})->with([
    'one code' => [new LiteralT(201), [201]],
    'a choice of codes, ascending' => [UnionT::of([new LiteralT(503), new LiteralT(200)]), [200, 503]],
    'a general int' => [ScalarT::int(), null],
    'a code or something else' => [UnionT::of([new LiteralT(200), ScalarT::int()]), null],
    'a string that looks like a code' => [new LiteralT('200'), null],
    'a status nothing read' => [new UnknownT('status not folded'), null],
    // The payload's own status is known, but it is not a code this argument states.
    'the payload\'s own status' => [new PayloadStatusT, null],
    'no status argument' => [null, null],
]);
