<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Reader\Arazzo\ArazzoDescription;
use Docuccino\Core\Reader\Arazzo\ArazzoVersion;
use Docuccino\Core\Reader\Arazzo\DescriptionBuilder;
use Docuccino\Core\SpecValidation\ArazzoMetaSchema;
use Docuccino\Core\SpecValidation\Finding;
use Docuccino\Core\SpecValidation\SchemaFindings;
use InvalidArgumentException;
use stdClass;

/**
 * An Arazzo description {@see ArazzoReader} has read: the version it declares, where it fails the Arazzo
 * schema for that version and where in its text each failure is written, and what it states, in core's
 * workflow model.
 *
 * As with {@see ReadDocument}, every answer is computed when asked for, and nothing reaches the network.
 */
final readonly class ReadArazzo
{
    /**
     * Core builds this; {@see ArazzoReader::read()} is the way in.
     *
     * @internal
     *
     * @param  array<string, mixed>  $document  the same description as $graph, with maps as arrays
     * @param  string  $source  the bytes it was read from
     */
    public function __construct(
        private stdClass $graph,
        private array $document,
        private Syntax $syntax,
        private string $source,
    ) {}

    public function syntax(): Syntax
    {
        return $this->syntax;
    }

    /** The root `arazzo` member as written, or null where there is none. */
    public function declaredVersion(): ?string
    {
        return is_string($this->graph->arazzo ?? null) ? $this->graph->arazzo : null;
    }

    /** The version {@see validate()} holds the description to, or null where none ships for what it declares. */
    public function version(): ?ArazzoVersion
    {
        return ArazzoVersion::of($this->declaredVersion());
    }

    /**
     * Every place the description fails the Arazzo schema for its version, and every `$components`
     * reference that resolves to nothing, cut at $max findings, each {@see FindingSource::Arazzo} and placed
     * in the text as {@see ReadDocument::validate()} places its own. A version with no schema here is one
     * finding rather than a check against another version's.
     *
     * Whether a step's operation exists in the description its source names is not asked: that is a
     * question about another document.
     */
    public function validate(int $max = 50): DocumentValidation
    {
        if ($max < 1) {
            throw new InvalidArgumentException(sprintf('A validation reports at least one finding; %d asks for none.', $max));
        }

        $findings = [];
        $truncated = false;
        foreach ([...$this->schemaFindings(), ...(new DescriptionBuilder($this->document))->unresolved()] as $finding) {
            if (SchemaFindings::isOverflow($finding)) {
                $truncated = true;
            } else {
                $findings[] = DocumentFinding::from(FindingSource::Arazzo, $finding);
            }
        }

        return new DocumentValidation(
            (new SourceMap($this->source))->placed($this->syntax, array_slice($findings, 0, $max)),
            $truncated || count($findings) > $max,
        );
    }

    /** What the description states, with its workflows in core's workflow model. */
    public function description(): ArazzoDescription
    {
        return (new DescriptionBuilder($this->document))->description();
    }

    /**
     * Where $pointer is written in the text, as {@see ReadDocument::position()} answers it: the workflow at
     * index `i` of {@see ArazzoDescription::$workflows} is at `/workflows/i`, its step `j` at
     * `/workflows/i/steps/j`.
     */
    public function position(string $pointer): ?SourcePosition
    {
        return (new SourceMap($this->source))->position($this->syntax, $pointer);
    }

    /**
     * Where each of $pointers is written, all in one pass over the text.
     *
     * @param  list<string>  $pointers
     * @return array<string, ?SourcePosition>
     */
    public function positions(array $pointers): array
    {
        return (new SourceMap($this->source))->positions($this->syntax, $pointers);
    }

    /** The description as JSON, exactly as read, by the writer {@see ReadDocument::json()} uses. */
    public function json(): string
    {
        return (new CanonicalJsonSerializer)->serialize($this->graph);
    }

    /**
     * @return list<Finding>
     */
    private function schemaFindings(): array
    {
        $version = $this->version();

        if ($version === null) {
            $declared = $this->declaredVersion();

            return [new Finding(
                $declared === null ? '/' : '/arazzo',
                'arazzo',
                ($declared === null ? 'The description declares no Arazzo version' : sprintf('The description declares Arazzo "%s"', $declared))
                    .sprintf(', and no schema for it ships with this package (%s); it was not validated', implode(', ', array_keys(ArazzoMetaSchema::SCHEMAS))),
            )];
        }

        return ArazzoMetaSchema::findings($version->value, $this->graph);
    }
}
