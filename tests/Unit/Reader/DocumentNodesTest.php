<?php

declare(strict_types=1);

use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Document\UseSites;
use Docuccino\Core\Reader\DocumentNode;
use Docuccino\Core\Reader\DocumentReader;
use Docuccino\Core\Reader\ExtensionMember;
use Docuccino\Core\Reader\NodeKind;
use Docuccino\Core\Support\JsonValue;

/*
 * The manifest of a read document, its content hash, and the model the differ reads — the same answers
 * for a UIR 2.0 artifact and its 2.1 lift, and for a plain document with no ids at all.
 */

it('lists a plain document\'s nodes, keyed by structure, with no identities', function (): void {
    $yaml = <<<'YAML'
        openapi: 3.1.0
        info: {title: Orders, version: '1'}
        paths:
          /orders/{orderId}:
            parameters:
              - {name: orderId, in: path, required: true, schema: {type: string}}
              - {name: X-Trace, in: header, schema: {type: string}}
            get:
              summary: Show an order
              parameters:
                - {name: X-Trace, in: header, deprecated: true, schema: {type: string}}
                - $ref: '#/components/parameters/Expand'
              responses:
                '200': {description: The order}
                '404': {$ref: '#/components/responses/NotFound'}
            delete:
              operationId: deleteOrder
              deprecated: true
              responses:
                '204': {description: Deleted}
        webhooks:
          orderShipped:
            post:
              responses:
                '200': {description: Received}
        components:
          parameters:
            Expand: {name: expand, in: query, schema: {type: string}}
          responses:
            NotFound: {description: Not found}
          schemas:
            Order: {title: An order, type: object}
            Legacy: {deprecated: true}
            Never: false
        YAML;

    $nodes = (new DocumentReader)->read($yaml)->nodes();

    $at = '/paths/~1orders~1{orderId}';
    expect(array_map(static fn (DocumentNode $node): array => [$node->kind, $node->identity, $node->key, $node->pointer, $node->label, $node->deprecated], $nodes))->toBe([
        [NodeKind::Operation, null, 'DELETE /orders/{p0}', $at.'/delete', 'deleteOrder', true],
        [NodeKind::Operation, null, 'GET /orders/{p0}', $at.'/get', 'Show an order', false],
        [NodeKind::Operation, null, 'POST webhook:orderShipped', '/webhooks/orderShipped/post', null, false],
        // A shared parameter is a node of its own, keyed by the reference its uses write.
        [NodeKind::Parameter, null, '#/components/parameters/Expand', '/components/parameters/Expand', 'expand', false],
        // A path-item parameter belongs to every operation that does not restate it, written once.
        [NodeKind::Parameter, null, 'DELETE /orders/{p0} header:X-Trace', $at.'/parameters/1', 'X-Trace', false],
        [NodeKind::Parameter, null, 'DELETE /orders/{p0} path:orderId', $at.'/parameters/0', 'orderId', false],
        // …and the operation's own wins where it does.
        [NodeKind::Parameter, null, 'GET /orders/{p0} header:X-Trace', $at.'/get/parameters/0', 'X-Trace', true],
        [NodeKind::Parameter, null, 'GET /orders/{p0} path:orderId', $at.'/parameters/0', 'orderId', false],
        // A `$ref` is keyed by what it resolves to.
        [NodeKind::Parameter, null, 'GET /orders/{p0} query:expand', $at.'/get/parameters/1', 'expand', false],
        [NodeKind::Response, null, '#/components/responses/NotFound', '/components/responses/NotFound', 'Not found', false],
        [NodeKind::Response, null, 'DELETE /orders/{p0} 204', $at.'/delete/responses/204', 'Deleted', false],
        [NodeKind::Response, null, 'GET /orders/{p0} 200', $at.'/get/responses/200', 'The order', false],
        [NodeKind::Response, null, 'GET /orders/{p0} 404', $at.'/get/responses/404', 'Not found', false],
        [NodeKind::Response, null, 'POST webhook:orderShipped 200', '/webhooks/orderShipped/post/responses/200', 'Received', false],
        [NodeKind::Schema, null, 'Legacy', '/components/schemas/Legacy', null, true],
        [NodeKind::Schema, null, 'Never', '/components/schemas/Never', null, false],
        [NodeKind::Schema, null, 'Order', '/components/schemas/Order', 'An order', false],
    ]);

    foreach ($nodes as $node) {
        expect($node->digest)->toMatch('/^[0-9a-f]{64}$/');
    }

    // One parameter written once is one node's bytes, under whichever operation it is listed; the one an
    // operation restates is a node of its own.
    expect($nodes[5]->digest)->toBe($nodes[7]->digest)
        ->and($nodes[6]->digest)->not->toBe($nodes[4]->digest);
});

