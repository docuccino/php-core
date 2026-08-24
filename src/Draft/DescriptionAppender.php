<?php

declare(strict_types=1);

namespace Docuccino\Core\Draft;

use Docuccino\Core\Patch\Contribution;

/**
 * The description-merge invariant: prose a producer adds to an operation's description joins what is
 * already there with a `\n\n` separator rather than replacing it. Written once here — the security
 * integrations annotate a description with a requirement note, the overrides layer with a deprecation
 * reason, and all of them owe the same separator.
 *
 * {@see append()} reads the resolved description and writes the joined text, so it is the shape for a
 * producer whose note is its only write. Where the description and the note are written together —
 * one patch at one layer, because a second write at the same layer is shadowed — {@see joined()} is
 * the same rule without the write.
 */
final class DescriptionAppender
{
    public static function append(OperationDraft $operation, string $addition, Contribution $by): void
    {
        $current = $operation->resolvedField('description');

        $operation->setDescription(self::joined(is_string($current) ? $current : null, $addition), $by);
    }

    public static function joined(?string $description, string $addition): string
    {
        return $description === null || $description === '' ? $addition : $description."\n\n".$addition;
    }
}
