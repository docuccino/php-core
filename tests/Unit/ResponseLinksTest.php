<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Links\BindingTake;
use Docuccino\Core\Links\BodyGive;
use Docuccino\Core\Links\DeclaredLink;
use Docuccino\Core\Links\OperationLinks;
use Docuccino\Core\Links\ResponseLinks;
use Docuccino\Core\Provenance\Source;

/**
 * A response link is a promise to a client: follow it, pass these values, and the call works. So each
 * row below states why the link is TRUE — every path parameter of the target is filled from something
 * the code proved — and the refusals state which proof is missing. A link written where one segment had
 * to be guessed would send a client a request that 404s.
 */
beforeEach(function (): void {
    // A document with the operations named, each answering `$status` with a `{data: {id, slug}}` body.
    $this->doc = static function (array $operations): array {
        $paths = [];
        foreach ($operations as $signature => $operation) {
            [$method, $path] = explode(' ', $signature, 2);
            $parameters = [];
            preg_match_all('/\{([^}]+)\}/', $path, $names);
            foreach ($names[1] as $name) {
                $parameters[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
            }
            $paths[$path][strtolower($method)] = [
                'operationId' => $operation['operationId'],
                ...(($operation['internal'] ?? false) ? ['x-internal' => true] : []),
                ...($parameters === [] ? [] : ['parameters' => $parameters]),
                'responses' => [($operation['status'] ?? '200') => ['description' => 'OK', 'content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'properties' => ['data' => ['$ref' => '#/components/schemas/Post']],
                ]]]]],
            ];
        }

        return [
            'openapi' => '3.2.0',
            'paths' => $paths,
            'components' => ['schemas' => ['Post' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'slug' => ['type' => 'string']]]]],
        ];
    };

    $this->post = new BindingTake('App\Post', 'id');
    $this->gives = static fn (string $status = '200', string $pointer = '/data/id', string $key = 'id'): BodyGive => new BodyGive($status, 'App\Post', $key, $pointer);
});

it('links a create endpoint to every operation that binds what it gave back', function (): void {
    $doc = ($this->doc)([
        'POST /posts' => ['operationId' => 'posts.store', 'status' => '201'],
        'GET /posts/{post}' => ['operationId' => 'posts.show'],
        'DELETE /posts/{post}' => ['operationId' => 'posts.destroy'],
        'GET /posts' => ['operationId' => 'posts.index'],
    ]);

    [$out, $diagnostics] = ResponseLinks::write($doc, [
        'POST /posts' => new OperationLinks(gives: [($this->gives)('201')]),
        'GET /posts/{post}' => new OperationLinks(takes: ['post' => $this->post]),
        'DELETE /posts/{post}' => new OperationLinks(takes: ['post' => $this->post]),
    ]);

    // The id the body carries is the column both routes bind on, so passing it is what the server matches.
    expect($out['paths']['/posts']['post']['responses']['201']['links'])->toBe([
        'posts.destroy' => [
            'operationId' => 'posts.destroy',
            'parameters' => ['post' => '$response.body#/data/id'],
            'x-docuccino' => ['provenance' => [['producer' => 'inference', 'layer' => 'inference']]],
        ],
        'posts.show' => [
            'operationId' => 'posts.show',
            'parameters' => ['post' => '$response.body#/data/id'],
            'x-docuccino' => ['provenance' => [['producer' => 'inference', 'layer' => 'inference']]],
        ],
    ])
        // The list takes nothing the body gives, so it is not a place the response leads.
        ->and($out['paths']['/posts']['get']['responses']['200'])->not->toHaveKey('links')
        ->and($diagnostics)->toBe([]);
});

it('links a resource to its own other routes, but never to itself', function (): void {
    $doc = ($this->doc)([
        'GET /posts/{post}' => ['operationId' => 'posts.show'],
        'PATCH /posts/{post}' => ['operationId' => 'posts.update'],
    ]);
    $bound = new OperationLinks(takes: ['post' => $this->post], gives: [($this->gives)()]);

    [$out] = ResponseLinks::write($doc, ['GET /posts/{post}' => $bound, 'PATCH /posts/{post}' => $bound]);

    expect(array_keys($out['paths']['/posts/{post}']['get']['responses']['200']['links']))->toBe(['posts.update'])
        ->and(array_keys($out['paths']['/posts/{post}']['patch']['responses']['200']['links']))->toBe(['posts.show']);
});