it('keys an operation by its path with the parameter names numbered, so a rename moves no key', function (): void {
    $read = static fn (string $path): string => (new DocumentReader)->read((string) json_encode([
        'openapi' => '3.1.0',
        'info' => ['title' => 'Orders', 'version' => '1'],
        'paths' => [$path => ['get' => ['responses' => ['200' => ['description' => 'OK']]]]],
    ]))->nodes()[0]->key;

    expect($read('/orders/{order}/lines/{line}'))->toBe('GET /orders/{p0}/lines/{p1}')
        ->and($read('/orders/{id}/lines/{lineId}'))->toBe('GET /orders/{p0}/lines/{p1}');
});

it('reads a path item written as a reference where its operations are', function (): void {
    $nodes = (new DocumentReader)->read((string) json_encode([
        'openapi' => '3.1.0',
        'info' => ['title' => 'Orders', 'version' => '1'],
        'paths' => ['/orders' => ['$ref' => '#/components/pathItems/Orders'], '/missing' => ['$ref' => '#/components/pathItems/Nowhere']],
        'components' => ['pathItems' => ['Orders' => ['get' => ['summary' => 'List orders', 'responses' => ['200' => ['description' => 'OK']]]]]],
    ]))->nodes();

    expect(array_map(static fn (DocumentNode $node): array => [$node->key, $node->pointer], $nodes))->toBe([
        ['GET /orders', '/components/pathItems/Orders/get'],
        ['GET /orders 200', '/components/pathItems/Orders/get/responses/200'],
    ]);
});

it('lists a custom operation whose method spells a fixed one as an operation of its own', function (): void {
    // OpenAPI 3.2's `additionalOperations` maps a method, case-sensitive and sent as written, to an operation,
    // and forbids only the nine fixed methods in upper case: `get` and `Get` there are methods of their own
    // beside the fixed `get`, which is sent as `GET`. So all four are listed, each keyed by where it is
    // written, and so are their parameters and responses — the path item's parameter under each of them.
    $operation = static fn (string $summary, string $description): array => [
        'summary' => $summary,
        'parameters' => [['name' => 'into', 'in' => 'query']],
        'responses' => ['200' => ['description' => $description]],
    ];
    $nodes = (new DocumentReader)->read((string) json_encode([
        'openapi' => '3.2.0',
        'info' => ['title' => 'Forms', 'version' => '1'],
        'paths' => ['/forms/{form}' => [
            'parameters' => [['name' => 'form', 'in' => 'path', 'required' => true]],
            'get' => $operation('Show', 'Shown'),
            'additionalOperations' => ['get' => $operation('Fetch', 'Fetched'), 'Get' => $operation('Retrieve', 'Retrieved'), 'COPY' => $operation('Copy', 'Copied')],
        ]],
    ]))->nodes();

    $at = '/paths/~1forms~1{form}';
    [$get, $copy, $Custom, $custom] = ['GET /forms/{p0}', 'additionalOperations/COPY /forms/{p0}', 'additionalOperations/Get /forms/{p0}', 'additionalOperations/get /forms/{p0}'];

    expect(array_map(static fn (DocumentNode $node): array => [$node->kind, $node->key, $node->pointer, $node->label], $nodes))->toBe([
        [NodeKind::Operation, $get, $at.'/get', 'Show'],
        [NodeKind::Operation, $copy, $at.'/additionalOperations/COPY', 'Copy'],
        [NodeKind::Operation, $Custom, $at.'/additionalOperations/Get', 'Retrieve'],
        [NodeKind::Operation, $custom, $at.'/additionalOperations/get', 'Fetch'],
        [NodeKind::Parameter, $get.' path:form', $at.'/parameters/0', 'form'],
        [NodeKind::Parameter, $get.' query:into', $at.'/get/parameters/0', 'into'],
        [NodeKind::Parameter, $copy.' path:form', $at.'/parameters/0', 'form'],
        [NodeKind::Parameter, $copy.' query:into', $at.'/additionalOperations/COPY/parameters/0', 'into'],
        [NodeKind::Parameter, $Custom.' path:form', $at.'/parameters/0', 'form'],
        [NodeKind::Parameter, $Custom.' query:into', $at.'/additionalOperations/Get/parameters/0', 'into'],
        [NodeKind::Parameter, $custom.' path:form', $at.'/parameters/0', 'form'],
        [NodeKind::Parameter, $custom.' query:into', $at.'/additionalOperations/get/parameters/0', 'into'],
        [NodeKind::Response, $get.' 200', $at.'/get/responses/200', 'Shown'],
        [NodeKind::Response, $copy.' 200', $at.'/additionalOperations/COPY/responses/200', 'Copied'],
        [NodeKind::Response, $Custom.' 200', $at.'/additionalOperations/Get/responses/200', 'Retrieved'],
        [NodeKind::Response, $custom.' 200', $at.'/additionalOperations/get/responses/200', 'Fetched'],
    ])
        ->and(array_unique(array_map(static fn (DocumentNode $node): string => $node->key, $nodes)))->toHaveCount(count($nodes));
});

