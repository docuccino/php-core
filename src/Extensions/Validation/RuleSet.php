<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation;

use InvalidArgumentException;

/**
 * A recovered set of validation rules keyed by field path, in Laravel dot + wildcard notation
 * (`author.name`, `items.*.id`), which the builder turns into nested object/array schemas. Field
 * insertion order is preserved so the emitted schema is deterministic.
 *
 * `variants` names the objects whose members a recovery proved are partitioned by a tag ({@see TaggedVariants}),
 * and `merged` is the whole field map as it reads with no partition proved — what an object publishes once
 * its variants are given up. A recovery moves presence rules off the members onto the partition, so a
 * rewrite that dropped the variants without restoring `merged` would publish those members stripped: every
 * rewrite goes through {@see mapFields()} or {@see without()}, which carry both.
 */
final readonly class RuleSet
{
    /**
     * @param  array<string, list<ValidationRule>>  $fields
     * @param  list<TaggedVariants>  $variants
     * @param  array<string, list<ValidationRule>>  $merged  the same keys as `$fields`, read with no partition;
     *                                                       empty where there are no variants
     */
    public function __construct(
        public array $fields = [],
        public array $variants = [],
        public array $merged = [],
    ) {
        if ($variants !== [] && (array_diff_key($fields, $merged) !== [] || array_diff_key($merged, $fields) !== [])) {
            throw new InvalidArgumentException('A rule set with variants owes the merged reading of every one of its fields.');
        }
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /**
     * The set with each field's rules rewritten, the merged reading rewritten alike.
     *
     * @param  callable(list<ValidationRule>): list<ValidationRule>  $rewrite
     */
    public function mapFields(callable $rewrite): self
    {
        return new self(array_map($rewrite, $this->fields), $this->variants, array_map($rewrite, $this->merged));
    }

    /**
     * The set with every variant one of `$keys` is the tag or a gated member of given up, its object's
     * members read as merged again.
     *
     * @param  list<string>  $keys
     */
    public function releasing(array $keys): self
    {
        $fields = $this->fields;
        $kept = [];
        foreach ($this->variants as $variant) {
            $named = array_filter($keys, $variant->names(...)) !== [];
            if (! $named) {
                $kept[] = $variant;

                continue;
            }

            foreach (array_keys($fields) as $key) {
                if ($variant->owns((string) $key)) {
                    $fields[$key] = $this->merged[$key];
                }
            }
        }

        return new self($fields, $kept, $kept === [] ? [] : $this->merged);
    }

    /**
     * The set less `$keys`, having first given up every variant one of them is read off ({@see releasing()}).
     *
     * @param  list<string>  $keys
     */
    public function without(array $keys): self
    {
        $released = $this->releasing($keys);
        $gone = array_flip($keys);

        return new self(array_diff_key($released->fields, $gone), $released->variants, array_diff_key($released->merged, $gone));
    }

    /** The set with every variant given up — what the rules publish where no partition is. */
    public function merged(): self
    {
        return $this->variants === [] ? $this : new self($this->merged);
    }
}
