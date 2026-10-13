<?php

declare(strict_types=1);

namespace Docuccino\Core\SpecValidation;

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Spec\UirSpec;
use Opis\JsonSchema\Exceptions\UnresolvedReferenceException;
use Opis\JsonSchema\Validator as OpisValidator;
use RuntimeException;

/**
 * Validates a UIR document array against the bundled document schema, which embeds the extension
 * schema as a resource of its own — so `x-docuccino` is checked against the same bytes a third party
 * would apply to a document of their own, with nothing to register and nothing to fetch.
 *
 * opis/json-schema is the only maintained PHP library with complete JSON Schema draft 2020-12
 * support (the OAS 3.2 dialect base) and it pulls in no illuminate/symfony. The document is
 * canonicalised and serialised before being decoded to opis's object graph, so validation sees the
 * exact bytes the emitter would write and empty objects show up as `{}`, not `[]`.
 *
 * The namespace says "spec" to keep this apart from `Extensions\Validation`, which is about a request's
 * validation rules — an unrelated meaning of the same word.
 *
 * @internal
 */
final class Validator
{
    private readonly string $schemaPath;

    /**
     * $schemaPath points at a document schema other than the bundled one. Nothing in the product
     * passes it: it is the seam self-containment is executed through, because the only way to prove a
     * schema needs nothing beside it is to run it with nothing beside it.
     */
    public function __construct(
        ?string $schemaPath = null,
        private readonly Canonicalizer $canonicalizer = new Canonicalizer,
        private readonly CanonicalJsonSerializer $serializer = new CanonicalJsonSerializer,
    ) {
        $this->schemaPath = $schemaPath ?? self::defaultSchemaPath();
    }

    public static function defaultSchemaPath(): string
    {
        // Package-relative, never monorepo-relative — the schema ships in the package's resources/
        // so this resolves the same from a vendor/docuccino/core install. `composer sync-schema`
        // copies the authoring originals from spec/uir/<version>/, and SchemaShippingTest guards the drift.
        return self::schemaDirectory().'/schema.json';
    }

    /**
     * The extension schema beside it: `x-docuccino` alone, published standalone so a third party can
     * apply it to ANY OpenAPI document. The document schema embeds a copy rather than referencing
     * this one, so nothing here is on the path a validation run takes.
     */
    public static function defaultExtensionSchemaPath(): string
    {
        return self::schemaDirectory().'/extension.schema.json';
    }

    private static function schemaDirectory(): string
    {
        return self::schemaRoot().'/'.UirSpec::minor();
    }

    private static function schemaRoot(): string
    {
        return dirname(__DIR__, 2).'/resources/spec/uir';
    }

    /**
     * The document schema this package ships for $version (`major.minor`), or null where it ships none.
     * Every published version is served forever, so a document written to an older one is checked against
     * the schema it was written to rather than against the current one.
     */
    public static function documentSchemaPathFor(string $version): ?string
    {
        $path = self::schemaRoot().'/'.$version.'/schema.json';

        return in_array($version, self::vendoredVersions(), true) && is_file($path) ? $path : null;
    }

    /**
     * Every `major.minor` this package ships a document schema for, oldest first.
     *
     * @return list<string>
     */
    public static function vendoredVersions(): array
    {
        $versions = [];
        foreach ((array) glob(self::schemaRoot().'/*/schema.json') as $file) {
            $version = basename(dirname((string) $file));

            if (preg_match('/^\d+\.\d+$/', $version) === 1) {
                $versions[] = $version;
            }
        }

        usort($versions, static fn (string $a, string $b): int => version_compare($a, $b));

        return $versions;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function validate(array $document): ValidationResult
    {
        // Validated as it is published: the bytes the full export writes, not the shape a build holds.
        $json = (new UirEmitter($this->canonicalizer, $this->serializer))->emitArray($document);

        $data = json_decode($json, false, flags: JSON_THROW_ON_ERROR);

        return $this->validateGraph($data);
    }

    /**
     * The same check over a document already decoded to its object graph — maps as objects, so `{}` and
     * `[]` stay apart — and read as it stands. Nothing is canonicalised first, which is right for bytes
     * someone else wrote: canonicalising would repair what the check is meant to report.
     */
    public function validateGraph(mixed $data): ValidationResult
    {
        // No resolver registration and no fetch: every `$id` the schema references is a resource
        // embedded in the schema itself, which is the property `SchemaSelfContainmentTest` holds.
        // So a reference that does not resolve says the schema FILE is wrong, not the document — and
        // opis names only the URI, which reads like a failed download of the thing nothing fetches.
        $validator = SchemaFindings::collecting(new OpisValidator);

        try {
            $result = $validator->validate($data, $this->loadSchema($this->schemaPath));
        } catch (UnresolvedReferenceException $exception) {
            throw new RuntimeException(
                'UIR schema at '.$this->schemaPath.' does not resolve '.$exception->getRef().
                ': the resource it embeds under $defs is missing or carries a different $id.',
                previous: $exception,
            );
        }

        if ($result->isValid()) {
            return ValidationResult::valid();
        }

        // A failed validation always names at least one place; the fallback keeps "invalid" from ever
        // arriving with nothing to show, which would read as valid to anyone counting findings.
        return ValidationResult::invalid(
            SchemaFindings::fromError($result->error()) ?: [new Finding('/', null, 'Document failed schema validation.')],
        );
    }

    private function loadSchema(string $path): object
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('UIR schema not found at '.$path);
        }

        $decoded = json_decode($contents, false, flags: JSON_THROW_ON_ERROR);

        if (! is_object($decoded)) {
            throw new RuntimeException('UIR schema is not a JSON object: '.$path);
        }

        return $decoded;
    }
}