it('lists every other node of a document exactly as the document without its custom operations lists it', function (): void {
    // A custom operation is a node of its own, so adding one — under a path, a webhook or a shared path item,
    // and whatever method it spells — moves nothing else the index lists: not a key, a pointer, a label, an
    // id or a digest, nor their order.
    $operation = static fn (string $id): array => [
        'x-docuccino' => ['id' => $id],
        'summary' => 'An operation',
        'parameters' => [['name' => 'into', 'in' => 'query'], ['$ref' => '#/components/parameters/Tenant']],
        'responses' => ['200' => ['description' => 'Done'], '404' => ['$ref' => '#/components/responses/NotFound']],
    ];
    $custom = ['get' => $operation('op:v1:1'), 'Get' => $operation('op:v1:2'), 'GET' => $operation('op:v1:3'), 'COPY' => $operation('op:v1:4')];
    $document = [
        'openapi' => '3.2.0',
        'info' => ['title' => 'Forms', 'version' => '1'],
        'paths' => [
            '/forms/{form}' => [
                'parameters' => [['name' => 'form', 'in' => 'path', 'required' => true]],
                'get' => $operation('op:v1:5'),
                'post' => $operation('op:v1:6'),
                'additionalOperations' => $custom,
            ],
            '/archive' => ['$ref' => '#/components/pathItems/Archive'],
        ],
        'webhooks' => ['formCopied' => ['post' => $operation('op:v1:7'), 'additionalOperations' => $custom]],
        'components' => [
            'parameters' => ['Tenant' => ['name' => 'Tenant', 'in' => 'header']],
            'responses' => ['NotFound' => ['description' => 'Not found']],
            'pathItems' => ['Archive' => ['get' => $operation('op:v1:8'), 'additionalOperations' => $custom]],
        ],
    ];
    $without = $document;
    unset($without['paths']['/forms/{form}']['additionalOperations'], $without['webhooks']['formCopied']['additionalOperations'], $without['components']['pathItems']['Archive']['additionalOperations']);

    $listed = static fn (array $document): array => array_map(
        static fn (DocumentNode $node): array => $node->toArray(),
        (new DocumentReader)->read((string) json_encode($document))->nodes(),
    );
    $all = $listed($document);
    $others = array_values(array_filter($all, static fn (array $node): bool => ! str_starts_with($node['key'], 'additionalOperations/')));

    // Three path items of four custom operations, each listing itself, a parameter and a response of its own
    // and one it shares; and under the one item that states one, the path parameter for each.
    expect($others)->toBe($listed($without))
        ->and(count($all) - count($others))->toBe(3 * 4 * 5 + 4);
});

it('lists a full document\'s nodes with every identity it carries', function (): void {
    $nodes = (new DocumentReader)->read((string) file_get_contents(golden('workbench-content.uir.json')))->nodes();

    $prefix = [
        NodeKind::Operation->value => 'op:',
        NodeKind::Parameter->value => 'par:',
        NodeKind::Response->value => 'res:',
        NodeKind::Schema->value => 'sch:',
        NodeKind::Page->value => 'page:',
    ];

    $kinds = [];
    foreach ($nodes as $node) {
        $kinds[$node->kind->value] = ($kinds[$node->kind->value] ?? 0) + 1;

        // A workflow is named by its key and has no id; a response with no body has none either, since
        // a response's id is minted from its media type. Everything else carries its own.
        if ($node->kind === NodeKind::Workflow) {
            expect($node->identity)->toBeNull();
        } elseif ($node->identity !== null || $node->kind !== NodeKind::Response) {
            expect((string) $node->identity)->toStartWith($prefix[$node->kind->value]);
        }
    }

    expect(array_keys($kinds))->toBe(array_map(static fn (NodeKind $kind): string => $kind->value, NodeKind::cases()))
        ->and(array_unique(array_map(static fn (DocumentNode $node): string => $node->key, $nodes)))->toHaveCount(count($nodes))
        ->and($nodes)->toHaveCount(array_sum($kinds));
});

