<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationSchema;
use Docuccino\Core\Inference\DType\DType;

/**
 * Converts a recovered {@see RuleSet} into a request {@see ValidationSchema} (design §6). Rules aren't
 * types — they carry presence and constraint facts a {@see DType} can't — so this is its own contract
 * rather than a {@see TypeToSchema} link, which keeps the type chain honest.
 *
 * The default implementation drives a chain of per-rule {@see RuleTransformer}s, so a custom rule means
 * registering one more transformer ahead of the built-ins, never forking this contract.
 */
interface ValidationRulesToSchema
{
    public function convert(RuleSet $rules, SchemaContext $context): ValidationSchema;
}
