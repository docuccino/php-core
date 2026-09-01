<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Attributes\Abilities;
use Docuccino\Attributes\BodyParameter;
use Docuccino\Attributes\CookieParameter;
use Docuccino\Attributes\DeprecatedOperation;
use Docuccino\Attributes\Description;
use Docuccino\Attributes\ErrorComponent;
use Docuccino\Attributes\ExcludeFromDocs;
use Docuccino\Attributes\Group;
use Docuccino\Attributes\HeaderParameter;
use Docuccino\Attributes\Hidden;
use Docuccino\Attributes\IgnoreParam;
use Docuccino\Attributes\IgnoreResponse;
use Docuccino\Attributes\InDocs;
use Docuccino\Attributes\Internal;
use Docuccino\Attributes\Mock;
use Docuccino\Attributes\OptionallyAuthenticated;
use Docuccino\Attributes\PathParameter;
use Docuccino\Attributes\QueryParameter;
use Docuccino\Attributes\Response;
use Docuccino\Attributes\ResponseHeader;
use Docuccino\Attributes\RuleSchema;
use Docuccino\Attributes\SchemaId;
use Docuccino\Attributes\SchemaName;
use Docuccino\Attributes\Security;
use Docuccino\Attributes\Summary;
use Docuccino\Attributes\Unauthenticated;
use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\AppliesTo;
use Docuccino\Attributes\Versioning\MadeRequestFieldOptional;
use Docuccino\Attributes\Versioning\MadeResponseFieldOptional;
use Docuccino\Attributes\Versioning\MadeResponseFieldRequired;
use Docuccino\Attributes\Versioning\RemovedResponseField;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Docuccino\Attributes\Webhook;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Provenance\ClassNames;
use Docuccino\Core\Support\Fqcn;
use ReflectionClass;

/**
 * Which of the class-target attributes a SCHEMA class is read for, and what to say about the rest.
 *
 * `Attribute::TARGET_CLASS` means two things in this product and only one of them is a schema. An
 * ACTION class is collected into the route attribute bag, which reads every attribute the package
 * ships; a TYPE — a request DTO, a Form Request, a Data class — is read by reflection here, and only
 * for the handful below. PHP accepts the rest at that declaration site and nothing reads them, so a
 * declaration written there used to have no effect and no report, which is the same silence for a typo
 * as for a fact the build simply cannot use.
 *
 * So the tables are exhaustive over the class-target set rather than over what anyone remembered:
 * every such attribute is in {@see HONOURED} or in {@see ELSEWHERE}, `SchemaClassAttributesTest`
 * derives the set from the attributes package and fails on one that is in neither. Adding an attribute
 * is then a decision about whether a type may carry it, made once, instead of a twenty-eighth silent
 * drop.
 *
 * Honoured is not the same as read at every route, which is the third state {@see CONDITIONAL} names:
 * an attribute a type IS read for, where the reading stands down for some routes. That one is not
 * answerable from a class alone — it needs the whole route set — so it is reported from
 * {@see UnusableBodyDeclarations} rather than by {@see unread()}.
 */
final class SchemaClassAttributes
{
    /**
     * The attributes a schema class IS read for, and what reads each. Order is the order the help
     * sentence lists them in, so it does not depend on how a map happened to be built.
     *
     * @var array<class-string, string>
     */
    public const array HONOURED = [
        Description::class => 'the schema description',
        SchemaName::class => 'the component name',
        SchemaId::class => 'the component diff identity',
        Hidden::class => 'the properties left out of the schema',
        Mock::class => 'the mock hints on its properties',
        BodyParameter::class => 'a field of the request body recovered from it',
    ];

    /**
     * The subset of {@see HONOURED} whose reading is conditional on the ROUTE, each with the condition
     * as the diagnostic states it. A row here says the attribute is read on a type and still reaches
     * nothing at some routes, which is a report {@see unread()} cannot make — it sees one class, and
     * the answer is a function of every route the type is bound to.
     *
     * {@see UnusableBodyDeclarations} is what makes that report, and `UnusableBodyDeclarationsTest`
     * holds the two together: a second row added here with no observation site of its own would
     * otherwise inherit the first's wording and say something false about it.
     *
     * @var array<class-string, string>
     */
    public const array CONDITIONAL = [
        BodyParameter::class => 'only where the route documents a request body',
    ];

