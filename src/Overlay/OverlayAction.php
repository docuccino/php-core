<?php

declare(strict_types=1);

namespace Docuccino\Core\Overlay;

/**
 * One OpenAPI Overlay 1.0 action: a `target` selector plus exactly one operation — `update` (merge an
 * object, or replace a scalar/array) or `remove` (delete the node). `update` can legitimately be
 * `null` or `false`, so its presence is tracked explicitly rather than inferred from the value.
 *
 * An action declaring both operations is a conflict, and it still parses with both flags set:
 * {@see OverlayApplier} raises an error at apply time instead of guessing which was meant.
 */
final readonly class OverlayAction
{
    public function __construct(
        public string $target,
        public bool $remove = false,
        public bool $hasUpdate = false,
        public mixed $update = null,
        public ?string $description = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, int $index): self
    {
        $target = $data['target'] ?? null;
        if (! is_string($target) || $target === '') {
            throw InvalidOverlayException::actionWithoutTarget($index);
        }

        $remove = ($data['remove'] ?? null) === true;
        $hasUpdate = array_key_exists('update', $data);

        if (! $remove && ! $hasUpdate) {
            throw InvalidOverlayException::actionWithoutOperation($index);
        }

        $description = $data['description'] ?? null;

        return new self(
            target: $target,
            remove: $remove,
            hasUpdate: $hasUpdate,
            update: $hasUpdate ? $data['update'] : null,
            description: is_string($description) ? $description : null,
        );
    }
}
