<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;

/**
 * The mutable façade a {@see RuleTransformer} sees for one field: set schema keywords, mark
 * presence/nullability, flag the request multipart, reach a sibling for a cross-field rule like
 * `confirmed`. It hides the {@see FieldNode} tree and the {@see RequestSchemaBuilder} root so
 * transformers stay pure.
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

    /** The dotted path naming this field, as a diagnostic about it would spell it (`address.city`). */
    public function path(): string
    {
        return $this->path;
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

    /**
     * A value this rule alone knows is legal, for the synthesized `example` — or null to say no value
     * would be, which is final and outranks any other rule's proposal.
     *
     * For a rule that constrains the VALUE without leaving a schema keyword behind: a `date_format`
     * wire format, a timezone identifier, a file upload. Whatever is proposed is still validated
     * against the field's finished schema before it is published, so a later rule that narrows the
     * field drops the proposal rather than contradicting it. A rule whose constraint IS a keyword
     * needs nothing here — the keyword is what the synthesis reads. See {@see FieldExample}.
     */
    public function proposeExample(mixed $value): void
    {
        if ($this->node->exampleSuppressed) {
            return;
        }

        if ($value === null) {
            $this->node->exampleSuppressed = true;
            $this->node->exampleProposal = null;

            return;
        }

        $this->node->exampleProposal ??= [$value];
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
