<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Support\Hydrate;

/**
 * One return path of an action with its flow-refined type. PHPStan's `MethodReturnStatementsNode`
 * pairs every `return` with the scope at that point, so per-return-path provenance comes for free.
 *
 * `$component` is the {@see ComponentDeclaration} the render path answering on this path declared, when
 * one did — absent everywhere else, which is every return an error renderer did not produce.
 *
 * Filled only under {@see CallableRef::$narrowToEvery}: `$returnsParameter` names the parameter this path
 * hands back unchanged — nothing that can run first may have written its body, status or media type —
 * `$conditions` the parameter calls proven to hold on the way here, and `$typeConditions` the parameter
 * classes proven to hold or not.
 */
final readonly class ReturnSite
{
    /**
     * @param  list<CallCondition>  $conditions
     * @param  list<TypeCondition>  $typeConditions
     */
    public function __construct(
        public DType $type,
        public SourceLocation $location,
        public ?ComponentDeclaration $component = null,
        public ?string $returnsParameter = null,
        public array $conditions = [],
        public array $typeConditions = [],
    ) {}

    /** The same return path under a declaration made further out on the call path. */
    public function withComponent(?ComponentDeclaration $component): self
    {
        return new self($this->type, $this->location, $component, $this->returnsParameter, $this->conditions, $this->typeConditions);
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
        if ($this->returnsParameter !== null) {
            $data['returnsParameter'] = $this->returnsParameter;
        }
        if ($this->conditions !== []) {
            $data['conditions'] = array_map(static fn (CallCondition $c): array => $c->toArray(), $this->conditions);
        }
        if ($this->typeConditions !== []) {
            $data['typeConditions'] = array_map(static fn (TypeCondition $c): array => $c->toArray(), $this->typeConditions);
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
            Hydrate::stringOrNull($data['returnsParameter'] ?? null),
            Hydrate::listOf($data['conditions'] ?? null, CallCondition::fromArray(...)),
            Hydrate::listOf($data['typeConditions'] ?? null, TypeCondition::fromArray(...)),
        );
    }
}
