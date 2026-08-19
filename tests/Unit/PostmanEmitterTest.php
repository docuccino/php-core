<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\Postman\CollectionEmitter;
use Opis\JsonSchema\Validator;

/**
 * The Postman collection emitter: what the format can carry, what it cannot, and the ordering rules
 * that keep a collection stable as the application around it changes.
 */

/**
 * The emitted collection, decoded.
 *
 * @param  array<string, mixed>  $document
 * @return array<string, mixed>
 */
function postman(array $document): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(
        (new CollectionEmitter)->emit(UirDocument::fromArray($document)),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    return $decoded;
}

/**
 * The diagnostics one emit raises.
 *
 * @param  array<string, mixed>  $document
 * @return list<Diagnostic>
 */
function postmanReport(array $document, EmitOptions $options = new EmitOptions): array
{
    return (new CollectionEmitter)->emitWithReport(UirDocument::fromArray($document), $options)->report->diagnostics;
}

/**
 * @param  array<string, mixed>  $document
 * @return list<string>
 */
function postmanCodes(array $document, EmitOptions $options = new EmitOptions): array
{
    return array_map(static fn (Diagnostic $d): string => $d->code, postmanReport($document, $options));
}

/**
 * Every leaf request, keyed by the folder path it sits at — the shape the ordering assertions read.
 *
 * @param  list<mixed>  $items
 * @return array<string, array<string, mixed>>
 */
function postmanLeaves(array $items, string $prefix = ''): array
{
    $out = [];

    foreach ($items as $item) {
        $item = is_array($item) ? $item : [];
        $name = is_string($item['name'] ?? null) ? $item['name'] : '';
        $path = $prefix.'/'.$name;

        if (is_array($item['item'] ?? null)) {
            $out = [...$out, ...postmanLeaves($item['item'], $path)];

            continue;
        }

        $out[$path] = $item;
    }

    return $out;
}

/**
 * Postman's collection schema is published as draft-04, which opis does not parse. This lifts the
 * dialect WITHOUT touching anything that constrains an instance: the draft-04 `id` anchors are dropped
 * (every `$ref` in the file is a `#/definitions/…` pointer that resolves without them) and the dialect
 * is redeclared. Keys inside a `properties` or `definitions` map are names, not keywords, so a property
 * genuinely called `id` survives.
 */
function liftPostmanSchema(mixed $node, bool $inMap = false): mixed
{
    if (is_array($node)) {
        return array_map(static fn (mixed $v): mixed => liftPostmanSchema($v), $node);
    }

    if (! $node instanceof stdClass) {
        return $node;
    }

    $out = new stdClass;
    foreach (get_object_vars($node) as $key => $value) {
        if (! $inMap && ($key === 'id' || $key === '$schema') && is_string($value)) {
            continue;
        }

        $out->{$key} = liftPostmanSchema($value, ! $inMap && in_array($key, ['properties', 'definitions'], true));
    }

    if (! $inMap && isset($node->{'$schema'})) {
        $out->{'$schema'} = 'http://json-schema.org/draft-07/schema#';
    }

    return $out;
}

/**
 * A minimal document carrying $paths (and optionally $tags), for the ordering and naming rules.
 *
 * @param  array<string, mixed>  $paths
 * @param  list<array<string, mixed>>  $tags
 * @return array<string, mixed>
 */
function postmanDocumentWithPaths(array $paths, array $tags = []): array
{
    $document = [
        '$schema' => 'https://spec.docuccino.app/uir/1.0/schema.json',
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => $paths,
    ];

    if ($tags !== []) {
        $document['tags'] = $tags;
    }

    return $document;
}

it('emits a schema-valid v2.1.0 collection for every fixture', function (string $fixture): void {
    // Postman publishes its collection schema as draft-04, which opis does not parse. The vendored file
    // stays byte-exact as Postman ships it and the dialect is lifted here: the draft-04 `id` anchors go
    // (every `$ref` in the file is a `#/definitions/…` pointer that resolves without them) and the
    // dialect is declared draft-07. Nothing that constrains an instance is touched.
    $schema = liftPostmanSchema(json_decode(
        (string) file_get_contents(dirname(__DIR__).'/Fixtures/postman-collection-v2.1.0.schema.json'),
        flags: JSON_THROW_ON_ERROR,
    ));

    $collection = json_decode(
        (new CollectionEmitter)->emit(UirDocument::fromArray(loadFixture($fixture))),
        flags: JSON_THROW_ON_ERROR,
    );

    $validator = new Validator;
    $validator->resolver()->registerRaw($schema, 'https://docuccino.test/postman-collection.json');

    expect($validator->validate($collection, 'https://docuccino.test/postman-collection.json')->isValid())->toBeTrue();
})->with([
    'worked-example' => ['worked-example.json'],
    'kitchen-sink' => ['kitchen-sink.uir.json'],
    'tag-hierarchy' => ['tag-hierarchy.uir.json'],
    'postman-surface' => ['postman-surface.uir.json'],
]);