it('fills a parent segment the two operations share from the request', function (): void {
    $comment = new BindingTake('App\Comment', 'id', scopedTo: 'post');
    $doc = ($this->doc)([
        'POST /posts/{post}/comments' => ['operationId' => 'comments.store', 'status' => '201'],
        'GET /posts/{post}/comments/{comment}' => ['operationId' => 'comments.show'],
    ]);

    [$out] = ResponseLinks::write($doc, [
        'POST /posts/{post}/comments' => new OperationLinks(takes: ['post' => $this->post], gives: [new BodyGive('201', 'App\Comment', 'id', '/data/id')]),
        'GET /posts/{post}/comments/{comment}' => new OperationLinks(takes: ['post' => $this->post, 'comment' => $comment]),
    ]);

    // The comment was made under the post in the request, so it is one of that post's comments: the
    // scoped lookup finds it.
    expect($out['paths']['/posts/{post}/comments']['post']['responses']['201']['links']['comments.show']['parameters'])
        ->toBe(['post' => '$request.path.post', 'comment' => '$response.body#/data/id']);
});

it('does not fill a scoped child from a body the parent in the request does not own', function (): void {
    $comment = new BindingTake('App\Comment', 'id', scopedTo: 'post');
    $doc = ($this->doc)([
        'GET /posts/{post}/pinned' => ['operationId' => 'posts.pinned'],
        'GET /posts/{post}/comments/{comment}' => ['operationId' => 'comments.show'],
    ]);

    [$out] = ResponseLinks::write($doc, [
        // Any comment at all could come back from here, so pairing it with the post is a guess.
        'GET /posts/{post}/pinned' => new OperationLinks(takes: ['post' => $this->post], gives: [new BodyGive('200', 'App\Comment', 'id', '/data/id')]),
        'GET /posts/{post}/comments/{comment}' => new OperationLinks(takes: ['post' => $this->post, 'comment' => $comment]),
    ]);

    expect($out['paths']['/posts/{post}/pinned']['get']['responses']['200'])->not->toHaveKey('links');
});

it('writes no link where a segment cannot be filled from what the code proved', function (string $case, array $targetTakes, array $gives, string $target): void {
    $doc = ($this->doc)([
        'POST /posts' => ['operationId' => 'posts.store'],
        $target => ['operationId' => 'target'],
    ]);

    [$out] = ResponseLinks::write($doc, [
        'POST /posts' => new OperationLinks(gives: $gives),
        $target => new OperationLinks(takes: $targetTakes),
    ]);

    expect($out['paths']['/posts']['post']['responses']['200'])->not->toHaveKey('links');
})->with([
    'a segment whose binding was not read' => ['unread', [], [new BodyGive('200', 'App\Post', 'id', '/data/id')], 'GET /posts/{post}'],
    'a segment bound on another column' => ['column', ['post' => new BindingTake('App\Post', 'slug')], [new BodyGive('200', 'App\Post', 'id', '/data/id')], 'GET /posts/{post}'],
    'a segment bound to another resource' => ['resource', ['post' => new BindingTake('App\Draft', 'id')], [new BodyGive('200', 'App\Post', 'id', '/data/id')], 'GET /posts/{post}'],
    'a second segment the source never had' => ['parent', ['user' => new BindingTake('App\User', 'id'), 'post' => new BindingTake('App\Post', 'id')], [new BodyGive('200', 'App\Post', 'id', '/data/id')], 'GET /users/{user}/posts/{post}'],
    'two segments taking one column' => ['twice', ['post' => new BindingTake('App\Post', 'id'), 'other' => new BindingTake('App\Post', 'id')], [new BodyGive('200', 'App\Post', 'id', '/data/id')], 'GET /posts/{post}/compare/{other}'],
    'a column given at two places' => ['contested', ['post' => new BindingTake('App\Post', 'id')], [new BodyGive('200', 'App\Post', 'id', '/data/id'), new BodyGive('200', 'App\Post', 'id', '/data/slug')], 'GET /posts/{post}'],
    'a pointer the response contradicts' => ['undocumented', ['post' => new BindingTake('App\Post', 'id')], [new BodyGive('200', 'App\Post', 'id', '/data/uuid')], 'GET /posts/{post}'],
    'a status the response does not document' => ['status', ['post' => new BindingTake('App\Post', 'id')], [new BodyGive('201', 'App\Post', 'id', '/data/id')], 'GET /posts/{post}'],
]);

