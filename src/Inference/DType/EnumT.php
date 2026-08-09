<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference\DType;

/**
 * A backed or pure enum, carrying its case names in declaration order.
 */
final readonly class EnumT extends DType
{
    public const KIND = 'enum';

    /**
     * @param  list<string>  $cases
     */
    public function __construct(public string $fqcn, public array $cases = []) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function toArray(): array
    {
        return ['kind' => self::KIND, 'fqcn' => $this->fqcn, 'cases' => $this->cases];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $fqcn = $data['fqcn'] ?? '';
        $cases = $data['cases'] ?? [];

        return new self(
            is_string($fqcn) ? $fqcn : '',
            is_array($cases) ? array_values(array_filter($cases, 'is_string')) : [],
        );
    }
}
