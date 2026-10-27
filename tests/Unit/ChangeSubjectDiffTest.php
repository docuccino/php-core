<?php

declare(strict_types=1);

use Docuccino\Core\Diff\Change;
use Docuccino\Core\Diff\ChangeKind;
use Docuccino\Core\Diff\Changeset;
use Docuccino\Core\Diff\ChangeSubject;
use Docuccino\Core\Diff\ChangeTarget;
use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Diff\Pairing;
use Docuccino\Core\Diff\SchemaComparator;
use Docuccino\Core\Diff\SubjectKind;
use Docuccino\Core\Reader\DocumentReader;

/*
 * Every change names what it is about as members — an operation by method and path (or webhook), a component
 * by its name, a page by its slug — because the path a change carries opens with that subject spelt as text,
 * and a path template, a webhook name and a component name can each hold the dot or the space that text uses
 * to say where within the subject it is. `components.schemas.Pet.enum` is the enum of `Pet` or the schema
 * `Pet.enum`, and only the subject says which.
 *
 * Documents are read the way an upload is, through the reader.
 */

/**
 * @param  array<string, mixed>  $old
 * @param  array<string, mixed>  $new
 */
$diff = static fn (array $old, array $new): Changeset => (new DocumentDiffer)->diff(
    (new DocumentReader)->read((string) json_encode($old))->uir(),
    (new DocumentReader)->read((string) json_encode($new))->uir(),
);

/**
 * One document before or after an edit to every kind of node the differ walks, each edit one the differ
 * reports under a code of its own: so between them, the two sides make every code {@see DocumentDiffer}
 * states. Names hold dots wherever OpenAPI lets them. Ids on every node where $ids, so the pair is made by
 * identity; none, and it is made by method and path.
 *
 * @return array<string, mixed>
 */
