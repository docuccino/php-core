<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Schema\ComponentNames;

/**
 * The names schemas contesting one component name end up published under. The property that matters is
 * not any single name but that the whole map is a function of the claims alone — never of who
 * registered first — because the alternative hands the plain name to whichever route happened to sort
 * earliest, and an unrelated route added later silently swaps two shapes.
 *
 * @param  array<string, array{base: string, identity: string|null, content: string}>  $claims
 * @param  array<string, string>  $expected
 */
it('publishes a name off what the schema is, not off the slot it landed in', function (array $claims, array $expected): void {
    expect(ComponentNames::settlement($claims)[0])->toEqual($expected);
})->with([
    'nothing contested' => [
        ['UserData' => claim('UserData', 'App\\Data\\UserData')],
        [],
    ],
    'a class request shape is not its class response shape, so neither has to fight for the name' => [
        // A slot-based answer lands these on `Foo`/`Foo_2` by route order, so adding one read route
        // flips which shape `Foo` means.
        ['Article' => claim('Article', 'article.v1#request'), 'Article_2' => claim('Article', 'article.v1')],
        ['Article' => 'ArticleRequest', 'Article_2' => 'Article'],
    ],
    'a request whose name already says request does not say it twice' => [
        ['StoreWidgetRequest' => claim('StoreWidgetRequest', 'App\\Http\\Requests\\StoreWidgetRequest#request')],
        [],
    ],
    'the real case: an input shape and an output shape of one name' => [
        ['SSOConnectionData' => claim('SSOConnectionData', 'App\\DTOs\\Schema\\Authentication\\SSOConnectionData'), 'SSOConnectionData_2' => claim('SSOConnectionData', 'App\\DTOs\\Data\\SSO\\SSOConnectionData')],
        ['SSOConnectionData' => 'AuthenticationSSOConnectionData', 'SSOConnectionData_2' => 'SSOSSOConnectionData'],
    ],
    'one segment is not enough, so both take two' => [
        ['Node' => claim('Node', 'App\\Read\\Shared\\Node'), 'Node_2' => claim('Node', 'App\\Write\\Shared\\Node')],
        ['Node' => 'ReadSharedNode', 'Node_2' => 'WriteSharedNode'],
    ],
    'three claimants, all qualified together' => [
        ['Node' => claim('Node', 'App\\A\\Node'), 'Node_2' => claim('Node', 'App\\B\\Node'), 'Node_3' => claim('Node', 'App\\C\\Node')],
        ['Node' => 'ANode', 'Node_2' => 'BNode', 'Node_3' => 'CNode'],
    ],
    'a qualified name another schema asked for plainly is deepened past, leaving the incumbent alone' => [
        ['Node' => claim('Node', 'App\\A\\Node'), 'Node_2' => claim('Node', 'App\\B\\Node'), 'ANode' => claim('ANode', 'App\\X\\ANode')],
        ['Node' => 'AppANode', 'Node_2' => 'BNode'],
    ],
    'a shape that names no identity is discriminated by the bytes it publishes' => [
        ['Node' => claim('Node', null, '{"type":"object"}'), 'Node_2' => claim('Node', 'App\\B\\Node')],
        ['Node' => 'Node_uldzsjrk', 'Node_2' => 'BNode'],
    ],
    'a global class has no namespace to walk, so it takes the hash rung' => [
        ['Node' => claim('Node', 'Node'), 'Node_2' => claim('Node', 'App\\B\\Node')],
        ['Node' => 'Node_5ezxeuz7', 'Node_2' => 'BNode'],
    ],
    'a #[SchemaId] pin with no namespace is still stable, just not descriptive' => [
        ['UserData' => claim('UserData', 'user-v1'), 'UserData_2' => claim('UserData', 'App\\Admin\\UserData')],
        ['UserData' => 'UserData_x7ztb6hq', 'UserData_2' => 'AdminUserData'],
    ],
    'a shared tail segment is separated by the root above it' => [
        ['Node' => claim('Node', 'Vendor\\Pkg\\Node'), 'Node_2' => claim('Node', 'App\\Pkg\\Node')],
        ['Node' => 'VendorPkgNode', 'Node_2' => 'AppPkgNode'],
    ],
    'one namespace, two classes claiming one name: the walk is exhausted, so the hash breaks it' => [
        ['Node' => claim('Node', 'App\\Pkg\\Alpha'), 'Node_2' => claim('Node', 'App\\Pkg\\Beta')],
        ['Node' => 'Node_dqd5ljz3', 'Node_2' => 'Node_2pvrnso5'],
    ],
    'the author-chosen base is what gets qualified, not the class short name' => [
        ['Statement' => claim('Statement', 'App\\Billing\\StatementData'), 'Statement_2' => claim('Statement', 'App\\Support\\StatementData')],
        ['Statement' => 'BillingStatement', 'Statement_2' => 'SupportStatement'],
    ],
    'a survivor left holding a suffix nothing else contests gets the name back' => [
        // What a warm fragment cache hands over once the route that held the plain name is deleted.
        ['SSOConnectionData_2' => claim('SSOConnectionData', 'App\\Data\\SSO\\SSOConnectionData')],
        ['SSOConnectionData_2' => 'SSOConnectionData'],
    ],
]);

