<?php

declare(strict_types=1);

use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Validation\Validator;

/**
 * Edge coverage (QA M8): the smallest document the schema permits must survive the whole pipeline,
 * and diffing against it must classify a populated document as all-additions and its reverse as
 * breaking removals.
 *
 * @return array<string, mixed>
 */
function minimalDocument(): array
{
    return [
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'Empty API', 'version' => '1.0.0'],
        'paths' => [],
    ];
}

it('emits, validates and round-trips a minimal document', function (): void {
    $doc = minimalDocument();

    $json = (new UirEmitter)->emitArray($doc);
    expect($json)->toContain('"paths": {}');

    $validation = (new Validator)->validate($doc);
    expect($validation->isValid())->toBeTrue()
        ->and($validation->errors)->toBe([]);

    expect(UirDocument::fromArray($doc)->toArray())->toEqual($doc);
});

it('classifies every difference from empty to populated as a non-breaking addition', function (): void {
    $changeset = (new DocumentDiffer)->diff(
        UirDocument::fromArray(minimalDocument()),
        UirDocument::fromArray(diffBase()),
    );

    expect($changeset->isEmpty())->toBeFalse();
    expect($changeset->isBreaking())->toBeFalse();
});

it('classifies removals from populated to empty as breaking', function (): void {
    $changeset = (new DocumentDiffer)->diff(
        UirDocument::fromArray(diffBase()),
        UirDocument::fromArray(minimalDocument()),
    );

    expect($changeset->isBreaking())->toBeTrue();
});
