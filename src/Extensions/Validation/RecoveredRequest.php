<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation;

use Docuccino\Attributes\BodyParameter;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Schema\ClassAnnotations;
use Docuccino\Core\Extensions\Schema\ClassDeclarations;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Extensions\Schema\DocumentedDescriptions;
use Docuccino\Core\Extensions\Schema\DocumentedExamples;
use Docuccino\Core\Extensions\Schema\MockHints;
use Docuccino\Core\Extensions\Schema\PropertyAnnotations;
use Docuccino\Core\Extensions\Schema\SchemaClassAttributes;
use Docuccino\Core\Extensions\Schema\SchemaIdentity;
use Docuccino\Core\Extensions\Schema\UnusableBodyDeclarations;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Provenance\ClassNames;
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
 * `Validator::make()` body, which has no class to name honestly, and an OPERATION carrying
 * `#[BodyParameter]`, because that attribute patches individual body properties by reading
 * `schema.properties` — absent on a `$ref` — and dereferencing beats mutating the shared component.
 * Call-site partials (`include`/`exclude`/`only`/`except`) shape the response via query parameters,
 * not the body, so they never force it inline.
 *
 * A `#[BodyParameter]` written on the request TYPE is the other half of that attribute and does NOT
 * force the body inline: it says the same thing about every operation that accepts the type, so the
 * component is where it belongs and {@see withDeclarations()} writes it there, before the hoist. Which
 * matters to the CONSUMER rather than to the author — a named component is what a client generator
 * turns into a named type, and restating a fact about the type per action used to cost them the type on
 * every action that said it, plus any agreement between two operations that accept the same shape.
 */
final class RecoveredRequest
{
    private const READ_VERBS = ['get', 'head'];

    public function __construct(
        private readonly DeclaredBodyFields $fields = new DeclaredBodyFields,
    ) {}

    /**
     * Whether this context's verb sends recovered rules to a request BODY rather than to query
     * parameters — the same reading {@see apply()} branches on, asked from outside so nothing else has
     * to list the verbs a second time. A guard that recognised a different set from the write it guards
     * is a hole, and a declaration about a body is only about anything at all where a body is written.
     */
    public static function documentsBody(RouteContext $context): bool
    {
        return ! in_array($context->httpMethod(), self::READ_VERBS, true);
    }

    /**
     * Drain the schema's diagnostics and write it as a request body (write verbs) or query parameters
     * (read verbs), attributed to `integration:<producer>`. Pass the single class the body was
     * recovered from as `$sourceClass` so it can hoist; null (an inline `validate()`) stays inline.
     *
     * `$keys` maps a PHP property name to the key the REQUEST accepts it under, for a source class
     * whose wire names are remapped. Only the caller knows that mapping — it is the vendor package's
     * own, and an input name is not an output name — so a caller that has one owes it here, or a
     * declaration written on the property will be looked for under a key the body doesn't publish.
     *
     * @param  array<string, string>  $keys
     */
    public function apply(OperationDraft $operation, RouteContext $context, ValidationSchema $result, string $producer, ?string $sourceClass = null, array $keys = []): void
    {
        foreach ($result->diagnostics as $diagnostic) {
            $context->components->addDiagnostic($diagnostic);
        }

        [$result, $declaredRequired] = $this->withDeclarations($result, $context, $sourceClass, $keys);

        $contribution = Contribution::integration($producer, $context->actionSource());

        if (! self::documentsBody($context)) {
            $this->applyQueryParameters($operation, $result, $contribution);

            return;
        }

        $this->applyRequestBody($operation, $context, $result, $contribution, $sourceClass, $declaredRequired);
    }

