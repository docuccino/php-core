<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * One link in the per-rule transformer chain a {@see ValidationRulesToSchema} drives (design §6). The
 * first transformer whose {@see supports()} is true handles the rule; a rule nothing supports becomes
 * an info diagnostic and leaves the field permissive.
 *
 * Transformers are pure and framework-agnostic: they read a {@see ValidationRule} (a name plus string
 * parameters, recovered statically — never executed) and mutate a {@see ValidationField}. Cross-field
 * rules like `confirmed` and media-type effects like `file`/`image` reach siblings and the request
 * root through that field facade.
 */
interface RuleTransformer
{
    public function supports(ValidationRule $rule): bool;

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void;

    /**
     * Every rule name this transformer handles, and the source of truth {@see supports()} derives
     * from. It keeps the vocabulary guardable: each declared name must route to exactly one
     * transformer and carry a dataset row.
     *
     * @return list<string>
     */
    public function handledRuleNames(): array;
}
