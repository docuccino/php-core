<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation;

use Docuccino\Attributes\BodyParameter;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Schema\SchemaIdentity;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Support\Fqcn;

/**
 * Applies a {@see ValidationSchema} to an operation the one way every request-schema source shares:
 * drain diagnostics, then give body verbs (POST/PUT/PATCH) a request body under the schema's media
 * type and read verbs (GET/HEAD) query parameters. That verb → body-or-query decision is generic OAS
 * assembly, so it lives in core; the adapter's recovery extensions (FormRequest/inline, spatie-Data,
 * laravel-actions) each recover a rule set differently and then converge here, passing only the
 * provenance producer that tells them apart.
 *
 * Hoisting (design §5): a body recovered from a single source class hoists to a `components.schemas`
 * entry the operation `$ref`s — named by class short name or `#[SchemaName]`, deduped so one class
 * across N operations is one component. Two cases stay inline: an inline `validate()`/
 * `Validator::make()` body, which has no class to name honestly, and an operation carrying
 * `#[BodyParameter]`, because that attribute patches individual body properties by reading
 * `schema.properties` — absent on a `$ref` — and dereferencing beats mutating the shared component.
 * Call-site partials (`include`/`exclude`/`only`/`except`) shape the response via query parameters,
 * not the body, so they never force it inline.
 */
final class RecoveredRequest
{
    private const READ_VERBS = ['get', 'head'];

    /**
     * Drain the schema's diagnostics and write it as a request body (write verbs) or query parameters
     * (read verbs), attributed to `integration:<producer>`. Pass the single class the body was
     * recovered from as `$sourceClass` so it can hoist; null (an inline `validate()`) stays inline.
     */
    public function apply(OperationDraft $operation, RouteContext $context, ValidationSchema $result, string $producer, ?string $sourceClass = null): void
    {
        foreach ($result->diagnostics as $diagnostic) {
            $context->components->addDiagnostic($diagnostic);
        }

        $contribution = Contribution::integration($producer, $context->actionSource());

        if (in_array($context->httpMethod(), self::READ_VERBS, true)) {
            $this->applyQueryParameters($operation, $result, $contribution);

            return;
        }

        $this->applyRequestBody($operation, $context, $result, $contribution, $sourceClass);
    }

    private function applyRequestBody(OperationDraft $operation, RouteContext $context, ValidationSchema $result, Contribution $contribution, ?string $sourceClass): void
    {
        $required = is_array($result->schema['required'] ?? null) && $result->schema['required'] !== [];

        $schema = $this->bodySchema($context, $result, $sourceClass);

        $body = ['content' => [$result->mediaType => ['schema' => $schema]]];
        if ($required) {
            $body = ['required' => true] + $body;
        }

        $operation->set('requestBody', $body, $contribution);
    }

    /**
     * A `$ref` to a hoisted component when the body came from a single source class and this operation
     * doesn't deviate from the class-derived schema; the inline schema otherwise.
     *
     * @return array<string, mixed>
     */
    private function bodySchema(RouteContext $context, ValidationSchema $result, ?string $sourceClass): array
    {
        if ($sourceClass === null || $this->deviates($context)) {
            return $result->schema;
        }

        $name = SchemaIdentity::name($sourceClass) ?? Fqcn::short($sourceClass);

        // A request-scoped diff identity, distinct from the response side's `sch:<FQCN>`, so a class used
        // on both sides never dedupes a rules-shape into a property-shape by identity alone —
        // structurally-equal shapes still collapse via the registry's structural dedupe. It honours
        // `#[SchemaId]` like the response side does, so a pinned class stays rename-stable on both.
        $id = (SchemaIdentity::id($sourceClass) ?? $sourceClass).'#request';

        return $context->components->reference($name, $result->schema, $id);
    }

    /** Whether `#[BodyParameter]` patches this body — which forces it inline; see the class header. */
    private function deviates(RouteContext $context): bool
    {
        return $context->attributes->all(BodyParameter::class) !== [];
    }

    private function applyQueryParameters(OperationDraft $operation, ValidationSchema $result, Contribution $contribution): void
    {
        $properties = $result->schema['properties'] ?? null;
        if (! is_array($properties)) {
            return;
        }

        $required = is_array($result->schema['required'] ?? null) ? $result->schema['required'] : [];

        foreach ($properties as $name => $schema) {
            if (! is_string($name) || ! is_array($schema)) {
                continue;
            }

            $parameter = $operation->parameter('query', $name);
            $parameter->setRequired(in_array($name, $required, true), $contribution);

            $description = $schema['description'] ?? null;
            if (is_string($description)) {
                $parameter->setDescription($description, $contribution);
                unset($schema['description']);
            }

            foreach ($schema as $keyword => $value) {
                $parameter->schema()->set((string) $keyword, $value, $contribution);
            }
        }
    }
}
