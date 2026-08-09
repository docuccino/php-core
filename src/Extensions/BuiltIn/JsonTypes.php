<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use Docuccino\Core\Inference\DType\ScalarT;

/**
 * Shared PHP-scalar → JSON-Schema `type` mapping for the built-in mappers.
 */
final class JsonTypes
{
    public static function forScalar(string $scalar): string
    {
        return match ($scalar) {
            ScalarT::INT => 'integer',
            ScalarT::FLOAT => 'number',
            ScalarT::BOOL => 'boolean',
            default => 'string',
        };
    }
}
