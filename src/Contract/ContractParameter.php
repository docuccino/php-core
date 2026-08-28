<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

/**
 * One documented parameter, with its `$ref` already followed and the pointer segments that address the
 * definition the reader would go and look at.
 *
 * A response header object is one of these too: OAS defines it as a parameter without `name` and `in`,
 * so it is read here and checked by the same code rather than by a parallel one ({@see ResponseHeaders}).
 * Such a header carries its own {@see $label}, since `header X-Tenant` in a response-half failure would
 * read as the request header of that name.
 */
final readonly class ContractParameter
{
    /**
     * @param  array<string, mixed>  $definition
     * @param  list<string>  $segments
     * @param  string|null  $label  overrides the `in`-derived label
     * @param  string|null  $danglingRef  the `$ref` this was documented behind that resolves to nothing
     */
    public function __construct(
        public string $name,
        public string $in,
        public bool $required,
        public array $definition,
        public array $segments,
        private ?string $label = null,
        public ?string $danglingRef = null,
    ) {}

    /** @return array<string, mixed>|null */
    public function schema(): ?array
    {
        $schema = $this->definition['schema'] ?? null;

        /** @var array<string, mixed>|null */
        return is_array($schema) ? $schema : null;
    }

    /**
     * Whether the contract gives this a SCHEMA-SHAPED schema. A `content`-typed parameter or header,
     * one documented with neither, and one whose `schema` is a string or a number cannot be checked —
     * which the caller says out loud rather than passing in silence. A boolean counts, because `true`
     * and `false` are schemas; so does `[]`, which is how associative decoding spells `{}`.
     *
     * The member being PRESENT is not the question: a `schema` nothing can validate against is exactly
     * as uncheckable as one that was never written, and reading presence alone let a mistyped one pass
     * as though it had been checked.
     */
    public function hasSchema(): bool
    {
        $schema = $this->definition['schema'] ?? null;

        return is_array($schema) || is_bool($schema);
    }

    /** @return list<string> */
    public function schemaSegments(): array
    {
        return array_merge($this->segments, ['schema']);
    }

    /** How a failure message names it: `?page`, `header X-Tenant`, `path {invoice}`. */
    public function label(): string
    {
        return $this->label ?? match ($this->in) {
            'query' => '?'.$this->name,
            'path' => 'path {'.$this->name.'}',
            default => $this->in.' '.$this->name,
        };
    }
}
