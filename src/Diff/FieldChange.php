<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

/**
 * One field-level change on a changed node: the field name (possibly dotted, e.g.
 * `schema.properties.status.enum`) with its old and new values.
 */
final readonly class FieldChange
{
    public function __construct(
        public string $field,
        public mixed $old,
        public mixed $new,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['field' => $this->field, 'old' => $this->old, 'new' => $this->new];
    }
}
