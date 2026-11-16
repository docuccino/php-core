<?php

declare(strict_types=1);

namespace Docuccino\Core\Links;

use Docuccino\Core\Contract\Pointer;

/**
 * One column of one resource that a response body carries verbatim, at a JSON pointer into the body —
 * the other half of a {@see BindingTake}, matched on the same resource and key. `body` names the shape
 * whose member the pointer ends at, so an adapter can tell a member a later transform moved.
 *
 * @internal
 */
final readonly class BodyGive
{
    public function __construct(
        public string $status,
        public string $resource,
        public string $key,
        public string $pointer,
        public string $body = '',
    ) {}

    /** The member the pointer ends at. */
    public function member(): string
    {
        $tokens = explode('/', $this->pointer);

        return Pointer::unescape((string) end($tokens));
    }

    public function token(): string
    {
        return $this->resource."\0".$this->key;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return ['status' => $this->status, 'resource' => $this->resource, 'key' => $this->key, 'pointer' => $this->pointer, 'body' => $this->body];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        foreach (['status', 'resource', 'key', 'pointer'] as $member) {
            if (! is_string($data[$member] ?? null) || $data[$member] === '') {
                return null;
            }
        }

        $body = $data['body'] ?? '';

        /** @var array{status: string, resource: string, key: string, pointer: string} $data */
        return new self($data['status'], $data['resource'], $data['key'], $data['pointer'], is_string($body) ? $body : '');
    }
}
