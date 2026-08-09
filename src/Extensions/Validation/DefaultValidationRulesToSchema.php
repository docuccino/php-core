<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\ValidationRulesToSchema;

/**
 * The default {@see ValidationRulesToSchema}: a pure, vocabulary-free chain driver. It builds a
 * request object schema by feeding each field's rules — in the order they are given — through the
 * {@see RuleTransformer} chain (first `supports()` wins). It knows no rule names: the meaning and
 * effect-ordering of rules live entirely in the transformer set an adapter supplies (Laravel's
 * FormRequest integration ships its own; a Symfony adapter would ship a different one). A rule no
 * transformer handles becomes an info diagnostic and leaves the field permissive.
 */
final readonly class DefaultValidationRulesToSchema implements ValidationRulesToSchema
{
    /**
     * @param  list<RuleTransformer>  $transformers
     */
    public function __construct(
        private array $transformers = [],
    ) {}

    public function convert(RuleSet $rules, SchemaContext $context): ValidationSchema
    {
        $builder = new RequestSchemaBuilder;
        $diagnostics = [];

        foreach ($rules->fields as $path => $fieldRules) {
            $field = $builder->field($path);
            foreach ($fieldRules as $rule) {
                $diagnostic = $this->applyRule($rule, $field, $context, $path);
                if ($diagnostic !== null) {
                    $diagnostics[] = $diagnostic;
                }
            }
        }

        if (! $builder->hasFields()) {
            return new ValidationSchema([], 'application/json', $diagnostics);
        }

        $schema = $builder->build($context->representation());
        $mediaType = $builder->isMultipart() ? 'multipart/form-data' : 'application/json';

        return new ValidationSchema($schema, $mediaType, $diagnostics);
    }

    private function applyRule(ValidationRule $rule, ValidationField $field, SchemaContext $context, string $path): ?Diagnostic
    {
        foreach ($this->transformers as $transformer) {
            if ($transformer->supports($rule)) {
                $transformer->apply($rule, $field, $context);

                return null;
            }
        }

        return new Diagnostic(
            severity: Severity::Info,
            code: 'validation.rule-unhandled',
            message: sprintf('No transformer handled validation rule "%s" on field "%s"; the property stays permissive.', $rule->name, $path),
            help: 'Register a RuleTransformer for this rule to document it.',
        );
    }
}
