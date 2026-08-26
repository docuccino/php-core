<?php

declare(strict_types=1);

use Docuccino\Attributes\Description;
use Docuccino\Attributes\Summary;
use Docuccino\Core\Extensions\Context\AttributeSet;

/*
 * The set keeps method-over-class precedence positionally, and records which entries were INHERITED so
 * a reader can tell what the author wrote on the action itself — a diagnostic about a class-level
 * declaration would otherwise fire once per action under it.
 */
it('lists every instance most-specific first, inherited ones included', function (): void {
    $set = new AttributeSet([$own = new Description(text: 'On the action.')]);
    $set->add($inherited = new Description(text: 'On the controller.'), inherited: true);

    expect($set->all(Description::class))->toBe([$own, $inherited])
        ->and($set->first(Description::class))->toBe($own)
        ->and($set->has(Description::class))->toBeTrue();
});

it('keeps only what the subject itself declared', function (): void {
    $set = new AttributeSet;
    $set->add($own = new Description(text: 'On the action.'));
    $set->add(new Description(text: 'On the controller.'), inherited: true);
    $set->add(new Summary('On the controller.'), inherited: true);

    expect($set->direct(Description::class))->toBe([$own])
        // Inherited-only, so nothing the author could correct where the action stands.
        ->and($set->direct(Summary::class))->toBe([])
        ->and($set->direct(stdClass::class))->toBe([]);
});

it('counts a constructor-supplied declaration as declared on the subject', function (): void {
    // A caller that hands the whole list over — a test, or a collector with nothing to walk — is
    // describing the subject, not an enclosing scope.
    $set = new AttributeSet([$own = new Description(text: 'On the action.')]);

    expect($set->direct(Description::class))->toBe([$own]);
});