it('emits a Postman collection byte-identical to the committed golden', function (): void {
    expect((new CollectionEmitter)->emit(UirDocument::fromArray(loadFixture('postman-surface.uir.json'))))
        ->toBe(loadGolden('postman-surface.postman.json'));
});

it('sends the payload the document illustrates, not one derived from the schema', function (): void {
    // `example` and `examples` sit BESIDE the schema, not in it, so reading only the schema silently
    // threw away every hand-written example the document publishes and shipped `{"id": 0}` placeholders
    // in their place — the one part of a collection a consumer actually presses Send on.
    $leaves = postmanLeaves(postman(loadFixture('postman-surface.uir.json'))['item']);

    $patch = $leaves['/Accounts/Change an account\'s tier'];
    $post = $leaves['/Accounts/Open an account'];

    // A raw JSON body reads the map's lowest key, the same rule every other reader of the document uses.
    expect($patch['request']['body']['raw'])->toBe("{\n  \"tier\": \"free\"\n}")
        ->and($patch['response'][0]['body'])->toContain('"tier": "free"')
        // A form body takes the fields its example supplies and derives only the rest.
        ->and(array_column($post['request']['body']['urlencoded'], 'value', 'key'))
        ->toBe(['email' => 'ada@example.com', 'referrer' => 'string'])
        // …and a saved example answers with what the response says it looks like.
        ->and($post['response'][0]['body'])->toContain('"email": "ada@example.com"');
});

it('takes a parameter\'s own example over one derived from its schema', function (): void {
    $leaves = postmanLeaves(postman(loadFixture('kitchen-sink.uir.json'))['item']);
    $request = reset($leaves)['request'];

    $header = array_column($request['header'], 'value', 'key');
    $query = array_column($request['url']['query'], 'value', 'key');

    expect($header['X-Trace-Id'])->toBe('abc-123')
        ->and($query['precision'])->toBe('0.1');
});

it('names itself once, unversioned, and publishes the version where a consumer reads it', function (): void {
    $collection = postman(loadFixture('worked-example.json'));

    expect((new CollectionEmitter)->format())->toBe('postman')
        ->and($collection['info']['schema'])->toBe('https://schema.getpostman.com/json/collection/v2.1.0/collection.json');
});

it('carries no identifier or timing the document did not publish', function (string $fixture): void {
    // A `_postman_id` would have to be random (which determinism forbids) or a hash we would then owe
    // forever; Postman mints its own uid on import, so there is nothing to gain by inventing one.
    $bytes = (new CollectionEmitter)->emit(UirDocument::fromArray(loadFixture($fixture)));

    expect($bytes)->not->toContain('_postman_id')
        ->and($bytes)->not->toContain('responseTime')
        ->and($bytes)->not->toContain('timings')
        // A `src` pointing at this machine's filesystem would be wrong on every other one.
        ->and($bytes)->not->toContain('"src": "')
        ->and($bytes)->not->toMatch('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z?"[,\s]*$/m');
})->with([
    'worked-example' => ['worked-example.json'],
    'kitchen-sink' => ['kitchen-sink.uir.json'],
    'postman-surface' => ['postman-surface.uir.json'],
]);

it('is byte-identical across two emits', function (): void {
    $document = UirDocument::fromArray(loadFixture('postman-surface.uir.json'));
    $emitter = new CollectionEmitter;

    expect($emitter->emit($document))->toBe($emitter->emit($document));
});

