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

    /**
     * The field's type where more than one is true of the value — the union a rule states because the
     * fact it recovered leaves the choice open, not one it is guessing between. A single member says
     * exactly what {@see setType()} says and is written the same way.
     *
     * @param  non-empty-list<string>  $types
     */
    public function setTypes(array $types): void
    {
        $this->node->keywords['type'] = count($types) === 1 ? $types[0] : $types;
    }

    /** The dotted path naming this field, as a diagnostic about it would spell it (`address.city`). */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Every type word set on the field — one for a scalar type, several for a union ({@see setTypes()}),
     * none where nothing has typed it yet. The ONLY reading of the field's type, because there is no
     * single-word answer to give: a rule may legally leave a field stating several, so a reader asking
     * for one word gets either a lie or a null indistinguishable from "untyped", and every reader that
     * asked it that way has had to be fixed. Null is not among the words — nullability is a flag
     * ({@see markNullable()}) the schema applies as it assembles, so a rule running after one still
     * reads what the field actually is.
     *
     * What a type-aware rule reads when its keyword differs per type: a bound that is `maxItems` on an
     * array and `maxProperties` on an object owes both to a value that may be either.
     *
     * @return list<string>
     */
    public function types(): array
    {
        return FieldNode::typeWords($this->node->keywords['type'] ?? null);
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
     * Drop a keyword an earlier, coarser rule left behind — for a rule that REPLACES that rule's claim
     * rather than adding to it, and would otherwise publish a keyword against a type it no longer
     * belongs to. The drop stands: a later rule that would only be guessing is refused the keyword
     * ({@see mayClaim()}), since republishing what was just withdrawn is the same wrong claim arriving
     * one rule later.
     */
    public function remove(string $keyword): void
    {
        unset($this->node->keywords[$keyword]);
        $this->node->withheld[$keyword] = true;
    }

    /**
     * Whether a rule with nothing but a GUESS to offer may claim this keyword — nothing set it, and
     * nothing withdrew it. The guard a rule uses where its keyword is a reading of intent rather than a
     * fact the rule states (a comparison bound's `format`, a type word's default one). A keyword the
     * AUTHOR states outright asks {@see has()} instead: their word outranks a withdrawal.
     */
    public function mayClaim(string $keyword): bool
    {
        return ! $this->has($keyword) && ! array_key_exists($keyword, $this->node->withheld);
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
