<?php

declare(strict_types=1);

namespace Docuccino\Core\SpecValidation;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Emit\YamlSerializer;
use JsonException;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * The OpenAPI emitters holding their own output to the published schema for the version they claim to
 * write, on every build.
 *
 * The UIR document has been validated against its schema on every build since the pipeline existed; the
 * artifact people actually consume was validated against nothing. It runs unconditionally, like
 * {@see Validator}: a knob here would only ever let an application switch off the one thing standing
 * between our defect and their generated client, and what it costs is why that is affordable — a 46 KB
 * document validates in ~10-45 ms the first time a format is asked for and ~6-12 ms after, against a
 * build measured in seconds.
 *
 * It reports under two codes, and the split is about WHO can act rather than about how bad the finding
 * is. A dangling `$ref`, a key a gate refuses, a schema shape no version accepts: nothing an
 * application puts in a route table produces one of those, so they are ours, and they are Errors.
 * A duplicate `operationId` is the opposite — the id strategy the application configured, the routes it
 * has, or an overlay it wrote is the usual cause — so it is a warning addressed to the author
 * ({@see duplicateOperationIds()}) rather than an accusation that Docuccino is broken.
 *
 * @internal
 */
final class EmittedSpecCheck
{
    /**
     * Every way the emitted artifact fails its own specification.
     *
     * $output is the bytes about to be WRITTEN and $yaml says which carrier they are in, because the
     * carrier is part of what is being checked: reading the JSON serialisation of a YAML emission would
     * leave the YAML writer answering to nothing — and the YAML writer is the half that has actually
     * shipped a defect, an empty `paths` MAP written as a sequence. Either way the bytes are read back
     * into an object graph, so an empty map shows up as `{}` and not as `[]`.
     *
     * Undecodable JSON is not reported: `json_encode` cannot produce any, so a diagnostic there would
     * stand in for an exception somebody can act on. Unreadable YAML is reported, and the asymmetry is
     * the evidence — a third-party dumper whose round trip is the very thing being checked is not a
     * total function into readable YAML the way `json_encode` is into readable JSON.
     *
     * @return list<Diagnostic>
     */
    public static function diagnostics(string $format, string $output, bool $yaml = false): array
    {
        if ($yaml) {
            try {
                $instance = (new YamlSerializer)->parse($output);
            } catch (ParseException $failure) {
                return [self::invalid($format, 'the YAML it wrote cannot be read back at all ('.$failure->getMessage().')')];
            }
        } else {
            try {
                $instance = json_decode($output, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return [];
            }
        }

        return [
            ...array_map(
                static fn (string $finding): Diagnostic => self::invalid($format, $finding),
                OpenApiMetaSchema::emitterFindings($format, $instance),
            ),
            ...self::duplicateOperationIds($format, $instance),
        ];
    }

    /** One finding only the emitter can have caused. */
    private static function invalid(string $format, string $finding): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Error,
            code: 'document.openapi-invalid',
            message: sprintf(
                'The %s artifact this build emitted does not answer to the published OpenAPI schema for that version: %s.',
                $format,
                $finding,
            ),
            help: 'Docuccino writes this file, so an invalid one is a defect in Docuccino rather than '
                ."in your application, and the artifact was still written.\n"
                .'Check the one cause you own first: an overlay, or a config value, that writes the '
                ."position named above.\n"
                .'Otherwise please report it at https://github.com/docuccino/docuccino/issues with the '
                .'format, this message, and the smallest route or attribute that reproduces it.',
        );
    }

    /**
     * The fact `route.duplicate-operation-id` reports from the route table and
     * `content.duplicate-operation-id` from a narrative directive, seen from the third vantage point:
     * the finished artifact, after overlays. An overlay-written collision reaches only this one —
     * nothing earlier in the build has the document it produced.
     *
     * A warning, at the severity its two siblings already carry. The document is out of spec, but every
     * cause is the author's to change and one of them is a documented setting — so failing the export
     * on it would make `representation.operation_id: controller-method` an option nobody can use.
     *
     * @return list<Diagnostic>
     */
    private static function duplicateOperationIds(string $format, mixed $instance): array
    {
        $diagnostics = [];

        foreach (OpenApiMetaSchema::operationIdFindings($instance) as $finding) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'document.duplicate-operation-id',
                message: sprintf(
                    'The %s artifact publishes one operationId for more than one operation, so a generated client names one function for the pair: %s.',
                    $format,
                    $finding,
                ),
                help: "Give one of them its own id with #[OperationId], or name the routes distinctly.\n"
                    .'Several routes onto one controller action share an id under '
                    ."representation.operation_id: controller-method — route-name gives each its own.\n"
                    .'An overlay that writes an operationId collides the same way, and is the one cause '
                    .'route.duplicate-operation-id cannot see.',
            );
        }

        return $diagnostics;
    }
}
