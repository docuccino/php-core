<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Support;

use Docuccino\Core\SpecValidation\OpenApiMetaSchema;
use Opis\JsonSchema\Validator;
use RuntimeException;
use stdClass;

/**
 * The vendored Postman Collection v2.1.0 schema, as the oracle every emitted collection is validated
 * against — and the two pins that say the oracle is still the schema it claims to be.
 *
 * The pins answer different questions, the same pair {@see OpenApiMetaSchema}
 * carries over the OpenAPI meta-schemas. {@see PUBLISHED} is IDENTITY: it catches the file being swapped
 * for another version's, and it is checked against what the file DECLARES about itself. {@see SHA256} is
 * CONTENT: it catches an edit to a file that keeps declaring the same id, which is the whole of what an
 * editor of 55 KB of third-party JSON would leave alone.
 *
 * The content pin is the load-bearing one, and it is more load-bearing here than for the meta-schemas.
 * A weakened schema does not fail anything — it passes everything, quietly — and this file is the only
 * structural oracle the Postman collection has: the four byte-locked goldens pin the four documents that
 * have one, and this pins the shape of every collection the emitter can produce. One `"required"` list
 * shortened, or one `"type"` widened, and the emitter answers to nothing at those positions while the
 * suite stays green.
 *
 * Neither pin is CURRENCY, and the gap is wider than the meta-schemas'. Those are served at dated,
 * immutable URIs, so upstream cannot revise one under its own name; Postman serves this at an undated
 * `v2.1.0/` URI that can be revised in place. Nothing offline can see that happen. Noticing it is a
 * deliberate step when the vendored copy is next refreshed, not something the suite can do.
 *
 * Not listed in `php/core/NOTICE`, which covers what the PACKAGE redistributes: `/tests` is
 * `export-ignore`d, so an installed `vendor/docuccino/core` contains neither this file nor the schema.
 */
final class PostmanSchema
{
    /** The vendored file, under `php/core/tests/Fixtures`. */
    public const string FILE = 'postman-collection-v2.1.0.schema.json';

    /** IDENTITY: the `id` the file must declare about itself. */
    public const string PUBLISHED = 'https://schema.getpostman.com/json/collection/v2.1.0/';

    /**
     * CONTENT: the SHA-256 of the bytes Postman publishes, verified against
     * `https://schema.postman.com/json/collection/v2.1.0/collection.json` rather than against the copy
     * on disk — a pin taken from the file it pins agrees with whatever the file happens to say.
     */
    public const string SHA256 = '90000a561d00b1a06ee37a6d3606e911e1357f757d8d91afc690d87dadacb9b2';

    /** A truncated or placeholder file re-pinned to its own new digest fails this rather than passing. */
    public const int MINIMUM_BYTES = 50000;

    /** Where the lifted schema is registered for validation. */
    public const string URI = 'https://docuccino.test/postman-collection.json';

    public static function path(): string
    {
        return dirname(__DIR__).'/Fixtures/'.self::FILE;
    }

    /**
     * Every way the file at $path is not the schema this class pins. Empty means both pins hold.
     *
     * Findings rather than a boolean, because "the oracle is wrong" and "the oracle is missing" are
     * different things to go and do, and a caller that cannot say which has only moved the puzzle.
     *
     * @return list<string>
     */
    public static function verify(string $path): array
    {
        if (! is_file($path)) {
            return [sprintf('the vendored schema is missing from %s', $path)];
        }

        $findings = [];
        $size = (int) filesize($path);

        if ($size < self::MINIMUM_BYTES) {
            $findings[] = sprintf('it is %d bytes, under the %d a whole published schema runs to', $size, self::MINIMUM_BYTES);
        }

        $digest = hash_file('sha256', $path);

        if ($digest !== self::SHA256) {
            $findings[] = sprintf('its content is sha256 %s, not the pinned %s', $digest, self::SHA256);
        }

        $decoded = json_decode((string) file_get_contents($path));

        if (! $decoded instanceof stdClass) {
            $findings[] = 'it does not decode to a JSON object at all';

            return $findings;
        }

        $declared = $decoded->id ?? $decoded->{'$id'} ?? null;

        if ($declared !== self::PUBLISHED) {
            $findings[] = sprintf(
                'it declares the id %s, not the pinned %s',
                is_string($declared) ? $declared : 'nothing',
                self::PUBLISHED,
            );
        }

        return $findings;
    }

    /**
     * The validator, cached — parsing 55 KB per assertion is the cost worth avoiding.
     *
     * It verifies the pins first and THROWS on a finding. Loud is right here: this is the only
     * structural oracle over the collection, and an oracle that quietly declines to run is worse than
     * one that is absent, because the suite goes on reporting green for the thing it stopped checking.
     */
    public static function validator(): Validator
    {
        static $validator = null;

        return $validator ??= self::build(self::path());
    }

    /**
     * The validator over the file at $path, unmemoised — {@see Validator()} is this over the vendored
     * copy. Taking the path is what lets the refusal above be executed against a tampered file rather
     * than asserted about one.
     */
    public static function build(string $path): Validator
    {
        $findings = self::verify($path);

        if ($findings !== []) {
            throw new RuntimeException(sprintf(
                "The vendored Postman collection schema is not the one pinned by %s, so it cannot be trusted as an oracle:\n  - %s",
                self::class,
                implode("\n  - ", $findings),
            ));
        }

        $validator = new Validator;
        $validator->setMaxErrors(50);

        // An oracle may not touch what it reads: opis writes schema `default`s INTO the instance otherwise.
        $validator->parser()->setOption('allowDefaults', false);

        $validator->resolver()?->registerRaw(self::dialect(self::decode($path)), self::URI);

        return $validator;
    }

    /** The file at $path, decoded to the object graph the lift walks. Defaults to the vendored copy. */
    public static function decode(?string $path = null): mixed
    {
        return json_decode((string) file_get_contents($path ?? self::path()), flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Postman publishes the schema as draft-04, which opis does not parse. This lifts the dialect
     * WITHOUT touching anything that constrains an instance: the draft-04 `id` anchors are dropped
     * (every `$ref` in the file is a `#/definitions/…` pointer that resolves without them) and the
     * dialect is redeclared. Keys inside a `properties` or `definitions` map are names, not keywords,
     * so a property genuinely called `id` survives.
     */
    public static function dialect(mixed $node, bool $inMap = false): mixed
    {
        if (is_array($node)) {
            return array_map(static fn (mixed $value): mixed => self::dialect($value), $node);
        }

        if (! $node instanceof stdClass) {
            return $node;
        }

        $out = new stdClass;

        foreach (get_object_vars($node) as $key => $value) {
            if (! $inMap && ($key === 'id' || $key === '$schema') && is_string($value)) {
                continue;
            }

            $out->{$key} = self::dialect($value, ! $inMap && in_array($key, ['properties', 'definitions'], true));
        }

        if (! $inMap && isset($node->{'$schema'})) {
            $out->{'$schema'} = 'http://json-schema.org/draft-07/schema#';
        }

        return $out;
    }
}