    /**
     * The rest of the class-target set, each with where it IS read — the half of the diagnostic the
     * author can act on. A mapping table, so `SchemaClassAttributesTest` walks every row.
     *
     * @var array<class-string, string>
     */
    public const array ELSEWHERE = [
        Abilities::class => 'on the action',
        ApiVersionChange::class => 'on a version-change class',
        AppliesTo::class => 'on a version-change class, to narrow it to some operations',
        CookieParameter::class => 'on the action',
        DeprecatedOperation::class => 'on the action',
        ErrorComponent::class => 'on the exception class whose body it names',
        ExcludeFromDocs::class => 'on the action',
        Group::class => 'on the action',
        HeaderParameter::class => 'on the action',
        IgnoreParam::class => 'on the action',
        IgnoreResponse::class => 'on the action',
        InDocs::class => 'on the action',
        Internal::class => 'on the action',
        MadeRequestFieldOptional::class => 'on a version-change class, beside its #[ApiVersionChange]',
        MadeResponseFieldOptional::class => 'on a version-change class, beside its #[ApiVersionChange]',
        MadeResponseFieldRequired::class => 'on a version-change class, beside its #[ApiVersionChange]',
        OptionallyAuthenticated::class => 'on the action',
        PathParameter::class => 'on the action',
        QueryParameter::class => 'on the action, or on a custom filter class',
        RemovedResponseField::class => 'on a version-change class, beside its #[ApiVersionChange]',
        RenamedResponseField::class => 'on a version-change class, beside its #[ApiVersionChange]',
        Response::class => 'on the action',
        ResponseHeader::class => 'on the action',
        RuleSchema::class => 'on the custom rule object it describes',
        Security::class => 'on the action',
        Summary::class => 'on the action',
        Unauthenticated::class => 'on the action',
        Webhook::class => 'on the webhook class it names',
    ];

    /** The attribute namespace a declaration has to be in before this has anything to say about it. */
    private const string PREFIX = 'Docuccino\\Attributes\\';

    /**
     * One diagnostic per Docuccino attribute `$fqcn` declares that nothing reads on a type, in
     * declaration order.
     *
     * Only the class's OWN declarations: PHP does not inherit class attributes, so a parent's are not
     * on this class to be dropped, and reporting them would name a file the author may not own.
     * Repeats collapse — two `#[QueryParameter]`s on one type are one mistake, and a repeatable
     * attribute would otherwise report once per declaration.
     *
     * Nothing is instantiated. `#[Summary(5)]` in application source is a typo whose `TypeError` names
     * the absolute file it was written in, and this is a published diagnostic; the NAME is all the
     * report needs, and reflection hands that over without running a constructor.
     *
     * @return list<Diagnostic>
     */
    public static function unread(string $fqcn): array
    {
        if (! class_exists($fqcn)) {
            return [];
        }

        $site = ClassNames::publishable($fqcn);

        $seen = [];
        $diagnostics = [];

        foreach ((new ReflectionClass($fqcn))->getAttributes() as $declaration) {
            $name = $declaration->getName();

            if (! str_starts_with($name, self::PREFIX) || isset(self::HONOURED[$name]) || isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;
            $short = Fqcn::short($name);

            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'attribute.schema-class-unread',
                message: sprintf(
                    'The #[%s] on %s is not read on a type; it was ignored.',
                    $short,
                    $site,
                ),
                help: sprintf(
                    '#[%s] is read %s. A type is read for %s.',
                    $short,
                    self::ELSEWHERE[$name] ?? 'somewhere other than a type',
                    self::honouredList(),
                ),
            );
        }

        return $diagnostics;
    }

    /** The honoured attributes as the help names them: `#[A]`, `#[B]` and `#[C]`. */
    private static function honouredList(): string
    {
        $names = [];
        foreach (array_keys(self::HONOURED) as $attribute) {
            $names[] = '#['.Fqcn::short($attribute).']';
        }

        $last = array_pop($names);

        return implode(', ', $names).' and '.$last;
    }
}
