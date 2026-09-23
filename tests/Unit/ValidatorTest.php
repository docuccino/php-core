<?php

declare(strict_types=1);

use Docuccino\Core\SpecValidation\Validator;

beforeEach(function (): void {
    $this->validator = new Validator;
});

it('validates the worked example from the design doc', function (): void {
    $result = $this->validator->validate(workedExample());

    expect($result->isValid())->toBeTrue()
        ->and($result->errors)->toBe([]);
});

it('rejects a parameter that states neither schema nor content', function (): void {
    $doc = workedExample();
    unset($doc['paths']['/api/v1/forms']['get']['parameters'][0]['schema']);

    $result = $this->validator->validate($doc);

    expect($result->isValid())->toBeFalse()
        ->and($result->errors)->not->toBe([]);
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

it('rejects a document missing the required openapi version', function (): void {
    $doc = workedExample();
    unset($doc['openapi']);

    $result = $this->validator->validate($doc);

    expect($result->isValid())->toBeFalse();
});

/*
 * The two members UIR 2.0 moved under `x-docuccino.generator`. They are not merely unused now: the
 * OpenAPI Object closes against them, so a document carrying either at its root is not an OpenAPI
 * document, and the schema has to say so rather than pass them through as it does an `x-` member.
 */
it('rejects a document carrying the root members UIR 2.0 moved into the extension', function (string $member, string $value): void {
    $doc = workedExample();
    $doc[$member] = $value;

    expect($this->validator->validate($doc)->isValid())->toBeFalse();
})->with([
    'schema url' => ['$schema', 'https://spec.docuccino.app/uir/2.0/schema.json'],
    'spec version' => ['uir', '2.0.0'],
]);

/*
 * Self-containment, executed through the product's own path. A user vendors `schema.json` and nothing
 * else — no extension schema beside it, no registration step, no network — and it has to validate. The
 * split into two published files silently cost this: the document schema referenced the extension by
 * absolute `$id`, so a lone copy resolved that reference over HTTPS or failed outright, and only
 * Docuccino's own bundled registration hid it. The extension schema is embedded as a resource of its
 * own now, which is what this proves.
 */
it('validates against a lone schema.json, with nothing beside it and nothing registered', function (): void {
    $alone = sys_get_temp_dir().'/docuccino-lone-schema-'.uniqid();
    @mkdir($alone, 0755, true);
    copy(Validator::defaultSchemaPath(), $alone.'/schema.json');

    // The directory holds ONE file, so a reference leaving it has nowhere local to land.
    expect(array_values(array_diff((array) scandir($alone), ['.', '..'])))->toBe(['schema.json'])
        ->and((new Validator($alone.'/schema.json'))->validate(workedExample())->isValid())->toBeTrue();

    // And the copy really is consulted rather than passing everything: the same lone file refuses a
    // member `x-docuccino` does not define, which only the embedded extension resource can decide.
    $undefined = workedExample();
    $undefined['paths']['/api/v1/forms']['get']['x-docuccino']['generatedAt'] = '2026-08-01T00:00:00Z';

    expect((new Validator($alone.'/schema.json'))->validate($undefined)->isValid())->toBeFalse();

    unlink($alone.'/schema.json');
    @rmdir($alone);
});

/*
 * The other side of the `$schemaPath` seam: what happens when the file it points at is not a schema.
 * Every case FAILS CLOSED — nothing returns "valid" over something it could not read — and every
 * message names the PATH, because the reader is someone whose vendored or hand-edited copy is wrong
 * and the file is the only thing they can act on. The last one is why the message is stated here
 * rather than left to opis, whose own names an https URI and nothing else: it reads as a failed
 * download of the resource this design embeds precisely so that nothing is ever fetched.
 */
it('refuses a schema it cannot read, and says which file', function (string $case, string $expected): void {
    $directory = sys_get_temp_dir().'/docuccino-unreadable-schema-'.uniqid();
    @mkdir($directory, 0755, true);
    $path = $directory.'/schema.json';

    if ($case === 'not an object') {
        file_put_contents($path, '["not a schema"]');
    }

    if ($case === 'resource renamed') {
        // The bundled schema with the embedded resource's own `$id` changed and nothing else, so
        // every `$ref` to it goes unresolved — what a hand-edit, or a sync that wrote one file of
        // the family and not the other, leaves behind.
        $extension = json_decode((string) file_get_contents(Validator::defaultExtensionSchemaPath()), true, flags: JSON_THROW_ON_ERROR);
        $id = is_array($extension) ? (string) $extension['$id'] : '';
        $text = (string) file_get_contents(Validator::defaultSchemaPath());

        expect($id)->not->toBe('')->and($text)->toContain('"$id": "'.$id.'"');

        file_put_contents($path, str_replace('"$id": "'.$id.'"', '"$id": "'.$id.'.renamed"', $text));
    }

    foreach ([$expected, $path] as $named) {
        expect(fn () => (new Validator($path))->validate(workedExample()))->toThrow(RuntimeException::class, $named);
    }

    @unlink($path);
    @rmdir($directory);
})->with([
    'no file there at all' => ['missing', 'UIR schema not found at'],
    'JSON, but not an object' => ['not an object', 'UIR schema is not a JSON object'],
    'the embedded resource renamed' => ['resource renamed', 'does not resolve'],
]);
