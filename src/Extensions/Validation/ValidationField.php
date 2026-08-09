<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;

/**
 * The mutable façade a {@see RuleTransformer} sees for one
 * field: it sets schema keywords, marks presence/nullability, flags the request multipart, and
 * reaches a sibling field for cross-field rules (`confirmed`). It hides the {@see FieldNode} tree
 * and the {@see RequestSchemaBuilder} root so a transformer stays pure.
 */
final readonly class ValidationField
{
    public function __construct(
        private FieldNode $node,
        private RequestSchemaBuilder $builder,
        private string $path,
    ) {}

    public function setType(string $type): void
    {
        $this->node->keywords['type'] = $type;
    }

    /** The field's scalar `type`, when a single string type has been set. */
    public function type(): ?string
    {
        $type = $this->node->keywords['type'] ?? null;

        return is_string($type) ? $type : null;
    }

    public function set(string $keyword, mixed $value): void
    {
        $this->node->keywords[$keyword] = $value;
    }

    public function get(string $keyword): mixed
    {
        return $this->node->keywords[$keyword] ?? null;
    }

    public function has(string $keyword): bool
    {
        return array_key_exists($keyword, $this->node->keywords);
    }

    public function markRequired(): void
    {
        $this->node->required = true;
    }

    public function markOptional(): void
    {
        $this->node->required = false;
    }

    /**
     * A `sometimes`-style rule: the field is validated only when present, so it never joins the
     * parent's `required` list — even alongside a `required` rule — regardless of rule order.
     */
    public function markSometimes(): void
    {
        $this->node->presenceOptional = true;
    }

    public function isRequired(): bool
    {
        return $this->node->required && ! $this->node->presenceOptional;
    }

    public function markNullable(): void
    {
        $this->node->nullable = true;
    }

    /** Flag the whole request as multipart (a `file`/`image` rule was seen). */
    public function markMultipart(): void
    {
        $this->builder->markMultipart();
    }

    /**
     * A sibling field of this one, sharing the parent path with the last segment renamed — the
     * `{field}_confirmation` partner a `confirmed` rule documents.
     */
    public function sibling(string $suffix): self
    {
        $position = strrpos($this->path, '.');
        $siblingPath = $position === false
            ? $this->path.$suffix
            : substr($this->path, 0, $position + 1).substr($this->path, $position + 1).$suffix;

        return $this->builder->field($siblingPath);
    }
}
