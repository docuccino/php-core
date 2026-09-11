<?php

declare(strict_types=1);

use Docuccino\Core\SpecValidation\SchemaFindings;
use Docuccino\Core\Tests\Support\PostmanSchema;
use Opis\JsonSchema\Validator;

/**
 * The oracle over the Postman collection, held to the same two pins the OpenAPI meta-schemas carry —
 * and made to prove it still refuses something.
 *
 * `PostmanEmitterTest` validates nine emitted collections against this schema and they all pass. That
 * says nothing on its own: a schema edited down to `{}` would pass them too, and so would a dialect
 * lift that dropped a constraint on its way through. So the file is pinned by identity and by content,
 * and the refusals below are EXECUTED rather than described — the collection the oracle should refuse
 * is written out and the refusal observed.
 */

/**
 * A throwaway copy of the vendored schema, edited by $edit, for the refusal tests. Returns its path.
 *
 * @param  callable(string): string  $edit
 */
function tamperedPostmanSchema(string $name, callable $edit): string
{
    $path = sys_get_temp_dir().'/docuccino-postman-schema-'.$name.'-'.getmypid().'.json';

    file_put_contents($path, $edit((string) file_get_contents(PostmanSchema::path())));

    return $path;
}

/**
 * IDENTITY. Checked against what the file declares about itself, which is what catches the file being
 * swapped for another version's — v2.0.0 is a real schema at a URI one character different.
 */
it('pins the vendored Postman schema to the id it declares', function (): void {
    $decoded = PostmanSchema::decode();

    expect($decoded)->toBeInstanceOf(stdClass::class);

    // Draft-04, so `id` rather than `$id`.
    expect($decoded->id ?? null)->toBe(PostmanSchema::PUBLISHED)
        ->and(PostmanSchema::PUBLISHED)->toContain('v2.1.0');
});

/**
 * CONTENT, which is the pin identity cannot give: `id` is exactly the field an editor of the other
 * 55 KB would leave alone.
 *
 * The digest is the one Postman serves at `https://schema.postman.com/json/collection/v2.1.0/collection.json`,
 * taken from that URI rather than from the copy on disk — a pin derived from the file it pins agrees
 * with whatever that file happens to say. The size floor is a second floor under the pin itself, so a
 * truncated or placeholder file re-pinned to its own new digest fails here instead of passing quietly.
 */
it('pins the vendored Postman schema by content, not only by the id it declares', function (): void {
    $path = PostmanSchema::path();

    expect(is_file($path))->toBeTrue(PostmanSchema::FILE)
        ->and(hash_file('sha256', $path))->toBe(PostmanSchema::SHA256)
        ->and(filesize($path))->toBeGreaterThan(PostmanSchema::MINIMUM_BYTES);
});

/** Both pins hold on the file actually shipped, so the refusals below are the only red the suite sees. */
it('accepts the vendored schema it pins', function (): void {
    expect(PostmanSchema::verify(PostmanSchema::path()))->toBe([]);
});

/**
 * The refusals, executed. Each writes the file the pin is supposed to reject and observes the rejection,
 * because "the digest would catch that" is exactly the claim that goes untested until it is wrong.
 */
it('refuses a vendored schema that is not the one it pins', function (string $name, callable $edit, string $expected): void {
    $path = tamperedPostmanSchema($name, $edit);

    try {
        $findings = PostmanSchema::verify($path);

        expect($findings)->not->toBe([])
            ->and(implode("\n", $findings))->toContain($expected);
    } finally {
        @unlink($path);
    }
})->with([
    // One byte of prose, nowhere near anything that constrains an instance: identity still checks out.
    'one edited byte' => [
        'edited',
        static fn (string $raw): string => str_replace('A collection', 'A colleXtion', $raw),
        'not the pinned '.PostmanSchema::SHA256,
    ],
    // The swap identity exists to catch: the previous major, under its own name.
    'another version of the schema' => [
        'swapped',
        static fn (string $raw): string => str_replace(PostmanSchema::PUBLISHED, 'https://schema.getpostman.com/json/collection/v2.0.0/', $raw),
        'not the pinned '.PostmanSchema::PUBLISHED,
    ],
    // A schema edited down to something that accepts everything, re-pinned or not.
    'a schema stripped to nothing' => [
        'stripped',
        static fn (string $raw): string => '{"id":"'.PostmanSchema::PUBLISHED.'"}',
        'under the '.PostmanSchema::MINIMUM_BYTES,
    ],
    'not JSON at all' => [
        'garbage',
        static fn (string $raw): string => 'this is not a schema',
        'does not decode to a JSON object',
    ],
]);