/**
 * The record a DELETE hands back has been removed, so the routes that would look it up by its key answer
 * 404 — a link there sends a client to a miss. A link the author declares (a restore, say) is theirs.
 */
it('derives no link from what a delete hands back', function (): void {
    $doc = ($this->doc)([
        'DELETE /posts/{post}' => ['operationId' => 'posts.destroy'],
        'GET /posts/{post}' => ['operationId' => 'posts.show'],
        'POST /posts/{post}/restore' => ['operationId' => 'posts.restore'],
    ]);
    $bound = new OperationLinks(takes: ['post' => $this->post], gives: [($this->gives)()]);

    [$out] = ResponseLinks::write($doc, [
        'DELETE /posts/{post}' => new OperationLinks(
            takes: ['post' => $this->post],
            gives: [($this->gives)()],
            declared: [new DeclaredLink('posts.restore', ['post' => '$response.body#/data/id'])],
        ),
        'GET /posts/{post}' => $bound,
        'POST /posts/{post}/restore' => $bound,
    ]);

    expect(array_keys($out['paths']['/posts/{post}']['delete']['responses']['200']['links']))->toBe(['posts.restore'])
        // The same facts on a read do lead on, so it is the method that refuses.
        ->and(array_keys($out['paths']['/posts/{post}']['get']['responses']['200']['links']))->toBe(['posts.destroy', 'posts.restore']);
});

/**
 * `x-internal` is the mark SDK generators and public-doc filters cut an operation by. A public operation
 * linking to one would, once filtered, name an endpoint the reader was never meant to see, through a link
 * that leads nowhere. An internal operation is cut with its links, so it may lead anywhere; and a link an
 * author declared is their choice.
 */
it('does not lead a public operation to an internal one unless declared', function (): void {
    $doc = ($this->doc)([
        'POST /posts' => ['operationId' => 'posts.store', 'status' => '201'],
        'GET /posts/{post}' => ['operationId' => 'posts.show'],
        'GET /posts/{post}/audit' => ['operationId' => 'posts.audit', 'internal' => true],
        'POST /posts/{post}/reindex' => ['operationId' => 'posts.reindex', 'internal' => true],
    ]);
    $bound = new OperationLinks(takes: ['post' => $this->post], gives: [($this->gives)()]);

    [$out] = ResponseLinks::write($doc, [
        'POST /posts' => new OperationLinks(
            gives: [($this->gives)('201')],
            declared: [new DeclaredLink('posts.reindex', ['post' => '$response.body#/data/id'])],
        ),
        'GET /posts/{post}' => new OperationLinks(takes: ['post' => $this->post]),
        'GET /posts/{post}/audit' => $bound,
        'POST /posts/{post}/reindex' => $bound,
    ]);

    expect(array_keys($out['paths']['/posts']['post']['responses']['201']['links']))->toBe(['posts.reindex', 'posts.show'])
        ->and(array_keys($out['paths']['/posts/{post}/audit']['get']['responses']['200']['links']))->toBe(['posts.reindex', 'posts.show']);
});

/**
 * Every operation that binds and returns one model links to every other, so N of them would write N²
 * links: a document that grows past use long before a reader follows the fiftieth. One response carries
 * fifty, the first by name, and says the rest were left out; a declared link is the author's and is
 * always written.
 */
