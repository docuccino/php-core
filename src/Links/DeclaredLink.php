<?php

declare(strict_types=1);

namespace Docuccino\Core\Links;

use Docuccino\Core\Provenance\Source;
use Docuccino\Core\Support\Hydrate;

/**
 * A link a developer declared on an operation: the operation it leads to — an operationId, or `METHOD
 * /path` as the document names it — and what each of its parameters is filled with. `$status` null
 * means the operation's first success response; `$name` empty means the target's operationId. `$layer`
 * is the precedence layer it was declared in, which is what its provenance says.
 *
 * @internal
 */
final readonly class DeclaredLink
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public string $operation,
        public array $parameters = [],
        public string $name = '',
        public ?string $status = null,
        public string $description = '',
        public string $layer = 'attribute',
        public ?Source $source = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'operation' => $this->operation,
            'parameters' => $this->parameters,
            'name' => $this->name,
            'status' => $this->status,
            'description' => $this->description,
            'layer' => $this->layer,
            'source' => $this->source?->toArray(),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $operation = $data['operation'] ?? null;
        if (! is_string($operation)) {
            return null;
        }

        return new self(
            operation: $operation,
            parameters: Hydrate::mapOrNull($data['parameters'] ?? null) ?? [],
            name: Hydrate::stringOr($data['name'] ?? null, ''),
            status: Hydrate::stringOrNull($data['status'] ?? null),
            description: Hydrate::stringOr($data['description'] ?? null, ''),
            layer: Hydrate::stringOr($data['layer'] ?? null, 'attribute'),
            source: Hydrate::objectOrNull($data['source'] ?? null, Source::fromArray(...)),
        );
    }
}
