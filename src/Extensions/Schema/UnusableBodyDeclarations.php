<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Attributes\BodyParameter;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteNotes;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Contracts\RouteNoteCollector;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Validation\RecoveredRequest;
use Docuccino\Core\Provenance\ClassNames;
use Docuccino\Core\Support\Fqcn;

/**
 * Reports a `#[BodyParameter]` on a request TYPE that no operation in the document could read.
 *
 * {@see SchemaClassAttributes} answers two of the three things a declaration on a type can be — read,
 * or read somewhere that is not a type. The third is {@see SchemaClassAttributes::CONDITIONAL}: read on
 * a type, but only where the route documents a request BODY. A read verb sends the same rules to query
 * parameters ({@see RecoveredRequest::documentsBody()}), so the declaration reaches nothing there, and
 * before this it was neither written nor reported — the same silence for a typo as for a fact the build
 * cannot use, surviving for one verb class.
 *
 * Why it is not a per-route report. One type is bound to more than one route: a DTO shared by
 * `GET /things` and `POST /things` carries a declaration that is load-bearing on the POST, and saying
 * so on the GET tells the author their correct declaration does nothing — a diagnostic firing where
 * nothing can be done, which is how a channel stops being read. So the question is document-wide:
 * report a type observed UNUSABLE somewhere and USED nowhere.
 *
 * Which is why the OBSERVATION rides the fragment and the verdict does not. A verdict that depends on
 * other routes is not a function of this route's inputs, so a warm hit re-using one route's fragment
 * while another changed would replay a stale answer. Each route records only what it saw
 * ({@see observe()}); the reconciliation below is a pure function of the fragment set, so a warm build
 * reconstructs it identically rather than replaying it — warm equals cold by construction.
 *
 * One class for both halves so the aggregate needs no container binding to be shared: the registry
 * makes one instance per registration and partitions it into every chain it satisfies.
 */
#[ExtensionOrder(priority: Priorities::LAST)]
final class UnusableBodyDeclarations implements DocumentTransformer, RouteNoteCollector
{
    /** The {@see RouteNotes} channel the observations travel on; the key is the request type's FQCN. */
    public const string CHANNEL = 'attribute.body-parameter-use';

    /** A route that wrote the type's declarations into a request body. */
    public const string USED = 'used';

    /** A route that read the type and could not use them, because it documents no body. */
    public const string UNUSABLE = 'unusable';

    /**
     * The one attribute this reconciles, and the one row {@see SchemaClassAttributes::CONDITIONAL} has.
     * `UnusableBodyDeclarationsTest` holds the two to each other: a second conditionally-read attribute
     * needs an observation site of its own, and must not inherit this one's wording by default.
     *
     * @var class-string
     */
    public const string ATTRIBUTE = BodyParameter::class;

    /** @var array<string, list<string>> request type FQCN ⇒ the deduped verdicts routes recorded for it */
    private array $observations = [];

    /**
     * Record what ONE route saw about `$sourceClass`: that it wrote the type's `#[BodyParameter]`
     * declarations into a request body, or that it read the type at a verb where they reach nothing.
     *
     * Silence for a type that declares none — there is nothing to be unusable — and the reading of
     * "declares one" is {@see RecoveredRequest::declaredOn()}'s, so the observation cannot recognise a
     * different set from the write it stands in for. A declaration whose constructor rejects its
     * arguments is not one of them: nothing writes it at any verb, and `attribute.unreadable` is where
     * that is already said.
     */
    public static function observe(RouteContext $context, string $sourceClass, bool $documentsBody): void
    {
        if (ClassDeclarations::of($sourceClass, self::ATTRIBUTE) === []) {
            return;
        }

        $context->notes()->record(
            self::CHANNEL,
            $sourceClass,
            $documentsBody ? self::USED : self::UNUSABLE,
        );
    }

    public function channel(): string
    {
        return self::CHANNEL;
    }

    public function forget(): void
    {
        $this->observations = [];
    }

    /**
     * @param  list<string>  $values
     */
    public function collect(string $key, array $values): void
    {
        $verdicts = $this->observations[$key] ?? [];
        foreach ($values as $value) {
            if (! in_array($value, $verdicts, true)) {
                $verdicts[] = $value;
            }
        }

        $this->observations[$key] = $verdicts;
    }

    /**
     * The types observed unusable somewhere and used nowhere, sorted by class name — an intrinsic key,
     * so what the document reports and the order it reports it in are functions of the types themselves
     * and never of the order the routes that met them were built.
     *
     * @return list<string>
     */
    public function unusable(): array
    {
        $classes = [];
        foreach ($this->observations as $class => $verdicts) {
            if (in_array(self::UNUSABLE, $verdicts, true) && ! in_array(self::USED, $verdicts, true)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    /** Publishes nothing: a declaration that reached no body wrote nothing there is to take back. */
    public function transform(UirDocumentDraft $document, DocumentContext $context): void
    {
        $short = Fqcn::short(self::ATTRIBUTE);

        foreach ($this->unusable() as $class) {
            $context->report(new Diagnostic(
                severity: Severity::Warning,
                code: 'attribute.schema-class-unusable',
                message: sprintf(
                    'The #[%s] on %s is read %s, and no operation this document builds from the type does; it was ignored.',
                    $short,
                    ClassNames::publishable($class),
                    SchemaClassAttributes::CONDITIONAL[self::ATTRIBUTE],
                ),
                help: sprintf(
                    'Every operation that recovers a request from this type is a read verb, where the same '
                    .'validation rules become query parameters and nothing reads a declaration about a body. '
                    .'Describe those with #[QueryParameter] on the action, or delete the #[%s]. If a write route '
                    .'was meant to accept the type, the missing route is the real fix.',
                    $short,
                ),
            ));
        }
    }
}
