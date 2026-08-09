<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

/**
 * The attributes discovered on a route's action, with method-over-class precedence. Generic over
 * attribute instances — it imports no concrete attribute class, so it stays framework- and
 * attribute-package-agnostic while the adapter populates it from reflection.
 *
 * Precedence is positional: entries are appended most-specific first, so {@see first()} returns the
 * most specific instance and {@see all()} lists them in that order.
 */
final class AttributeSet
{
    /**
     * @param  list<object>  $attributes  most-specific first (method-level before class-level)
     */
    public function __construct(
        private array $attributes = [],
    ) {}

    public function add(object $attribute): void
    {
        $this->attributes[] = $attribute;
    }

    /**
     * All instances of the given attribute class, most-specific first.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return list<T>
     */
    public function all(string $class): array
    {
        $out = [];
        foreach ($this->attributes as $attribute) {
            if ($attribute instanceof $class) {
                $out[] = $attribute;
            }
        }

        return $out;
    }

    /**
     * The most-specific instance of the given attribute class, or null.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    public function first(string $class): ?object
    {
        foreach ($this->attributes as $attribute) {
            if ($attribute instanceof $class) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * @param  class-string  $class
     */
    public function has(string $class): bool
    {
        return $this->first($class) !== null;
    }
}
