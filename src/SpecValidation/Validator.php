<?php

declare(strict_types=1);

namespace Docuccino\Core\SpecValidation;

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator as OpisValidator;
use RuntimeException;

/**
 * Validates a UIR document array against the bundled `spec/uir/1.0/schema.json`.
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
        // copies the authoring original from spec/uir/1.0/, and SchemaShippingTest guards the drift.
        return dirname(__DIR__, 2).'/resources/spec/uir/1.0/schema.json';
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function validate(array $document): ValidationResult
    {
        $json = $this->serializer->serialize($this->canonicalizer->canonicalize($document));

        $data = json_decode($json, false, flags: JSON_THROW_ON_ERROR);
        $schema = $this->loadSchema();

        $result = (new OpisValidator)->validate($data, $schema);

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

    private function loadSchema(): object
    {
        $contents = @file_get_contents($this->schemaPath);

        if ($contents === false) {
            throw new RuntimeException('UIR schema not found at '.$this->schemaPath);
        }

        $decoded = json_decode($contents, false, flags: JSON_THROW_ON_ERROR);

        if (! is_object($decoded)) {
            throw new RuntimeException('UIR schema is not a JSON object: '.$this->schemaPath);
        }

        return $decoded;
    }
}
