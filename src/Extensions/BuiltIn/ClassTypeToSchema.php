<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Schema\ComponentHoist;
use Docuccino\Core\Extensions\Schema\SchemaIdentity;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\UnionT;

/**
 * A named class → an object schema hoisted to `components.schemas` and referenced by `$ref`. Properties
 * come from {@see TypeEngine::classMetadata()} less whatever `#[Hidden]` denies, read through
 * {@see SchemaIdentity} so a plain DTO hides a property exactly as a Data class or a model does. Being
 * the framework-agnostic fallback, it is the ONLY mapper a plain DTO reaches, so it leaves the component
 * name and diff identity to {@see ComponentHoist}'s attribute fallback rather than forcing the short name.
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

        return $this->hoist->hoist($context, $fqcn, function () use ($fqcn, $context): ?array {
            $metadata = $context->engine()->classMetadata(new ClassRef($fqcn));

            // The class's reflected source is a fragment-cache dependency — adding or retyping a
            // property must invalidate any warm fragment that referenced it.
            $context->dependsOn(...$metadata->dependencyFiles);

            if ($metadata->properties === []) {
                // Degrade to a bare object: null leaves the reserved name unused, so it never reaches
                // components.schemas. An unexpandable class has no body to self-reference anyway.
                return null;
            }

            $hidden = SchemaIdentity::hidden($fqcn);

            $properties = [];
            $required = [];
            foreach ($metadata->properties as $property) {
                if (in_array($property->name, $hidden, true) || SchemaIdentity::hidesProperty($fqcn, $property->name)) {
                    continue;
                }

                $schema = $context->convert($property->type);
                if ($property->summary !== null) {
                    $schema['description'] = $property->summary;
                }
                $properties[$property->name] = $schema;
                if (! ($property->type instanceof UnionT && $property->type->containsNull())) {
                    $required[] = $property->name;
                }
            }

            if ($properties === []) {
                // Everything the class exposes is hidden — same degradation as an unexpandable class.
                return null;
            }

            $object = ['type' => 'object', 'properties' => $properties];
            if ($required !== []) {
                $object['required'] = $required;
            }

            return $object;
        });
    }
}
