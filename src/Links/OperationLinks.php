<?php

declare(strict_types=1);

namespace Docuccino\Core\Links;

use Docuccino\Core\Support\Hydrate;
use Docuccino\Core\Support\JsonValue;
use Throwable;

/**
 * What one operation's code proves about the links it takes part in: the path parameters it binds
 * ({@see BindingTake}), the columns its responses give back ({@see BodyGive}), and the links declared on
 * it ({@see DeclaredLink}). {@see ResponseLinks} joins these across the document.
 *
 * Carried as JSON because it rides the operation fragment's notes, which is what makes a warm build write
 * the links a cold one does. A declaration JSON cannot carry — a pure enum case among its parameters — is
 * kept as the reason it could not be ({@see $unreadable}), so the writer can report it rather than lose it.
 *
 * @internal
 */
final readonly class OperationLinks
{
    /**
     * @param  array<string, BindingTake>  $takes  path parameter → what it takes
     * @param  list<BodyGive>  $gives
     * @param  list<DeclaredLink>  $declared
     * @param  list<string>  $unreadable  why a declared link could not be recorded
     */
    public function __construct(
        public array $takes = [],
        public array $gives = [],
        public array $declared = [],
        public array $unreadable = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->takes === [] && $this->gives === [] && $this->declared === [] && $this->unreadable === [];
    }

    /**
     * The facts as JSON. `serialize_precision` is pinned because a declared parameter's value reaches the
     * published document, and a float encoded at the host's setting is the machine deciding what it says.
     */
    public function toJson(): string
    {
        $previous = ini_set('serialize_precision', '-1');

        try {
            try {
                return self::encode($this->takes, $this->gives, $this->declared, $this->unreadable);
            } catch (Throwable $failure) {
                return self::encode($this->takes, $this->gives, [], [...$this->unreadable, $failure->getMessage()]);
            }
        } finally {
            if (is_string($previous)) {
                ini_set('serialize_precision', $previous);
            }
        }
    }

    /** The facts back from {@see toJson()}; anything that does not read as them is no facts at all. */
    public static function fromJson(string $json): self
    {
        try {
            $data = Hydrate::map(JsonValue::decode($json));
        } catch (Throwable) {
            return new self;
        }

        $takes = [];
        foreach (Hydrate::map($data['takes'] ?? null) as $parameter => $take) {
            $take = Hydrate::objectOrNull($take, BindingTake::fromArray(...));
            if ($take !== null) {
                $takes[$parameter] = $take;
            }
        }

        return new self(
            takes: $takes,
            gives: array_values(array_filter(Hydrate::listOf($data['gives'] ?? null, BodyGive::fromArray(...)))),
            declared: array_values(array_filter(Hydrate::listOf($data['declared'] ?? null, DeclaredLink::fromArray(...)))),
            unreadable: Hydrate::stringList($data['unreadable'] ?? null),
        );
    }

    /**
     * @param  array<string, BindingTake>  $takes
     * @param  list<BodyGive>  $gives
     * @param  list<DeclaredLink>  $declared
     * @param  list<string>  $unreadable
     */
    private static function encode(array $takes, array $gives, array $declared, array $unreadable): string
    {
        return json_encode(array_filter([
            'takes' => array_map(static fn (BindingTake $take): array => $take->toArray(), $takes),
            'gives' => array_map(static fn (BodyGive $give): array => $give->toArray(), $gives),
            'declared' => array_map(static fn (DeclaredLink $link): array => $link->toArray(), $declared),
            'unreadable' => $unreadable,
        ], static fn (array $member): bool => $member !== []), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
