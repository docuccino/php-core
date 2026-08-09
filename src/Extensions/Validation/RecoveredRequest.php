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
 * diagnostics are drained, then body verbs (POST/PUT/PATCH) get a request body under the schema's
 * media type and read verbs (GET/HEAD) get query parameters. Its input is a core value object
 * (a ValidationSchema), not framework code — the HTTP verb → body-or-query decision is generic OAS
 * assembly, so it lives in core; the adapter's recovery extensions (FormRequest/inline, spatie-Data,
 * laravel-actions) each recover a rule set differently, then converge on this one applier, passing
 * only the provenance producer that distinguishes them.
 *
 * Component hoisting (design §5): when the body was recovered from a single source CLASS (a spatie
 * Data class, a FormRequest, an action `rules()` class), the class-derived body schema is hoisted to a
 * `components.schemas` entry the operation `$ref`s — named by the class short name / `#[SchemaName]`,
 * deduped so the same class across N operations yields ONE component. An inline `validate()`/
 * `Validator::make()` body has no source class to name honestly, so it stays inline. So does an
 * operation carrying a `#[BodyParameter]` — that attribute patches individual body PROPERTIES at a
 * higher layer, which cannot be expressed through a `$ref` without mutating the shared component
 * (the attribute extension reads `schema.properties`, absent on a `$ref`); dereferencing keeps its
 * $ref honest and lets the patch merge onto the full inline body. Call-site partials
 * (`include`/`exclude`/`only`/`except`) shape the RESPONSE via query parameters, not the request body,
 * so they never force the request body inline.
 *
 * Request- and response-side components of the same class carry DISTINCT diff identities (the request
 * body's is the FQCN plus a `#request` discriminator), so a class used on both sides never dedupes a
 * request rules-shape into a response property-shape (or vice versa) by identity: structurally-equal
 * shapes still collapse to one component via the registry's structural dedupe, and genuinely different
 * shapes yield two deterministically-named components.
 */
final class RecoveredRequest
{
    private const READ_VERBS = ['get', 'head'];

    /**
     * Drain the schema's diagnostics and write it as a request body (write verbs) or query parameters
     * (read verbs), attributed to `integration:<producer>`. `$sourceClass` is the single class the body
     * was recovered from (a Data class / FormRequest / action `rules()` class) so the body can hoist to
     * a named component; null (an inline `validate()`) keeps the body inline.
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
     * The body schema to write: a `$ref` to a hoisted component when the body came from a single source
     * class and this operation does not deviate from that class-derived schema, else the inline schema.
     *
     * @return array<string, mixed>
     */
    private function bodySchema(RouteContext $context, ValidationSchema $result, ?string $sourceClass): array
    {
        if ($sourceClass === null || $this->deviates($context)) {
            return $result->schema;
        }

        $name = SchemaIdentity::name($sourceClass) ?? Fqcn::short($sourceClass);

        // A request-scoped diff identity, distinct from the response-side `sch:<FQCN>`, so the two never
        // dedupe across the request/response divide by identity alone. It honours `#[SchemaId]` exactly as
        // the response side does ({@see ComponentHoist}/{@see EnumSchema}) so a pinned class stays
        // rename-stable on BOTH sides — only the `#request` discriminator keeps them distinct.
        $id = (SchemaIdentity::id($sourceClass) ?? $sourceClass).'#request';

        return $context->components->reference($name, $result->schema, $id);
    }

    /**
     * Whether a higher layer patches this operation's body relative to the canonical class-derived
     * schema — a `#[BodyParameter]` attribute, which the attribute-layer body extension applies by
     * reading `schema.properties`; a `$ref` has none, so that op keeps its body inline (dereferenced).
     */
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
