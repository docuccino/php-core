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
 * Both hold a bare `description` string, so both refuse the same two declarations for the same reason,
 * and the rule is stated here once rather than at each reader: a declaration carrying both `text:` and
 * `file:`, or neither, says nothing certain, and `file:` is an operation-level form — no application
 * root reaches a schema mapper to resolve a path against.
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

        return $description->text;
    }
}
