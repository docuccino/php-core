<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use JsonSerializable;
use ReflectionClass;
use ReflectionProperty;

/**
 * Whether a plain object's key is present, which is a question of initialisation and never of
 * nullability: `json_encode` writes every initialised public property, `null` included, and a client
 * may leave out whatever a default fills in.
 *
 * @internal
 */
final class PropertyPresence
{
    /**
     * Whether `json_encode` always writes the key: the property is untyped (implicitly `null`), has a
     * default, or is promoted by the constructor the class runs. Never for a `JsonSerializable`, which
     * states its own keys, nor for a name that is no declared property (a `@property` tag).
     */
    public static function alwaysWritten(string $fqcn, string $property): bool
    {
        if (is_a($fqcn, JsonSerializable::class, true)) {
            return false;
        }

        $reflected = self::reflect($fqcn, $property);
        if ($reflected === null) {
            return false;
        }

        return $reflected[1]->hasDefaultValue() || self::promotedByTheConstructorThatRuns(...$reflected);
    }

    /**
     * Whether a default supplies the value when the key is left out: the property's own (an untyped one's
     * implicit `null` included), or, for a promoted one, its constructor parameter's.
     */
    public static function defaulted(string $fqcn, string $property): bool
    {
        $reflected = self::reflect($fqcn, $property);
        if ($reflected === null) {
            return false;
        }

        $declared = $reflected[1];
        if ($declared->hasDefaultValue()) {
            return true;
        }

        if (! $declared->isPromoted()) {
            return false;
        }

        foreach ($declared->getDeclaringClass()->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->getName() === $property) {
                return $parameter->isDefaultValueAvailable();
            }
        }

        return false;
    }

    /** @return array{ReflectionClass<object>, ReflectionProperty}|null */
    private static function reflect(string $fqcn, string $property): ?array
    {
        if (! class_exists($fqcn)) {
            return null;
        }

        $class = new ReflectionClass($fqcn);
        if (! $class->hasProperty($property)) {
            return null;
        }

        $reflected = $class->getProperty($property);

        return $reflected->isPublic() && ! $reflected->isStatic() ? [$class, $reflected] : null;
    }

    /**
     * A subclass that replaces the promoting constructor may never call it, so the promise holds only
     * where that constructor is the one the class runs.
     *
     * @param  ReflectionClass<object>  $class
     */
    private static function promotedByTheConstructorThatRuns(ReflectionClass $class, ReflectionProperty $property): bool
    {
        return $property->isPromoted()
            && $class->getConstructor()?->getDeclaringClass()->getName() === $property->getDeclaringClass()->getName();
    }
}
