<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Attributes\Description;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;

/**
 * The prose a `#[Description]` states, for the readers that write it onto a schema — {@see
 * PropertyAnnotations} for a property's member, {@see ClassAnnotations} for the schema of a class.
 *
 * Both hold a bare `description` string, so both refuse the same declarations for the same reasons, and
 * the rule is stated here once rather than at each reader: a declaration carrying both `text:` and
 * `file:`, or neither, says nothing certain, while `file:` and `request:` are operation-level forms —
 * no application root reaches a schema mapper to resolve a path against, and a request body belongs to
 * an operation rather than to a type.
 *
 * `#[Description]` is repeatable and a schema slot holds one string, so both readers call this for
 * EVERY declaration and keep the first that yields text: a misplaced declaration is then reported
 * without costing the author the good one beside it.
 */
final class DescribedText
{
    /**
     * The prose to publish, or null with a report when the declaration says nothing a schema can hold.
     * `$subject` names what would have carried it, as the message reads it ("a property's description").
     *
     * @param  list<Diagnostic>  $diagnostics
     */
    public static function of(Description $description, string $site, string $subject, array &$diagnostics): ?string
    {
        if (($description->text === null) === ($description->file === null)) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'attribute.description-unusable',
                message: sprintf(
                    'The #[Description] on %s carries %s; the description was not documented.',
                    $site,
                    $description->text === null ? 'neither `text:` nor `file:`' : 'both `text:` and `file:`',
                ),
                help: 'One of `text:` (inline prose) or `file:` (a markdown file under the application root) per declaration.',
            );

            return null;
        }

        if ($description->file !== null) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'attribute.property-unsupported',
                message: sprintf(
                    'The #[Description(file: …)] on %s says something a schema cannot hold — %s is read from the attribute itself; it was ignored.',
                    $site,
                    $subject,
                ),
                help: 'Write the prose inline as `text:`, or put the `file:` declaration on the action instead.',
            );

            return null;
        }

        if ($description->request) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'attribute.property-unsupported',
                message: sprintf(
                    'The #[Description(request: true)] on %s says something a schema cannot hold — a request body is one operation\'s use of a type, and %s describes the type itself; it was ignored.',
                    $site,
                    $subject,
                ),
                help: 'Drop `request:` to describe the type here, and declare the request-body prose on the action that accepts it.',
            );

            return null;
        }

        return $description->text;
    }
}
