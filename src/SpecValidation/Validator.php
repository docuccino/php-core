<?php

declare(strict_types=1);

namespace Docuccino\Core\SpecValidation;

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Spec\UirSpec;
use Opis\JsonSchema\Errors\ErrorFormatter;
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
        return dirname(__DIR__, 2).'/resources/spec/uir/'.UirSpec::minor();
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function validate(array $document): ValidationResult
    {
        $json = $this->serializer->serialize($this->canonicalizer->canonicalize($document));

        $data = json_decode($json, false, flags: JSON_THROW_ON_ERROR);

        // No resolver registration and no fetch: every `$id` the schema references is a resource
        // embedded in the schema itself, which is the property `SchemaSelfContainmentTest` holds.
        // So a reference that does not resolve says the schema FILE is wrong, not the document — and
        // opis names only the URI, which reads like a failed download of the thing nothing fetches.
        try {
            $result = (new OpisValidator)->validate($data, $this->loadSchema($this->schemaPath));
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

        $error = $result->error();

        if ($error === null) {
            return ValidationResult::invalid([new ValidationError('', 'Document failed schema validation.')]);
        }

        $formatted = (new ErrorFormatter)->format($error, true);

        $errors = [];
        foreach ($formatted as $pointer => $messages) {
            foreach ((array) $messages as $message) {
                $errors[] = new ValidationError((string) $pointer, is_string($message) ? $message : (string) json_encode($message));
            }
        }

        if ($errors === []) {
            $errors[] = new ValidationError('', 'Document failed schema validation.');
        }

        return ValidationResult::invalid($errors);
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
