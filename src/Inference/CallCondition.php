<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

use Docuccino\Core\Support\Hydrate;

/**
 * A fact the analyser proved at one return: a method called on one of the callable's parameters, with
 * nothing but string literals for arguments, is known to answer `$value` on every path reaching it —
 * `$request->is('api/*')` is false inside `if (! $request->is('api/*')) { return …; }`, and
 * `$response->getStatusCode()` is 419 inside `if ($response->getStatusCode() === 419) { return …; }`.
 *
 * Only the fact travels. What the call MEANS is the host's to say, since the engine cannot know whether
 * a given `is()` tests a path or anything else.
 */
final readonly class CallCondition
{
    /**
     * @param  list<string>  $arguments
     */
    public function __construct(
        public string $parameter,
        public string $method,
        public array $arguments,
        public bool|int|float|string $value,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['parameter' => $this->parameter, 'method' => $this->method, 'arguments' => $this->arguments, 'value' => $this->value];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $arguments = $data['arguments'] ?? [];
        $value = $data['value'] ?? null;

        return new self(
            Hydrate::stringOr($data['parameter'] ?? null, ''),
            Hydrate::stringOr($data['method'] ?? null, ''),
            is_array($arguments) ? array_values(array_filter($arguments, 'is_string')) : [],
            is_scalar($value) ? $value : false,
        );
    }
}
