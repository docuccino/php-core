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
    // A branch of a request's tagged object asks for its whole name, built on the request's own.
    'a part of a faceted shape adds no facet of its own' => [
        ['StoreThingRequestPaymentCard' => claim('StoreThingRequestPaymentCard', 'App\\Http\\StoreThingRequest#request/payment.method=card')],
        [],
    ],
    // What a part's identity holds after the `#` is a path and a value, which may hold anything; the
    // namespace walk reads the class's namespace alone.
    'two parts of one name climb by their classes\' namespaces, whatever the value holds' => [
        ['ThingCard' => claim('ThingCard', 'App\\A\\Thing#request/kind=card\\x'), 'ThingCard_2' => claim('ThingCard', 'App\\B\\Thing#request/kind=card\\x')],
        ['ThingCard' => 'AThingCard', 'ThingCard_2' => 'BThingCard'],
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

it('awards two claims nothing else separates by the set, not by which arrived first', function (array $claims): void {
    // The test above proves the property over claims with DIFFERENT identities, which never reach the
    // award's tie-break at all. These do: a pair agreeing on the discriminant runs off the same last
    // rung, one of them wears the `_2`, and a comparison that ties lets PHP's stable sort decide which —
    // so the plain name means one shape in a build that met it first and the other shape in a build that
    // did not. Whichever name each claim gets, it has to get the same one both ways round.
    [$one, $other] = array_keys($claims);

    [$forwards] = ComponentNames::mint($claims);
    [$backwards] = ComponentNames::mint([$other => $claims[$other], $one => $claims[$one]]);

    expect($forwards[$one])->toBe($backwards[$one])
        ->and($forwards[$other])->toBe($backwards[$other])
        // Still one-to-one, or the pair agreeing is being answered by one component.
        ->and(array_unique(array_values($forwards)))->toHaveCount(2);
})->with([
    // Two unidentified claims of one body: the discriminant IS the content, so both tie.
    'nothing but a body, and the same body' => [[
        'alpha' => claim('Thing', null, '{"type":"object"}'),
        'beta' => claim('Thing', null, '{"type":"object"}'),
    ]],
    // Two claims of one identity: the discriminant is the identity, so the content never gets read.
    'one identity claimed twice' => [[
        'alpha' => claim('Thing', 'App\\Data\\Thing', '{"type":"object"}'),
        'beta' => claim('Thing', 'App\\Data\\Thing', '{"type":"string"}'),
    ]],
]);

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

it('refuses every character a $ref could not carry into a JSON pointer', function (string $hostile): void {
    // `SharedErrorResponses` and the registry build a `$ref` by concatenating a name onto
    // `#/components/schemas/` with no escaping, which is safe ONLY because `/` and `~` — the two
    // characters RFC 6901 gives meaning to — cannot survive sanitize(). That coupling is load-bearing
    // and otherwise unguarded, so it is asserted here beside the charset it depends on.
    expect(ComponentNames::isLegal($hostile))->toBeFalse();
})->with([
    'pointer separator' => ['a/b'],
    'pointer escape' => ['a~b'],
    'both at once' => ['~0/~1'],
    'empty' => [''],
    'whitespace' => ['a b'],
    'only whitespace' => ['   '],
    'fragment' => ['a#b'],
    'percent' => ['a%2Fb'],
    'quote' => ['a"b'],
    'newline' => ["a\nb"],
    'nul' => ["a\0b"],
    'non-ascii' => ['Café'],
    'emoji' => ['📄'],
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

/*
 * A `$ref` is a reference only where the document makes one. Inside a value the document STATES — an
 * example, a default, a `const`, an `enum` member, an Example Object's `value` — it is part of the
 * payload, as an API serving schema documents sends it, and a client comparing a response with the
 * example gets back the pointer the server wrote. Rewriting it there publishes an example the server
 * never sends, and names whichever class registered first under the contested name.
 */
$pointer = ['$ref' => '#/components/schemas/Old'];

it('leaves a pointer a stated value carries exactly as the value states it', function (array $node): void {
    expect(ComponentNames::rename($node, ['Old' => 'New']))->toBe($node)
        ->and(ComponentNames::referenced($node))->toBe([]);
})->with([
    'a schema\'s example' => [['type' => 'object', 'example' => ['name' => 'user', 'schema' => $pointer]]],
    'a schema\'s default' => [['type' => 'object', 'default' => $pointer]],
    'a schema\'s const' => [['const' => $pointer]],
    'an enum member' => [['enum' => [$pointer]]],
    'a schema\'s examples' => [['examples' => [$pointer]]],
    'a media type\'s example' => [['content' => ['application/json' => ['schema' => ['type' => 'object'], 'example' => $pointer]]]],
    'an Example Object\'s value' => [['content' => ['application/json' => ['examples' => ['stored' => ['value' => $pointer]]]]]],
    'an Example Object\'s dataValue' => [['content' => ['application/json' => ['examples' => ['stored' => ['dataValue' => $pointer]]]]]],
    'a parameter\'s example' => [['parameters' => [['name' => 'shape', 'in' => 'query', 'schema' => ['type' => 'object'], 'example' => $pointer]]]],
    'a header\'s example' => [['headers' => ['X-Shape' => ['schema' => ['type' => 'object'], 'example' => $pointer]]]],
    'a shared Example Object' => [['components' => ['examples' => ['Stored' => ['value' => ['fields' => [$pointer]]]]]]],
]);

/*
 * And a member only SPELLED like one of those is a node like any other: a property called `example`,
 * the `default` response. A pointer there is a reference, and it follows the component it names — as
 * does one in the provenance a node records, which states a shape this document published under the
 * name it has now, and one in an extension, whose vocabulary is its own to define.
 */
it('renames a pointer behind a member only spelled like a stated value', function (array $node, array $renamed): void {
    expect(ComponentNames::rename($node, ['Old' => 'New']))->toBe($renamed)
        ->and(array_values(array_unique(ComponentNames::referenced($node))))->toBe(['Old']);
})->with([
    'a property named example' => [
        ['properties' => ['example' => $pointer]],
        ['properties' => ['example' => ['$ref' => '#/components/schemas/New']]],
    ],
    'properties named after every other stated value' => [
        ['properties' => ['default' => $pointer, 'const' => $pointer, 'enum' => $pointer, 'examples' => $pointer, 'value' => $pointer, 'dataValue' => $pointer]],
        ['properties' => array_fill_keys(['default', 'const', 'enum', 'examples', 'value', 'dataValue'], ['$ref' => '#/components/schemas/New'])],
    ],
    'the default response' => [
        ['responses' => ['default' => ['content' => ['application/json' => ['schema' => $pointer]]]]],
        ['responses' => ['default' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/New']]]]]],
    ],
    'a component named example' => [
        ['components' => ['schemas' => ['example' => ['items' => $pointer]]]],
        ['components' => ['schemas' => ['example' => ['items' => ['$ref' => '#/components/schemas/New']]]]],
    ],
    'a discriminator mapping' => [
        ['oneOf' => [$pointer], 'discriminator' => ['propertyName' => 'kind', 'mapping' => ['old' => '#/components/schemas/Old']]],
        ['oneOf' => [['$ref' => '#/components/schemas/New']], 'discriminator' => ['propertyName' => 'kind', 'mapping' => ['old' => '#/components/schemas/New']]],
    ],
    'the provenance a node records' => [
        ['type' => 'object', 'x-docuccino' => ['provenance' => [['producer' => 'integration:pagination', 'layer' => 'integration', 'fields' => ['properties'], 'overrode' => [['field' => 'properties', 'value' => ['data' => $pointer], 'producer' => 'inference']]]]]],
        ['type' => 'object', 'x-docuccino' => ['provenance' => [['producer' => 'integration:pagination', 'layer' => 'integration', 'fields' => ['properties'], 'overrode' => [['field' => 'properties', 'value' => ['data' => ['$ref' => '#/components/schemas/New']], 'producer' => 'inference']]]]]],
    ],
    'an extension' => [
        ['x-webhooks' => ['ping' => ['post' => ['requestBody' => ['content' => ['application/json' => ['schema' => $pointer]]]]]]],
        ['x-webhooks' => ['ping' => ['post' => ['requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/New']]]]]]]],
    ],
]);

it('reads the references a node makes by the walk that renames them', function (): void {
    $node = [
        'properties' => ['a' => ['$ref' => '#/components/schemas/A'], 'b' => ['items' => ['$ref' => '#/components/schemas/B']]],
        'example' => ['a' => ['$ref' => '#/components/schemas/C']],
        'x-docuccino' => ['provenance' => [['overrode' => [['field' => 'items', 'value' => ['$ref' => '#/components/schemas/D']]]]]],
        'allOf' => [['$ref' => '#/components/responses/E'], ['$ref' => '#/components/schemas/A']],
    ];

    // In the order the walk meets them, repeats kept: a caller queueing them keeps its own visited set.
    expect(ComponentNames::referenced($node))->toBe(['A', 'B', 'D', 'A'])
        ->and(ComponentNames::referenced($node, 'responses'))->toBe(['E']);
});

it('rekeys a bucket through a rename map, leaving unnamed entries where they are', function (): void {
    expect(ComponentNames::rekey(['Old' => 1, 'Kept' => 2], ['Old' => 'New']))->toBe(['New' => 1, 'Kept' => 2])
        ->and(ComponentNames::rekey(['Old' => 1], []))->toBe(['Old' => 1]);
});

/*
 * A component name PHP reads as a number. Every caller collects `$taken` from the keys of a published
 * bucket, and `foreach ($bucket as $name => …)` hands back `int(404)` for the key `'404'` — so the
 * ladder's strict `in_array` missed the incumbent while `award()`'s `isset()` coerced and hit it. The
 * claim never climbed, never counted as contested, and took the first-come `_2` tail instead: `404` and
 * `404_2` published side by side, silently. These pin a numeric name behaving like any other.
 */

it('sends a claim up the ladder when a numeric name is already taken, just as it does a worded one', function (): void {
    // The keys a caller actually collects: PHP has already turned '404' into int(404) by this point.
    $taken = array_keys(['404' => ['type' => 'object']]);

    [$names, $contests] = ComponentNames::mint(['body' => claim('404', null, '{"type":"string"}')], $taken);

    expect($names['body'])->not->toBe('404_2')
        ->and($names['body'])->toStartWith('404_')
        ->and($contests)->toHaveKey('404');
});

it('reads an int-keyed taken list and a string-keyed one identically', function (): void {
    // Normalising is the boundary's job, so the two spellings a caller might hand it agree. If they
    // ever disagree again, the guard and the minter have gone back to reading different types.
    $claims = ['body' => claim('404', null, '{"type":"string"}')];

    expect(ComponentNames::mint($claims, [404]))->toEqual(ComponentNames::mint($claims, ['404']));
});

it('never mints a first-come counter for a numeric name, whatever contests it', function (): void {
    // `Foo_2` is the anti-pattern: deterministic per build, and it still reassigns meaning when an
    // unrelated route arrives. A numeric base reaches the same content-derived rung a worded one does.
    [$names] = ComponentNames::mint([
        'a' => claim('404', null, '{"type":"string"}'),
        'b' => claim('404', null, '{"type":"integer"}'),
    ], array_keys(['404' => ['type' => 'object']]));

    expect(array_values($names))->not->toContain('404')
        ->and(array_values($names))->not->toContain('404_2')
        ->and(array_unique(array_values($names)))->toHaveCount(2);
});

it('asks for the name a shape\'s first rung is, so a part of it can be named from the shape', function (string $base, ?string $identity, string $stem): void {
    expect(ComponentNames::stem($base, $identity))->toBe($stem);
})->with([
    'a request shape of a class that does not say so' => ['Article', 'App\\Article#request', 'ArticleRequest'],
    'a request shape of a class that does' => ['StoreWidgetRequest', 'App\\StoreWidgetRequest#request', 'StoreWidgetRequest'],
    'a class\'s own shape' => ['Article', 'App\\Article', 'Article'],
    'no identity' => ['Error 404', null, 'Error404'],
]);
