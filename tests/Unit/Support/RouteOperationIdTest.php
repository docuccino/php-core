<?php

declare(strict_types=1);

use Docuccino\Core\Lint\OperationIdStyle;
use Docuccino\Core\Support\RouteOperationId;

/**
 * The minted `operationId` an operation carries when nothing named it. A generated client turns the
 * field into a method name, so the three things under test are that the name is READABLE, that it is
 * a function of this operation alone — no other route is an input, so no other route can move it —
 * and that no two operations of one document can be handed the same one.
 */
it('mints a name from the method and the path', function (string $method, string $uri, string $expected): void {
    expect(RouteOperationId::mint($method, $uri))->toBe($expected);
})->with([
    'a collection' => ['get', '/api/forms', 'get.api.forms'],
    'a member' => ['get', '/api/forms/{form}', 'get.api.forms.@form'],
    'a write' => ['post', '/api/widgets', 'post.api.widgets'],
    'a delete' => ['delete', '/api/model-widgets/{id}', 'delete.api.model-widgets.@id'],
    // The separators are the path's own, so they are carried rather than folded into capitals: it is
    // the folding that used to make three different paths one name.
    'a hyphenated segment' => ['get', '/api/json-paginated-articles', 'get.api.json-paginated-articles'],
    'an underscored segment' => ['get', '/api/legacy_forms', 'get.api.legacy__forms'],
    'a camel-case segment' => ['get', '/api/formEntries', 'get.api.formEntries'],
    'a dotted segment' => ['get', '/api/v1.1/forms', 'get.api.v1_2E1.forms'],
    // How the route was WRITTEN is not what the path IS: an optional marker and a bound column are
    // route syntax, and the template a document keys the operation by carries neither.
    'an optional parameter' => ['get', '/api/forms/{form?}', 'get.api.forms.@form'],
    'a parameter bound on a column' => ['get', '/api/posts/{post:slug}', 'get.api.posts.@post'],
    'several parameters' => ['get', '/api/forms/{form}/entries/{entry}', 'get.api.forms.@form.entries.@entry'],
    'an uppercase method' => ['GET', '/api/forms', 'get.api.forms'],
    'a path with no leading slash' => ['get', 'api/forms', 'get.api.forms'],
    'the root path' => ['get', '/', 'get'],
    // A segment outside the alphabet is spelled out, not dropped: dropping it left `/api/形式` and
    // `/api` sharing a name, and a long name that means one route beats a short one that means two.
    'a non-ASCII segment' => ['get', '/api/形式', 'get.api._E5_BD_A2_E5_BC_8F'],
    // A segment that is part parameter and part text has no short spelling that could be read back.
    'a parameter beside literal text' => ['get', '/api/{name}.{ext}', 'get.api._7Bname_7D_2E_7Bext_7D'],
    // The method leads; with none, a `_` stands in, which is the one part no method escapes to.
    'no method at all' => ['', '/2fa/tokens', '_.2fa.tokens'],
]);

/**
 * The pairs the fold used to hand one name to. Each is two paths a Laravel application may register
 * side by side, so each is a document that published one `operationId` twice — a name a generated
 * client cannot give to two methods. They are a table rather than prose because the fold was lossy
 * in six separate ways, and fixing one of them would not have fixed the class.
 */
it('gives two paths that differ only in punctuation two names', function (string $left, string $right): void {
    expect(RouteOperationId::mint('get', $left))->not->toBe(RouteOperationId::mint('get', $right));
})->with([
    'a hyphen against an underscore' => ['/api/user-profile', '/api/user_profile'],
    'a hyphen against camel case' => ['/api/user-profile', '/api/userProfile'],
    'a hyphen against a segment break' => ['/api/user-profile', '/api/user/profile'],
    'camel case against a segment break' => ['/api/userProfile', '/api/user/profile'],
    'a dot against a segment break' => ['/api/v1.orders', '/api/v1/orders'],
    'an accent against the letters left when it is dropped' => ['/api/résumé', '/api/r-sum'],
    'a segment outside the alphabet against its prefix' => ['/api/形式', '/api'],
    'a parameter against a literal segment spelled like the marker' => ['/api/forms/{form}', '/api/forms/by-form'],
    'a parameter against the two segments the marker read as' => ['/api/forms/{form}', '/api/forms/by/form'],
    'a parameter beside text against one parameter named for both' => ['/api/{name}.{ext}', '/api/{nameExt}'],
]);

/**
 * The uniqueness claim stated from the other end, and independently of the mint: a READER that knows
 * only the spelling — `.` between the parts, `@` in front of a parameter, `_` in front of an escape —
 * recovers the method and the path template from the name. A name that can be read back is a name
 * only one operation can have, which is the whole of the claim; asking the mint whether it agrees
 * with itself would prove nothing.
 */
