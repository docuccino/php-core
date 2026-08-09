<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation;

use Docuccino\Core\Extensions\Context\RepresentationPolicy;

/**
 * Assembles the request object schema from dot/wildcard field paths. `field('items.*.id')`
 * descends the tree creating an `items` array whose item objects carry an `id` property; a `*`
 * segment turns the current node into an array and descends into its items. The multipart flag is
 * raised by a `file`/`image` transformer through the field façade.
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
        foreach (explode('.', $path) as $segment) {
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
     * @return array<string, mixed>
     */
    public function build(RepresentationPolicy $policy): array
    {
        return $this->root->build($policy);
    }
}