it('gives a use of a shared parameter the id of the component an export carries it on', function (): void {
    $nodes = (new DocumentReader)->read((string) file_get_contents(golden('versioned-forms.openapi32.ids.json')))->nodes();
    $parameters = array_values(array_filter($nodes, static fn (DocumentNode $node): bool => $node->kind === NodeKind::Parameter));

    // The component itself, then its use.
    expect($parameters)->toHaveCount(2 + 1)
        ->and($parameters[0]->key)->toStartWith('#/components/parameters/')
        ->and(array_map(static fn (DocumentNode $node): ?string => $node->identity, $parameters))->toBe(array_fill(0, 3, 'par:v1:tbvh7ol7jzjn5kpw'))
        ->and($parameters[1]->key)->toBe('GET /api/versioned-forms header:X-Api-Version');
});

it('reads a UIR 2.0 artifact and its 2.1 lift as the same document', function (): void {
    // The 2.1 golden carries its uses on the operation; lowered, it is the 2.0 artifact the same build
    // wrote. Nodes, hash and model must not be able to tell them apart.
    $published = (string) file_get_contents(golden('workbench-content.uir.json'));
    $older = UseSites::lower(JsonValue::decode($published));
    $older['x-docuccino']['generator']['specVersion'] = '2.0.0';

    $current = (new DocumentReader)->read($published);
    $previous = (new DocumentReader)->read((string) json_encode($older));

    expect($older)->not->toBe(JsonValue::decode($published))
        ->and($previous->inspect()->extensions)->not->toContain(ExtensionMember::Uses)
        ->and($previous->nodes())->toEqual($current->nodes())
        ->and($previous->contentHash())->toBe($current->contentHash())
        ->and((new DocumentDiffer)->diff($previous->uir(), $current->uir())->isEmpty())->toBeTrue();
});

it('moves no digest when only provenance changes, and only the edited node\'s when content does', function (): void {
    $golden = JsonValue::decode((string) file_get_contents(golden('workbench-content.uir.json')));
    expect($golden)->toBeArray();

    /** @var array<string, mixed> $golden */
    $relocated = $golden;
    $relocated['paths']['/api/forms']['get']['x-docuccino']['provenance'][0]['source']['line'] = 999;
    $relocated['components']['schemas']['FormData']['x-docuccino']['provenance'] = [['producer' => 'config', 'layer' => 'config']];

    $edited = $golden;
    $edited['paths']['/api/forms']['get']['summary'] = 'List every form.';

    $digests = static fn (array $document): array => array_map(
        static fn (DocumentNode $node): string => $node->digest,
        (new DocumentReader)->read((string) json_encode($document))->nodes(),
    );

    $before = $digests($golden);
    $moved = array_keys(array_diff_assoc($digests($edited), $before));

    expect($digests($relocated))->toBe($before)
        ->and($moved)->toHaveCount(1)
        ->and((new DocumentReader)->read((string) json_encode($edited))->nodes()[$moved[0]]->key)->toBe('GET /api/forms');
});

it('moves the shared component\'s digest, and no use\'s, when a shared response is edited', function (): void {
    $document = static fn (string $description): string => (string) json_encode([
        'openapi' => '3.1.0',
        'info' => ['title' => 'Orders', 'version' => '1'],
        'paths' => ['/orders' => ['get' => ['responses' => ['404' => ['$ref' => '#/components/responses/NotFound']]]]],
        'components' => ['responses' => ['NotFound' => ['description' => $description]]],
    ]);
    $digests = static function (string $bytes): array {
        $keyed = [];
        foreach ((new DocumentReader)->read($bytes)->nodes() as $node) {
            $keyed[$node->key] = $node->digest;
        }

        return $keyed;
    };

    $before = $digests($document('Not found'));
    $after = $digests($document('No such order'));

    expect(array_keys(array_diff_assoc($after, $before)))->toBe(['#/components/responses/NotFound']);
});

