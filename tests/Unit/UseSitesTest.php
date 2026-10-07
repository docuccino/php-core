<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Document\UseSites;
use Docuccino\Core\Identity\ContentHasher;
use Docuccino\Core\SpecValidation\OpenApiMetaSchema;
use Docuccino\Core\SpecValidation\Validator;

/**
 * A document using a shared response and a shared parameter, with each use's own `x-docuccino` where a
 * build writes it: beside the `$ref`.
 *
 * @return array<string, mixed>
 */
function documentWithUses(): array
{
    return [
        'openapi' => '3.2.0',
        'info' => ['title' => 'Uses', 'version' => '1.0.0'],
        'paths' => [
            '/forms' => [
                'get' => [
                    'x-docuccino' => ['id' => 'op:v1:aaaaaaaaaaaaaaaa'],
                    'parameters' => [
                        ['x-docuccino' => ['id' => 'par:v1:bbbbbbbbbbbbbbbb'], '$ref' => '#/components/parameters/ApiVersion'],
                        ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']],
                    ],
                    'responses' => [
                        '200' => ['description' => 'OK'],
                        '404' => [
                            'x-docuccino' => [
                                'id' => 'res:v1:cccccccccccccccc',
                                'provenance' => [['producer' => 'integration:framework-errors', 'layer' => 'integration']],
                            ],
                            '$ref' => '#/components/responses/NotFound',
                        ],
                    ],
                ],
            ],
        ],
        'components' => [
            'parameters' => ['ApiVersion' => ['name' => 'Api-Version', 'in' => 'header', 'schema' => ['type' => 'string']]],
            'responses' => ['NotFound' => ['description' => 'Not Found']],
        ],
    ];
}

it('moves each use onto its operation, keyed by status and by in then name', function (): void {
    $lifted = UseSites::lift(documentWithUses());
    $operation = $lifted['paths']['/forms']['get'];

    expect($operation['x-docuccino'])->toBe([
        'id' => 'op:v1:aaaaaaaaaaaaaaaa',
        'uses' => [
            'responses' => ['404' => documentWithUses()['paths']['/forms']['get']['responses']['404']['x-docuccino']],
            'parameters' => ['header' => ['Api-Version' => ['id' => 'par:v1:bbbbbbbbbbbbbbbb']]],
        ],
    ])
        ->and($operation['responses']['404'])->toBe(['$ref' => '#/components/responses/NotFound'])
        ->and($operation['parameters'][0])->toBe(['$ref' => '#/components/parameters/ApiVersion'])
        // An inline node is no Reference Object and keeps what it carries.
        ->and($operation['parameters'][1])->toBe(documentWithUses()['paths']['/forms']['get']['parameters'][1]);
});

it('puts every use back where the model keeps it, so the move is lossless', function (): void {
    $document = documentWithUses();

    expect(UseSites::lower(UseSites::lift($document)))->toEqual($document);
});

it('reads a 2.0 document and its 2.1 form as one document', function (): void {
    $document = documentWithUses();

    expect(UirDocument::fromArray(UseSites::lift($document))->toArray())
        ->toEqual(UirDocument::fromArray($document)->toArray());
});

it('changes nothing in a document that has no uses to move', function (): void {
    $document = documentWithUses();
    unset($document['paths']['/forms']['get']['responses']['404']['x-docuccino'], $document['paths']['/forms']['get']['parameters'][0]['x-docuccino']);

    expect(UseSites::lift($document))->toBe($document)
        ->and(UseSites::lower($document))->toBe($document);
});

it('leaves a parameter use whose $ref names nothing where it stands, for the dangling reference to be reported there', function (): void {
    $document = documentWithUses();
    $document['paths']['/forms']['get']['parameters'][0]['$ref'] = '#/components/parameters/Missing';

    $lifted = UseSites::lift($document);

    expect($lifted['paths']['/forms']['get']['parameters'][0])->toBe($document['paths']['/forms']['get']['parameters'][0])
        ->and($lifted['paths']['/forms']['get']['x-docuccino']['uses'])->not->toHaveKey('parameters');
});

it('moves nothing twice, and puts nothing back twice', function (): void {
    $document = documentWithUses();
    $lifted = UseSites::lift($document);

    expect(UseSites::lift($lifted))->toEqual($lifted)
        ->and(UseSites::lower(UseSites::lower($lifted)))->toEqual($document);
});

it('adds to the uses an operation already carries rather than replacing them', function (): void {
    // Half-lifted: the response already on the operation, the parameter still beside its `$ref`.
    $document = documentWithUses();
    $response = $document['paths']['/forms']['get']['responses']['404'];
    $document['paths']['/forms']['get']['x-docuccino']['uses']['responses']['404'] = $response['x-docuccino'];
    unset($document['paths']['/forms']['get']['responses']['404']['x-docuccino']);

    expect(UseSites::lift($document))->toEqual(UseSites::lift(documentWithUses()));
});