it('writes at most fifty derived links on one response, and says when it left some out', function (): void {
    $build = function (int $count): array {
        $operations = $facts = [];
        for ($i = 0; $i < $count; $i++) {
            $signature = sprintf('GET /r%02d/{post}', $i);
            $operations[$signature] = ['operationId' => sprintf('r%02d', $i)];
            $facts[$signature] = new OperationLinks(takes: ['post' => $this->post], gives: [($this->gives)()]);
        }
        $facts['GET /r00/{post}'] = new OperationLinks(
            takes: ['post' => $this->post],
            gives: [($this->gives)()],
            declared: [new DeclaredLink(sprintf('r%02d', $count - 1), ['post' => '$response.body#/data/id'], name: 'Declared')],
        );

        return ResponseLinks::write(($this->doc)($operations), $facts);
    };

    // Fifty others to lead to: all written, nothing said.
    [$out, $diagnostics] = $build(51);
    expect($out['paths']['/r50/{post}']['get']['responses']['200']['links'])->toHaveCount(50)
        ->and($diagnostics)->toBe([]);

    // Fifty-one: the first fifty by name are written, and each response says what it left out.
    [$out, $diagnostics] = $build(52);
    $links = static fn (string $path): array => array_keys($out['paths'][$path]['get']['responses']['200']['links']);

    expect($links('/r51/{post}'))->toBe(array_map(static fn (int $i): string => sprintf('r%02d', $i), range(0, 49)))
        ->and($links('/r00/{post}'))->toHaveCount(51)
        ->and($links('/r00/{post}'))->toContain('Declared')
        ->and($links('/r00/{post}'))->not->toContain('r51')
        ->and($out['paths']['/r00/{post}']['get']['responses']['200']['links']['Declared']['operationId'])->toBe('r51')
        ->and($diagnostics)->toHaveCount(52)
        ->and(array_unique(array_map(static fn ($d): string => $d->code.' '.$d->severity->value, $diagnostics)))->toBe(['response-link.truncated info'])
        ->and($diagnostics[0]->routeSignature)->toBe('GET /r00/{post}')
        ->and($diagnostics[0]->message)->toContain('leads to 51 operations');
});

it('does not link to an operation its operationId cannot name alone', function (): void {
    $doc = ($this->doc)([
        'POST /posts' => ['operationId' => 'posts.store'],
        'GET /posts/{post}' => ['operationId' => 'posts.show'],
        'PUT /posts/{post}' => ['operationId' => 'posts.show'],
    ]);

    [$out] = ResponseLinks::write($doc, [
        'POST /posts' => new OperationLinks(gives: [($this->gives)()]),
        'GET /posts/{post}' => new OperationLinks(takes: ['post' => $this->post]),
        'PUT /posts/{post}' => new OperationLinks(takes: ['post' => $this->post]),
    ]);

    expect($out['paths']['/posts']['post']['responses']['200'])->not->toHaveKey('links');
});

it('writes a declared link with where it was declared', function (string $operation): void {
    $doc = ($this->doc)([
        'POST /posts' => ['operationId' => 'posts.store', 'status' => '201'],
        'GET /posts/{post}' => ['operationId' => 'posts.show'],
    ]);
    $source = new Source('app/Http/PostController.php', 12, 'App\Http\PostController::store');

    [$out, $diagnostics] = ResponseLinks::write($doc, [
        'POST /posts' => new OperationLinks(declared: [new DeclaredLink($operation, ['post' => '$response.body#/data/slug'], name: 'Read', description: 'Read it back.', source: $source)]),
    ]);

    expect($out['paths']['/posts']['post']['responses']['201']['links'])->toBe(['Read' => [
        'operationId' => 'posts.show',
        'parameters' => ['post' => '$response.body#/data/slug'],
        'description' => 'Read it back.',
        'x-docuccino' => ['provenance' => [['producer' => 'attribute', 'layer' => 'attribute', 'source' => $source->toArray()]]],
    ]])->and($diagnostics)->toBe([]);
})->with([
    'by operationId' => ['posts.show'],
    'by signature' => ['get /posts/{post}'],
]);

