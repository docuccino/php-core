<?php

declare(strict_types=1);

namespace Docuccino\Core\Document;

use Docuccino\Core\Provenance\Provenance;
use Docuccino\Core\Support\Hydrate;

/**
 * The node-level `x-docuccino` member on operations, parameters, responses and schemas: id,
 * provenance, mock hints. {@see DocumentExtension} is the document-level counterpart. Unknown members
 * survive verbatim, so additive forward compatibility holds.
 *
 * @internal
 */
final readonly class NodeExtension
{
    /**
     * @param  array<string, mixed>|null  $mock
     * @param  array<string, mixed>  $rest
     */
    public function __construct(
        public ?string $id = null,
        public ?Provenance $provenance = null,
        public ?array $mock = null,
        public array $rest = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $id = Hydrate::stringOrNull($data['id'] ?? null);
        unset($data['id']);

        $provenance = null;
        if (isset($data['provenance']) && is_array($data['provenance'])) {
            /** @var list<array<string, mixed>> $records */
            $records = array_values($data['provenance']);
            $provenance = Provenance::fromArray($records);
        }
        unset($data['provenance']);

        $mock = null;
        if (isset($data['mock']) && is_array($data['mock'])) {
            /** @var array<string, mixed> $mock */
            $mock = $data['mock'];
        }
        unset($data['mock']);

        return new self(
            id: $id,
            provenance: $provenance,
            mock: $mock,
            rest: $data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];

        if ($this->id !== null) {
            $out['id'] = $this->id;
        }

        if ($this->provenance !== null && ! $this->provenance->isEmpty()) {
            $out['provenance'] = $this->provenance->toArray();
        }

        if ($this->mock !== null) {
            $out['mock'] = $this->mock;
        }

        return $out + $this->rest;
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }
}
