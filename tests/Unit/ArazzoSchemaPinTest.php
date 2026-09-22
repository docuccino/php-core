<?php

declare(strict_types=1);

use Docuccino\Core\Tests\Support\ArazzoSchema;
use Opis\JsonSchema\Validator;

/**
 * The oracle over the emitted Arazzo description, held to the same two pins the Postman schema and the
 * OpenAPI meta-schemas carry — and made to prove it still refuses a file that is not the one it pins.
 *
 * `ArazzoEmitterTest` validates the emitted description against this schema and shows it refusing six
 * shapes Arazzo forbids. That is the oracle's BITE. These are the pins that say the oracle is still the
 * schema it claims to be: a file edited down passes everything quietly, and a suite goes on reporting
 * green for the thing it stopped checking.
 */

/**
 * A throwaway copy of the vendored schema, edited by $edit, for the refusal tests. Returns its path.
 *
 * @param  callable(string): string  $edit
 */
function tamperedArazzoSchema(string $name, callable $edit): string
{
    $path = sys_get_temp_dir().'/docuccino-arazzo-schema-'.$name.'-'.getmypid().'.json';

    file_put_contents($path, $edit((string) file_get_contents(ArazzoSchema::path())));

    return $path;
}

/**
 * IDENTITY — and here it is also CURRENCY, which the Postman pin cannot be. The OAI publishes each
 * schema revision at its own dated, immutable URI, so a newer revision is a different URL and a
 * deliberate step to adopt rather than something upstream can change under the digest.
 */
it('pins the vendored Arazzo schema to the id it declares', function (): void {
    $decoded = ArazzoSchema::decode();

    expect($decoded)->toBeInstanceOf(stdClass::class)
        ->and($decoded->{'$id'} ?? null)->toBe(ArazzoSchema::PUBLISHED)
        ->and(ArazzoSchema::PUBLISHED)->toContain('/arazzo/1.1/');
});

/** CONTENT, which is the pin identity cannot give: `$id` is the one field an edit would leave alone. */
it('pins the vendored Arazzo schema by content, not only by the id it declares', function (): void {
    $path = ArazzoSchema::path();

    expect(is_file($path))->toBeTrue(ArazzoSchema::FILE)
        ->and(hash_file('sha256', $path))->toBe(ArazzoSchema::SHA256)
        ->and(filesize($path))->toBeGreaterThan(ArazzoSchema::MINIMUM_BYTES);
});

/** Both pins hold on the file actually shipped, so the refusals below are the only red the suite sees. */
it('accepts the vendored schema it pins', function (): void {
    expect(ArazzoSchema::verify(ArazzoSchema::path()))->toBe([]);
});

it('refuses a vendored schema that is not the one it pins', function (string $name, callable $edit, string $expected): void {
    $path = tamperedArazzoSchema($name, $edit);

    try {
        $findings = ArazzoSchema::verify($path);

        expect($findings)->not->toBe([])
            ->and(implode("\n", $findings))->toContain($expected);
    } finally {
        @unlink($path);
    }
})->with([
    // One byte of prose, nowhere near anything that constrains an instance: identity still checks out.
    'one edited byte' => [
        'edited',
        static fn (string $raw): string => str_replace('A summary of the purpose', 'A summXry of the purpose', $raw),
        'not the pinned '.ArazzoSchema::SHA256,
    ],
    // The swap identity exists to catch: the previous minor, under its own dated name.
    'another revision of the schema' => [
        'swapped',
        static fn (string $raw): string => str_replace(ArazzoSchema::PUBLISHED, 'https://spec.openapis.org/arazzo/1.0/schema/2025-10-15', $raw),
        'not the pinned '.ArazzoSchema::PUBLISHED,
    ],
    'a schema stripped to nothing' => [
        'stripped',
        static fn (string $raw): string => '{"$id":"'.ArazzoSchema::PUBLISHED.'"}',
        'under the '.ArazzoSchema::MINIMUM_BYTES,
    ],
    'not JSON at all' => [
        'garbage',
        static fn (string $raw): string => 'this is not a schema',
        'does not decode to a JSON object',
    ],
]);

it('refuses a vendored schema that is not there', function (): void {
    $findings = ArazzoSchema::verify(sys_get_temp_dir().'/docuccino-arazzo-schema-absent.json');

    expect($findings)->toHaveCount(1)
        ->and($findings[0])->toContain('missing');
});

/**
 * And the refusal on the path that matters: loading. A `verify()` nobody consults would leave the
 * validator reading whatever is on disk, so the loader is shown refusing rather than assumed to.
 */
it('will not build a validator on a schema it does not recognise', function (): void {
    $path = tamperedArazzoSchema('unbuildable', static fn (string $raw): string => str_replace('A summary of the purpose', 'A summXry of the purpose', $raw));

    try {
        expect(static fn () => ArazzoSchema::build($path))
            ->toThrow(RuntimeException::class, 'cannot be trusted as an oracle');
    } finally {
        @unlink($path);
    }

    // The control: the same call over the vendored copy builds.
    expect(ArazzoSchema::build(ArazzoSchema::path()))->toBeInstanceOf(Validator::class);
});