it('does not depend on the order of any map the author did not order', function (string $key): void {
    // `paths`, `components.schemas`, `content` and `responses` are JSON objects: their key order is an
    // artifact of how the document was written, never a decision, so it may not reach the bytes.
    $document = loadFixture('postman-surface.uir.json');
    $baseline = (new CollectionEmitter)->emit(UirDocument::fromArray($document));

    $target = &$document;
    foreach (explode('.', $key) as $segment) {
        $target = &$target[$segment];
    }
    $target = array_reverse($target, preserve_keys: true);
    unset($target);

    expect((new CollectionEmitter)->emit(UirDocument::fromArray($document)))->toBe($baseline);
})->with([
    'paths' => ['paths'],
    'components.schemas' => ['components.schemas'],
    'components.securitySchemes' => ['components.securitySchemes'],
]);

it('picks the oauth2 grant by preference, not by the order the flows are written', function (): void {
    // `flows` is a map. Reading the grant off its first key would make the credential a consumer sets
    // up depend on JSON ordering nobody chose.
    $document = loadFixture('postman-surface.uir.json');
    $baseline = (new CollectionEmitter)->emit(UirDocument::fromArray($document));

    $document['components']['securitySchemes']['oauth']['flows'] =
        array_reverse($document['components']['securitySchemes']['oauth']['flows'], preserve_keys: true);

    expect((new CollectionEmitter)->emit(UirDocument::fromArray($document)))->toBe($baseline)
        // …and the grant it settles on is the most complete one declared, not `implicit`, which is first.
        ->and($baseline)->toContain('authorizationCode');
});

it('orders folders the way the document declares its tags', function (): void {
    $collection = postman(loadFixture('tag-hierarchy.uir.json'));

    // Declaration order, not alphabetical: that order is already published, and re-sorting it here
    // would make the collection disagree with every other rendering of the same document.
    expect(array_column($collection['item'], 'name'))->toBe(['Billing']);

    $billing = $collection['item'][0];
    expect(array_column($billing['item'], 'name'))->toBe(['Invoices'])
        // Refunds, Partners and Internal are declared but hold no operations, so they are pruned;
        // Billing has none of its own and survives only because a descendant does.
        ->and(array_keys(postmanLeaves($collection['item'])))->toBe(['/Billing/Invoices/List invoices']);
});

it('leaves an unrelated request untouched when a route is added elsewhere', function (): void {
    $before = postman(loadFixture('postman-surface.uir.json'));

    $document = loadFixture('postman-surface.uir.json');
    $document['tags'][] = ['name' => 'Zebras', 'description' => 'Later.'];
    $document['paths']['/zebras'] = ['get' => [
        'operationId' => 'zebras.index',
        'summary' => 'List zebras',
        'tags' => ['Zebras'],
        'responses' => ['200' => ['description' => 'OK']],
    ]];

    $after = postman($document);

    $leavesBefore = postmanLeaves($before['item']);
    $leavesAfter = postmanLeaves($after['item']);

    // Every original request keeps its name, its folder and its URL…
    foreach ($leavesBefore as $path => $item) {
        expect($leavesAfter)->toHaveKey($path)
            ->and($leavesAfter[$path])->toBe($item);
    }

    // …and its position within its own folder. (Root-level requests sit after every folder, so a new
    // folder does shift them along — that is the document's own tag list changing, not a request moving
    // relative to its neighbours.)
    $byFolder = static function (array $leaves): array {
        $grouped = [];
        foreach (array_keys($leaves) as $path) {
            $grouped[substr($path, 0, (int) strrpos($path, '/'))][] = $path;
        }

        return $grouped;
    };

    foreach ($byFolder($leavesBefore) as $folder => $order) {
        expect($byFolder($leavesAfter)[$folder] ?? null)->toBe($order);
    }
});

it('orders requests by path and method, never by name', function (): void {
    // Two operations may share a summary; ordering by name would let renaming one move the other.
    $collection = postman(postmanDocumentWithPaths([
        '/b' => ['get' => ['summary' => 'Same', 'responses' => []]],
        '/a' => [
            'post' => ['summary' => 'Same', 'responses' => []],
            'get' => ['summary' => 'Same', 'responses' => []],
        ],
    ]));

    expect(array_column($collection['item'], 'name'))->toBe(['Same', 'Same', 'Same'])
        ->and(array_map(static fn (array $i): string => $i['request']['url']['raw'], $collection['item']))
        // /a before /b by path; within /a, GET before POST by the canonical method order.
        ->toBe(['{{baseUrl}}/a', '{{baseUrl}}/a', '{{baseUrl}}/b'])
        ->and(array_map(static fn (array $i): string => $i['request']['method'], $collection['item']))
        ->toBe(['GET', 'POST', 'GET']);
});

