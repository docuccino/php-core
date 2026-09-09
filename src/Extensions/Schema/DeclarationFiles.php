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
 *
 * The order is hierarchy order and it is load-bearing rather than incidental: the first entry is the file
 * of the class asked about, and a caller reads it as where that class is written. So this list is
 * deliberately NOT canonicalised — sorting it for tidiness answers with whichever ancestor's path sorts
 * first, silently, since a dependency set compares the same either way. `DeclarationFilesTest`'s
 * leading-entry row is what refuses it.
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
     * The same list for a collaborator the build was handed at RUNTIME, or null when its declaration is
     * nothing a fragment can be keyed on.
     *
     * A dependency manifest records a file that is not there as absent, and absent compares FRESH for as
     * long as it stays absent — so a path that names no file keys nothing while looking like it does.
     * `eval()`'d code is exactly that: it reports a file like `/app/Tags.php(12) : eval()'d code`, which
     * no `is_file()` matches. A class with no file at all (an internal one) is the same answer arrived at
     * sooner. Either way the caller has been handed something it cannot key, and refusing to cache is the
     * only reading that cannot serve a stale fragment.
     *
     * An anonymous class and a class inside a phar both key fine, and are deliberately not special-cased:
     * `new class {…}` reports the file it was written in, and `phar://app.phar/src/Tags.php` both
     * `is_file()`s and hashes — to the entry's own bytes, so rebuilding the phar retires the fragment.
     *
     * One unhashable file anywhere in the hierarchy answers null for the whole class: a parent declared in
     * `eval()`'d code writes as much of the answer as the leaf does.
     *
     * @return list<string>|null
     */
    public static function keyableFor(object $subject): ?array
    {
        $files = self::forClass(new ReflectionClass($subject));

        foreach ($files as $file) {
            if (! @is_file($file)) {
                return null;
            }
        }

        return $files === [] ? null : $files;
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
