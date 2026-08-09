<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

/**
 * The single "short class name" helper: the last namespace segment of an FQCN
 * (or the input, if unqualified). Shared across throw-frame labels, the engine's
 * self label, constant-value rendering, component naming and operationId
 * derivation — every site that shorts an FQCN for display must do it identically,
 * so a private copy in each risked drift.
 *
 * Public (not `@internal`): a pure, stable string helper that built-in integrations use directly
 * rather than inlining a copy to dodge the arch-test allow-list (see IntegrationsArchTest).
 */
final class Fqcn
{
    /** The last namespace segment of an FQCN (or the input, if unqualified). */
    public static function short(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos !== false ? substr($fqcn, $pos + 1) : $fqcn;
    }
}
