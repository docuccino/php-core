<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

use Docuccino\Core\Support\Arr;
use Docuccino\Core\Support\Hydrate;

/**
 * A guard as the analyser read it off the source: {@see CallCondition}s and {@see TypeCondition}s joined by
 * `&&`, `||` and `!`, with a condition it could not read standing as an unknown. Read in three values: each
 * leaf answers true, false or nothing, and the whole is false only where it is false whatever the unanswered
 * leaves turn out to be — so a guard with one unreadable half never rules a path out on the other alone.
 */
final readonly class ConditionTree
{
    private const ALL = 'all';

    private const ANY = 'any';

    private const NOT = 'not';

    private const LEAF = 'leaf';

    private const KNOWN = 'known';

    /**
     * @param  self::ALL|self::ANY|self::NOT|self::LEAF|self::KNOWN  $kind
     * @param  list<self>  $operands
     */
    private function __construct(
        private string $kind,
        private array $operands = [],
        private CallCondition|TypeCondition|null $leaf = null,
        private ?bool $known = null,
    ) {}

    public static function leaf(CallCondition|TypeCondition $condition): self
    {
        return new self(self::LEAF, leaf: $condition);
    }

    /** A condition already answered, or — null — one nothing can answer. */
    public static function known(?bool $value): self
    {
        return new self(self::KNOWN, known: $value);
    }

    public static function not(self $operand): self
    {
        return match ($operand->kind) {
            self::KNOWN => self::known($operand->known === null ? null : ! $operand->known),
            self::NOT => $operand->operands[0],
            default => new self(self::NOT, [$operand]),
        };
    }

    /**
     * @param  list<self>  $operands
     */
    public static function all(array $operands): self
    {
        return self::join(self::ALL, $operands);
    }

    /**
     * @param  list<self>  $operands
     */
    public static function any(array $operands): self
    {
        return self::join(self::ANY, $operands);
    }

    /**
     * What the guard answers, given what each leaf answers: null where an unanswered leaf could still decide it.
     *
     * @param  callable(CallCondition|TypeCondition): ?bool  $holds
     */
    public function evaluate(callable $holds): ?bool
    {
        $answered = $this->answered($holds);

        return $answered->kind === self::KNOWN ? $answered->known : null;
    }

    /**
     * The same guard with every leaf `$holds` answers replaced by its answer.
     *
     * @param  callable(CallCondition|TypeCondition): ?bool  $holds  null leaves the leaf as it is
     */
    public function answered(callable $holds): self
    {
        if ($this->leaf !== null) {
            $answer = $holds($this->leaf);

            return $answer === null ? $this : self::known($answer);
        }

        return match ($this->kind) {
            self::NOT => self::not($this->operands[0]->answered($holds)),
            self::ALL, self::ANY => self::join($this->kind, array_map(static fn (self $o): self => $o->answered($holds), $this->operands)),
            default => $this,
        };
    }

    /** Whether the guard could rule a path out: anything but one that always holds, or that says nothing. */
    public function constrains(): bool
    {
        return $this->kind !== self::KNOWN || $this->known === false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return match ($this->kind) {
            self::LEAF => $this->leaf instanceof TypeCondition ? ['type' => $this->leaf->toArray()] : ['call' => $this->leaf?->toArray()],
            self::KNOWN => ['known' => $this->known],
            self::NOT => ['not' => $this->operands[0]->toArray()],
            default => [$this->kind => array_map(static fn (self $o): array => $o->toArray(), $this->operands)],
        };
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        foreach ([self::ALL, self::ANY] as $kind) {
            if (array_key_exists($kind, $data)) {
                return self::join($kind, Hydrate::listOf($data[$kind], self::fromArray(...)));
            }
        }

        return match (true) {
            is_array($data['not'] ?? null) => self::not(self::fromArray(Arr::stringKeyed($data['not']))),
            is_array($data['call'] ?? null) => self::leaf(CallCondition::fromArray($data['call'])),
            is_array($data['type'] ?? null) => self::leaf(TypeCondition::fromArray($data['type'])),
            default => self::known(Hydrate::boolOrNull($data['known'] ?? null)),
        };
    }

    /**
     * `$kind` over the operands, settled as far as their answers allow: a false member settles `all` false and a
     * true one settles `any` true, a member that cannot change the answer drops out, and one left stands for the whole.
     *
     * @param  self::ALL|self::ANY  $kind
     * @param  list<self>  $operands
     */
    private static function join(string $kind, array $operands): self
    {
        $decides = $kind === self::ANY;
        $kept = [];
        foreach ($operands as $operand) {
            if ($operand->kind === self::KNOWN && $operand->known === $decides) {
                return self::known($decides);
            }
            if ($operand->kind === self::KNOWN && $operand->known === ! $decides) {
                continue;
            }
            if ($operand->kind === $kind) {
                array_push($kept, ...$operand->operands);

                continue;
            }
            $kept[] = $operand;
        }

        return match (count($kept)) {
            0 => self::known(! $decides),
            1 => $kept[0],
            default => new self($kind, $kept),
        };
    }
}
