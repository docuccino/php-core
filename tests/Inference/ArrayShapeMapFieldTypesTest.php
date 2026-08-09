<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\StatusMarkerT;

/**
 * The shared member-rewrite helper, folding the two hand-rolled `array_map`-over-fields rebuilds the
 * refiner (pin one key to a folded literal) and the response builder (resolve every status-provenance
 * member to a concrete status) each carried. The invariant that matters for determinism: field ORDER,
 * keys, optionality and `isList` all survive the rewrite untouched.
 */
function shapeFixture(): ArrayShapeT
{
    return new ArrayShapeT([
        new ArrayShapeField('type', ScalarT::string()),
        new ArrayShapeField('status', new StatusMarkerT),
        new ArrayShapeField('detail', ScalarT::string(), optional: true),
        new ArrayShapeField(0, ScalarT::int()),
    ]);
}

it('rewrites only the types the callback replaces, preserving keys, order and optionality', function (): void {
    $mapped = shapeFixture()->mapFieldTypes(
        static fn (DType $type, string|int $key): DType => $key === 'type' ? new LiteralT('about:blank') : $type,
    );

    expect(array_map(static fn (ArrayShapeField $f): string|int => $f->key, $mapped->fields))
        ->toBe(['type', 'status', 'detail', 0])
        ->and($mapped->fields[0]->type)->toEqual(new LiteralT('about:blank'))
        ->and($mapped->fields[1]->type)->toBeInstanceOf(StatusMarkerT::class)
        // Optionality rides along untouched.
        ->and($mapped->fields[2]->optional)->toBeTrue()
        ->and($mapped->fields[3]->key)->toBe(0);
});

it('replaces every member matching a type predicate (the status-marker resolution shape)', function (): void {
    $mapped = shapeFixture()->mapFieldTypes(
        static fn (DType $type): DType => $type instanceof StatusMarkerT ? new LiteralT(403) : $type,
    );

    expect($mapped->fields[1]->type)->toEqual(new LiteralT(403))
        ->and($mapped->fields[0]->type)->toBeInstanceOf(ScalarT::class);
});

it('carries isList through and leaves an empty shape empty', function (bool $isList): void {
    $mapped = (new ArrayShapeT([], $isList))->mapFieldTypes(static fn (DType $t): DType => new LiteralT(1));

    expect($mapped->fields)->toBe([])
        ->and($mapped->isList)->toBe($isList);
})->with([[true], [false]]);
