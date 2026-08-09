<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

/**
 * One frame of a {@see ThrownException}'s call chain: the symbol (e.g.
 * `App\Services\OrderService::reserve`) and where the hop occurred. Layer-3
 * descent emits multi-frame chains (Spike C).
 */
final readonly class Frame
{
    public function __construct(
        public string $symbol,
        public SourceLocation $location,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['symbol' => $this->symbol, 'location' => $this->location->toArray()];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $symbol = $data['symbol'] ?? '';
        $location = $data['location'] ?? [];

        return new self(
            is_string($symbol) ? $symbol : '',
            is_array($location) ? SourceLocation::fromArray($location) : new SourceLocation(''),
        );
    }
}
