<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

/**
 * The attributes discovered on a route's action, with method-over-class precedence. Generic over
 * attribute instances — it imports no concrete attribute class, so it stays framework- and
 * attribute-package-agnostic while the adapter populates it from reflection.
 *
 * Precedence is positional: entries are appended most-specific first, so {@see first()} returns the
 * most specific instance and {@see all()} lists them in that order. Each entry also records whether it
 * was INHERITED from an enclosing scope rather than written on the subject itself, which is what
 * {@see direct()} filters on.
 */
final class AttributeSet
{
    /**
     * Whether each entry of {@see $attributes} was inherited, by the same index.
     *
     * @var list<bool>
     */
    private array $inherited;

    /**
     * @param  list<object>  $attributes  most-specific first (method-level before class-level)
     */
    public function __construct(
        private array $attributes = [],
    ) {
        $this->inherited = array_fill(0, count($attributes), false);
    }

    public function add(object $attribute, bool $inherited = false): void
    {
        $this->attributes[] = $attribute;
        $this->inherited[] = $inherited;
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
     * The instances written on the SUBJECT itself, without those inherited from an enclosing controller
     * class or one of its parents — what a diagnostic wants when the mistake can only be corrected
     * where it was written.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return list<T>
     */
    public function direct(string $class): array
    {
        $out = [];
        foreach ($this->attributes as $index => $attribute) {
            if ($attribute instanceof $class && ! $this->inherited[$index]) {
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