it('names duplicate summaries the same rather than minting a counter', function (): void {
    // `Same_2` would make one request's name depend on which was met first.
    $collection = postman(postmanDocumentWithPaths([
        '/a' => ['get' => ['summary' => 'Same', 'responses' => []]],
        '/b' => ['get' => ['summary' => 'Same', 'responses' => []]],
    ]));

    expect(array_column($collection['item'], 'name'))->toBe(['Same', 'Same']);
});

it('falls back from summary to operationId to method and path for a name', function (array $operation, string $expected): void {
    $collection = postman(postmanDocumentWithPaths(['/thing' => ['get' => $operation + ['responses' => []]]]));

    expect($collection['item'][0]['name'])->toBe($expected);
})->with([
    'summary wins' => [['summary' => 'Show a thing', 'operationId' => 'things.show'], 'Show a thing'],
    'operationId next' => [['operationId' => 'things.show'], 'things.show'],
    'method and path last' => [[], 'GET /thing'],
]);

it('files a multi-tagged operation once, in its earliest tag', function (): void {
    // Duplicating it would give a consumer N copies of one endpoint to edit independently.
    $leaves = postmanLeaves(postman(loadFixture('postman-surface.uir.json'))['item']);

    expect($leaves)->toHaveKey('/Accounts/List accounts')
        ->and($leaves)->not->toHaveKey('/Reporting/List accounts');
});

it('puts untagged operations at the root, after every folder', function (): void {
    // Not in a synthesized "Default" folder — inventing a folder name mints a name from nothing.
    $collection = postman(loadFixture('postman-surface.uir.json'));
    $names = array_column($collection['item'], 'name');

    expect(array_slice($names, 0, 2))->toBe(['Accounts', 'Reporting'])
        ->and(array_slice($names, 2))->toBe(['Show a scoped resource', 'Show the category tree', 'Show the vault']);
});

it('appends a tag used but never declared, sorted by name', function (): void {
    $collection = postman(postmanDocumentWithPaths([
        '/a' => ['get' => ['summary' => 'A', 'tags' => ['Zulu'], 'responses' => []]],
        '/b' => ['get' => ['summary' => 'B', 'tags' => ['Alpha'], 'responses' => []]],
    ], [['name' => 'Declared']]));

    // Declared tags keep their order; the undeclared ones follow, sorted — a rule derived from the set,
    // never from which operation happened to be met first.
    expect(array_column($collection['item'], 'name'))->toBe(['Alpha', 'Zulu']);
});

it('maps the collection URL onto {{baseUrl}} and a split path', function (): void {
    $leaves = postmanLeaves(postman(loadFixture('postman-surface.uir.json'))['item']);
    $url = $leaves['/Accounts/Replace the avatar']['request']['url'];

    expect($url['host'])->toBe(['{{baseUrl}}'])
        ->and($url['path'])->toBe(['accounts', ':id', 'avatar'])
        ->and($url['raw'])->toBe('{{baseUrl}}/accounts/:id/avatar')
        ->and($url['variable'][0]['key'])->toBe('id')
        // A `protocol` member would be wrong: the scheme lives inside the variable.
        ->and($url)->not->toHaveKey('protocol');
});

it('rebuilds raw from the same parts it publishes, so the two cannot disagree', function (): void {
    foreach (postmanLeaves(postman(loadFixture('postman-surface.uir.json'))['item']) as $item) {
        $url = $item['request']['url'];
        $enabled = array_values(array_filter(
            $url['query'] ?? [],
            static fn (array $q): bool => ($q['disabled'] ?? false) !== true,
        ));

        $expected = '{{baseUrl}}/'.implode('/', $url['path']);
        if ($enabled !== []) {
            $expected .= '?'.implode('&', array_map(
                static fn (array $q): string => $q['key'].'='.$q['value'],
                $enabled,
            ));
        }

        expect($url['raw'])->toBe($expected);
    }
});

it('leaves a partial path template literal and says the URL needs editing', function (): void {
    // Postman's `:name` stands for a WHOLE segment, so `{name}.csv` would swallow the extension.
    $leaves = postmanLeaves(postman(loadFixture('postman-surface.uir.json'))['item']);

    expect($leaves['/Reporting/Download an export']['request']['url']['path'])->toBe(['exports', '{name}.csv'])
        ->and(postmanCodes(loadFixture('postman-surface.uir.json')))->toContain('postman.path-template-partial');
});