it('depends on the claims alone, not on which of them registered first', function (): void {
    // The whole point. Two builds that met the same two classes in opposite orders publish the same
    // two names, so adding a route that sorts earlier cannot swap what `SSOConnectionData` means.
    $a = claim('SSOConnectionData', 'App\\Schema\\Auth\\SSOConnectionData');
    $b = claim('SSOConnectionData', 'App\\Data\\SSO\\SSOConnectionData');

    $forwards = ComponentNames::settlement(['SSOConnectionData' => $a, 'SSOConnectionData_2' => $b])[0];
    $backwards = ComponentNames::settlement(['SSOConnectionData' => $b, 'SSOConnectionData_2' => $a])[0];

    // Same class, same published name, whichever provisional slot it happened to land in.
    expect($forwards)->toEqual(['SSOConnectionData' => 'AuthSSOConnectionData', 'SSOConnectionData_2' => 'SSOSSOConnectionData'])
        ->and($backwards)->toEqual(['SSOConnectionData' => 'SSOSSOConnectionData', 'SSOConnectionData_2' => 'AuthSSOConnectionData']);
});

it('retires a name two claims asked for rather than awarding it to one of them', function (): void {
    // If one claimant kept `Node`, a build that met the other first would publish a `Node` of the other
    // shape — same name, different meaning, and a green build either way.
    $renames = ComponentNames::settlement(['Node' => claim('Node', 'App\\A\\Node'), 'Node_2' => claim('Node', 'App\\B\\Node')])[0];

    expect($renames)->toHaveKeys(['Node', 'Node_2'])
        ->and(array_values($renames))->not->toContain('Node');
});

it('leaves every other claim exactly where it was when one is added', function (): void {
    // Locality, stated directly: a new class contesting `Node` may move `Node`, and must move nothing
    // else — not the request shape beside it, and not the class that already held `ANode`.
    $before = [
        'Article' => claim('Article', 'article.v1#request'),
        'Article_2' => claim('Article', 'article.v1'),
        'ANode' => claim('ANode', 'App\\X\\ANode'),
        'Node' => claim('Node', 'App\\A\\Node'),
    ];

    $after = ComponentNames::settlement([...$before, 'Node_2' => claim('Node', 'App\\B\\Node')])[0];
    $settled = ComponentNames::settlement($before)[0];

    expect($settled)->toEqual(['Article' => 'ArticleRequest', 'Article_2' => 'Article'])
        ->and($after['Article'])->toBe('ArticleRequest')
        ->and($after['Article_2'])->toBe('Article')
        ->and($after)->not->toHaveKey('ANode')
        ->and($after['Node'])->toBe('AppANode');
});

it('reports each name two claims asked for, and nothing that was never contested', function (): void {
    $contests = ComponentNames::settlement([
        'Article' => claim('Article', 'article.v1#request'),
        'Article_2' => claim('Article', 'article.v1'),
        'Node' => claim('Node', 'App\\A\\Node'),
        'Node_2' => claim('Node', null, '{"type":"string"}'),
    ])[1];

    // A request shape beside its class's own shape never wanted one name, so it is not a collision.
    expect($contests)->toHaveKey('Node')
        ->and($contests)->not->toHaveKey('Article')
        ->and($contests['Node'])->toBe(['ANode' => 'App\\A\\Node', 'Node_abae42de' => 'an unidentified schema']);
});

it('sanitizes a name down to the characters a $ref may carry', function (string $raw, string $expected): void {
    expect(ComponentNames::sanitize($raw))->toBe($expected);
})->with([
    'kept verbatim' => ['User.Data-1_x', 'User.Data-1_x'],
    'generic brackets stripped' => ['Paginated<User>', 'PaginatedUser'],
    'nothing left is still a name' => ['<>', 'Schema'],
]);

it('rewrites references through a rename map, and only in the bucket named', function (): void {
    $node = [
        'a' => ['$ref' => '#/components/schemas/Old'],
        'b' => ['$ref' => '#/components/responses/Old'],
        'c' => ['$ref' => '#/components/schemas/Untouched'],
        'd' => ['nested' => [['$ref' => '#/components/schemas/Old']]],
    ];

    expect(ComponentNames::rename($node, ['Old' => 'New']))->toBe([
        'a' => ['$ref' => '#/components/schemas/New'],
        'b' => ['$ref' => '#/components/responses/Old'],
        'c' => ['$ref' => '#/components/schemas/Untouched'],
        'd' => ['nested' => [['$ref' => '#/components/schemas/New']]],
    ])
        ->and(ComponentNames::rename($node, ['Old' => 'New'], 'responses')['b'])->toBe(['$ref' => '#/components/responses/New'])
        ->and(ComponentNames::rename($node, []))->toBe($node);
});

it('rekeys a bucket through a rename map, leaving unnamed entries where they are', function (): void {
    expect(ComponentNames::rekey(['Old' => 1, 'Kept' => 2], ['Old' => 'New']))->toBe(['New' => 1, 'Kept' => 2])
        ->and(ComponentNames::rekey(['Old' => 1], []))->toBe(['Old' => 1]);
});
