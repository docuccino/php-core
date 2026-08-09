<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationSchema;
use Docuccino\Core\Inference\DType\DType;

/**
 * Converts a recovered {@see RuleSet} into a request {@see ValidationSchema} (design §6). Rules
 * aren't types — they carry presence/shape/constraint facts a {@see DType}
 * can't — so this is a contract of its own rather than a {@see TypeToSchema} link, keeping the
 * type chain honest.
 *
 * The default implementation drives a chain of per-rule {@see RuleTransformer} sub-extensions
 * (first `supports()` wins), so a user adds a custom rule by registering one more transformer
 * ahead of the built-ins — never by forking this contract.
 */
interface ValidationRulesToSchema
{
    public function convert(RuleSet $rules, SchemaContext $context): ValidationSchema;
}
