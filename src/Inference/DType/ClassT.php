<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference\DType;

use Docuccino\Core\Inference\TypeEngine;

/**
 * An object of a named class/interface with any resolved generic type arguments
 * (`Collection<int, User>` → `ClassT('…\Collection', [int, User])`).
 *
 * A ClassT carries identity only, which keeps it serializable and cache-stable; expanding the class
 * into properties and docblocks is lazy, via {@see TypeEngine::classMetadata()}.
 */
final readonly class ClassT extends DType
{
    public const KIND = 'class';

    /**
     * @param  list<DType>  $typeArgs
     */
    public function __construct(public string $fqcn, public array $typeArgs = []) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function toArray(): array
    {
        return [
            'kind' => self::KIND,
            'fqcn' => $this->fqcn,
            'typeArgs' => array_map(static fn (DType $t): array => $t->toArray(), $this->typeArgs),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $fqcn = $data['fqcn'] ?? '';
        $typeArgs = $data['typeArgs'] ?? [];

        return new self(
            is_string($fqcn) ? $fqcn : '',
            is_array($typeArgs)
                ? array_values(array_map(
                    static fn (mixed $t): DType => is_array($t) ? DType::fromArray($t) : new UnknownT('malformed type arg'),
                    $typeArgs,
                ))
                : [],
        );
    }
}
