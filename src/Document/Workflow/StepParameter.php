<?php

declare(strict_types=1);

namespace Docuccino\Core\Document\Workflow;

use Docuccino\Core\Support\Hydrate;

/**
 * One value a workflow step passes to the operation it calls: where it travels, what it is called, and
 * the literal or runtime expression that supplies it.
 *
 * `in` is OpenAPI's own parameter location rather than a vocabulary of our own, because the parameter
 * being supplied is one the operation already declares — a location the operation has not got names
 * nothing.
 *
 * @internal
 */
final readonly class StepParameter
{
    public function __construct(
        public string $name,
        public string $in,
        public mixed $value,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: Hydrate::stringOr($data['name'] ?? '', ''),
            in: Hydrate::stringOr($data['in'] ?? '', ''),
            value: $data['value'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'in' => $this->in, 'value' => $this->value];
    }
}
