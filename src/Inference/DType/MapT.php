<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference\DType;

/**
 * A map — a non-constant keyed array (`array<K, V>`).
 */
final readonly class MapT extends DType
{
    public const KIND = 'map';

    public function __construct(public DType $key, public DType $value) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function toArray(): array
    {
        return [
            'kind' => self::KIND,
            'key' => $this->key->toArray(),
            'value' => $this->value->toArray(),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $key = $data['key'] ?? null;
        $value = $data['value'] ?? null;

        return new self(
            is_array($key) ? DType::fromArray($key) : new UnknownT('malformed map key'),
            is_array($value) ? DType::fromArray($value) : new UnknownT('malformed map value'),
        );
    }
}
