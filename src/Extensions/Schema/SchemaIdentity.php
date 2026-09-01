<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Attributes\Hidden;
use Docuccino\Attributes\SchemaId;
use Docuccino\Attributes\SchemaName;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Provenance\ClassNames;
use Docuccino\Core\Support\NameList;
use Docuccino\Core\Support\PlainText;
use ReflectionClass;

/**
 * Reads `#[SchemaName]` (component display name), `#[SchemaId]` (diff identity) and the `#[Hidden]`
 * deny-list — class-level names and the per-property form — off a class. Every class-hoisting schema
 * mapper reads them through here, so the behaviour is identical whether the source is core's class
 * mapper, a spatie Data class, an API Resource or an Eloquent model.
 *
 * The class-level deny-list is the only half that can miss — the per-property form sits ON the property,
 * so it has no name to get wrong — and a miss is silent in the worst direction: a name gone stale through
 * a rename hides nothing, and the field the attribute was written to keep out is published.
 * {@see unmatchedHidden()} is the one report for it, here rather than in each mapper, so a name is judged
 * the same way whichever kind of class carried it. Which mappers may ask is stated there and pinned by a
 * test over the callers, since nothing here can tell one caller from another.
 *
 * It instantiates its own declarations rather than going through {@see ClassDeclarations}, whose
 * silence is wrong for all three of these: a `#[Hidden]` PHP cannot construct would fall to publishing
 * the property it was written to keep out, and a `#[SchemaName]`/`#[SchemaId]` to a name or an identity
 * a diff reads as a different schema. Nothing published quietly on an argument nobody was told about.
 */
final class SchemaIdentity
{
    /**
     * Property names a class-level `#[Hidden(...)]` keeps out of the output, merged across every such
     * attribute.
     *
     * @return list<string>
     */
    public static function hidden(string $fqcn): array
    {
        if (! class_exists($fqcn)) {
            return [];
        }

        $hidden = [];
        foreach ((new ReflectionClass($fqcn))->getAttributes(Hidden::class) as $attribute) {
            $hidden = [...$hidden, ...$attribute->newInstance()->properties];
        }

        return $hidden;
    }

    /**
     * The class-level `#[Hidden]` names that hid nothing: one diagnostic each, in the order they were
     * written. `$published` is every property name the mapper weighed, INCLUDING the ones this deny-list
     * removed — judging a name against what the mapper was left with would report every name that did
     * its job.
     *
     * The message states only what is certain — that no property of this schema carries the name — and
     * leaves the consequence to the help, because the two cases read alike from here: a name that was
     * always wrong hid nothing and there is nothing to leak, while a name gone stale through a rename
     * hid nothing and the field is published under the new spelling. Asserting the second of a
     * declaration that is merely dead would be a report the reader can prove wrong.
     *
     * Only a mapper whose candidate set IS the class's own declaration may ask — core's class mapper and
     * a Data class, whose properties are declared and recovered whole. An Eloquent model must not: its
     * documented surface is recovered EVIDENCE (`@property` tags, `$casts`, `$fillable`), so a name
     * outside it is far more often a column nobody documented than a name anyone typed wrong, and the
     * action the report implies — deleting a deny-list entry — is the one that leaks the column the day
     * somebody adds the tag. Measured on this repo, the single `#[Hidden]` written on a model would
     * have reported falsely under two of the three engines that convert it.
     *
     * @param  list<string>  $published
     * @return list<Diagnostic>
     */
    public static function unmatchedHidden(string $fqcn, array $published): array
    {
        // The name every diagnostic below names the class by — never the raw `class-string`, which for an
        // ANONYMOUS class carries the build machine and a per-process counter ({@see ClassNames}).
        $site = ClassNames::publishable($fqcn);

        $diagnostics = [];
        $reported = [];

        foreach (self::hidden($fqcn) as $property) {
            // Deduped: `#[Hidden('a', 'a')]` is one mistake, and saying it twice sends the reader looking
            // for a second declaration.
            if (in_array($property, $published, true) || in_array($property, $reported, true)) {
                continue;
            }

            $reported[] = $property;

            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'attribute.hidden-unmatched',
                message: sprintf(
                    "#[Hidden('%s')] on %s hid nothing: this schema publishes no property of that name. %s",
                    PlainText::of($property),
                    $site,
                    self::publishing($published),
                ),
                help: 'Correct the name against that list, or delete the declaration. A property renamed since keeps its old spelling only in the attribute, and the field the declaration was written to keep out is then published under the new one. A Data class hides by the property\'s own name, not by the key `#[MapName]` publishes it under.',
            );
        }

        return $diagnostics;
    }

    /**
     * What the schema publishes, capped — the half of the report that is a remedy rather than a
     * complaint, since the typo is only visible beside the spelling that works. Capping and escaping are
     * {@see NameList}'s, which is where a diagnostic's list of names is made safe to print.
     *
     * @param  list<string>  $published
     */
    private static function publishing(array $published): string
    {
        $listing = NameList::of($published);

        if ($listing === null) {
            return 'It publishes no properties at all.';
        }

        return sprintf('It publishes %s.', $listing);
    }

    /** Whether the property carries its own `#[Hidden]`, the per-property half of the same deny-list. */
    public static function hidesProperty(string $fqcn, string $property): bool
    {
        if (! class_exists($fqcn)) {
            return false;
        }

        $reflection = new ReflectionClass($fqcn);

        return $reflection->hasProperty($property)
            && $reflection->getProperty($property)->getAttributes(Hidden::class) !== [];
    }

    /** The `#[SchemaName]` display name, else null (caller defaults to the short class name). */
    public static function name(string $fqcn): ?string
    {
        if (! class_exists($fqcn)) {
            return null;
        }

        foreach ((new ReflectionClass($fqcn))->getAttributes(SchemaName::class) as $attribute) {
            return $attribute->newInstance()->name;
        }

        return null;
    }

    /**
     * The diff identity a class's schema is published under: the `#[SchemaId]` it pins, or its own
     * name. `$facet` qualifies a shape that is not the class's plain one — `request` for the body a
     * client SENDS, and the pagination envelopes over it — so a class published on both sides of the
     * wire never dedupes one shape into the other.
     *
     * One mint, because the qualifier is a fact several producers and every reader have to agree on:
     * a version change resolving `Foo#request` where the recovery chain wrote something else would
     * find no schema and report that the document publishes none.
     */
    public static function publishedId(string $fqcn, string $facet = ''): string
    {
        $id = self::id($fqcn) ?? $fqcn;

        return $facet === '' ? $id : $id.'#'.$facet;
    }

    /** The `#[SchemaId]` identity, else null (caller defaults to the FQCN). */
    public static function id(string $fqcn): ?string
    {
        if (! class_exists($fqcn)) {
            return null;
        }

        foreach ((new ReflectionClass($fqcn))->getAttributes(SchemaId::class) as $attribute) {
            return $attribute->newInstance()->id;
        }

        return null;
    }
}