it('sends each parameter where the wire carries it', function (string $leaf, string $where, string $key): void {
    $item = postmanLeaves(postman(loadFixture('postman-surface.uir.json'))['item'])[$leaf];
    $request = $item['request'];

    $found = match ($where) {
        'header' => array_column($request['header'], 'key'),
        'query' => array_column($request['url']['query'] ?? [], 'key'),
        'variable' => array_column($request['url']['variable'] ?? [], 'key'),
        default => [],
    };

    expect($found)->toContain($key);
})->with([
    'path' => ['/Accounts/Replace the avatar', 'variable', 'id'],
    'query' => ['/Accounts/List accounts', 'query', 'sort'],
    'deepObject expands per property' => ['/Accounts/List accounts', 'query', 'filter[status]'],
    'accept header' => ['/Accounts/List accounts', 'header', 'Accept'],
    'content type header' => ['/Accounts/Open an account', 'header', 'Content-Type'],
]);

it('disables an optional parameter so a consumer can press Send', function (): void {
    $query = postmanLeaves(postman(loadFixture('postman-surface.uir.json'))['item'])['/Accounts/List accounts']['request']['url']['query'];

    foreach ($query as $entry) {
        expect($entry['disabled'])->toBeTrue();
    }
});

it('describes an enum parameter with the values a consumer may send', function (): void {
    $query = postmanLeaves(postman(loadFixture('postman-surface.uir.json'))['item'])['/Accounts/List accounts']['request']['url']['query'];
    $status = array_values(array_filter($query, static fn (array $q): bool => $q['key'] === 'filter[status]'))[0];

    expect($status['description'])->toContain('One of: open, closed.');
});

it('publishes every server variable as its own collection variable', function (): void {
    // Switching tenant is what a server variable is for, and it should be one edit rather than a
    // search-and-replace through every URL.
    $collection = postman(loadFixture('kitchen-sink.uir.json'));
    $variables = array_column($collection['variable'], 'value', 'key');

    expect($variables['baseUrl'])->toBe('https://{{tenant}}.example.com/{{basePath}}')
        ->and($variables['tenant'])->toBe('acme')
        ->and($variables['basePath'])->toBe('v2');
});

it('lists the servers it cannot target rather than minting baseUrl2', function (): void {
    // A numbered second variable is a first-come counter wearing a different hat.
    $collection = postman(loadFixture('postman-surface.uir.json'));

    expect(array_column($collection['variable'], 'key'))->not->toContain('baseUrl2')
        ->and($collection['info']['description'])->toContain('sandbox.example.com')
        // Several servers is normal and nothing is lost, so it earns no diagnostic.
        ->and(postmanCodes(loadFixture('postman-surface.uir.json')))->not->toContain('postman.servers-dropped');
});

it('names each credential variable after the scheme that needs it', function (): void {
    $keys = array_column(postman(loadFixture('postman-surface.uir.json'))['variable'], 'key');

    expect($keys)->toContain('bearer')
        ->and($keys)->toContain('basicUsername')
        ->and($keys)->toContain('basicPassword')
        ->and($keys)->toContain('oauthClientId')
        ->and($keys)->toContain('oauthClientSecret');
});

it('maps each security scheme onto the auth Postman has for it', function (string $leaf, ?string $type): void {
    $item = postmanLeaves(postman(loadFixture('postman-surface.uir.json'))['item'])[$leaf];

    expect($item['request']['auth']['type'] ?? null)->toBe($type);
})->with([
    'basic' => ['/Reporting/Download an export', 'basic'],
    'digest' => ['/Reporting/Push a legacy payload', 'digest'],
    'oauth2' => ['/Show a scoped resource', 'oauth2'],
    'no auth required' => ['/Accounts/List accounts', 'noauth'],
    // openIdConnect and mutualTLS have no Postman form: no block, and a diagnostic instead.
    'openIdConnect' => ['/Show the category tree', null],
    'mutualTLS' => ['/Show the vault', null],
]);

