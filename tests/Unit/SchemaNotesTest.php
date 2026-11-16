<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Draft\SchemaKeywords;
use Docuccino\Core\Draft\SchemaNotes;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Patch\Contribution;

/*
 * A note is a fact a rule enforces, carried beside a schema's description until the schema is published,
 * so prose written over the description in between replaces the prose and not the fact.
 */

it('publishes notes beneath the description, as one paragraph in the order stated', function (): void {
    $schema = SchemaNotes::onto(['type' => 'string', 'description' => 'End of the range.'], ['Must be a date after from.']);
    $schema = SchemaNotes::onto($schema, ['Required when name is present.']);

    expect(SchemaNotes::rendered($schema))->toBe([
        'type' => 'string',
        'description' => "End of the range.\n\nMust be a date after from. Required when name is present.",
    ]);
});

it('publishes notes as the whole description where nothing wrote one, standing where they were carried', function (): void {
    // The bytes a field publishes with no prose of its own are the bytes it published before notes were
    // carried apart: the sentence, at the place in the schema the rule put it.
    $schema = SchemaNotes::onto(['type' => 'string', 'format' => 'date'], ['Must be a date after from.']);
    $schema['example'] = '2024-01-01';

    expect(SchemaNotes::rendered($schema))->toBe([
        'type' => 'string',
        'format' => 'date',
        'description' => 'Must be a date after from.',
        'example' => '2024-01-01',
    ]);
});

it('keeps every other x-docuccino member a schema carries', function (): void {
    $schema = SchemaNotes::onto(['type' => 'string', 'x-docuccino' => ['facts' => ['blankAsNull' => '^$']]], ['An image file.']);

    expect(SchemaNotes::rendered($schema))->toBe([
        'type' => 'string',
        'description' => 'An image file.',
        'x-docuccino' => ['facts' => ['blankAsNull' => '^$']],
    ]);
});

it('renders notes at every subschema position', function (string $keyword): void {
    $note = SchemaNotes::onto(['type' => 'string'], ['Must start with one of: ab, cd.']);
    $value = match (SchemaKeywords::positionOf($keyword)) {
        SchemaKeywords::POSITION_SCHEMA => $note,
        SchemaKeywords::POSITION_SCHEMA_LIST => [$note],
        default => ['member' => $note],
    };

    $rendered = SchemaNotes::rendered([$keyword => $value]);
    $member = match (SchemaKeywords::positionOf($keyword)) {
        SchemaKeywords::POSITION_SCHEMA => $rendered[$keyword],
        SchemaKeywords::POSITION_SCHEMA_LIST => $rendered[$keyword][0],
        default => $rendered[$keyword]['member'],
    };

    expect($member)->toBe(['type' => 'string', 'description' => 'Must start with one of: ab, cd.']);
})->with(fn (): array => [
    ...SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA),
    ...SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA_LIST),
    ...SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA_MAP),
]);

it('hands back a schema\'s own notes for a draft to declare, and renders the ones inside it', function (): void {
    $items = SchemaNotes::onto(['type' => 'string'], ['Must start with one of: ab, cd.']);
    $schema = SchemaNotes::onto(['type' => 'array', 'items' => $items], ['Required when name is present.', 'Must not be sent when kind is business.']);

    [$taken, $paragraph] = SchemaNotes::take($schema);

    expect($paragraph)->toBe('Required when name is present. Must not be sent when kind is business.')
        ->and($taken)->toBe(['type' => 'array', 'items' => ['type' => 'string', 'description' => 'Must start with one of: ab, cd.']])
        ->and(SchemaNotes::take(['type' => 'string']))->toBe([['type' => 'string'], null]);
});

it('reads, drops and leaves alone a schema\'s own notes', function (): void {
    $schema = SchemaNotes::onto(['type' => 'string', 'x-docuccino' => ['mock' => ['faker' => 'word']]], ['A', 'B']);

    expect(SchemaNotes::of($schema))->toBe(['A', 'B'])
        ->and(SchemaNotes::without($schema))->toBe(['type' => 'string', 'x-docuccino' => ['mock' => ['faker' => 'word']]])
        ->and(SchemaNotes::without(SchemaNotes::onto(['type' => 'string'], ['A'])))->toBe(['type' => 'string'])
        ->and(SchemaNotes::onto(['type' => 'string'], []))->toBe(['type' => 'string'])
        ->and(SchemaNotes::of(['type' => 'string']))->toBe([]);
});

it('publishes notes in a component as it is registered', function (): void {
    $registry = new ComponentRegistry;
    $registry->registerSchema('Range', ['type' => 'object', 'properties' => [
        'until' => SchemaNotes::onto(['type' => 'string', 'description' => 'End of the range.'], ['Must be a date after from.']),
    ]]);

    expect($registry->schemas()['Range']['properties']['until'])->toBe([
        'type' => 'string',
        'description' => "End of the range.\n\nMust be a date after from.",
    ]);
});

it('publishes notes in an operation\'s body as it freezes, and in a body another producer displaced', function (): void {
    // A body written over by a higher layer is read back as the document it would have been, notes and all.
    $body = static fn (string $description): array => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => [
        'until' => SchemaNotes::onto(['type' => 'string', 'description' => $description], ['Must be a date after from.']),
    ]]]]];

    $draft = new OperationDraft;
    $draft->set('requestBody', $body('A date.'), Contribution::integration('form-request'));
    $draft->set('requestBody', $body('End of the range.'), Contribution::attribute());

    $operation = $draft->freeze()->toArray();
    $overrode = $operation['x-docuccino']['provenance'][0]['overrode'][0]['value'] ?? null;

    expect($operation['requestBody']['content']['application/json']['schema']['properties']['until']['description'])->toBe("End of the range.\n\nMust be a date after from.")
        ->and($overrode['content']['application/json']['schema']['properties']['until']['description'] ?? null)->toBe("A date.\n\nMust be a date after from.");
});

it('publishes notes in a response body as it freezes, beneath the description and in place of none', function (): void {
    // A response states what the server sends as a request states what it accepts, so a note reaching a
    // body inline is published the same way, and never as an x-docuccino member a reader cannot use.
    $draft = new ResponseDraft('200');
    $draft->content('application/json')->declareShape(['type' => 'object', 'properties' => [
        'closed_on' => SchemaNotes::onto(['type' => 'string', 'description' => 'The day it closed.'], ['Serialized using the date format "d/m/Y".']),
        'opened_on' => SchemaNotes::onto(['type' => 'string'], ['Serialized using the date format "d/m/Y".']),
    ]], Contribution::integration('eloquent'));

    $properties = $draft->freeze()->toArray()['content']['application/json']['schema']['properties'];

    expect($properties)->toBe([
        'closed_on' => ['type' => 'string', 'description' => "The day it closed.\n\nSerialized using the date format \"d/m/Y\"."],
        'opened_on' => ['type' => 'string', 'description' => 'Serialized using the date format "d/m/Y".'],
    ]);
});
