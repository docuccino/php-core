<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use BackedEnum;
use Closure;
use Docuccino\Core\Support\Json;
use ReflectionClass;
use ReflectionFunction;
use UnitEnum;

/**
 * A digest of one resolved collaborator's own configuration: its properties, private and inherited ones
 * included, keyed by where they were declared.
 *
 * The other half of {@see DeclarationFiles} — that one keys a collaborator on the bytes it is WRITTEN
 * in, this one on what the instance was handed. Neither answers for the other: two instances of one
 * class share every file, and a class edited in place keeps every property it was constructed with. A
 * fragment key that carries one and not the other is under-keyed, so anything reading a collaborator's
 * answer into output owes both.
 *
 * What it sees is what configuration is made of: scalars, arrays of them, enum cases, and a closure by
 * where it was written plus what it captured. What it does NOT see is a collaborator object's own
 * fields — this deliberately does not descend into one, since an injected container would be an
 * unbounded walk and a collaborator is a dependency rather than a setting. Two instances differing only
 * inside such an object therefore still key alike; holding the setting itself is the fix.
 *
 * The digest leans on {@see Json::stable()} being TOTAL over what a property can hold: a value
 * `json_encode` refuses — a binary blob, a resource, an INF — fingerprints as itself, because one
 * shared digest for all of them would reopen the very cache collision this closes.
 *
 * @internal
 */
final class ConfigurationDigest
{
    /** How deep {@see readable()} descends into a property before it stops. */
    private const int MAX_DEPTH = 64;

    /** What stands in for a value below {@see MAX_DEPTH}. */
    private const string TRUNCATED = '@docuccino:depth';

    /** 16 hex characters, or `''` for an instance with no readable state at all. */
    public static function of(object $subject): string
    {
        $state = [];
        foreach (self::properties($subject) as $key => $value) {
            $state[$key] = self::readable($value);
        }

        return $state === [] ? '' : substr(hash('sha256', Json::stable($state)), 0, 16);
    }

    /**
     * An instance's readable property values, keyed `Declaring\Class::name`. Uninitialised typed
     * properties have no value to read and static ones belong to the class, not the configuration.
     *
     * @return array<string, mixed>
     */
    private static function properties(object $subject): array
    {
        $values = [];
        for ($class = new ReflectionClass($subject); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || ! $property->isInitialized($subject)) {
                    continue;
                }

                $values[$property->getDeclaringClass()->getName().'::'.$property->getName()] = $property->getValue($subject);
            }
        }

        return $values;
    }

    /**
     * The two configuration values {@see Json::stable()} would flatten to a bare class name — an enum
     * case, so `Mode::Strict` and `Mode::Loose` are not the same setting, and a closure, read as where
     * it was written plus what it captured. Everything else is left to `Json::stable()`, which is why a
     * collaborator object still collapses to its class.
     *
     * A closure's source position is an absolute path, which never leaves this class: the digest is a
     * fragment-cache key and nothing else, so it is local to the machine that built the cache.
     *
     * The descent is bounded because a property may hold anything at all, `$a['self'] = &$a` included —
     * and that is a stack overflow, which is SIGSEGV with no message. `Json::stable()` bounds its own
     * walk for the same reason, but this one reaches the value first.
     */
    private static function readable(mixed $value, int $depth = 0): mixed
    {
        if (is_array($value)) {
            return $depth >= self::MAX_DEPTH
                ? self::TRUNCATED
                : array_map(static fn (mixed $item): mixed => self::readable($item, $depth + 1), $value);
        }

        if ($value instanceof Closure) {
            $function = new ReflectionFunction($value);

            return [
                'closure' => $function->getFileName().':'.$function->getStartLine().'-'.$function->getEndLine(),
                'bound' => $function->getClosureScopeClass()?->getName(),
                'captured' => self::readable($function->getStaticVariables(), $depth + 1),
            ];
        }

        if ($value instanceof BackedEnum) {
            return $value::class.'::'.$value->value;
        }

        return $value instanceof UnitEnum ? $value::class.'::'.$value->name : $value;
    }
}
