<?php

declare(strict_types=1);

use Docuccino\Core\Tests\Support\OpenApiMetaSchema;

/**
 * What the meta-schema oracle actually catches, measured rather than claimed.
 *
 * `allowUnevaluated` is off (the opis defects are named in `OpenApiMetaSchema::validator()`), and the
 * cost of that is bigger than "no unrecognised-member check": `unevaluatedProperties: false` is spelled at
 * 28 sites in each of the 3.1 and 3.2 meta-schemas, and it is the ONLY thing enforcing their
 * `patternProperties` key gates. 3.0 carries no such keyword — its gates close with
 * `additionalProperties` — which is why it rejects nearly everything below and the other two do not.
 *
 * `OpenApiMetaSchema::keyGateFindings()` walks the three key gates back on directly, so the first two rows
 * reject everywhere. The rest is the honest remainder, and the rows that PASS are the point: pinned
 * blindness stops being a surprise, and a future weakening — or a recovery — shows up as a row flipping.
 *
 * Three losses reach this matrix, one row set each: the key gates and the unrecognised-member check
 * (`allowUnevaluated`), the format assertion (`allowFormats`, the `info.contact.email` row), and the
 * `contains` bound `opisWorkarounds()` drops (the two `querystring` rows). All three are named at their
 * call site in `OpenApiMetaSchema`; this file is where each is measured.
 */
function unevaluatedScopeVersions(): array
{
    return ['openapi-3.2' => '3.2.0', 'openapi-3.1' => '3.1.0', 'openapi-3.0' => '3.0.4'];
}

