<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference\DType;

/**
 * A callable/closure type. The signature (parameter types + return type) is
 * optional — the translator emits a bare `CallableT` when it cannot resolve one.
 */
final readonly class CallableT extends DType
{
    public const KIND = 'callable';

    /**
     * @param  list<DType>|null  $paramTypes
     */
    public function __construct(
        public ?array $paramTypes = null,
        public ?DType $returnType = null,
    ) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function toArray(): array
    {
        $out = ['kind' => self::KIND];

        if ($this->paramTypes !== null) {
            $out['paramTypes'] = array_map(static fn (DType $t): array => $t->toArray(), $this->paramTypes);
        }

        if ($this->returnType !== null) {
            $out['returnType'] = $this->returnType->toArray();
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $paramTypes = $data['paramTypes'] ?? null;
        $returnType = $data['returnType'] ?? null;

        return new self(
            is_array($paramTypes)
                ? array_values(array_map(
                    static fn (mixed $t): DType => is_array($t) ? DType::fromArray($t) : new UnknownT('malformed param type'),
                    $paramTypes,
                ))
                : null,
            is_array($returnType) ? DType::fromArray($returnType) : null,
        );
    }
}
