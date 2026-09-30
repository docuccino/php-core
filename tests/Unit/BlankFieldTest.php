<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Validation\RequestSchemaBuilder;

/*
 * A field the server reads a blank string on as its null publishes exactly the schema it would without
 * that, and states the reading beside it as a fact. The schema is what a client is held to, and sending
 * null already does what a blank does, so widening it would cost every consumer a type for nothing.
 */

beforeEach(function (): void {
    // One `code` field built from the given keywords, nullable or not, admitting a blank or not.
    $this->build = static function (array $keywords, bool $nullable, bool $admit, string $policy = 'type-array', bool $refuse = false, bool $refuseFirst = false): array {
        $builder = new RequestSchemaBuilder;
        $field = $builder->field('code');
        foreach ($keywords as $keyword => $value) {
            $field->set($keyword, $value);
        }
        if ($nullable) {
            $field->markNullable();
        }
        if ($refuse && $refuseFirst) {
            $field->refuseBlank();
        }
        if ($admit) {
            $field->admitBlank('^ *$');
        }
        if ($refuse && ! $refuseFirst) {
            $field->refuseBlank();
        }

        return $builder->build(new RepresentationPolicy(nullable: $policy))['properties']['code'];
    };
});

it('states the fact beside the schema it would publish without it, under either nullable policy', function (array $keywords, string $policy): void {
    $plain = ($this->build)($keywords, true, false, $policy);
    $admitted = ($this->build)($keywords, true, true, $policy);

    expect($admitted['x-docuccino'] ?? null)->toBe(['facts' => ['blankAsNull' => '^ *$']]);

    unset($admitted['x-docuccino']);
    expect($admitted)->toBe($plain);
})->with([
    'a value list' => [['type' => 'string', 'enum' => ['open', 'closed']]],
    'a pattern' => [['type' => 'string', 'pattern' => '^[a-z]+$']],
    'an integer' => [['type' => 'integer', 'minimum' => 1]],
    'an upload' => [['type' => 'string', 'format' => 'binary']],
    'a reference' => [['$ref' => '#/components/schemas/Status']],
])->with(['type-array', 'anyof']);

it('states no blank where a rule refuses one, in either order', function (bool $refuseFirst): void {
    expect(($this->build)(['type' => 'string', 'format' => 'uuid'], true, true, refuse: true, refuseFirst: $refuseFirst))
        ->toBe(['type' => ['string', 'null'], 'format' => 'uuid']);
})->with(['refused first' => true, 'refused last' => false]);

it('reaches no consumer: every OpenAPI emitter strips the fact with the rest of x-docuccino', function (string $emitter): void {
    $field = ($this->build)(['type' => 'string', 'enum' => ['open']], true, true);
    $document = UirDocument::fromArray([
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'paths' => [],
        'components' => ['schemas' => ['Ticket' => ['type' => 'object', 'properties' => ['code' => $field]]]],
    ]);

    expect((new $emitter)->emit($document))->not->toContain('blankAsNull')->not->toContain('x-docuccino');
})->with([OpenApi32Emitter::class, OpenApi31DownlevelEmitter::class, OpenApi30DownlevelEmitter::class]);
