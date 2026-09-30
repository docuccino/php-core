<?php

declare(strict_types=1);

namespace Docuccino\Core\Diagnostics;

use Docuccino\Core\Provenance\ClassNames;
use Docuccino\Core\Support\Fqcn;
use ReflectionAttribute;
use Throwable;

/**
 * Instantiates the Docuccino attributes an application wrote, and the one mint of `attribute.unreadable`
 * for one PHP cannot build: a declaration the author wrote never vanishes without a word, whether it
 * sits on an action, a schema class, a property, an enum case or a filter class.
 *
 * Only the thrown CLASS is published, never its message: a `TypeError` names the absolute file the
 * declaration was written in, and PHP allows `new` in an attribute's arguments, so the cause can be an
 * anonymous class whose name carries the machine too ({@see ClassNames}). The caller hands `$site`
 * already publishable — a class name through {@see ClassNames::publishable()}, or a relativised path.
 *
 * @internal
 */
final class UnreadableAttribute
{
    /**
     * The declarations PHP could build, in source order, and a report for each one it could not.
     *
     * @template T of object
     *
     * @param  list<ReflectionAttribute<T>>  $declarations
     * @return array{0: list<T>, 1: list<Diagnostic>}
     */
    public static function instantiate(array $declarations, string $site, ?string $routeSignature = null): array
    {
        $instances = [];
        $diagnostics = [];
        foreach ($declarations as $declaration) {
            try {
                $instances[] = $declaration->newInstance();
            } catch (Throwable $cause) {
                $diagnostics[] = self::diagnostic($declaration->getName(), $site, ClassNames::publishable($cause::class), $routeSignature);
            }
        }

        return [$instances, $diagnostics];
    }

    private static function diagnostic(string $attribute, string $site, string $cause, ?string $routeSignature): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'attribute.unreadable',
            message: sprintf('The #[%s] on %s could not be instantiated and was ignored.', Fqcn::short($attribute), $site),
            routeSignature: $routeSignature,
            help: sprintf('Its constructor threw %s. Check the arguments at that declaration against the attribute\'s constructor.', $cause),
        );
    }
}
