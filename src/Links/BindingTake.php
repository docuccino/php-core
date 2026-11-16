<?php

declare(strict_types=1);

namespace Docuccino\Core\Links;

/**
 * A path parameter that takes one column of one resource — what the code proves the segment is matched
 * on. `$resource` is an adapter's own name for the thing bound (a model class), compared and never
 * published; `$scopedTo` is the parameter the framework resolves this one within, where it does.
 *
 * @internal
 */
final readonly class BindingTake
{
    public function __construct(
        public string $resource,
        public string $key,
        public ?string $scopedTo = null,
    ) {}

    /** What a give must carry to fill this parameter. */
    public function token(): string
    {
        return $this->resource."\0".$this->key;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return ['resource' => $this->resource, 'key' => $this->key, ...($this->scopedTo === null ? [] : ['scopedTo' => $this->scopedTo])];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $resource = $data['resource'] ?? null;
        $key = $data['key'] ?? null;
        $scopedTo = $data['scopedTo'] ?? null;

        if (! is_string($resource) || ! is_string($key) || $resource === '' || $key === '') {
            return null;
        }

        return new self($resource, $key, is_string($scopedTo) ? $scopedTo : null);
    }
}
