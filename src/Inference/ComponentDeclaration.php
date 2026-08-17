<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

use Docuccino\Core\Support\Hydrate;

/**
 * A component name an analysed method declared for the body it answers with, and where it was written.
 *
 * It rides on {@see ReturnSite} rather than on the response TYPE because it is a fact about the return
 * PATH — which methods the recovery walked — and not about the value's shape. `symbol` is the declaring
 * `Class::method`, so a name a reader has to be told about can be reported against the line that wrote it.
 */
final readonly class ComponentDeclaration
{
    public function __construct(
        public string $name,
        public string $symbol,
        public SourceLocation $location = new SourceLocation(''),
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'symbol' => $this->symbol, 'location' => $this->location->toArray()];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $location = $data['location'] ?? [];

        return new self(
            Hydrate::stringOr($data['name'] ?? null, ''),
            Hydrate::stringOr($data['symbol'] ?? null, ''),
            is_array($location) ? SourceLocation::fromArray($location) : new SourceLocation(''),
        );
    }
}