/** A document valid under all three versions, so every row below measures the MUTATION and nothing else. */
function unevaluatedScopeBase(string $version): stdClass
{
    return json_decode((string) json_encode([
        'openapi' => $version,
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => [
            '/things' => [
                'get' => [
                    'responses' => [
                        '200' => [
                            'description' => 'ok',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => ['id' => ['type' => 'string', 'format' => 'uuid']],
                                        'additionalProperties' => false,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]), flags: JSON_THROW_ON_ERROR);
}

/**
 * The matrix: mutation => outcome per format, in the order {@see unevaluatedScopeVersions()} lists them.
 *
 * The last two rows are the third loss, which is collateral rather than chosen: `opisWorkarounds()` drops
 * `contains` with BOTH its bounds wherever `minContains: 0` sits beside it, and only `minContains` is what
 * opis mis-evaluates. `maxContains: 1` goes with it, so 3.2's "at most one querystring parameter" cap is
 * unenforced. The pair measures the loss and its edge together — a row recording a loss with nothing
 * bounding it reads as a measurement and measures nothing.
 *
 * 3.1 and 3.0 reject both rows for a reason that is not the cap: `querystring` is a 3.2-only `in` value,
 * so their columns say only that the parameter does not belong there.
 *
 * @return array<string, list<string>>
 */
function unevaluatedScopeMatrix(): array
{
    return [
        'paths key not starting with a slash' => ['rejects', 'rejects', 'rejects'],
        'response key twohundred' => ['rejects', 'rejects', 'rejects'],
        'responses present but empty' => ['rejects', 'rejects', 'rejects'],
        'operation member typo' => ['passes', 'passes', 'rejects'],
        'info member typo' => ['passes', 'passes', 'rejects'],
        'type is a misspelled string' => ['passes', 'passes', 'rejects'],
        'type is a number' => ['passes', 'passes', 'rejects'],
        'properties is a sequence' => ['passes', 'passes', 'rejects'],
        'additionalProperties is a sequence' => ['passes', 'passes', 'rejects'],
        'info.contact.email is malformed' => ['passes', 'passes', 'passes'],
        'two querystring parameters' => ['passes', 'rejects', 'rejects'],
        'query parameter beside a querystring one' => ['rejects', 'rejects', 'rejects'],
    ];
}

/**
 * A 3.2 `querystring` parameter, which takes `content` rather than `schema`.
 *
 * @return array{name: string, in: string, content: array{'application/json': array{schema: array{type: string}}}}
 */
function unevaluatedScopeQuerystring(string $name): array
{
    return ['name' => $name, 'in' => 'querystring', 'content' => ['application/json' => ['schema' => ['type' => 'string']]]];
}

function unevaluatedScopeMutate(string $mutation, stdClass $document): void
{
    $operation = $document->paths->{'/things'}->get;
    $schema = $operation->responses->{'200'}->content->{'application/json'}->schema;

    match ($mutation) {
        'paths key not starting with a slash' => (function () use ($document): void {
            $document->paths->things = $document->paths->{'/things'};
            unset($document->paths->{'/things'});
        })(),
        'response key twohundred' => (function () use ($operation): void {
            $operation->responses->twohundred = $operation->responses->{'200'};
            unset($operation->responses->{'200'});
        })(),
        'responses present but empty' => $operation->responses = new stdClass,
        'operation member typo' => (function () use ($operation): void {
            $operation->respones = $operation->responses;
            unset($operation->responses);
        })(),
        'info member typo' => $document->info->versionn = '1.0.0',
        'type is a misspelled string' => $schema->type = 'objekt',
        'type is a number' => $schema->type = 42,
        'properties is a sequence' => $schema->properties = [],
        'additionalProperties is a sequence' => $schema->additionalProperties = [],
        'info.contact.email is malformed' => $document->info->contact = json_decode('{"email":"not an email"}', flags: JSON_THROW_ON_ERROR),
        // The dropped `maxContains: 1`. Two of them is one more than 3.2 allows, and 3.2 says nothing.
        'two querystring parameters' => $operation->parameters = json_decode((string) json_encode(
            [unevaluatedScopeQuerystring('one'), unevaluatedScopeQuerystring('two')],
        ), flags: JSON_THROW_ON_ERROR),
        // The edge of that loss: the mutual exclusion of `query` and `querystring` rides a `not`/`allOf`
        // opis reads correctly, so it is NOT collateral and 3.2 still rejects this.
        'query parameter beside a querystring one' => $operation->parameters = json_decode((string) json_encode(
            [['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']], unevaluatedScopeQuerystring('one')],
        ), flags: JSON_THROW_ON_ERROR),
    };
}

/** @return array<string, array{string, string, string}> */
function unevaluatedScopeSubjects(): array
{
    $subjects = [];

    foreach (unevaluatedScopeMatrix() as $mutation => $outcomes) {
        foreach (array_keys(unevaluatedScopeVersions()) as $index => $format) {
            $subjects[$mutation.' · '.$format] = [$mutation, $format, $outcomes[$index]];
        }
    }

    return $subjects;
}

/**
 * The floor under the whole matrix. Every row measures a mutation of THIS document, so a base that had
 * stopped being valid would make each row reject for a reason that has nothing to do with its mutation.
 */
it('measures every mutation against a document all three versions accept', function (): void {
    foreach (unevaluatedScopeVersions() as $format => $version) {
        expect(OpenApiMetaSchema::findings($format, unevaluatedScopeBase($version)))->toBe([], $format);
    }
});

it('catches, or does not catch, exactly what the matrix records', function (string $mutation, string $format, string $outcome): void {
    $document = unevaluatedScopeBase(unevaluatedScopeVersions()[$format]);

    unevaluatedScopeMutate($mutation, $document);

    $findings = OpenApiMetaSchema::findings($format, $document);

    expect($findings === [] ? 'passes' : 'rejects')->toBe($outcome, implode("\n", $findings));
})->with(unevaluatedScopeSubjects());

/**
 * A matrix that recorded one outcome everywhere would prove nothing — it would read the same whether the
 * oracle caught everything or nothing. Both outcomes have to appear, and the 3.0 column has to be the
 * strict one, which is the whole shape of the finding.
 */
it('records a matrix with something in both columns', function (): void {
    $outcomes = array_merge(...array_values(unevaluatedScopeMatrix()));

    $strict = array_filter(unevaluatedScopeMatrix(), static fn (array $row): bool => $row[2] === 'rejects');

    expect(unevaluatedScopeMatrix())->toHaveCount(12)
        ->and(count(unevaluatedScopeSubjects()))->toBe(36)
        ->and(array_count_values($outcomes)['passes'] ?? 0)->toBeGreaterThanOrEqual(10)
        ->and(array_count_values($outcomes)['rejects'] ?? 0)->toBeGreaterThanOrEqual(10)
        ->and($strict)->toHaveCount(11);
});