it('adds nothing to an operation that only restates the document default', function (): void {
    // Comparing the RESOLVED blocks is what makes this free: the requirement differs, the credential
    // does not, so the request carries no `auth` member at all.
    $leaves = postmanLeaves(postman(loadFixture('postman-surface.uir.json'))['item']);

    expect($leaves['/Accounts/Replace the avatar']['request'])->not->toHaveKey('auth')
        ->and($leaves['/Accounts/Open an account']['request'])->not->toHaveKey('auth');
});

it('omits the collection auth entirely when the document requires none', function (): void {
    // An explicit `{"type":"noauth"}` at collection level would override nothing and read as a claim.
    expect(postman(loadFixture('tag-hierarchy.uir.json')))->not->toHaveKey('auth');
});

it('says which credential an AND-composed requirement had to drop', function (): void {
    $multi = array_values(array_filter(
        postmanReport(loadFixture('postman-surface.uir.json')),
        static fn (Diagnostic $d): bool => $d->code === 'postman.auth-multi-scheme',
    ));

    expect($multi)->toHaveCount(1)
        ->and($multi[0]->message)->toContain('apiKey')
        ->and($multi[0]->routeSignature)->toBe('PUT /accounts/{id}/avatar');
});

it('builds each media type into the body mode Postman has for it', function (string $leaf, string $mode): void {
    $item = postmanLeaves(postman(loadFixture('postman-surface.uir.json'))['item'])[$leaf];

    expect($item['request']['body']['mode'])->toBe($mode);
})->with([
    'urlencoded' => ['/Accounts/Open an account', 'urlencoded'],
    'multipart' => ['/Accounts/Replace the avatar', 'formdata'],
    'unrepresentable falls back to raw' => ['/Reporting/Push a legacy payload', 'raw'],
]);

it('offers a file field for a consumer to fill rather than a path from this machine', function (): void {
    $formdata = postmanLeaves(postman(loadFixture('postman-surface.uir.json'))['item'])['/Accounts/Replace the avatar']['request']['body']['formdata'];
    $file = array_values(array_filter($formdata, static fn (array $f): bool => $f['key'] === 'file'))[0];

    expect($file['type'])->toBe('file')
        ->and($file['src'])->toBeNull();
});

it('leaves a required form field enabled and an optional one disabled', function (): void {
    $fields = postmanLeaves(postman(loadFixture('postman-surface.uir.json'))['item'])['/Accounts/Open an account']['request']['body']['urlencoded'];
    $by = array_column($fields, null, 'key');

    expect($by['email'])->not->toHaveKey('disabled')
        ->and($by['referrer']['disabled'])->toBeTrue()
        ->and($by['referrer']['description'])->toBe('Who sent them.');
});

it('warns once per media type, however many operations use it', function (): void {
    // A document with 300 XML endpoints owes its reader one warning, not 300 that bury everything else.
    $media = array_values(array_filter(
        postmanReport(loadFixture('postman-surface.uir.json')),
        static fn (Diagnostic $d): bool => $d->code === 'postman.body-media-type',
    ));

    expect($media)->toHaveCount(1)
        ->and($media[0]->message)->toContain('application/xml');
});

it('saves a documented response as a runnable example', function (): void {
    $response = postmanLeaves(postman(loadFixture('postman-surface.uir.json'))['item'])['/Accounts/List accounts']['response'][0];

    expect($response['name'])->toBe('200 OK — A page')
        ->and($response['code'])->toBe(200)
        ->and($response['status'])->toBe('OK')
        ->and($response['_postman_previewlanguage'])->toBe('json')
        ->and($response['body'])->toContain('user@example.com')
        // The originalRequest is what makes the example usable in Postman's UI.
        ->and($response['originalRequest']['method'])->toBe('GET')
        ->and(array_column($response['header'], 'key'))->toContain('X-Total-Count');
});

it('skips a status it cannot put in an integer code', function (): void {
    // `default`/`2XX` cannot fill Postman's `code`; the OpenAPI artifact still carries them, so nothing
    // a consumer can act on is lost and there is nothing to report.
    $codes = array_column(
        postmanLeaves(postman(loadFixture('postman-surface.uir.json'))['item'])['/Accounts/Open an account']['response'],
        'code',
    );

    expect($codes)->toBe([201, 400, 401, 403, 409, 422])
        ->and($codes)->not->toContain('default');
});

it('caps saved examples and says how many it kept', function (): void {
    $truncated = array_values(array_filter(
        postmanReport(loadFixture('postman-surface.uir.json')),
        static fn (Diagnostic $d): bool => $d->code === 'postman.examples-truncated',
    ));

    expect($truncated)->toHaveCount(1)
        ->and($truncated[0]->routeSignature)->toBe('POST /accounts');
});

