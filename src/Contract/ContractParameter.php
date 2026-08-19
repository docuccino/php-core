<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

/**
 * One documented parameter, with its `$ref` already followed and the pointer segments that address the
 * definition the reader would go and look at.
 */
final readonly class ContractParameter
{
    /**
     * @param  array<string, mixed>  $definition
     * @param  list<string>  $segments
     */
    public function __construct(
        public string $name,
        public string $in,
        public bool $required,
        public array $definition,
        public array $segments,
    ) {}

    /** @return array<string, mixed>|null */
    public function schema(): ?array
    {
        $schema = $this->definition['schema'] ?? null;

        /** @var array<string, mixed>|null */
        return is_array($schema) ? $schema : null;
    }

    /** @return list<string> */
    public function schemaSegments(): array
    {
        return array_merge($this->segments, ['schema']);
    }

    /** How a failure message names it: `?page`, `header X-Tenant`, `path {invoice}`. */
    public function label(): string
    {
        return match ($this->in) {
            'query' => '?'.$this->name,
            'path' => 'path {'.$this->name.'}',
            default => $this->in.' '.$this->name,
        };
    }
}
