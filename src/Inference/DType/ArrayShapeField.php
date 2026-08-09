<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference\DType;

/**
 * One field of an {@see ArrayShapeT}: a string or int key, its value type, and
 * whether the key is optional (`array{id: int, name?: string}`).
 */
final readonly class ArrayShapeField
{
    public function __construct(
        public string|int $key,
        public DType $type,
        public bool $optional = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type->toArray(),
            'optional' => $this->optional,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $key = $data['key'] ?? '';
        $type = $data['type'] ?? null;

        return new self(
            is_int($key) ? $key : (string) (is_scalar($key) ? $key : ''),
            is_array($type) ? DType::fromArray($type) : new UnknownT('malformed field type'),
            (bool) ($data['optional'] ?? false),
        );
    }
}
