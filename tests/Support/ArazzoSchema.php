<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Support;

use Opis\JsonSchema\Validator;
use RuntimeException;
use stdClass;

/**
 * The vendored Arazzo 1.1 schema, as the oracle every emitted workflow description is validated
 * against — and the two pins that say the oracle is still the schema it claims to be.
 *
 * The same pair {@see PostmanSchema} carries, for the same reasons. {@see PUBLISHED} is IDENTITY,
 * checked against what the file declares about itself, and catches the file being swapped for another
 * version's. {@see SHA256} is CONTENT, and catches an edit to a file that goes on declaring the same
 * id — which is the whole of what an editor of 32 KB of third-party JSON would leave alone. A weakened
 * schema fails nothing: it passes everything, quietly, and this file is the only structural oracle the
 * Arazzo emitter has.
 *
 * Unlike Postman's, this one IS served at a dated, immutable URI — the OAI publishes each schema
 * revision under its own date — so the identity pin is also a currency pin: a newer revision is a
 * different URL and a deliberate step to adopt, never something upstream can change underneath the
 * digest.
 *
 * Not listed in `php/core/NOTICE`, which covers what the PACKAGE redistributes: `/tests` is
 * `export-ignore`d, so an installed `vendor/docuccino/core` contains neither this file nor the schema.
 */
final class ArazzoSchema
{
    /** The vendored file, under `php/core/tests/Fixtures`. */
    public const string FILE = 'arazzo-1.1.schema.json';

    /** IDENTITY: the `$id` the file must declare about itself. */
    public const string PUBLISHED = 'https://spec.openapis.org/arazzo/1.1/schema/2026-04-15';

    /**
     * CONTENT: the SHA-256 of the bytes the OAI publishes, verified against the URL above rather than
     * against the copy on disk — a pin taken from the file it pins agrees with whatever the file says.
     */
    public const string SHA256 = '37be908409bdb2f7bffe61fa23685c7e84cbeebfafac475a1d01dbc50ff7ab9e';

    /** A truncated or placeholder file re-pinned to its own new digest fails this rather than passing. */
    public const int MINIMUM_BYTES = 25000;

    /** The only schema the file references from outside itself. */
    public const string JSON_SCHEMA_DIALECT = 'https://json-schema.org/draft/2020-12/schema';

    public static function path(): string
    {
        return dirname(__DIR__).'/Fixtures/'.self::FILE;
    }

    /**
     * Every way the file at $path is not the schema this class pins. Empty means both pins hold.
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

        $declared = $decoded->{'$id'} ?? null;

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
     * The validator, cached. It verifies the pins first and THROWS on a finding: an oracle that quietly
     * declines to run is worse than one that is absent, because the suite goes on reporting green for
     * the thing it stopped checking.
     */
    public static function validator(): Validator
    {
        static $validator = null;

        return $validator ??= self::build(self::path());
    }

    /**
     * The validator over the file at $path, unmemoised — taking the path is what lets the refusal above
     * be executed against a tampered file rather than asserted about one.
     */
    public static function build(string $path): Validator
    {
        $findings = self::verify($path);

        if ($findings !== []) {
            throw new RuntimeException(sprintf(
                "The vendored Arazzo schema is not the one pinned by %s, so it cannot be trusted as an oracle:\n  - %s",
                self::class,
                implode("\n  - ", $findings),
            ));
        }

        $validator = new Validator;
        $validator->setMaxErrors(50);

        // An oracle may not touch what it reads: opis writes schema `default`s INTO the instance otherwise.
        $validator->parser()->setOption('allowDefaults', false);

        // The one EXTERNAL reference the file makes: `#/$defs/schema` points at the draft 2020-12
        // meta-schema, for a workflow's `inputs`. Registered as the permissive schema rather than
        // fetched, so the oracle stays offline — and the position is not left unchecked by doing so,
        // because `inputs` is a Schema Object that the UIR schema constrains structurally on every
        // build, before any emission. What this oracle is for is the ARAZZO shape around it.
        $validator->resolver()?->registerRaw(true, self::JSON_SCHEMA_DIALECT);

        // Registered under the id it declares, which is also the id its internal `$ref`s resolve
        // against. No dialect lift: the OAI publishes draft 2020-12, which opis parses as it stands.
        $validator->resolver()?->registerRaw(self::decode($path), self::PUBLISHED);

        return $validator;
    }

    /** The file at $path, decoded. Defaults to the vendored copy. */
    public static function decode(?string $path = null): mixed
    {
        return json_decode((string) file_get_contents($path ?? self::path()), flags: JSON_THROW_ON_ERROR);
    }
}
