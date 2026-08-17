<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\UnknownT;

/**
 * One return path of an action with its flow-refined type. PHPStan's `MethodReturnStatementsNode`
 * pairs every `return` with the scope at that point, so per-return-path provenance comes for free.
 *
 * `$component` is the {@see ComponentDeclaration} the render path answering on this path declared, when
 * one did — absent everywhere else, which is every return an error renderer did not produce.
 */
final readonly class ReturnSite
{
    public function __construct(
        public DType $type,
        public SourceLocation $location,
        public ?ComponentDeclaration $component = null,
    ) {}

    /** The same return path under a declaration made further out on the call path. */
    public function withComponent(?ComponentDeclaration $component): self
    {
        return new self($this->type, $this->location, $component);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = ['type' => $this->type->toArray(), 'location' => $this->location->toArray()];
        if ($this->component !== null) {
            $data['component'] = $this->component->toArray();
        }

        return $data;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? [];
        $location = $data['location'] ?? [];
        $component = $data['component'] ?? null;

        return new self(
            is_array($type) ? DType::fromArray($type) : new UnknownT('malformed return type'),
            is_array($location) ? SourceLocation::fromArray($location) : new SourceLocation(''),
            is_array($component) ? ComponentDeclaration::fromArray($component) : null,
        );
    }
}