it('refuses a vendored schema that is not there', function (): void {
    $findings = PostmanSchema::verify(sys_get_temp_dir().'/docuccino-postman-schema-absent.json');

    expect($findings)->toHaveCount(1)
        ->and($findings[0])->toContain('missing');
});

/**
 * And the refusal on the path that matters: loading. A `verify()` nobody consults would leave the
 * validator reading whatever is on disk, so the loader is shown refusing rather than assumed to.
 */
it('will not build a validator on a schema it does not recognise', function (): void {
    $path = tamperedPostmanSchema('unbuildable', static fn (string $raw): string => str_replace('A collection', 'A colleXtion', $raw));

    try {
        expect(static fn () => PostmanSchema::build($path))
            ->toThrow(RuntimeException::class, 'cannot be trusted as an oracle');
    } finally {
        @unlink($path);
    }

    // The control: the same call over the vendored copy builds.
    expect(PostmanSchema::build(PostmanSchema::path()))->toBeInstanceOf(Validator::class);
});

/**
 * The oracle's bite. `PostmanEmitterTest` proves nine collections pass; nothing proved anything fails,
 * which is the state a neutered schema and a working one are indistinguishable in.
 *
 * The shapes are stated from the v2.1.0 format rather than from our emitter: a collection carries
 * `info` (with a `name` and a `schema`) and an `item` sequence, and an item's `request` is not
 * optional. Each is a defect a client would actually hit — Postman refuses to import the file, or
 * imports a request that cannot be sent.
 */
it('refuses a collection that is not v2.1.0-shaped', function (string $case, callable $break, string $expected): void {
    /** @var stdClass $collection */
    $collection = json_decode(
        (string) file_get_contents(dirname(__DIR__).'/Fixtures/golden/postman-surface.postman.json'),
        flags: JSON_THROW_ON_ERROR,
    );

    $findings = SchemaFindings::of(PostmanSchema::validator(), $break($collection), PostmanSchema::URI);

    expect($findings)->not->toBe([], $case)
        ->and(implode("\n", $findings))->toContain($expected);
})->with([
    'no info' => ['no info', static function (stdClass $c): stdClass {
        unset($c->info);

        return $c;
    }, 'The required properties (info) are missing'],
    'no info.name' => ['no info.name', static function (stdClass $c): stdClass {
        unset($c->info->name);

        return $c;
    }, 'The required properties (name) are missing'],
    'no info.schema' => ['no info.schema', static function (stdClass $c): stdClass {
        unset($c->info->schema);

        return $c;
    }, 'The required properties (schema) are missing'],
    'no item' => ['no item', static function (stdClass $c): stdClass {
        unset($c->item);

        return $c;
    }, 'The required properties (item) are missing'],
    'item is not a sequence' => ['item is not a sequence', static function (stdClass $c): stdClass {
        $c->item = 'not a sequence';

        return $c;
    }, 'must match the type: array'],
    'an item with no request' => ['an item with no request', static function (stdClass $c): stdClass {
        $item = $c->item;
        expect($item)->toBeArray()->not->toBe([]);
        unset($item[0]->request, $item[0]->item);

        return $c;
    }, 'The required properties (request) are missing'],
]);

/** The control the refusals need: the unbroken collection they are edits of passes. */
it('accepts the collection the refusals are edits of', function (): void {
    $collection = json_decode(
        (string) file_get_contents(dirname(__DIR__).'/Fixtures/golden/postman-surface.postman.json'),
        flags: JSON_THROW_ON_ERROR,
    );

    expect(SchemaFindings::of(PostmanSchema::validator(), $collection, PostmanSchema::URI))->toBe([]);
});
