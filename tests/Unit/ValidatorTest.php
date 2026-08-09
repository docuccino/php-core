<?php

declare(strict_types=1);

use Docuccino\Core\Validation\Validator;

beforeEach(function (): void {
    $this->validator = new Validator;
});

it('validates the worked example from the design doc', function (): void {
    $result = $this->validator->validate(workedExample());

    expect($result->isValid())->toBeTrue()
        ->and($result->errors)->toBe([]);
});

it('rejects a document with an invalid provenance layer and reports where', function (): void {
    $doc = workedExample();
    $doc['paths']['/api/v1/forms']['get']['x-docuccino']['provenance'][0]['layer'] = 'bogus';

    $result = $this->validator->validate($doc);

    expect($result->isValid())->toBeFalse()
        ->and($result->errors)->not->toBe([]);

    expect(implode("\n", $result->messages()))->toContain('layer');
});

it('rejects an unknown member under x-docuccino provenance (strictly closed)', function (): void {
    $doc = workedExample();
    $doc['paths']['/api/v1/forms']['get']['x-docuccino']['provenance'][0]['generatedAt'] = '2026-08-01T00:00:00Z';

    $result = $this->validator->validate($doc);

    expect($result->isValid())->toBeFalse();
});

it('rejects a timestamp-like member inside an x-docuccino source record', function (): void {
    $doc = workedExample();
    $doc['paths']['/api/v1/forms']['get']['x-docuccino']['provenance'][0]['source']['timestamp'] = 123;

    $result = $this->validator->validate($doc);

    expect($result->isValid())->toBeFalse();
});

it('rejects a document missing the required uir version', function (): void {
    $doc = workedExample();
    unset($doc['uir']);

    $result = $this->validator->validate($doc);

    expect($result->isValid())->toBeFalse();
});
