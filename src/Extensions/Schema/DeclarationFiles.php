<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use ReflectionClass;
use Throwable;

/**
 * The files a class's declaration spans: its own, every parent's, and every trait flattened into any of
 * them.
 *
 * A fragment-cache dependency wherever a recovered answer depends on what the whole hierarchy declares —
 * an inherited property, a `hasMethod()` inheritance answers, a `$casts` array a parent model
 * contributes. The class's own file is only where the question was ASKED. PHP reports a trait-imported
 * member as the using class's, so the file that actually holds it is reachable no other way.
 *
 * Recording a file too many costs a rebuild; recording one too few serves a stale answer, so this errs
 * upward — while staying proportional, since a parent invalidates its subclasses and nothing else.
 */
final class DeclarationFiles
{
    /**
     * @return list<string> deduped, in hierarchy order; empty for a class that isn't loadable
     */
    public static function of(?string $fqcn): array
    {
        if ($fqcn === null || (! class_exists($fqcn) && ! trait_exists($fqcn))) {
            return [];
        }

        try {
            return self::forClass(new ReflectionClass($fqcn));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @return list<string>
     */
    public static function forClass(ReflectionClass $class): array
    {
        $files = [];
        for ($current = $class; $current !== false; $current = $current->getParentClass()) {
            $file = $current->getFileName();
            if ($file !== false) {
                $files[$file] = true;
            }

            foreach (self::traitFiles($current) as $trait) {
                $files[$trait] = true;
            }
        }

        return array_keys($files);
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @return list<string>
     */
    private static function traitFiles(ReflectionClass $class): array
    {
        $files = [];
        foreach ($class->getTraits() as $trait) {
            $file = $trait->getFileName();
            if ($file !== false) {
                $files[$file] = true;
            }

            // getTraits() reports only the traits used directly, and a trait may use traits itself.
            foreach (self::traitFiles($trait) as $nested) {
                $files[$nested] = true;
            }
        }

        return array_keys($files);
    }
}
