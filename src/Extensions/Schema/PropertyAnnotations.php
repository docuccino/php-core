<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Attributes\Description;
use Docuccino\Attributes\Example;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Provenance\ClassNames;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/**
 * Reads the property-target prose attributes — `#[Description]` and `#[Example]` — off a class and
 * writes them to the schema each named member publishes. Every class-hoisting schema mapper goes
 * through here, so a plain DTO carries them exactly as a Data class or a model does.
 *
 * These beat the docblock a property already gets its `description` and `example` from, because an
 * attribute outranks a docblock everywhere else (precedence: docblock 30 < attribute 40) and one
 * docblock serves two readers. An attribute argument is also a real PHP value, so unlike `@example`
 * there is nothing to guess about its type.
 *
 * A schema property is a bare slot: one `description`, one `example` value. Anything a declaration
 * says that has no slot there — a `file:`, a named or targeted example — is reported rather than
 * dropped quietly, since the author is standing at the property that would carry it.
 */
final class PropertyAnnotations
{
    /**
     * {@see apply()} with the diagnostics reported straight to the schema context — what a mapper
     * building a component wants.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, string>  $keys
     * @return array<string, mixed>
     */
    public static function applyTo(SchemaContext $context, array $object, string $fqcn, array $keys = []): array
    {
        [$object, $diagnostics] = self::apply($object, $fqcn, $keys);

        foreach ($diagnostics as $diagnostic) {
            $context->diagnostic($diagnostic);
        }

        return $object;
    }

    /**
     * `$object` with each property's declarations written to the member it publishes, plus everything
     * those declarations said that a property schema cannot hold. `$keys` maps a PHP property name to
     * the key it publishes under, for a mapper whose wire names differ from its properties.
     *
     * A property the object doesn't publish is skipped whole: a hidden one legitimately carries prose
     * nobody reads here, and diagnosing that would fire where there is nothing to do.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, string>  $keys
     * @return array{0: array<string, mixed>, 1: list<Diagnostic>}
     */
    public static function apply(array $object, string $fqcn, array $keys = []): array
    {
        if (! class_exists($fqcn)) {
            return [$object, []];
        }

        $published = is_array($object['properties'] ?? null) ? $object['properties'] : [];
        if ($published === []) {
            return [$object, []];
        }

        // The name every diagnostic below stands a property against. `$fqcn` is whatever the caller was
        // handed, and `::class` on an ANONYMOUS class is the base name, a NUL byte, the absolute file it
        // was written in and a counter of the anonymous classes the PROCESS declared before it — none of
        // which a published diagnostic may carry ({@see ClassNames}).
        $site = ClassNames::publishable($fqcn);

        $diagnostics = [];
        foreach ((new ReflectionClass($fqcn))->getProperties() as $property) {
            $key = $keys[$property->getName()] ?? $property->getName();
            if (! is_array($published[$key] ?? null)) {
                continue;
            }

            /** @var array<string, mixed> $schema */
            $schema = $published[$key];
            $published[$key] = self::annotate($schema, $property, $site, $diagnostics);
        }

        $object['properties'] = $published;

        return [$object, $diagnostics];
    }

    /**
     * One property's schema with its declarations applied. `$declaring` is the publishable class name
     * {@see apply()} resolved, so the site is composed around it rather than around a raw `class-string`.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private static function annotate(array $schema, ReflectionProperty $property, string $declaring, array &$diagnostics): array
    {
        $site = $declaring.'::$'.$property->getName();

        $description = self::first($property, Description::class);
        if ($description !== null) {
            $text = DescribedText::of($description, $site, "a property's description", $diagnostics);
            if ($text !== null) {
                $schema['description'] = $text;
            }
        }

        $example = self::exampleValue($property, $site, $diagnostics);
        if ($example !== null) {
            $schema['example'] = $example[0];
        }

        return $schema;
    }

    /**
     * The one example value a property publishes, wrapped so `null` and "none" stay distinguishable, or
     * null when the declarations describe none.
     *
     * A property schema holds a bare value, so only a nameless inline `value:` fits: a name, an
     * external value, a file or a target names an Example Object, which lives on a response, a request
     * body or a parameter. Two usable declarations leave the first standing, as they do on an
     * operation — source order, so nothing depends on discovery order.
     *
     * @param  list<Diagnostic>  $diagnostics
     * @return array{0: mixed}|null
     */
    private static function exampleValue(ReflectionProperty $property, string $site, array &$diagnostics): ?array
    {
        $found = null;
        foreach (self::all($property, Example::class) as $example) {
            $sources = (int) ($example->value !== null)
                + (int) ($example->file !== null)
                + (int) ($example->externalValue !== null);

            if ($sources !== 1) {
                $diagnostics[] = new Diagnostic(
                    severity: Severity::Warning,
                    code: 'attribute.example-unusable',
                    message: sprintf(
                        'An #[Example] on %s carries %s; it was not documented.',
                        $site,
                        $sources === 0 ? 'no value — give it a `value:`' : 'more than one value — `value:`, `file:` and `externalValue:` are alternatives',
                    ),
                    help: 'One value (`value:`, `file:` or `externalValue:`) and at most one target (`status:`, `request:` or `parameter:`) per declaration.',
                );

                continue;
            }

            $unheld = self::unheldArguments($example);
            if ($unheld !== []) {
                $diagnostics[] = self::unsupported(
                    '#[Example]',
                    $site,
                    sprintf('a property publishes one bare example value, which carries no %s', implode(', no ', $unheld)),
                    'Drop those arguments to pin the property\'s example, or move the declaration to the action, where an Example Object can carry them.',
                );

                continue;
            }

            $found ??= [$example->value];
        }

        return $found;
    }

    /**
     * The arguments a declaration names that only an Example Object has a slot for, as it names them.
     *
     * @return list<string>
     */
    private static function unheldArguments(Example $example): array
    {
        $named = [];

        foreach ([
            'name:' => $example->name !== null,
            'summary:' => $example->summary !== null,
            'description:' => $example->description !== null,
            'externalValue:' => $example->externalValue !== null,
            'file:' => $example->file !== null,
            'status:' => $example->status !== null,
            'mediaType:' => $example->mediaType !== null,
            'parameter:' => $example->parameter !== null,
            'request:' => $example->request,
        ] as $argument => $stated) {
            if ($stated) {
                $named[] = '`'.$argument.'`';
            }
        }

        return $named;
    }

    private static function unsupported(string $what, string $site, string $because, string $help): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'attribute.property-unsupported',
            message: sprintf('The %s on %s says something a property schema cannot hold — %s; it was ignored.', $what, $site, $because),
            help: $help,
        );
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $attribute
     * @return list<T>
     */
    private static function all(ReflectionProperty $property, string $attribute): array
    {
        $instances = [];
        foreach ($property->getAttributes($attribute) as $declaration) {
            try {
                $instances[] = $declaration->newInstance();
            } catch (Throwable) {
                // An argument the constructor rejects is already the adapter's `attribute.unreadable`
                // story on an action; here there is no route to name, so it simply says nothing.
            }
        }

        return $instances;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $attribute
     * @return T|null
     */
    private static function first(ReflectionProperty $property, string $attribute): ?object
    {
        return self::all($property, $attribute)[0] ?? null;
    }
}
