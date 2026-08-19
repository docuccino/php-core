<?php

declare(strict_types=1);

use Docuccino\Core\Patch\Layer;
use Docuccino\Core\Provenance\Explain\ExplainedNode;
use Docuccino\Core\Provenance\Explain\OperationExplainer;

/**
 * Reading the provenance trail back off an operation: which layer won each field, what it displaced,
 * and where each contribution came from. The documents here are written out by hand rather than
 * generated, because the point is what the READER of a UIR document can recover from it.
 */
beforeEach(function (): void {
    $this->document = [
        'paths' => [
            '/api/invoices' => [
                'post' => [
                    'x-docuccino' => [
                        'id' => 'op:v1:aaaaaaaaaaaaaaaa',
                        'provenance' => [
                            [
                                'producer' => 'attribute',
                                'layer' => 'attribute',
                                'fields' => ['summary'],
                                'source' => ['file' => 'app/Http/Controllers/InvoiceController.php', 'line' => 42, 'symbol' => 'App\Http\Controllers\InvoiceController::store'],
                                'overrode' => [
                                    ['field' => 'summary', 'value' => 'Store invoice', 'producer' => 'docblock'],
                                    ['field' => 'summary', 'value' => 'POST /api/invoices', 'producer' => 'fallback'],
                                ],
                            ],
                            ['producer' => 'inference', 'layer' => 'inference', 'fields' => ['tags'], 'confidence' => 0.4],
                        ],
                    ],
                    'summary' => 'Create an invoice',
                    'tags' => ['Invoices'],
                    'parameters' => [
                        [
                            'x-docuccino' => ['provenance' => [
                                ['producer' => 'integration:query-builder', 'layer' => 'integration', 'fields' => ['required']],
                            ]],
                            'name' => 'status',
                            'in' => 'query',
                            'required' => false,
                        ],
                    ],
                    'responses' => [
                        '201' => [
                            'x-docuccino' => [
                                'provenance' => [
                                    ['producer' => 'integration:framework-errors', 'layer' => 'integration', 'fields' => ['component', 'description']],
                                ],
                                'facts' => ['component' => 'Created'],
                            ],
                            '$ref' => '#/components/responses/Created',
                        ],
                    ],
                ],
            ],
        ],
        'components' => [
            'responses' => [
                'Created' => [
                    'x-docuccino' => ['provenance' => [
                        ['producer' => 'config', 'layer' => 'config', 'fields' => ['description']],
                    ]],
                    'description' => 'The invoice as stored.',
                ],
            ],
        ],
    ];
});

it('reads every node of an operation that recorded anything, in reading order', function (): void {
    $nodes = (new OperationExplainer)->explain($this->document, '/api/invoices', 'post');

    expect(array_map(static fn (ExplainedNode $node): string => $node->label, $nodes))->toBe([
        'operation',
        'parameters.query:status',
        'responses.201',
        '#/components/responses/Created',
    ]);
});

it('stacks a contested field highest rung first, with the published value on top', function (): void {
    $nodes = (new OperationExplainer)->explain($this->document, '/api/invoices', 'post');
    $summary = $nodes[0]->fields[0];

    expect($summary->field)->toBe('summary')
        ->and($summary->isContested())->toBeTrue()
        ->and(array_map(static fn ($c): string => $c->layer->label(), $summary->contributions))->toBe(['attribute', 'docblock', 'fallback'])
        ->and(array_map(static fn ($c): bool => $c->won, $summary->contributions))->toBe([true, false, false])
        ->and(array_map(static fn ($c): mixed => $c->value, $summary->contributions))->toBe(['Create an invoice', 'Store invoice', 'POST /api/invoices'])
        ->and($summary->winner()?->source?->line)->toBe(42);
});

it('keeps a field only one layer reached as a stack of one', function (): void {
    $nodes = (new OperationExplainer)->explain($this->document, '/api/invoices', 'post');
    $tags = $nodes[0]->fields[1];

    expect($tags->field)->toBe('tags')
        ->and($tags->isContested())->toBeFalse()
        ->and($tags->winner()?->confidence)->toBe(0.4)
        ->and($tags->winner()?->layer)->toBe(Layer::Inference);
});

it('names a parameter by the in:name pair rather than its position', function (): void {
    $nodes = (new OperationExplainer)->explain($this->document, '/api/invoices', 'post');

    expect($nodes[1]->label)->toBe('parameters.query:status')
        ->and($nodes[1]->pointer)->toBe('/paths/~1api~1invoices/post/parameters/0')
        ->and($nodes[1]->fields[0]->winner()?->value)->toBeFalse();
});

it('reads a value off the facts bag and off the component the node points at', function (): void {
    $nodes = (new OperationExplainer)->explain($this->document, '/api/invoices', 'post');
    $response = $nodes[2];

    expect($response->ref)->toBe('#/components/responses/Created')
        // `component` is never a member of the node, so only `facts` can answer for it…
        ->and($response->fields[0]->field)->toBe('component')
        ->and($response->fields[0]->winner()?->value)->toBe('Created')
        // …and a node written as a bare $ref publishes its description from the component.
        ->and($response->fields[1]->field)->toBe('description')
        ->and($response->fields[1]->winner()?->value)->toBe('The invoice as stored.');
});

it('follows a $ref into the component it names', function (): void {
    $nodes = (new OperationExplainer)->explain($this->document, '/api/invoices', 'post');

    expect($nodes[3]->pointer)->toBe('/components/responses/Created')
        ->and($nodes[3]->fields[0]->winner()?->layer)->toBe(Layer::Config);
});

