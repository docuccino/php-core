<?php

declare(strict_types=1);

namespace Docuccino\Core\Patch;

/**
 * The explicit-removal sentinel. Passing {@see Remove::value()} to a guard is a real write that
 * competes on precedence like any other value, but resolves to "field absent" when the draft freezes.
 * Not the same as `null`, which means "not specified" and is never written at all.
 */
final class Remove
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function value(): self
    {
        return self::$instance ??= new self;
    }
}
