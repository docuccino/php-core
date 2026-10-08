<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Schema\ComponentHoist;
use Docuccino\Core\Extensions\Schema\DocumentedExamples;
use Docuccino\Core\Extensions\Schema\MockHints;
use Docuccino\Core\Extensions\Schema\PropertyAnnotations;
use Docuccino\Core\Extensions\Schema\RequestShape;
use Docuccino\Core\Extensions\Schema\SchemaIdentity;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Extensions\Schema\SealedHierarchy;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Provenance\ClassNames;
use Docuccino\Core\Support\NameList;

/**
 * A named class → an object schema hoisted to `components.schemas` and referenced by `$ref`. Properties
 * come from {@see TypeEngine::classMetadata()} less whatever `#[Hidden]` denies, read through
 * {@see SchemaIdentity} so a plain DTO hides a property exactly as a Data class or a model does. Being
 * the framework-agnostic fallback, it is the ONLY mapper a plain DTO reaches, so it leaves the component
 * name and diff identity to {@see ComponentHoist}'s attribute fallback rather than forcing the short name.
 * A sealed interface or abstract class publishes the union of what it permits ({@see SealedHierarchy}).
 */
final class ClassTypeToSchema implements TypeToSchema
{
    public function __construct(
        private readonly ComponentHoist $hoist = new ComponentHoist,
    ) {}

    public function supports(DType $type): bool
    {
        return $type instanceof ClassT;
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $type instanceof ClassT) {
            return null;
        }

        $fqcn = $type->fqcn;

        $sealed = SealedHierarchy::of($fqcn);
        if ($sealed !== null) {
            return $this->hoist->hoist($context, $fqcn, static fn (): ?array => self::sealed($fqcn, $sealed, $context));
        }

        // A request shape that differs from the response one is its own component; one that doesn't is
        // the same shape, and stays the one component both sides reference. What it reaches is weighed
        // by the hoist, for every mapper ({@see RequestShape}).
        $schemaId = $context->describesRequest() && RequestShape::keysDiffer($fqcn, $context)
            ? SchemaIdentity::publishedId($fqcn, 'request')
            : null;

        return $this->hoist->hoist($context, $fqcn, function () use ($fqcn, $context): ?array {
            $metadata = $context->engine()->classMetadata(new ClassRef($fqcn));

            // The class's reflected source is a fragment-cache dependency — adding or retyping a
            // property must invalidate any warm fragment that referenced it.
            $context->dependsOn(...$metadata->dependencyFiles);

            if ($metadata->properties === []) {
                // Degrade to a bare object: with no properties there is nothing to publish, and nothing
                // was walked that could have self-referenced it.
                return null;
            }

            $hidden = SchemaIdentity::hidden($fqcn);

            $properties = [];
            $required = [];
            // Every name the deny-list was weighed against, the hidden ones included — what a `#[Hidden]`
            // that matched nothing is reported against below.
            $considered = [];
            foreach ($metadata->properties as $property) {
                $considered[] = $property->name;

                if (in_array($property->name, $hidden, true) || SchemaIdentity::hidesProperty($fqcn, $property->name)) {
                    continue;
                }

                $schema = $context->convert($property->type);
                if ($property->summary !== null) {
                    $schema['description'] = $property->summary;
                }
                $properties[$property->name] = $schema;
                if (RequestShape::required($fqcn, $property, $context->describesRequest())) {
                    $required[] = $property->name;
                }
            }

            foreach (SchemaIdentity::unmatchedHidden($fqcn, $considered) as $diagnostic) {
                $context->diagnostic($diagnostic);
            }

            if ($properties === []) {
                // Everything the class exposes is hidden — same degradation as an unexpandable class.
                return null;
            }

            $object = ['type' => 'object', 'properties' => $properties];
            if ($required !== []) {
                $object['required'] = $required;
            }

            // Docblock prose (30) then the attributes (40) — the same order the `description` above is
            // written in, so an author who writes both gets the attribute.
            $object = DocumentedExamples::applyTo($context, $object, $fqcn, $metadata->properties);
            $object = PropertyAnnotations::applyTo($context, $object, $fqcn);

            return MockHints::applyTo($context, $object, $fqcn);
        }, schemaId: $schemaId);
    }

    /**
     * The union a seal permits, or null (the bare object, reported) where it names a non-subtype.
     *
     * @return array<string, mixed>|null
     */
    private static function sealed(string $fqcn, SealedHierarchy $sealed, SchemaContext $context): ?array
    {
        if ($sealed->unreadable !== []) {
            $context->diagnostic(new Diagnostic(
                severity: Severity::Warning,
                code: 'docblock.sealed-unreadable',
                message: sprintf(
                    'The @phpstan-sealed tag on %1$s names %2$s, which %3$s not a class extending or implementing it, so a value typed %1$s is published as a bare object.',
                    ClassNames::publishable($fqcn),
                    NameList::of($sealed->unreadable),
                    count($sealed->unreadable) === 1 ? 'is' : 'are',
                ),
                help: 'Name every permitted subtype by a class the file can resolve — imported, or fully qualified — and only classes that really extend or implement the sealed type.',
            ));

            return null;
        }

        return $context->convert(UnionT::of($sealed->members));
    }
}