$document = static function (bool $after, bool $ids): array {
    $id = static fn (string $kind, string $n): array => $ids ? ['x-docuccino' => ['id' => $kind.':v1:'.str_pad($n, 16, $n)]] : [];
    $parameter = static fn (string $n, string $name, bool $required, array $extra = []): array => [...$id('par', $n), 'name' => $name, 'in' => 'query', 'required' => $required, 'schema' => ['type' => 'string'], ...$extra];
    $json = static fn (array $schema): array => ['application/json' => ['schema' => $schema]];

    $form = [
        ...$id('op', '1'),
        'operationId' => $after ? 'forms.show' : 'forms.read',
        'summary' => $after ? 'Show a form' : 'Read a form',
        'description' => $after ? 'One form.' : 'A form.',
        'deprecated' => $after,
        'tags' => [$after ? 'Forms' : 'Form'],
        'parameters' => [
            $parameter('2', 'status', $after),
            $parameter('3', 'sort', ! $after),
            $parameter('4', 'q', false, ['description' => $after ? 'Search terms.' : 'Search.']),
            ...($after
                ? [$parameter('5', 'page', false), $parameter('6', 'tenant', true)]
                : [$parameter('7', 'legacy', false)]),
        ],
        'responses' => [
            '200' => [...$id('res', '8'), 'description' => $after ? 'The form.' : 'A form.', 'content' => [
                ...$json([
                    'type' => 'object',
                    'properties' => ['title' => ['type' => 'string', ...($after ? ['maxLength' => 80] : [])]],
                    // A change under `not` is forced breaking, which rebuilds the change it is reported as.
                    'not' => ['required' => $after ? ['secret'] : []],
                ]),
                ...($after ? ['application/xml' => ['schema' => ['type' => 'string']]] : ['text/csv' => ['schema' => ['type' => 'string']]]),
            ]],
            '410' => ['description' => 'Gone.', 'content' => $json(['$ref' => '#/components/schemas/Still.Used'])],
        ] + ($after
            ? ['201' => [...$id('res', '9'), 'description' => 'Created.']]
            : ['404' => [...$id('res', 'a'), 'description' => 'Not found.']]),
        ...($after ? ['security' => [['oauth.v2' => []]]] : []),
    ];

    $copy = [
        ...$id('op', 'b'),
        'responses' => ['204' => [...$id('res', 'c'), 'description' => 'Copied.']],
        'requestBody' => $after ? ['$ref' => '#/components/requestBodies/Copy.Request'] : ['content' => $json(['type' => 'object'])],
        ...($after ? [] : ['security' => [['apiKey.v1' => []]]]),
    ];

    $operation = static fn (string $n): array => [...$id('op', $n), 'responses' => ['204' => [...$id('res', $n.'f'), 'description' => 'Done.']]];

    $saved = [
        ...$id('op', 'd'),
        'requestBody' => ['content' => $json(['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => $after ? ['name'] : []])],
        'responses' => ['204' => [...$id('res', 'e'), 'description' => 'Received.']],
    ];

    $schema = static fn (string $n, array $schema): array => [...$id('sch', $n), ...$schema];
    $scheme = static fn (string $n, array $scheme): array => [...$id('sec', $n), ...$scheme];
    $page = static fn (string $n, string $slug, string $title, string $content): array => [...($ids ? ['id' => 'page:v1:'.str_pad($n, 16, $n)] : []), 'slug' => $slug, 'title' => $title, 'content' => $content];

    return [
        'openapi' => '3.2.0',
        'info' => ['title' => 'Forms', 'version' => '1'],
        'paths' => [
            '/forms.v1/{form}' => ['get' => $form, 'additionalOperations' => ['COPY' => $copy]],
            '/broken' => $after ? ['$ref' => '#/components/pathItems/Broken.Item'] : ['get' => $operation('1a')],
            ...($after ? ['/fresh' => ['get' => $operation('1b')]] : ['/legacy' => ['get' => $operation('1c')]]),
        ],
        'webhooks' => [
            'form.saved' => ['post' => $saved],
            'form.gone' => $after ? ['$ref' => '#/components/pathItems/Gone.Item'] : ['post' => $operation('1d')],
        ],
        'components' => [
            'schemas' => [
                'Pet' => $schema('2a', ['type' => 'string', 'enum' => $after ? ['cat', 'dog'] : ['cat']]),
                'App.Models.Form' => $schema('2b', ['type' => 'object', 'properties' => ['name' => ['type' => $after ? 'integer' : 'string']]]),
                ...($after
                    ? ['New.Shape' => $schema('2c', ['type' => 'boolean'])]
                    : ['Pet.enum' => $schema('2d', ['type' => 'string']), 'Still.Used' => $schema('2e', ['type' => 'object'])]),
            ],
            'securitySchemes' => [
                'oauth.v2' => $scheme('3a', ['type' => 'http', 'scheme' => $after ? 'basic' : 'bearer']),
                'docs.v4' => $scheme('3b', [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'summary' => $after ? 'Docs token' : 'Docs',
                    'description' => $after ? 'A token for the docs.' : 'A token.',
                    'deprecated' => $after,
                ]),
                ...($after
                    ? ['basic.v3' => $scheme('3c', ['type' => 'http', 'scheme' => 'basic'])]
                    : ['apiKey.v1' => $scheme('3d', ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Key'])]),
            ],
        ],
        'x-docuccino' => ['content' => ['pages' => [
            $page('4', 'getting.started', $after ? 'Getting started' : 'Start', $after ? 'Hello again.' : 'Hello.'),
            $after ? $page('5', 'new.page', 'New', 'New.') : $page('6', 'old.page', 'Old', 'Old.'),
        ]]],
    ];
};

/**
 * The text a change's path opens with for $subject, stated from the paths the differ has always printed
 * rather than read off it: an operation as its method and where it is, a webhook's after `webhooks.`, a
 * component under its section, a page after `pages`.
 *
 * @param  array<string, string>  $subject
 */
$spelt = static fn (array $subject): string => match ($subject['kind']) {
    'operation' => $subject['method'].' '.(isset($subject['webhook']) ? 'webhooks.'.$subject['webhook'] : $subject['path']),
    'pathItem' => isset($subject['webhook']) ? 'webhooks.'.$subject['webhook'] : $subject['path'],
    'schema' => 'components.schemas.'.$subject['name'],
    'securityScheme' => 'components.securitySchemes.'.$subject['name'],
    'page' => 'pages '.$subject['slug'],
};

it('names what every change is about, whichever code it is reported under and however it was paired', function (bool $ids, Pairing $pairing) use ($diff, $document, $spelt): void {
    $changeset = $diff($document(false, $ids), $document(true, $ids));
    $changes = array_map(static fn (Change $change): array => $change->toArray(), $changeset->changes);

    // Every code the differ states, read off the differ itself, so a code added there is a code this corpus
    // owes a change for; the comparator's own are reported through the schema positions below.
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Diff/DocumentDiffer.php');
    preg_match_all("/'((?:operation|parameter|response|requestBody|pathItem|schema|securityScheme|page)\\.[A-Za-z-]+)'/", $source, $matches);
    $stated = array_values(array_unique($matches[1]));

    expect($changeset->pairing)->toBe($pairing)
        ->and($stated)->toHaveCount(35)
        ->and(array_values(array_diff($stated, array_column($changes, 'code'))))->toBe([]);

    // What each kind of node a change targets may be about: a parameter, a response and a schema position
    // are on an operation; a path item no operation under it could be compared at is the subject of its own.
    $about = [
        'operation' => ['operation', 'pathItem'],
        'parameter' => ['operation'],
        'response' => ['operation'],
        'schema' => ['operation', 'schema'],
        'securityScheme' => ['securityScheme'],
        'page' => ['page'],
    ];

    $shapes = [];
    $forced = 0;

    foreach ($changes as $change) {
        expect($change['subject'])->toBeArray();

        /** @var array<string, string> $subject */
        $subject = $change['subject'];
        $text = $spelt($subject);
        // Where within the subject a change is follows a space, except below a schema, where it follows a dot.
        $within = $subject['kind'] === 'schema' ? '.' : ' ';

        expect($subject['kind'])->toBeIn($about[$change['target']])
            ->and($change['path'] === $text || str_starts_with($change['path'], $text.$within))->toBeTrue("{$change['path']} is not about {$text}");

        $shapes[implode(',', array_keys($subject))] = true;
        $forced += str_contains($change['path'], ' schema.not.') && $change['breaking'] ? 1 : 0;
    }

    // Every kind of subject, an operation and a path item each under a path and under a webhook — and a change
    // the comparator rebuilt as breaking, which must carry its subject across.
    expect(array_keys($shapes))->toEqualCanonicalizing([
        'kind,method,path',
        'kind,method,webhook',
        'kind,path',
        'kind,webhook',
        'kind,name',
        'kind,slug',
    ])->and(array_values(array_unique(array_column(array_column($changes, 'subject'), 'kind'))))->toEqualCanonicalizing(array_column(SubjectKind::cases(), 'value'))
        ->and($forced)->toBeGreaterThan(0);
})->with([
    'paired by identity' => [true, Pairing::Identity],
    'paired by method and path' => [false, Pairing::Structural],
]);

it('tells a dotted component from another component\'s keyword its path spells alike', function (bool $ids) use ($diff): void {
    $id = static fn (string $n): array => $ids ? ['x-docuccino' => ['id' => 'sch:v1:'.str_repeat($n, 16)]] : [];
    $doc = static fn (array $schemas): array => ['openapi' => '3.2.0', 'info' => ['title' => 'Pets', 'version' => '1'], 'paths' => (object) [], 'components' => ['schemas' => $schemas]];

    $changes = $diff(
        $doc(['Pet' => [...$id('1'), 'type' => 'string', 'enum' => ['cat']], 'Pet.enum' => [...$id('2'), 'type' => 'string']]),
        $doc(['Pet' => [...$id('1'), 'type' => 'string', 'enum' => ['cat', 'dog']]]),
    )->changes;

    $said = array_map(static fn (Change $change): array => [$change->path, $change->code, $change->subject?->toArray()], $changes);

    // One path, and two subjects: the enum of `Pet`, and the schema `Pet.enum`.
    expect($said)->toEqualCanonicalizing([
        ['components.schemas.Pet.enum', 'schema.enum-value-added', ['kind' => 'schema', 'name' => 'Pet']],
        ['components.schemas.Pet.enum', 'schema.removed', ['kind' => 'schema', 'name' => 'Pet.enum']],
    ]);
})->with(['paired by identity' => [true], 'paired by method and path' => [false]]);

it('tells a webhook from a path its changes spell alike', function () use ($diff): void {
    // A webhook is printed after `webhooks.`, and a webhook name may hold a dot and a space; the subject
    // names it by itself.
    $operation = static fn (string $summary): array => ['summary' => $summary, 'responses' => ['204' => ['description' => 'Done.']]];
    $doc = static fn (string $summary): array => [
        'openapi' => '3.2.0',
        'info' => ['title' => 'Forms', 'version' => '1'],
        'paths' => ['/forms' => ['post' => $operation($summary)]],
        'webhooks' => ['form.saved /forms' => ['post' => $operation($summary)]],
    ];

    $subjects = array_map(static fn (Change $change): ?array => $change->subject?->toArray(), $diff($doc('Save'), $doc('Store'))->changes);

    expect($subjects)->toEqualCanonicalizing([
        ['kind' => 'operation', 'method' => 'POST', 'path' => '/forms'],
        ['kind' => 'operation', 'method' => 'POST', 'webhook' => 'form.saved /forms'],
    ]);
});

it('names an operation by where each side has it', function (bool $ids, array $expected) use ($diff): void {
    // Paired by identity, an operation whose path parameter was renamed is the same operation, and is named by
    // the path the new document has it under — as its path names it. Paired by method and path it is one
    // operation gone from the old path and another arrived at the new.
    $doc = static fn (string $path, string $summary): array => [
        'openapi' => '3.2.0',
        'info' => ['title' => 'Forms', 'version' => '1'],
        'paths' => [$path => ['get' => [
            ...($ids ? ['x-docuccino' => ['id' => 'op:v1:aaaaaaaaaaaaaaaa']] : []),
            'summary' => $summary,
            'responses' => ['204' => ['description' => 'Done.']],
        ]]],
    ];

    $said = array_map(
        static fn (Change $change): array => [$change->code, $change->subject?->toArray()],
        $diff($doc('/forms/{form}', 'Read'), $doc('/forms/{id}', 'Show'))->changes,
    );

    expect($said)->toEqualCanonicalizing($expected);
})->with([
    'paired by identity' => [true, [
        ['operation.summary-changed', ['kind' => 'operation', 'method' => 'GET', 'path' => '/forms/{id}']],
    ]],
    'paired by method and path' => [false, [
        ['operation.removed', ['kind' => 'operation', 'method' => 'GET', 'path' => '/forms/{form}']],
        ['operation.added', ['kind' => 'operation', 'method' => 'GET', 'path' => '/forms/{id}']],
    ]],
]);

it('spells each kind of subject as the members it has', function (ChangeSubject $subject, array $expected): void {
    expect($subject->toArray())->toBe($expected)
        ->and($subject->kind->value)->toBe($expected['kind']);
})->with([
    'an operation under a path' => [ChangeSubject::operation('GET', '/pets/{id}'), ['kind' => 'operation', 'method' => 'GET', 'path' => '/pets/{id}']],
    'an operation under a webhook' => [ChangeSubject::webhookOperation('POST', 'pet.created'), ['kind' => 'operation', 'method' => 'POST', 'webhook' => 'pet.created']],
    'a path item' => [ChangeSubject::pathItem('/pets'), ['kind' => 'pathItem', 'path' => '/pets']],
    'a webhook\'s path item' => [ChangeSubject::webhookPathItem('pet.created'), ['kind' => 'pathItem', 'webhook' => 'pet.created']],
    'a schema' => [ChangeSubject::schema('App.Models.Pet'), ['kind' => 'schema', 'name' => 'App.Models.Pet']],
    'a security scheme' => [ChangeSubject::securityScheme('oauth.v2'), ['kind' => 'securityScheme', 'name' => 'oauth.v2']],
    'a page' => [ChangeSubject::page('getting.started'), ['kind' => 'page', 'slug' => 'getting.started']],
]);

it('lets a loose schema comparison name its subject, or none', function (): void {
    $compare = static fn (?ChangeSubject $subject): array => (new SchemaComparator)->compare(
        ['type' => 'string', 'not' => ['enum' => ['a']]],
        ['type' => 'integer', 'not' => ['enum' => ['a', 'b']]],
        'S',
        'sch:v1:0000000000000000',
        request: false,
        subject: $subject,
    );

    $named = $compare(ChangeSubject::schema('S'));

    // Two changes, one of them under `not` and so rebuilt as breaking: both keep what they are about.
    expect($named)->toHaveCount(2)
        ->and(array_map(static fn (Change $change): ?array => $change->subject?->toArray(), $named))->each->toBe(['kind' => 'schema', 'name' => 'S'])
        ->and(array_map(static fn (Change $change): ?ChangeSubject => $change->subject, $compare(null)))->each->toBeNull();
});

it('states a change built without a subject as about nothing named', function (): void {
    $change = new Change(ChangeKind::Removed, ChangeTarget::Operation, 'op:v1:aaaaaaaaaaaaaaaa', 'GET /forms', true, 'operation.removed');

    expect($change->subject)->toBeNull()
        ->and($change->toArray())->toBe([
            'kind' => 'removed',
            'target' => 'operation',
            'id' => 'op:v1:aaaaaaaaaaaaaaaa',
            'path' => 'GET /forms',
            'subject' => null,
            'breaking' => true,
            'code' => 'operation.removed',
        ]);
});