it('lets a declared link outrank a derived one, and a link the document carries outrank both', function (): void {
    $doc = ($this->doc)([
        'POST /posts' => ['operationId' => 'posts.store'],
        'GET /posts/{post}' => ['operationId' => 'posts.show'],
        'DELETE /posts/{post}' => ['operationId' => 'posts.destroy'],
    ]);
    $doc['paths']['/posts']['post']['responses']['200']['links'] = ['posts.destroy' => ['operationId' => 'posts.destroy', 'description' => 'An overlay wrote this.']];

    [$out] = ResponseLinks::write($doc, [
        'POST /posts' => new OperationLinks(gives: [($this->gives)()], declared: [new DeclaredLink('posts.show', ['post' => '$response.body#/data/slug'])]),
        'GET /posts/{post}' => new OperationLinks(takes: ['post' => $this->post]),
        'DELETE /posts/{post}' => new OperationLinks(takes: ['post' => $this->post]),
    ]);
    $links = $out['paths']['/posts']['post']['responses']['200']['links'];

    expect($links['posts.show']['parameters'])->toBe(['post' => '$response.body#/data/slug'])
        ->and($links['posts.show']['x-docuccino']['provenance'][0]['layer'])->toBe('attribute')
        ->and($links['posts.destroy'])->toBe(['operationId' => 'posts.destroy', 'description' => 'An overlay wrote this.']);
});

it('accepts a parameter qualified by its location', function (): void {
    $doc = ($this->doc)(['POST /posts' => ['operationId' => 'posts.store'], 'GET /posts/{post}' => ['operationId' => 'posts.show']]);

    [$out, $diagnostics] = ResponseLinks::write($doc, [
        'POST /posts' => new OperationLinks(declared: [new DeclaredLink('posts.show', ['path.post' => '$response.body#/data/id'])]),
    ]);

    expect($out['paths']['/posts']['post']['responses']['200']['links']['posts.show']['parameters'])->toBe(['path.post' => '$response.body#/data/id'])
        ->and($diagnostics)->toBe([]);
});

it('reports a declared link it cannot write, and leaves it out', function (DeclaredLink $declared, string $code): void {
    $doc = ($this->doc)(['POST /posts' => ['operationId' => 'posts.store', 'status' => '201'], 'GET /posts/{post}' => ['operationId' => 'posts.show']]);

    [$out, $diagnostics] = ResponseLinks::write($doc, ['POST /posts' => new OperationLinks(declared: [$declared])]);

    expect($out['paths']['/posts']['post']['responses']['201'])->not->toHaveKey('links')
        ->and(array_map(static fn ($d): string => $d->code, $diagnostics))->toBe([$code])
        ->and($diagnostics[0]->routeSignature)->toBe('POST /posts');
})->with([
    'an operation the document does not publish' => [new DeclaredLink('posts.missing'), 'response-link.unknown-operation'],
    'a signature the document does not publish' => [new DeclaredLink('PUT /posts/{post}'), 'response-link.unknown-operation'],
    'a status the operation does not document' => [new DeclaredLink('posts.show', status: '200'), 'response-link.unknown-status'],
    'a parameter the target does not declare' => [new DeclaredLink('posts.show', ['postId' => '$response.body#/data/id']), 'response-link.parameter-undeclared'],
    'a parameter qualified by another location' => [new DeclaredLink('posts.show', ['query.post' => '$response.body#/data/id']), 'response-link.parameter-undeclared'],
    'a pointer the response contradicts' => [new DeclaredLink('posts.show', ['post' => '$response.body#/data/uuid']), 'response-link.pointer-undocumented'],
]);

/**
 * A Link Object keys a parameter by its bare name, and OpenAPI lets it be qualified by location — `path.page`
 * — for the one case a bare key cannot settle: the target uses the name in two locations, a `page` segment
 * and a `page` query parameter for pagination. Unqualified, a client or an Arazzo runner may put the body's
 * value in either, and a uuid lands in an integer page number.
 */