it('reads nothing from a component that records nothing', function (): void {
    $document = $this->document;
    unset($document['components']['responses']['Created']['x-docuccino']);

    $labels = array_map(
        static fn (ExplainedNode $node): string => $node->label,
        (new OperationExplainer)->explain($document, '/api/invoices', 'post'),
    );

    expect($labels)->not->toContain('#/components/responses/Created');
});

it('reads a $ref that resolves to nothing as nothing rather than failing', function (string $ref): void {
    $document = $this->document;
    $document['paths']['/api/invoices']['post']['responses']['201']['$ref'] = $ref;

    $nodes = (new OperationExplainer)->explain($document, '/api/invoices', 'post');

    expect($nodes[2]->ref)->toBe($ref)
        ->and($nodes[2]->fields[1]->winner()?->value)->toBeNull()
        ->and($nodes)->toHaveCount(3);
})->with([
    'a component that is not there' => ['#/components/responses/Gone'],
    'a pointer at the document root' => ['#/'],
]);

it('terminates on a component that points at itself', function (): void {
    $document = $this->document;
    $document['components']['responses']['Created']['$ref'] = '#/components/responses/Created';

    expect((new OperationExplainer)->explain($document, '/api/invoices', 'post'))->toHaveCount(4);
});

it('explains nothing for an operation that is not there', function (string $path, string $method): void {
    expect((new OperationExplainer)->explain($this->document, $path, $method))->toBe([]);
})->with([
    'an unknown path' => ['/api/credits', 'post'],
    'a verb the path does not answer' => ['/api/invoices', 'delete'],
]);

/**
 * What `--provenance=none` leaves behind. The command builds its own document so it never reads one,
 * but the model has to answer for it rather than assume a trail is always there.
 */
it('explains nothing for a document exported without provenance', function (): void {
    $strip = function (array $node) use (&$strip): array {
        foreach ($node as $key => $value) {
            if ($key === 'x-docuccino' && is_array($value)) {
                unset($value['provenance']);
                $node[$key] = $value;

                continue;
            }

            if (is_array($value)) {
                $node[$key] = $strip($value);
            }
        }

        return $node;
    };

    expect((new OperationExplainer)->explain($strip($this->document), '/api/invoices', 'post'))->toBe([]);
});

/**
 * A shadowed value is remembered by producer alone, so the rung it lost from has to be recovered from
 * that name — by the same mapping the build ranked it with.
 */
it('recovers the rung a shadowed contribution lost from', function (string $producer, Layer $layer): void {
    $document = $this->document;
    $document['paths']['/api/invoices']['post']['x-docuccino']['provenance'][0]['overrode'] = [
        ['field' => 'summary', 'value' => 'displaced', 'producer' => $producer],
    ];

    $summary = (new OperationExplainer)->explain($document, '/api/invoices', 'post')[0]->fields[0];
    $shadowed = array_values(array_filter($summary->contributions, static fn ($c): bool => ! $c->won));

    expect($shadowed)->toHaveCount(1)
        ->and($shadowed[0]->layer)->toBe($layer);
})->with([
    'fallback' => ['fallback', Layer::Fallback],
    'inference' => ['inference', Layer::Inference],
    'an integration' => ['integration:eloquent', Layer::Integration],
    'docblock' => ['docblock', Layer::Docblock],
    'attribute' => ['attribute', Layer::Attribute],
    'overlay' => ['overlay', Layer::Overlay],
    'config' => ['config', Layer::Config],
    'a producer named by an extension' => ['acme-contracts', Layer::Inference],
]);

it('names a shadowed contribution whose producer was never recorded', function (): void {
    $document = $this->document;
    $document['paths']['/api/invoices']['post']['x-docuccino']['provenance'][0]['overrode'] = [
        ['field' => 'summary', 'value' => 'displaced'],
    ];

    $shadowed = (new OperationExplainer)->explain($document, '/api/invoices', 'post')[0]->fields[0]->contributions[1];

    expect($shadowed->producer)->toBe('(unrecorded)')
        ->and($shadowed->won)->toBeFalse()
        ->and($shadowed->layer)->toBe(Layer::Inference);
});

it('ranks a winner by its producer when the layer it names is one we do not know', function (): void {
    $document = $this->document;
    $document['paths']['/api/invoices']['post']['x-docuccino']['provenance'][0]['layer'] = 'recording';

    $winner = (new OperationExplainer)->explain($document, '/api/invoices', 'post')[0]->fields[0]->winner();

    expect($winner?->layer)->toBe(Layer::Attribute);
});

it('publishes the whole trail as data for a tool to read', function (): void {
    $node = (new OperationExplainer)->explain($this->document, '/api/invoices', 'post')[2];

    expect($node->toArray())->toBe([
        'label' => 'responses.201',
        'pointer' => '/paths/~1api~1invoices/post/responses/201',
        'ref' => '#/components/responses/Created',
        'fields' => [
            [
                'field' => 'component',
                'contributions' => [[
                    'producer' => 'integration:framework-errors',
                    'layer' => 'integration',
                    'rank' => 20,
                    'won' => true,
                    'value' => 'Created',
                ]],
            ],
            [
                'field' => 'description',
                'contributions' => [[
                    'producer' => 'integration:framework-errors',
                    'layer' => 'integration',
                    'rank' => 20,
                    'won' => true,
                    'value' => 'The invoice as stored.',
                ]],
            ],
        ],
    ]);
});
