<?php

declare(strict_types=1);

namespace Docuccino\Core\Validation;

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator as OpisValidator;
use RuntimeException;

/**
 * Validates a UIR document array against the bundled `spec/uir/1.0/schema.json`.
 *
 * opis/json-schema is the chosen validator: it is the only actively maintained PHP library
 * with complete JSON Schema draft 2020-12 support (the OAS 3.2 dialect base), and it is
 * dependency-light (no illuminate/symfony). The document is canonicalised and serialised
 * first, then decoded to the object graph opis expects — so validation runs against the
 * exact bytes the emitter would produce, and empty objects present as `{}` not `[]`.
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
        // Resolve PACKAGE-relative (packages/core/src/Validation → packages/core), never
        // monorepo-relative: the schema ships inside the package's resources/ so it resolves
        // identically from a vendor/docuccino/core install. The canonical authoring copy at the
        // monorepo root (spec/uir/1.0/schema.json) is synced here by `composer sync-schema`; a
        // byte-equality drift guard keeps the two identical (see SchemaShippingTest).
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
