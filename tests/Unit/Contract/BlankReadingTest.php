<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractChecker;
use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\ParameterValue;
use Docuccino\Core\Contract\Violation;
use Docuccino\Core\Document\BlankAsNull;

/*
 * A contract that says the server reads a blank string as null somewhere (`x-docuccino.facts.blankAsNull`)
 * is checked the way the server reads the request: the blank IS that null, at every place a request carries
 * a value the framework rewrites before validating — a query value, a form field, a JSON body member — and
 * nowhere else. The schema is never widened for it, so a string that is not blank is held to it as ever.
 */

beforeEach(function (): void {
    $this->blank = '^[\t ]*$';

    // A nullable value list, the shape a closed set publishes, stating the fact beside it.
    $this->status = BlankAsNull::onto(['anyOf' => [['type' => 'string', 'enum' => ['open', 'closed']], ['type' => 'null']]], $this->blank);
    $this->limit = BlankAsNull::onto(['type' => ['integer', 'null'], 'minimum' => 1], $this->blank);

    $object = ['type' => 'object', 'properties' => [
        'status' => $this->status,
        'limit' => $this->limit,
        'tags' => ['type' => 'array', 'items' => $this->status],
        'plain' => ['anyOf' => [['type' => 'string', 'enum' => ['a']], ['type' => 'null']]],
    ]];

    $this->index = ContractIndex::fromArray([
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'paths' => ['/api/tickets' => [
            'get' => [
                'parameters' => [
                    ['name' => 'status', 'in' => 'query', 'schema' => $this->status],
                    ['name' => 'X-Status', 'in' => 'header', 'schema' => $this->status],
                ],
                'responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => $object]]]],
            ],
            'post' => [
                'requestBody' => ['content' => [
                    'application/json' => ['schema' => ['$ref' => '#/components/schemas/Ticket']],
                    'application/x-www-form-urlencoded' => ['schema' => $object],
                ]],
                'responses' => ['204' => ['description' => 'Saved']],
            ],
        ]],
        'components' => ['schemas' => ['Ticket' => $object]],
    ]);

    // Where each finding is, once: an `anyOf` reports every branch it tried.
    $this->findings = fn ($exchange, string $half = 'request'): array => array_values(array_unique(array_map(
        static fn (Violation $violation): string => $violation->where(),
        (new ContractChecker($this->index))->check($exchange)->{$half}->violations,
    )));
});

it('reads a blank query value as the null the server reads', function (string $sent): void {
    expect(($this->findings)(contractExchange('GET', '/api/tickets', query: ['status' => $sent], responseBody: '{}')))->toBe([]);
})->with(['empty' => [''], 'spaces' => ['  '], 'a tab' => ["\t"]]);

it('holds a query value that is not blank to the schema, as ever', function (string $sent): void {
    expect(($this->findings)(contractExchange('GET', '/api/tickets', query: ['status' => $sent], responseBody: '{}')))->toBe(['?status']);
})->with(['a padded member' => [' open '], 'an outsider' => ['gone'], 'a blank the pattern does not name' => ["\u{A0}"]]);

it('reads a blank member of a JSON body, behind a reference and inside a list, as null', function (): void {
    $body = json_encode(['status' => '', 'limit' => '  ', 'tags' => ['open', "\t"]], JSON_THROW_ON_ERROR);

    expect(($this->findings)(contractExchange('POST', '/api/tickets', status: 204, requestBody: $body)))->toBe([])
        // What JSON already typed is left as it was.
        ->and(ParameterValue::readBlanks(json_decode('{"limit": 3, "status": null}'), $this->index->document()['components']['schemas']['Ticket']))
        ->toEqual(json_decode('{"limit": 3, "status": null}'));
});

it('reads a blank form field as null', function (): void {
    $exchange = contractExchange('POST', '/api/tickets', status: 204, requestContentType: 'application/x-www-form-urlencoded', requestForm: [
        'status' => '', 'limit' => ' ', 'tags' => ['closed', ''],
    ]);

    expect(($this->findings)($exchange))->toBe([]);
});

it('reads no blank where the contract states none', function (): void {
    // `plain` takes null as well, but nothing says the server reads a blank as one — so it is a string.
    $body = json_encode(['plain' => ''], JSON_THROW_ON_ERROR);

    expect(($this->findings)(contractExchange('POST', '/api/tickets', status: 204, requestBody: $body)))->toBe(['the request body at /plain']);
});

it('reads no blank in a header or a response, which the framework never rewrites', function (): void {
    expect(($this->findings)(contractExchange('GET', '/api/tickets', headers: ['X-Status' => ['']], responseBody: '{}')))->toBe(['header X-Status'])
        ->and(($this->findings)(contractExchange('GET', '/api/tickets', responseBody: '{"status": ""}'), 'response'))->toBe(['the response body at /status']);
});

it('reads a blank ahead of every other reading of a query string', function (): void {
    // Neither the comma list an array would be split into nor a number is read out of a blank.
    $list = BlankAsNull::onto(['type' => ['array', 'null'], 'items' => ['type' => 'integer']], $this->blank);

    expect(ParameterValue::coerce('', $list, blanks: true))->toBeNull()
        ->and(ParameterValue::coerce('', $list))->toBe([''])
        ->and(ParameterValue::coerce(' ', $this->limit, blanks: true))->toBeNull()
        ->and(ParameterValue::coerce('5', $this->limit, blanks: true))->toBe(5)
        // A branch that states the fact states it for the value: the first one found answers.
        ->and(ParameterValue::coerce('', ['anyOf' => [$this->limit, ['type' => 'null']]], blanks: true))->toBeNull();
});

it('reads the fact it writes, and a pattern PCRE refuses as naming no blank', function (): void {
    $schema = BlankAsNull::onto(['type' => 'string', 'x-docuccino' => ['mock' => ['faker' => 'word']]], '^$');

    expect($schema['x-docuccino'])->toBe(['mock' => ['faker' => 'word'], 'facts' => ['blankAsNull' => '^$']])
        ->and(BlankAsNull::of($schema))->toBe('^$')
        ->and(BlankAsNull::of(['type' => 'string']))->toBeNull()
        ->and(BlankAsNull::of(['x-docuccino' => ['facts' => ['blankAsNull' => true]]]))->toBeNull()
        ->and(BlankAsNull::matches('^$', ''))->toBeTrue()
        ->and(BlankAsNull::matches('^$', ' '))->toBeFalse()
        ->and(BlankAsNull::matches('^[', ''))->toBeFalse()
        ->and(BlankAsNull::matches("^\x01$", ''))->toBeFalse();
});