it('reports webhooks, which describe what the API sends rather than what you send', function (): void {
    expect(postmanCodes(loadFixture('kitchen-sink.uir.json')))->toContain('postman.webhooks-dropped');
});

it('writes JSON and says so when asked for YAML', function (): void {
    $document = loadFixture('worked-example.json');
    $result = (new CollectionEmitter)->emitWithReport(UirDocument::fromArray($document), (new EmitOptions)->withYaml());

    // Postman imports JSON only, so a YAML "collection" is a file it refuses.
    expect(str_starts_with($result->output, '{'))->toBeTrue()
        ->and(postmanCodes($document, (new EmitOptions)->withYaml()))->toContain('postman.yaml-ignored');
});

it('ignores the options that describe a UIR or OpenAPI artifact', function (): void {
    // A collection is a view of the published contract, so it reads the same array the OpenAPI file
    // does — never the caller's id-keeping or mock-hint options.
    $document = UirDocument::fromArray(loadFixture('kitchen-sink.uir.json'));
    $emitter = new CollectionEmitter;

    $loaded = (new EmitOptions)->withKeepIds()->withMockFakerKey('x-faker');

    expect($emitter->emit($document, $loaded))->toBe($emitter->emit($document))
        ->and($emitter->emit($document, $loaded))->not->toContain('x-docuccino')
        ->and($emitter->emit($document, $loaded))->not->toContain('x-faker');
});

it('carries a deprecation in prose, which is the only place the format has for it', function (): void {
    $leaves = postmanLeaves(postman(loadFixture('kitchen-sink.uir.json'))['item']);
    $description = $leaves['/Widgets/Show a widget']['request']['description'];

    // Nothing is lost, so this earns no diagnostic.
    expect($description)->toContain('**Deprecated.**')
        ->and(postmanCodes(loadFixture('kitchen-sink.uir.json')))->not->toContain('postman.deprecated');
});

it('keeps the 3.2 query method intact rather than downlevelling it', function (): void {
    // `request.method` is a free string, so unlike the OAS downlevels there is nothing to drop here.
    $methods = array_map(
        static fn (array $i): string => $i['request']['method'],
        postmanLeaves(postman(loadFixture('kitchen-sink.uir.json'))['item']),
    );

    expect(array_values($methods))->toContain('QUERY');
});

it('addresses the consumer, never the author', function (string $fixture): void {
    // Whoever holds this file cannot see the application, its config or its attributes — so no prose
    // here may mention any of them.
    $bytes = (new CollectionEmitter)->emit(UirDocument::fromArray(loadFixture($fixture)));

    foreach (['docuccino', 'config/', '#[', 'attribute', 'phpstan', 'artisan'] as $forbidden) {
        expect(strtolower($bytes))->not->toContain($forbidden);
    }
})->with([
    'worked-example' => ['worked-example.json'],
    'kitchen-sink' => ['kitchen-sink.uir.json'],
    'postman-surface' => ['postman-surface.uir.json'],
]);

it('says a document with no servers needs a host before anything will run', function (): void {
    $collection = postman(loadFixture('tag-hierarchy.uir.json'));

    expect(array_column($collection['variable'], 'value', 'key')['baseUrl'])->toBe('')
        ->and(postmanCodes(loadFixture('tag-hierarchy.uir.json')))->toContain('postman.no-server');
});

it('reports a server variable it cannot suggest a value for', function (): void {
    expect(postmanCodes(loadFixture('postman-surface.uir.json')))->toContain('postman.server-variable-no-default');

    // …and still falls back to the first enum entry rather than leaving it blank.
    expect(array_column(postman(loadFixture('postman-surface.uir.json'))['variable'], 'value', 'key')['version'])->toBe('v1');
});

it('yields to the base URL when a server variable would collide with it', function (): void {
    $document = loadFixture('tag-hierarchy.uir.json');
    $document['servers'] = [['url' => 'https://{baseUrl}.example.com', 'variables' => ['baseUrl' => ['default' => 'acme']]]];

    $keys = array_column(postman($document)['variable'], 'key');

    expect(array_count_values($keys)['baseUrl'])->toBe(1)
        ->and(postmanCodes($document))->toContain('postman.variable-name-collision');
});