it('qualifies a filled path parameter by location only where the target uses its name twice', function (): void {
    $doc = ($this->doc)([
        'POST /posts' => ['operationId' => 'posts.store'],
        'GET /posts/{post}' => ['operationId' => 'posts.show'],
        'GET /posts/{post}/versions' => ['operationId' => 'posts.versions'],
    ]);
    $doc['paths']['/posts/{post}/versions']['get']['parameters'][] = ['name' => 'post', 'in' => 'query', 'schema' => ['type' => 'integer']];

    [$out, $diagnostics] = ResponseLinks::write($doc, [
        'POST /posts' => new OperationLinks(gives: [($this->gives)()]),
        'GET /posts/{post}' => new OperationLinks(takes: ['post' => $this->post]),
        'GET /posts/{post}/versions' => new OperationLinks(takes: ['post' => $this->post]),
    ]);
    $links = $out['paths']['/posts']['post']['responses']['200']['links'];

    expect($links['posts.versions']['parameters'])->toBe(['path.post' => '$response.body#/data/id'])
        // The target with one `post` keeps the bare key it has always published.
        ->and($links['posts.show']['parameters'])->toBe(['post' => '$response.body#/data/id'])
        ->and($diagnostics)->toBe([]);
});

it('qualifies a name the target declares twice through a shared parameter or its path item', function (): void {
    // The second `post` is declared where a reader of the operation alone would miss it.
    $doc = ($this->doc)([
        'POST /posts' => ['operationId' => 'posts.store'],
        'GET /posts/{post}/versions' => ['operationId' => 'posts.versions'],
    ]);
    $doc['components']['parameters']['PostPage'] = ['name' => 'post', 'in' => 'query', 'schema' => ['type' => 'integer']];
    $doc['paths']['/posts/{post}/versions']['parameters'] = [['$ref' => '#/components/parameters/PostPage']];

    [$out] = ResponseLinks::write($doc, [
        'POST /posts' => new OperationLinks(gives: [($this->gives)()]),
        'GET /posts/{post}/versions' => new OperationLinks(takes: ['post' => $this->post]),
    ]);

    expect($out['paths']['/posts']['post']['responses']['200']['links']['posts.versions']['parameters'])
        ->toBe(['path.post' => '$response.body#/data/id']);
});

it('refuses a declared link filling a bare name its target declares in two locations, and keeps a qualified one', function (): void {
    $doc = ($this->doc)([
        'POST /posts' => ['operationId' => 'posts.store'],
        'GET /posts/{post}/versions' => ['operationId' => 'posts.versions'],
    ]);
    $doc['paths']['/posts/{post}/versions']['get']['parameters'][] = ['name' => 'post', 'in' => 'query', 'schema' => ['type' => 'integer']];

    [$bare, $refused] = ResponseLinks::write($doc, [
        'POST /posts' => new OperationLinks(declared: [new DeclaredLink('posts.versions', ['post' => '$response.body#/data/id'])]),
    ]);
    [$qualified, $accepted] = ResponseLinks::write($doc, [
        'POST /posts' => new OperationLinks(declared: [new DeclaredLink('posts.versions', ['path.post' => '$response.body#/data/id', 'query.post' => 2])]),
    ]);

    expect($bare['paths']['/posts']['post']['responses']['200'])->not->toHaveKey('links')
        ->and(array_map(static fn ($d): string => $d->code, $refused))->toBe(['response-link.parameter-ambiguous'])
        ->and($refused[0]->message)->toContain('(path, query)')
        ->and($refused[0]->help)->toBe('Qualify the parameter with its location: `path.post` or `query.post`.')
        ->and($qualified['paths']['/posts']['post']['responses']['200']['links']['posts.versions']['parameters'])
        ->toBe(['path.post' => '$response.body#/data/id', 'query.post' => 2])
        ->and($accepted)->toBe([]);
});

it('reports an operation with no success response to hang a declared link on', function (): void {
    $doc = ($this->doc)(['POST /posts' => ['operationId' => 'posts.store', 'status' => '422'], 'GET /posts/{post}' => ['operationId' => 'posts.show']]);

    [, $diagnostics] = ResponseLinks::write($doc, ['POST /posts' => new OperationLinks(declared: [new DeclaredLink('posts.show')])]);

    expect($diagnostics[0]->code)->toBe('response-link.unknown-status')
        ->and($diagnostics[0]->message)->toContain('no success response');
});

