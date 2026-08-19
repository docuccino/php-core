<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation;

use Docuccino\Attributes\BodyParameter;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Extensions\Schema\MockHints;
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

        $result = $this->withMockHints($result, $context, $sourceClass);

        $contribution = Contribution::integration($producer, $context->actionSource());

        if (in_array($context->httpMethod(), self::READ_VERBS, true)) {
            $this->applyQueryParameters($operation, $result, $contribution);

            return;
        }

        $this->applyRequestBody($operation, $context, $result, $contribution, $sourceClass);
    }

    /**
     * The schema with whatever `#[Mock]` the source class declares written onto it. A validated field
     * is named by rules rather than by a property, so it is the class-level form that reaches one —
     * and the class file is recorded as a dependency, since adding the attribute has to invalidate.
     */
    private function withMockHints(ValidationSchema $result, RouteContext $context, ?string $sourceClass): ValidationSchema
    {
        if ($sourceClass === null) {
            return $result;
        }

        $context->recordDependencyFiles(DeclarationFiles::of($sourceClass));

        [$schema, $diagnostics] = MockHints::apply($result->schema, $sourceClass);

        foreach ($diagnostics as $diagnostic) {
            $context->components->addDiagnostic($diagnostic);
        }

        return new ValidationSchema($schema, $result->mediaType);
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
        foreach (self::queryLeaves($result->schema, '') as [$name, $schema, $required]) {
            $parameter = $operation->parameter('query', $name);
            $parameter->setRequired($required, $contribution);

            // A hint is an x-docuccino member, not a schema keyword — it travels on the draft rather
            // than through the guard, which would publish it as a keyword of that name.
            $docuccino = $schema['x-docuccino'] ?? null;
            unset($schema['x-docuccino']);
            if (is_array($docuccino) && is_array($docuccino['mock'] ?? null)) {
                /** @var array<string, mixed> $mock */
                $mock = $docuccino['mock'];
                $parameter->schema()->assignMock($mock);
            }

            $description = $schema['description'] ?? null;
            if (is_string($description)) {
                $parameter->setDescription($description, $contribution);
                unset($schema['description']);
            }

            // A container can't be named as a bracketed leaf, so it has to say on the parameter itself
            // that its members are bracketed — form (the query default) would document `?id=` for what is
            // really `?items[0][id]=`.
            if (self::isContainer($schema)) {
                $parameter->set('style', 'deepObject', $contribution);
                $parameter->set('explode', true, $contribution);
            }

            foreach ($schema as $keyword => $value) {
                $parameter->schema()->set((string) $keyword, $value, $contribution);
            }
        }
    }

    /**
     * The query parameters an object schema flattens to, as `[name, schema, required]`. A nested field is
     * a bracketed leaf, because `filter.radius_lat` in validator syntax IS `filter[radius_lat]` on the
     * wire — which also puts it on the same parameter identity a bracketing integration writes, so the
     * two merge instead of duplicating.
     *
     * @param  array<array-key, mixed>  $schema
     * @return list<array{0: string, 1: array<array-key, mixed>, 2: bool}>
     */
    private static function queryLeaves(array $schema, string $prefix): array
    {
        $properties = $schema['properties'] ?? null;
        if (! is_array($properties)) {
            return [];
        }

        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

        $leaves = [];
        foreach ($properties as $key => $child) {
            if (! is_string($key) || ! is_array($child)) {
                continue;
            }

            $name = $prefix === '' ? $key : $prefix.'['.$key.']';

            $members = $child['properties'] ?? null;
            if (is_array($members) && $members !== []) {
                foreach (self::queryLeaves($child, $name) as $leaf) {
                    $leaves[] = $leaf;
                }

                continue;
            }

            $leaves[] = [$name, $child, in_array($key, $required, true)];
        }

        return $leaves;
    }

    /**
     * Whether a schema describes a structure whose members ride in the query string as brackets.
     *
     * @param  array<array-key, mixed>  $schema
     */
    private static function isContainer(array $schema): bool
    {
        if (isset($schema['properties']) || isset($schema['items'])) {
            return true;
        }

        $type = $schema['type'] ?? null;
        $types = is_array($type) ? $type : [$type];

        return in_array('object', $types, true) || in_array('array', $types, true);
    }
}
