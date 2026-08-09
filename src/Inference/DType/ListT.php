<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference\DType;

/**
 * A list — a sequential, integer-keyed array of a single value type
 * (`list<V>`). Non-constant; constant shapes use {@see ArrayShapeT}.
 */
final readonly class ListT extends DType
{
    public const KIND = 'list';

    public function __construct(public DType $value) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function toArray(): array
    {
        return ['kind' => self::KIND, 'value' => $this->value->toArray()];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $value = $data['value'] ?? null;

        return new self(is_array($value) ? DType::fromArray($value) : new UnknownT('malformed list value'));
    }
}
