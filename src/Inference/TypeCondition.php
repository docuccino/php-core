<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

use Docuccino\Core\Support\Hydrate;

/**
 * A fact the analyser proved at one return: one of the callable's parameters is (`$value` true) or is not
 * (false) an instance of `$class` on every path reaching it — `$response instanceof JsonResponse` is false
 * inside `if (! $response instanceof JsonResponse) { return $response; }`. {@see CallCondition}'s sibling:
 * only the fact travels, and what the parameter holds at run time is the host's to say.
 */
final readonly class TypeCondition
{
    public function __construct(
        public string $parameter,
        public string $class,
        public bool $value,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['parameter' => $this->parameter, 'class' => $this->class, 'value' => $this->value];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Hydrate::stringOr($data['parameter'] ?? null, ''),
            Hydrate::stringOr($data['class'] ?? null, ''),
            Hydrate::boolOrNull($data['value'] ?? null) === true,
        );
    }
}
