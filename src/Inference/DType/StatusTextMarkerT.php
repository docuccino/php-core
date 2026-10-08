<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference\DType;

/**
 * A placeholder for a payload member whose value is the reason phrase of the enclosing response's own HTTP
 * status (RFC 9457's `title` for an `about:blank` problem), the sibling of {@see StatusMarkerT}. `$type` is
 * what the member was read as before it was recognised, and is all its SCHEMA ever says: the phrase differs
 * per status, so a body shared across statuses states nothing more. `$fallback` is the value it takes for a
 * status the phrase table does not name, or null where nothing is known to stand in.
 *
 * The response-building seam resolves it for the EXAMPLE alone, against the status the response is
 * documented under; anywhere else it is just `$type`.
 */
final readonly class StatusTextMarkerT extends DType
{
    public const KIND = 'statusText';

    public function __construct(
        public DType $type,
        public ?LiteralT $fallback = null,
    ) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function toArray(): array
    {
        return $this->fallback === null
            ? ['kind' => self::KIND, 'type' => $this->type->toArray()]
            : ['kind' => self::KIND, 'type' => $this->type->toArray(), 'fallback' => $this->fallback->toArray()];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? null;
        $fallback = $data['fallback'] ?? null;
        $fallback = is_array($fallback) ? DType::fromArray($fallback) : null;

        return new self(
            is_array($type) ? DType::fromArray($type) : new UnknownT('status text without a type'),
            $fallback instanceof LiteralT ? $fallback : null,
        );
    }
}
