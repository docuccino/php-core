<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Inference;

use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnionT;

/**
 * Guards {@see DType::canonicalKey()} against `serialize_precision` leakage: a
 * float literal's key (and therefore union member ordering) must be identical
 * whether the ini rounds floats to 17 digits or to their shortest round-trip.
 */
function canonicalKeysUnder(string $serializePrecision): array
{
    $original = ini_get('serialize_precision');
    ini_set('serialize_precision', $serializePrecision);

    try {
        $members = [new LiteralT(0.1), new LiteralT(0.2), new LiteralT(0.30000000000000004)];
        $union = UnionT::of($members);
        $order = $union instanceof UnionT
            ? array_map(static fn ($m): string => $m->canonicalKey(), $union->members)
            : [];

        return [
            'single' => (new LiteralT(0.1))->canonicalKey(),
            'order' => $order,
        ];
    } finally {
        ini_set('serialize_precision', $original === false ? '-1' : $original);
    }
}

it('produces a precision-independent canonicalKey for float literals', function (): void {
    $at17 = canonicalKeysUnder('17');
    $atShortest = canonicalKeysUnder('-1');

    expect($at17['single'])->toBe($atShortest['single'])
        ->and($at17['order'])->toBe($atShortest['order'])
        ->and($at17['order'])->toHaveCount(3); // distinct floats stay distinct
});