it('keeps an entry whose $ref is gone, rather than losing the id it published', function (): void {
    $lifted = UseSites::lift(documentWithUses());
    $lifted['paths']['/forms']['get']['responses']['404'] = ['description' => 'Not Found'];

    $lowered = UseSites::lower($lifted);

    expect($lowered['paths']['/forms']['get']['x-docuccino']['uses'])->toBe([
        'responses' => ['404' => documentWithUses()['paths']['/forms']['get']['responses']['404']['x-docuccino']],
    ])
        // …while the parameter, whose `$ref` is still there, went back beside it.
        ->and($lowered['paths']['/forms']['get']['parameters'][0]['x-docuccino'])->toBe(['id' => 'par:v1:bbbbbbbbbbbbbbbb']);
});

it('keys a parameter through a chain of $refs by the parameter it lands on', function (): void {
    $document = documentWithUses();
    $document['components']['parameters']['Alias'] = ['$ref' => '#/components/parameters/ApiVersion'];
    $document['paths']['/forms']['get']['parameters'][0]['$ref'] = '#/components/parameters/Alias';

    expect(UseSites::lift($document)['paths']['/forms']['get']['x-docuccino']['uses']['parameters'])
        ->toBe(['header' => ['Api-Version' => ['id' => 'par:v1:bbbbbbbbbbbbbbbb']]]);
});

it('leaves a second use of one in and name beside its $ref, where it is reported, rather than overwrite the first', function (): void {
    $document = documentWithUses();
    $document['paths']['/forms']['get']['parameters'][] = ['x-docuccino' => ['id' => 'par:v1:dddddddddddddddd'], '$ref' => '#/components/parameters/ApiVersion'];

    $operation = UseSites::lift($document)['paths']['/forms']['get'];

    expect($operation['x-docuccino']['uses']['parameters']['header']['Api-Version'])->toBe(['id' => 'par:v1:bbbbbbbbbbbbbbbb'])
        ->and($operation['parameters'][2]['x-docuccino'])->toBe(['id' => 'par:v1:dddddddddddddddd']);
});

it('moves a response use whatever its $ref names, since its status is all it is keyed by', function (): void {
    $document = documentWithUses();
    $document['paths']['/forms']['get']['responses']['404']['$ref'] = '#/components/responses/Missing';

    expect(UseSites::lift($document)['paths']['/forms']['get']['x-docuccino']['uses']['responses'])->toHaveKey('404');
});

it('moves the uses of every operation a document declares, wherever it declares it', function (): void {
    $operation = documentWithUses()['paths']['/forms']['get'];
    $document = documentWithUses();
    $document['webhooks'] = ['formCreated' => ['post' => $operation]];
    $document['components']['pathItems'] = ['Shared' => ['put' => $operation]];
    $document['paths']['/forms']['additionalOperations'] = ['COPY' => $operation];
    $document['paths']['/forms']['get']['callbacks'] = ['onSubmit' => ['{$request.body#/url}' => ['post' => $operation]]];

    $graph = json_decode((string) json_encode(UseSites::lift($document)), flags: JSON_THROW_ON_ERROR);

    // The rule the move exists to satisfy, asked of the whole document by the check that states it.
    expect(OpenApiMetaSchema::referenceSiblingFindings('openapi-3.2', $graph))->toBe([])
        ->and(UseSites::lower(UseSites::lift($document)))->toEqual($document);
});

it('hashes a document and its published form alike, so the hash is of what is published', function (): void {
    $document = documentWithUses();

    expect((new ContentHasher)->hash(UseSites::lift($document)))->toBe((new ContentHasher)->hash($document));
});

it('indexes every use id from a 2.1 artifact as it did from a 2.0 one', function (): void {
    $published = ContractIndex::fromJson((string) json_encode(UseSites::lift(documentWithUses())));

    expect(array_keys($published->identities()))
        ->toContain('res:v1:cccccccccccccccc')
        ->toContain('par:v1:bbbbbbbbbbbbbbbb')
        ->and($published->identities())->toBe(ContractIndex::fromArray(documentWithUses())->identities());
});

it('refuses a uses map that is not keyed the way 2.1 keys it', function (): void {
    $document = workedExample();
    $first = array_key_first($document['paths']);
    $method = array_key_first($document['paths'][$first]);
    $document['paths'][$first][$method]['x-docuccino']['uses'] = ['parameters' => ['body' => ['payload' => ['id' => 'par:v1:eeeeeeeeeeeeeeee']]]];

    expect((new Validator)->validate($document)->isValid())->toBeFalse();
});
