<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;

/**
 * Assembles the request object schema from dot/wildcard field paths: `field('items.*.id')` creates an
 * `items` array whose item objects carry an `id` property, since a `*` segment descends into the
 * current node's element. Which container that element belongs to is the node's own to settle
 * ({@see FieldNode::build()}). A `file`/`image` transformer raises the multipart flag through the field
 * façade.
 *
 * Paths are split by {@see FieldPath}, so a `\.` escape names a field whose own name holds a dot
 * rather than descending — the same reading Laravel's validator gives the same key.
 */
final class RequestSchemaBuilder
{
    private readonly FieldNode $root;

    private bool $multipart = false;

    public function __construct()
    {
        $this->root = new FieldNode;
    }

    /** Resolve (creating as needed) the field at a dot/wildcard path and return its façade. */
    public function field(string $path): ValidationField
    {
        $node = $this->root;
        foreach (FieldPath::segments($path) as $segment) {
            $node = $segment === '*' ? $node->itemsNode() : $node->child($segment);
        }

        return new ValidationField($node, $this, $path);
    }

    public function markMultipart(): void
    {
        $this->multipart = true;
    }

    public function isMultipart(): bool
    {
        return $this->multipart;
    }

    public function hasFields(): bool
    {
        return $this->root->properties !== [] || $this->root->items !== null;
    }

    /**
     * Give every LEAF an `example` its own rules earn ({@see FieldExample}). Run once the whole tree
     * exists, so a node is judged on its finished keywords rather than on whichever rule came last —
     * and so a node that turned out to be a container is skipped, its children illustrating it.
     *
     * Returns what synthesis had to report, in tree order — a configured format sample a field's own
     * rules reject.
     *
     * @return list<Diagnostic>
     */
    public function synthesizeExamples(RepresentationPolicy $policy = new RepresentationPolicy): array
    {
        return self::walk($this->root, $policy, '');
    }

    /**
     * @return list<Diagnostic>
     */
    private static function walk(FieldNode $node, RepresentationPolicy $policy, string $path): array
    {
        if ($node->properties !== []) {
            $diagnostics = [];
            foreach ($node->properties as $name => $child) {
                $diagnostics = [...$diagnostics, ...self::walk($child, $policy, self::join($path, (string) $name))];
            }

            return $diagnostics;
        }

        if ($node->items !== null) {
            return self::walk($node->items, $policy, self::join($path, '*'));
        }

        return FieldExample::attach($node, $policy, $path);
    }

    private static function join(string $path, string $segment): string
    {
        return $path === '' ? $segment : $path.'.'.$segment;
    }

    /**
     * @return array<string, mixed>
     */
    public function build(RepresentationPolicy $policy): array
    {
        return $this->root->build($policy);
    }
}