it('lists nodes in an order that does not depend on the order the bytes were written in', function (): void {
    $golden = JsonValue::decode((string) file_get_contents(golden('workbench-content.uir.json')));
    expect($golden)->toBeArray();

    /** @var array<string, mixed> $golden */
    $reversed = $golden;
    $reversed['paths'] = array_reverse($golden['paths'], true);
    $reversed['components']['schemas'] = array_reverse($golden['components']['schemas'], true);
    $reversed = array_reverse($reversed, true);

    expect((new DocumentReader)->read((string) json_encode($reversed))->nodes())
        ->toEqual((new DocumentReader)->read((string) json_encode($golden))->nodes());
});

it('recomputes the content hash every generated full artifact carries', function (string $path): void {
    $bytes = (string) file_get_contents($path);
    $stored = JsonValue::decode($bytes)['x-docuccino']['document']['contentHash'] ?? null;

    expect((new DocumentReader)->read($bytes)->contentHash())->toBe($stored);
})->with(static function (): array {
    $goldens = [];
    foreach ((array) glob(golden('*.uir.json')) as $path) {
        $goldens[basename((string) $path)] = [(string) $path];
    }

    // A glob that matched nothing would pass forever.
    expect(count($goldens))->toBeGreaterThanOrEqual(50);

    return $goldens;
});

it('hashes what the document says, never who built it or what the build reported', function (): void {
    $golden = JsonValue::decode((string) file_get_contents(golden('workbench-content.uir.json')));
    expect($golden)->toBeArray();

    /** @var array<string, mixed> $golden */
    $rebuilt = $golden;
    $rebuilt['x-docuccino']['generator'] = ['name' => 'docuccino/laravel', 'version' => '9.9.9', 'specVersion' => '2.1.0'];
    $rebuilt['x-docuccino']['diagnostics'] = [['code' => 'example.unused', 'severity' => 'info', 'message' => 'Unused.']];
    $rebuilt['x-docuccino']['document']['contentHash'] = str_repeat('0', 64);

    $edited = $golden;
    $edited['info']['title'] = 'Another API';

    $hash = (new DocumentReader)->read((string) json_encode($golden))->contentHash();

    expect((new DocumentReader)->read((string) json_encode($rebuilt))->contentHash())->toBe($hash)
        ->and((new DocumentReader)->read((string) json_encode($edited))->contentHash())->not->toBe($hash);
});

it('hashes a plain document by the same algorithm, whatever spelling it arrived in', function (): void {
    $compact = '{"openapi":"3.1.0","info":{"title":"Orders","version":"1"},"paths":{"/orders":{"get":{"responses":{"200":{"description":"OK"}}}}}}';
    $pretty = (string) json_encode(json_decode($compact), JSON_PRETTY_PRINT);
    $yaml = "paths:\n  /orders:\n    get:\n      responses:\n        '200': {description: OK}\ninfo: {version: '1', title: Orders}\nopenapi: 3.1.0\n";

    $hash = (new DocumentReader)->read($compact)->contentHash();

    expect($hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and((new DocumentReader)->read($pretty)->contentHash())->toBe($hash)
        ->and((new DocumentReader)->read($yaml)->contentHash())->toBe($hash);
});

it('hands the differ a model it pairs by identity', function (): void {
    $golden = JsonValue::decode((string) file_get_contents(golden('workbench-content.uir.json')));
    expect($golden)->toBeArray();

    /** @var array<string, mixed> $golden */
    $removed = $golden;
    unset($removed['paths']['/api/forms']);

    $changes = (new DocumentDiffer)->diff(
        (new DocumentReader)->read((string) json_encode($golden))->uir(),
        (new DocumentReader)->read((string) json_encode($removed))->uir(),
    );

    expect($changes->isBreaking())->toBeTrue();
});

it('writes a node as values a store can hold, one member per property', function (): void {
    $nodes = (new DocumentReader)->read((string) file_get_contents(golden('workbench-content.uir.json')))->nodes();
    $node = $nodes[0];

    expect($node->toArray())->toBe([
        'kind' => $node->kind->value,
        'identity' => $node->identity,
        'key' => $node->key,
        'pointer' => $node->pointer,
        'label' => $node->label,
        'deprecated' => $node->deprecated,
        'digest' => $node->digest,
    ])
        ->and($node->identity)->toBeString()
        // Every property the node carries, in the order it declares them, so one added is one written.
        ->and(array_keys($node->toArray()))->toBe(array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            (new ReflectionMethod(DocumentNode::class, '__construct'))->getParameters(),
        ))
        ->and(array_map(static fn (DocumentNode $node): string => $node->toArray()['kind'], $nodes))->each->toBeIn(array_map(static fn (NodeKind $kind): string => $kind->value, NodeKind::cases()));
});
