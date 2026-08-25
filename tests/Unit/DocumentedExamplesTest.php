<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Tests\Fixtures\DocumentedNode;
use Docuccino\Core\Tests\Support\StubTypeEngine;

/**
 * The generic class mapper reading a property's docblock `@example` — the fallback every plain DTO
 * reaches, and one of the three producers that wrote the tag off as decoration while the Data mapper
 * beside it published one.
 *
 * The strings scripted here are exactly the tags {@see DocumentedNode} carries, because a docblock tag
 * holds text and the engine hands the text over unread.
 */
function documentedNodeEngine(): StubTypeEngine
{
    return new StubTypeEngine(classes: [
        DocumentedNode::class => new ClassMetadata(DocumentedNode::class, [
            new PropertyMetadata('tenant', ScalarT::string(), 'Who owns the invoice.', 'acme-corp'),
            new PropertyMetadata('settled', ScalarT::bool(), null, 'false'),
            new PropertyMetadata('seats', ScalarT::int(), null, '7'),
            new PropertyMetadata('permissions', new ListT(ScalarT::string()), null, '["listing.view", "listing.create"]'),
            new PropertyMetadata('renewals', ScalarT::int(), null, 'n/a'),
            new PropertyMetadata('pinned', ScalarT::string(), null, 'from-the-docblock'),
            new PropertyMetadata('hushed', ScalarT::string(), null, 'never-read'),
        ]),
    ]);
}

/** @return array{0: array<string, mixed>, 1: list<string>} */
function documentedNodeComponent(): array
{
    $components = new ComponentRegistry;
    (new SchemaConverter(DefaultTypeMappers::all(), documentedNodeEngine(), $components))
        ->toSchema(new ClassT(DocumentedNode::class));

    /** @var array<string, mixed> $properties */
    $properties = $components->schemas()['DocumentedNode']['properties'];

    return [$properties, array_map(static fn ($d): string => $d->code, $components->diagnostics())];
}

it('publishes a plain DTO property\'s docblock example as the JSON type beside it', function (string $property, mixed $example): void {
    [$props] = documentedNodeComponent();

    /** @var array<string, mixed> $schema */
    $schema = $props[$property];

    expect(array_key_exists('example', $schema))->toBeTrue()
        ->and($schema['example'])->toBe($example);
})->with([
    // Written and silently ignored until now: the same tag on a Data class property published, and here
    // it did nothing, so where an author put the DTO decided whether their example reached a consumer.
    'a string' => ['tenant', 'acme-corp'],
    'a boolean' => ['settled', false],
    'an integer' => ['seats', 7],
    'an array, from its JSON literal' => ['permissions', ['listing.view', 'listing.create']],
]);

it('leaves the attribute standing where a property carries both', function (): void {
    // Precedence, on one property: docblock 30 < attribute 40. Publishing the docblock here would be the
    // same inversion the attribute reader itself was written to close.
    [$props] = documentedNodeComponent();

    expect($props['pinned']['example'])->toBe('from-the-attribute');
});

it('publishes no example it cannot read, and says which property and type', function (): void {
    // `@example n/a` on an `int`. A wrong example is the one part of the document a consumer copies, so
    // dropping it is the honest answer — and the author hears where to go and what to change.
    [$props, $codes] = documentedNodeComponent();

    expect($props['renewals'])->not->toHaveKey('example')
        ->and($codes)->toBe(['docblock.example-untypable']);
});

it('says nothing about an example on a member the schema hides', function (): void {
    // There is no member to carry it and nothing the author could do, so a diagnostic here would fire
    // where the reader cannot act — the one code raised above is the untypable one, and only that.
    [$props, $codes] = documentedNodeComponent();

    expect($props)->not->toHaveKey('hushed')
        ->and($codes)->toBe(['docblock.example-untypable']);
});
