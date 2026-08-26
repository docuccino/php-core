<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Attributes\Description;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Provenance\ClassNames;
use ReflectionClass;
use Throwable;

/**
 * Reads the class-target prose a class declares about ITSELF — `#[Description]` — and writes it as the
 * `description` of the schema published for that class. The class-level counterpart of
 * {@see PropertyAnnotations}, and it goes through the same {@see DescribedText} rule, so a `file:` or a
 * both-and-neither declaration is refused here exactly as it is on a property.
 *
 * Only the attribute. A class DOCBLOCK is not read: it is where an author explains a class to whoever
 * maintains it next, so it routinely names properties, attributes and internal machinery that the
 * consumer of an emitted document cannot see — and a description that misinforms costs a reader more
 * than an absent one. The attribute says, unambiguously, "publish this sentence".
 *
 * The declaration is read off the class the schema is FOR, not off its parents: a base DTO's sentence
 * describes the base, and inheriting it would put one description on every shape below it.
 */
final class ClassAnnotations
{
    /**
     * {@see describe()} with the diagnostics reported straight to the schema context.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function applyTo(SchemaContext $context, array $schema, string $fqcn): array
    {
        [$schema, $diagnostics] = self::describe($schema, $fqcn);

        foreach ($diagnostics as $diagnostic) {
            $context->diagnostic($diagnostic);
        }

        return $schema;
    }

    /**
     * `$schema` with the class's `#[Description]` as its `description`, plus whatever its declarations
     * said that a schema cannot hold ({@see DescribedText} for which one of several stands). A schema
     * already carrying a description keeps it — a mapper that built one knows something the class-level
     * sentence does not.
     *
     * @param  array<string, mixed>  $schema
     * @return array{0: array<string, mixed>, 1: list<Diagnostic>}
     */
    public static function describe(array $schema, string $fqcn): array
    {
        if (isset($schema['description']) || ! class_exists($fqcn)) {
            return [$schema, []];
        }

        $site = ClassNames::publishable($fqcn);

        $diagnostics = [];
        $text = null;
        foreach (self::all($fqcn) as $description) {
            $candidate = DescribedText::of($description, $site, "a schema's description", $diagnostics);
            $text ??= $candidate;
        }

        if ($text !== null) {
            $schema['description'] = $text;
        }

        return [$schema, $diagnostics];
    }

    /**
     * The class's own `#[Description]` declarations, in source order.
     *
     * @param  class-string  $fqcn
     * @return list<Description>
     */
    private static function all(string $fqcn): array
    {
        $instances = [];
        foreach ((new ReflectionClass($fqcn))->getAttributes(Description::class) as $declaration) {
            try {
                $instances[] = $declaration->newInstance();
            } catch (Throwable) {
                // An argument the constructor rejects is the adapter's `attribute.unreadable` story on an
                // action; here there is no route to name, so it simply says nothing.
            }
        }

        return $instances;
    }
}
