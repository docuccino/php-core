<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnionT;

/**
 * The codes a response type's status argument states: one int literal, or a union of nothing but int
 * literals (`$ok ? 200 : 503`), each a status the server can send. The one reading of that grammar, shared
 * by the engine that writes it and the adapter that publishes it, so the two can never disagree about
 * which statuses a type carries.
 */
final class StatusCodes
{
    /**
     * Ascending, once each; null for any other status argument — one nothing read, a general int, a marker.
     *
     * @return non-empty-list<int>|null
     */
    public static function of(?DType $status): ?array
    {
        $members = $status instanceof UnionT ? $status->members : [$status];

        $codes = [];
        foreach ($members as $member) {
            if (! $member instanceof LiteralT || ! is_int($member->value)) {
                return null;
            }
            $codes[$member->value] = $member->value;
        }
        if ($codes === []) {
            return null;
        }
        ksort($codes);

        return array_values($codes);
    }
}
