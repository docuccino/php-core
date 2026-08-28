<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

/**
 * A Reference Object {@see ComponentRefs} reached no component through, and whether the document itself is
 * the reason. That distinction is the whole point of the type: a local `#/components/…` pointer at a name
 * this document does not declare publishes NOTHING at that position, for every reader of the document,
 * while a pointer that leaves the document may well name a whole endpoint in a file this resolver cannot
 * open. Read as one answer they let a broken document pass as an unopenable one.
 *
 * @internal
 */
final readonly class UnresolvedRef
{
    private function __construct(public string $ref, public bool $undeclared) {}

    /**
     * A local pointer into a `components` bucket, at a name this document does not declare — the document
     * is broken here, which is what renaming or removing a component under a pointer leaves behind.
     */
    public static function undeclared(string $ref): self
    {
        return new self($ref, true);
    }

    /**
     * A pointer this resolver cannot follow and cannot call broken either: another document, a section it
     * does not index, a node inside a component, or a chain it stops one hop into.
     */
    public static function unopenable(string $ref): self
    {
        return new self($ref, false);
    }

    /**
     * Two sides pointing the same way for the same reason. The pointer text alone is not the answer: one
     * document can declare the name and chain off it while the other declares nothing at all, and those are
     * the same pointer saying two different things.
     */
    public static function same(?self $old, ?self $new): bool
    {
        return $old?->ref === $new?->ref && $old?->undeclared === $new?->undeclared;
    }
}
