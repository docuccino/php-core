<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

/**
 * A single semantic change between two documents: what kind of change, on which target node
 * (identified by its stable `x-docuccino.id` where available), a human-readable path, whether it is
 * a breaking change, a stable classification `code`, and any field-level detail.
 */
final readonly class Change
{
    /**
     * @param  list<FieldChange>  $fields
     */
    public function __construct(
        public ChangeKind $kind,
        public ChangeTarget $target,
        public string $id,
        public string $path,
        public bool $breaking,
        public string $code,
        public array $fields = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'kind' => $this->kind->value,
            'target' => $this->target->value,
            'id' => $this->id,
            'path' => $this->path,
            'breaking' => $this->breaking,
            'code' => $this->code,
        ];

        if ($this->fields !== []) {
            $out['fields'] = array_map(static fn (FieldChange $f): array => $f->toArray(), $this->fields);
        }

        return $out;
    }

    /**
     * A deterministic sort key: breaking first, then path, code, kind, id.
     */
    public function sortKey(): string
    {
        return ($this->breaking ? '0' : '1')
            .'|'.$this->path
            .'|'.$this->code
            .'|'.$this->kind->value
            .'|'.$this->id;
    }
}
