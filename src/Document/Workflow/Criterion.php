<?php

declare(strict_types=1);

namespace Docuccino\Core\Document\Workflow;

use Docuccino\Core\Support\Hydrate;

/**
 * One condition a step's result is held to, as Arazzo's Criterion Object states it: the condition, the
 * runtime expression it is applied to (`context`), and how it is read — `simple` where nothing says
 * otherwise, or `regex`, `jsonpath` or `xpath`, with the version of that expression language where one is
 * named.
 */
final readonly class Criterion
{
    public function __construct(
        public string $condition,
        public string $type = 'simple',
        public ?string $context = null,
        public ?string $version = null,
    ) {}

    /**
     * Reads both spellings of `type`: a name, or Arazzo's expression type object (`{type, version}`).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? null;
        $typed = is_array($type) ? $type : [];

        return new self(
            condition: Hydrate::stringOr($data['condition'] ?? '', ''),
            type: Hydrate::stringOrNull($typed['type'] ?? $type) ?? 'simple',
            context: Hydrate::stringOrNull($data['context'] ?? null),
            version: Hydrate::stringOrNull($typed['version'] ?? $data['version'] ?? null),
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'condition' => $this->condition,
            'context' => $this->context,
            'type' => $this->type,
            'version' => $this->version,
        ], static fn (?string $value): bool => $value !== null);
    }
}