    /**
     * The schema with everything the source class declares about its fields written onto it: the
     * docblock layer (a property's summary and `@example`), then the attribute layer over it, then
     * whatever `#[Mock]` the class states.
     *
     * A class that describes itself with `#[Description]` describes this body too, the way it describes
     * any component the hoist lifts — this one is assembled here rather than there, so it is written here.
     *
     * A validated field is named by its RULES, not by the property behind it, so none of this arrives
     * with the schema — it has to be matched back on afterwards, which is the whole reason a docblock
     * written on an input DTO used to reach the response side and not the request side. The order is
     * the precedence: docblock 30, then attribute 40 over it. `#[Mock]` is read class-level, since a
     * rule-named field has no property to hang it on.
     *
     * The class file is recorded as a dependency because adding any of these has to invalidate, and the
     * metadata's own files with it — inheritance answers a docblock as readily as an attribute.
     *
     * Every one of these reads a PROPERTY and writes to a published key, so `$keys` travels through all
     * four: a remapped field is the case where the two names differ, and looking under the wrong one
     * loses the declaration silently.
     *
     * Returns the schema plus whether a declaration proved the BODY is required. A field a declaration
     * marks required deep inside the body makes the body itself required, and only the root schema's
     * `required` list is read for that — so a nested one has to travel out here or the document says a
     * body carrying a required member may be left off entirely.
     *
     * @param  array<string, string>  $keys
     * @return array{0: ValidationSchema, 1: bool}
     */
    private function withDeclarations(ValidationSchema $result, RouteContext $context, ?string $sourceClass, array $keys): array
    {
        if ($sourceClass === null) {
            return [$result, false];
        }

        $context->recordDependencyFiles(DeclarationFiles::of($sourceClass));
        $this->observe($sourceClass, $context);

        $metadata = $context->engine->classMetadata(new ClassRef($sourceClass));
        $context->recordDependencyFiles($metadata->dependencyFiles);

        $schema = ClassAnnotations::applyTo($context->converter(), $result->schema, $sourceClass);
        $schema = DocumentedDescriptions::applyTo($schema, $metadata->properties, $keys);
        $schema = DocumentedExamples::applyTo($context->converter(), $schema, $sourceClass, $metadata->properties, $keys);

        [$schema, $diagnostics] = PropertyAnnotations::apply($schema, $sourceClass, $keys);
        [$schema, $hintDiagnostics] = MockHints::apply($schema, $sourceClass, $keys);

        [$schema, $declaredRequired, $fieldDiagnostics] = $this->fields->apply(
            $schema,
            self::declaredOn($sourceClass, $context),
            $context->converter(),
            ClassNames::publishable($sourceClass),
        );

        foreach ([...$diagnostics, ...$hintDiagnostics, ...$fieldDiagnostics, ...$this->unread($sourceClass, $context)] as $diagnostic) {
            $context->components->addDiagnostic($diagnostic);
        }

        return [new ValidationSchema($schema, $result->mediaType), $declaredRequired];
    }

    /**
     * The `#[BodyParameter]` declarations a request source class writes about ITSELF — the type-level
     * half of the attribute, read here so every recoverer that converges on this class gets it at once
     * rather than one vendor integration's DTOs getting it and resources, models and Form Requests not.
     *
     * Three things make the list empty, and each is a decision rather than a shortcut:
     *
     * - no class, so there is no type anything could be declared about;
     * - a read verb, where the same rules become QUERY parameters ({@see documentsBody()}) and a
     *   declaration about a body reaches nothing — the reading `validation.container-undecided`'s
     *   stand-down already takes, so the guard and the write see the same set;
     * - the source class IS the route's action, where ONE declaration site serves both roles and the
     *   route attribute bag already reads it. Nothing was ever dropped there, and the operation-level
     *   meaning it has today — patch the body, inline it — is the one that already exists. The defect
     *   this reads for is a declaration on a class the bag never sees, which is exactly a source class
     *   that is not the action.
     *
     * What it reads on the class is {@see ClassDeclarations}'s: the class's own declarations, and
     * silence for one whose constructor rejects its arguments.
     *
     * @return list<BodyParameter>
     */
    public static function declaredOn(?string $sourceClass, RouteContext $context): array
    {
        if ($sourceClass === null || ! self::documentsBody($context)) {
            return [];
        }

        if ($sourceClass === $context->actionRef->class) {
            return [];
        }

        return ClassDeclarations::of($sourceClass, BodyParameter::class);
    }

    /**
     * What the source class declares that nothing reads on a type ({@see SchemaClassAttributes}) —
     * reported here because this is the one place a class is known to be a request TYPE and not the
     * action, which is the whole difference between a declaration the route bag reads and one it never
     * sees.
     *
     * @return list<Diagnostic>
     */
    private function unread(string $sourceClass, RouteContext $context): array
    {
        return $sourceClass === $context->actionRef->class ? [] : SchemaClassAttributes::unread($sourceClass);
    }

    /**
     * Record what this route saw about the type's `#[BodyParameter]` — read here, or read at a verb
     * that documents no body ({@see UnusableBodyDeclarations}). The verdict is the document's to reach,
     * so only the observation is made here.
     *
     * The same stand-down {@see unread()} takes for a source class that IS the action: one declaration
     * site serves both roles there, and the route attribute bag reads it whatever the verb.
     */
    private function observe(string $sourceClass, RouteContext $context): void
    {
        if ($sourceClass === $context->actionRef->class) {
            return;
        }

        UnusableBodyDeclarations::observe($context, $sourceClass, self::documentsBody($context));
    }

    private function applyRequestBody(OperationDraft $operation, RouteContext $context, ValidationSchema $result, Contribution $contribution, ?string $sourceClass, bool $declaredRequired = false): void
    {
        $required = $declaredRequired || (is_array($result->schema['required'] ?? null) && $result->schema['required'] !== []);

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
        $id = SchemaIdentity::publishedId($sourceClass, 'request');

        return $context->components->reference($name, $result->schema, $id);
    }

    /**
     * Whether an OPERATION's own `#[BodyParameter]` patches this body — which forces it inline; see the
     * class header. The bag holds what the route's action and its controller class declare, never what
     * the request type does, so a type-level declaration cannot reach here and does not deviate: it is
     * already in the component every operation `$ref`s.
     */
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
