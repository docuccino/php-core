<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\UnknownT;

/**
 * One return path of an action, paired with its flow-refined type. Because
 * PHPStan's `MethodReturnStatementsNode` pairs every `return` with the scope at
 * that point, each `ReturnSite` carries per-return-path provenance for free
 * (design §2).
 */
final readonly class ReturnSite
{
    public function __construct(
        public DType $type,
        public SourceLocation $location,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['type' => $this->type->toArray(), 'location' => $this->location->toArray()];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? [];
        $location = $data['location'] ?? [];

        return new self(
            is_array($type) ? DType::fromArray($type) : new UnknownT('malformed return type'),
            is_array($location) ? SourceLocation::fromArray($location) : new SourceLocation(''),
        );
    }
}
