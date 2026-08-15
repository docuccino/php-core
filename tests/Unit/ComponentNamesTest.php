<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Schema\ComponentNames;

/**
 * The published names two or more classes contesting one component name end up under. The property
 * that matters is not any single name but that the whole map is a function of the contesting FQCNs
 * alone — never of who registered first — because the alternative hands the plain name to whichever
 * route happened to sort earliest, and an unrelated route added later silently swaps two shapes.
 */
it('publishes contested names off the namespace, taking only as much as it needs', function (array $schemaIds, array $names, array $expected): void {
    expect(ComponentNames::resolve($schemaIds, $names))->toEqual($expected);
})->with([
    'nothing contested' => [
        ['UserData' => 'App\\Data\\UserData'],
        ['UserData'],
        [],
    ],
    'the real case: an input shape and an output shape of one name' => [
        ['SSOConnectionData' => 'App\\DTOs\\Schema\\Authentication\\SSOConnectionData', 'SSOConnectionData_2' => 'App\\DTOs\\Data\\SSO\\SSOConnectionData'],
        ['SSOConnectionData', 'SSOConnectionData_2'],
        ['SSOConnectionData' => 'AuthenticationSSOConnectionData', 'SSOConnectionData_2' => 'SSOSSOConnectionData'],
    ],
    'one segment is not enough, so both take two' => [
        ['Node' => 'App\\Read\\Shared\\Node', 'Node_2' => 'App\\Write\\Shared\\Node'],
        ['Node', 'Node_2'],
        ['Node' => 'ReadSharedNode', 'Node_2' => 'WriteSharedNode'],
    ],
    'three claimants, all qualified together' => [
        ['Node' => 'App\\A\\Node', 'Node_2' => 'App\\B\\Node', 'Node_3' => 'App\\C\\Node'],
        ['Node', 'Node_2', 'Node_3'],
        ['Node' => 'ANode', 'Node_2' => 'BNode', 'Node_3' => 'CNode'],
    ],
    'a qualified name another schema already holds is deepened past it' => [
        ['Node' => 'App\\A\\Node', 'Node_2' => 'App\\B\\Node'],
        ['Node', 'Node_2', 'ANode'],
        ['Node' => 'AppANode', 'Node_2' => 'BNode'],
    ],
    'a contested base an unidentified shape claims keeps its positional names' => [
        ['Node_2' => 'App\\B\\Node'],
        ['Node', 'Node_2'],
        [],
    ],
    'a global class has no namespace, so the pair keeps its positional names' => [
        ['Node' => 'Node', 'Node_2' => 'App\\B\\Node'],
        ['Node', 'Node_2'],
        [],
    ],
    'one class hoisted twice — a request shape beside its response shape — is not a class contest' => [
        ['UserData' => 'App\\Data\\UserData', 'UserData_2' => 'App\\Data\\UserData#request'],
        ['UserData', 'UserData_2'],
        [],
    ],
    'a same-class pair blocks the walk for the whole group, third claimant included' => [
        ['UserData' => 'App\\Data\\UserData', 'UserData_2' => 'App\\Data\\UserData#request', 'UserData_3' => 'App\\Dto\\UserData'],
        ['UserData', 'UserData_2', 'UserData_3'],
        [],
    ],
    'a shared tail segment is separated by the root above it' => [
        ['Node' => 'Vendor\\Pkg\\Node', 'Node_2' => 'App\\Pkg\\Node'],
        ['Node', 'Node_2'],
        ['Node' => 'VendorPkgNode', 'Node_2' => 'AppPkgNode'],
    ],
    'one namespace, two classes claiming one name: the walk is exhausted, so the FQCN order breaks it' => [
        ['Node' => 'App\\Pkg\\Alpha', 'Node_2' => 'App\\Pkg\\Beta'],
        ['Node', 'Node_2'],
        ['Node' => 'AppPkgNode', 'Node_2' => 'AppPkgNode_2'],
    ],
    'the author-chosen base is what gets qualified, not the class short name' => [
        ['Statement' => 'App\\Billing\\StatementData', 'Statement_2' => 'App\\Support\\StatementData'],
        ['Statement', 'Statement_2'],
        ['Statement' => 'BillingStatement', 'Statement_2' => 'SupportStatement'],
    ],
]);

it('depends on the contesting FQCNs alone, not on which of them registered first', function (): void {
    // The whole point. Two builds that met the same two classes in opposite orders publish the same
    // two names, so adding a route that sorts earlier cannot swap what `SSOConnectionData` means.
    $a = 'App\\Schema\\Auth\\SSOConnectionData';
    $b = 'App\\Data\\SSO\\SSOConnectionData';

    $names = ['SSOConnectionData', 'SSOConnectionData_2'];
    $forwards = ComponentNames::resolve(['SSOConnectionData' => $a, 'SSOConnectionData_2' => $b], $names);
    $backwards = ComponentNames::resolve(['SSOConnectionData' => $b, 'SSOConnectionData_2' => $a], $names);

    // Same class, same published name, whichever provisional slot it happened to land in.
    expect($forwards)->toEqual(['SSOConnectionData' => 'AuthSSOConnectionData', 'SSOConnectionData_2' => 'SSOSSOConnectionData'])
        ->and($backwards)->toEqual(['SSOConnectionData' => 'SSOSSOConnectionData', 'SSOConnectionData_2' => 'AuthSSOConnectionData']);
});

it('retires the contested name rather than awarding it to one of the claimants', function (): void {
    // If one claimant kept `Node`, a build that met the other first would publish a `Node` of the other
    // shape — same name, different meaning, and a green build either way.
    $renames = ComponentNames::resolve(['Node' => 'App\\A\\Node', 'Node_2' => 'App\\B\\Node'], ['Node', 'Node_2']);

    expect(array_values($renames))->not->toContain('Node');
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
