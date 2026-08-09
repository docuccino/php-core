<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;

/**
 * The shared UnionT nullability + member-removal helpers (D3), de-duplicating the isNullable and
 * marker-strip re-rolls the schema mappers used to each carry.
 */
it('reports containsNull for a nullable union only', function (): void {
    $nullable = new UnionT([ScalarT::string(), new NullT]);
    $plain = new UnionT([ScalarT::string(), ScalarT::int()]);

    expect($nullable->containsNull())->toBeTrue()
        ->and($plain->containsNull())->toBeFalse();
});

it('without() removes matching members and collapses a single survivor', function (): void {
    $union = new UnionT([ScalarT::string(), new NullT]);

    $stripped = $union->without(static fn ($m): bool => $m instanceof NullT);

    // One survivor collapses back to that member (UnionT::of semantics).
    expect($stripped)->toBeInstanceOf(ScalarT::class)
        ->and($stripped->canonicalKey())->toBe(ScalarT::string()->canonicalKey());
});

it('without() keeps a multi-member survivor set as a union', function (): void {
    $union = new UnionT([ScalarT::string(), ScalarT::int(), new NullT]);

    $stripped = $union->without(static fn ($m): bool => $m instanceof NullT);

    expect($stripped)->toBeInstanceOf(UnionT::class)
        ->and($stripped->containsNull())->toBeFalse();
});

it('without() treats rejecting every member as a no-op (nothing to strip to)', function (): void {
    $onlyMarkers = new UnionT([new ClassT('Some\\Marker'), new ClassT('Other\\Marker')]);

    $stripped = $onlyMarkers->without(static fn ($m): bool => true);

    // Removing everything would leave an empty/unknown type, so the union is returned unchanged.
    expect($stripped)->toBe($onlyMarkers);
});