it('spells a name only one operation can have', function (): void {
    $unescape = static function (string $part): string {
        expect($part)->not->toContain('.')->not->toContain('@');

        $text = '';
        for ($i = 0, $length = strlen($part); $i < $length; $i++) {
            if ($part[$i] !== '_') {
                $text .= $part[$i];

                continue;
            }

            $next = substr($part, $i + 1, 2);
            expect($next)->toMatch('/^(_.?|[0-9A-F]{2})$/');

            if ($next[0] === '_') {
                $text .= '_';
                $i++;
            } else {
                $text .= chr((int) hexdec($next));
                $i += 2;
            }
        }

        return $text;
    };

    // Every method an operation can be keyed by, against paths that between them use every form the
    // spelling has: a plain segment, each separator, a parameter, bytes outside the alphabet, and the
    // two characters the spelling reserves for itself.
    foreach (['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace', 'query', ''] as $method) {
        foreach ([
            '/',
            '/api/forms',
            '/api/forms/{form}',
            '/api/model-widgets/{id}',
            '/api/user_profile',
            '/api/user/profile',
            '/api/v1.orders',
            '/api/@handle',
            '/api/résumé',
            '/api/{name}.{ext}',
            '/api/2fa/back-up_codes.json',
            '/api/形式/{パラメータ}',
        ] as $path) {
            $parts = explode('.', RouteOperationId::mint($method, $path));

            $verb = array_shift($parts);
            $segments = array_map(
                static fn (string $part): string => str_starts_with($part, '@')
                    ? '{'.$unescape(substr($part, 1)).'}'
                    : $unescape($part),
                $parts,
            );

            expect($verb === '_' ? '' : $unescape($verb))->toBe($method)
                ->and('/'.implode('/', $segments))->toBe($path);
        }
    }
});

/**
 * The style rule is stated here from the OUTSIDE — a name a generated client can turn into a method —
 * rather than by asking the mint what it produces. `lint.operation-id-style` ships on by default on
 * the grounds that nothing Docuccino mints can trip it, and this is now the mint that has to hold
 * that up for every operation in every document rather than only for a named route.
 */
it('mints only names a generated client can carry', function (string $uri): void {
    foreach (['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace', 'query', ''] as $method) {
        $id = RouteOperationId::mint($method, $uri);

        expect(OperationIdStyle::problem($id))->toBeNull()
            ->and($id)->toMatch('/^[a-z_]/');
    }
})->with([
    '/',
    '/api/forms',
    '/api/forms/{form}',
    '/api/forms/{form?}/entries/{entry:uuid}',
    '/api/2fa/back-up_codes.json',
    '/api/形式/{パラメータ}',
    '/api//double//slashes//',
    '/2fa/tokens',
    '/api/@handle',
    "/api/\x00\xFF",
]);

/**
 * The invariant the field exists for: a published name is a function of the thing, never of the order
 * it was met. Nothing but this operation's own method and path reaches the mint, so a name cannot be
 * moved by a route being added, removed, renamed or reordered somewhere else — which is what a
 * first-come tail (`Foo_2`) or a contest between claimants would each reintroduce.
 */
it('reads nothing but the one operation it names', function (): void {
    $mint = new ReflectionMethod(RouteOperationId::class, 'mint');

    expect(array_map(static fn (ReflectionParameter $p): string => $p->getName(), $mint->getParameters()))
        ->toBe(['method', 'uri']);
});

/**
 * Two operations of one document are two methods or two path templates, and the spelling gives those
 * two names — so the document below needs no contest settled, the pairs that used to be one name
 * included. Paths that differ only in how a parameter is SPELLED are the exception, and the right
 * one: `{form}` and `{form?}` are one template, so a document holding both holds one path.
 */
it('gives every operation of one document its own name', function (): void {
    $document = [
        ['get', '/api/forms'],
        ['post', '/api/forms'],
        ['get', '/api/forms/{form}'],
        ['get', '/api/forms/{form}/entries'],
        ['get', '/api/form'],
        ['get', '/api/forms-archive'],
        ['delete', '/api/forms/{form}'],
        ['get', '/api/user-profile'],
        ['get', '/api/user_profile'],
        ['get', '/api/userProfile'],
        ['get', '/api/user/profile'],
        ['get', '/api/forms/by-form'],
    ];

    $ids = array_map(static fn (array $o): string => RouteOperationId::mint($o[0], $o[1]), $document);

    expect(array_unique($ids))->toHaveCount(count($document));
});

/** A parameter's name is route syntax, so renaming one moves the name it contributes and nothing else. */
it('separates a parameter from a literal segment spelled like it', function (): void {
    expect(RouteOperationId::mint('get', '/api/forms/{form}'))
        ->not->toBe(RouteOperationId::mint('get', '/api/forms/form'));
});