it('says nothing about facts recorded for an operation this document does not publish', function (): void {
    $doc = ($this->doc)(['GET /posts/{post}' => ['operationId' => 'posts.show']]);

    [$out, $diagnostics] = ResponseLinks::write($doc, ['POST /elsewhere' => new OperationLinks(declared: [new DeclaredLink('nowhere')], unreadable: ['x'])]);

    expect($out)->toBe($doc)->and($diagnostics)->toBe([]);
});

it('round-trips the facts it rides the fragment as, and keeps the reason a declaration could not ride', function (): void {
    $facts = new OperationLinks(
        takes: ['post' => new BindingTake('App\Post', 'id', 'user')],
        gives: [new BodyGive('201', 'App\Post', 'id', '/data/id')],
        declared: [new DeclaredLink('posts.show', ['post' => '$response.body#/data/id', 'ratio' => 1.0, 'filter' => new stdClass], 'Read', '201', 'Back.', 'attribute', new Source('a.php', 3, 'A::b'))],
    );

    $back = OperationLinks::fromJson($facts->toJson());

    expect($back->toJson())->toBe($facts->toJson())
        ->and($back->declared[0]->parameters['ratio'])->toBe(1.0)
        // `{}` is a value a PHP array cannot spell, so it comes back as the object it was.
        ->and($back->declared[0]->parameters['filter'])->toBeInstanceOf(stdClass::class)
        ->and(OperationLinks::fromJson('not json')->isEmpty())->toBeTrue();

    $unreadable = OperationLinks::fromJson((new OperationLinks(takes: ['post' => new BindingTake('App\Post', 'id')], declared: [new DeclaredLink('x', ['bad' => "\xB1"])]))->toJson());

    expect($unreadable->declared)->toBe([])
        ->and($unreadable->takes)->toHaveKey('post')
        ->and($unreadable->unreadable)->toHaveCount(1);
});

it('reports a declared link that could not be recorded', function (): void {
    $doc = ($this->doc)(['POST /posts' => ['operationId' => 'posts.store']]);

    [, $diagnostics] = ResponseLinks::write($doc, ['POST /posts' => new OperationLinks(unreadable: ['Malformed UTF-8'])]);

    expect($diagnostics[0]->code)->toBe('response-link.unreadable')
        ->and($diagnostics[0]->message)->toContain('Malformed UTF-8');
});

it('publishes a link in every OpenAPI version, and its provenance only in the UIR', function (): void {
    $doc = ($this->doc)(['POST /posts' => ['operationId' => 'posts.store'], 'GET /posts/{post}' => ['operationId' => 'posts.show']]);
    $doc['info'] = ['title' => 'API', 'version' => '1.0.0'];
    [$doc] = ResponseLinks::write($doc, [
        'POST /posts' => new OperationLinks(gives: [($this->gives)()]),
        'GET /posts/{post}' => new OperationLinks(takes: ['post' => $this->post]),
    ]);
    $document = UirDocument::fromArray($doc);

    foreach ([new OpenApi32Emitter, new OpenApi31DownlevelEmitter, new OpenApi30DownlevelEmitter] as $emitter) {
        $result = $emitter->emitWithReport($document);
        $emitted = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);

        // OpenAPI has nowhere for where a fact came from, so the link goes out as the plain Link Object.
        expect($emitted['paths']['/posts']['post']['responses']['200']['links'])
            ->toBe(['posts.show' => ['operationId' => 'posts.show', 'parameters' => ['post' => '$response.body#/data/id']]])
            ->and(array_map(static fn ($d): string => $d->code, $result->report->diagnostics))->toBe([]);
    }

    $uir = json_decode((new UirEmitter)->emit($document), true, flags: JSON_THROW_ON_ERROR);

    expect($uir['paths']['/posts']['post']['responses']['200']['links']['posts.show']['x-docuccino'])
        ->toBe(['provenance' => [['producer' => 'inference', 'layer' => 'inference']]]);
});
