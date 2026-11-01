<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader\Arazzo;

/** An Arazzo version this package reads and validates, as the major.minor its schema is published under. */
enum ArazzoVersion: string
{
    case V1_0 = '1.0';
    case V1_1 = '1.1';

    /**
     * The version $declared (a root `arazzo` member) is read by: its major.minor, so `1.1` with no patch
     * still answers 1.1 and the schema is what reports the missing patch. Null for any other.
     */
    public static function of(?string $declared): ?self
    {
        if ($declared === null || preg_match('/^(\d+)\.(\d+)(?:\.|$)/', $declared, $match) !== 1) {
            return null;
        }

        return self::tryFrom(sprintf('%d.%d', (int) $match[1], (int) $match[2]));
    }
}
