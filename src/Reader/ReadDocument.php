<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Identity\ContentHasher;
use Docuccino\Core\SpecValidation\Finding;
use Docuccino\Core\SpecValidation\OpenApiMetaSchema;
use Docuccino\Core\SpecValidation\Validator;
use InvalidArgumentException;
use stdClass;

/**
 * A document {@see DocumentReader} has read: the facts it states about itself, where it fails its
 * specifications, the nodes it holds, its content hash, and the model the differ reads.
 *
 * Every answer is computed when asked for and nothing is cached, so hold on to what you need. Nothing
 * here reaches the network: every schema a document is checked against ships with this package.
 */
final readonly class ReadDocument
{
    /**
     * Core builds this; {@see DocumentReader::read()} is the way in.
     *
     * @internal
     *
     * @param  array<string, mixed>  $document  the same document as $graph, with maps as arrays except
     *                                          the two shapes no PHP array can spell
     */
    public function __construct(
        private stdClass $graph,
        private array $document,
        private DocumentInspection $inspection,
    ) {}

    public function inspect(): DocumentInspection
    {
        return $this->inspection;
    }

    /**
     * Every place the document fails the OpenAPI meta-schema for the version it declares and — for a full
     * document — the Docuccino schema for the UIR version its generator wrote, cut at $max findings.
     *
     * A version with no schema in this package is reported as a finding rather than checked against
     * another version's: the OpenAPI version from `openapi`, the UIR version from
     * `x-docuccino.generator.specVersion` (or the root `uir` an artifact written before UIR 2.0 carries).
     */
    public function validate(int $max = 50): DocumentValidation
    {
        if ($max < 1) {
            throw new InvalidArgumentException(sprintf('A validation reports at least one finding; %d asks for none.', $max));
        }

        $findings = [...$this->openApiFindings(), ...$this->extensionFindings()];

        if (count($findings) <= $max) {
            return new DocumentValidation($findings);
        }

        $shown = array_slice($findings, 0, $max);
        $shown[] = new DocumentFinding(
            $findings[$max]->source,
            '/',
            null,
            sprintf('more than %d findings; the first %1$d are shown', $max),
        );

        return new DocumentValidation($shown, truncated: true);
    }

    /**
     * One entry per operation, parameter, response and component schema, and — in a full document — per
     * page and workflow, ordered by kind, then key, then pointer. A UIR 2.0 and a 2.1 artifact of one
     * build list the same nodes, and a plain document lists its nodes with no identities.
     *
     * @return list<DocumentNode>
     */
    public function nodes(): array
    {
        return (new NodeManifest)->of($this->document);
    }

    /**
     * The document's `contentHash`: for a full document the value Docuccino writes at
     * `x-docuccino.document.contentHash`, by the algorithm of this version of core; for any other
     * document the same algorithm over what it holds. Generator, diagnostics and the stored hash itself
     * never move it.
     */
    public function contentHash(): string
    {
        return (new ContentHasher)->hash($this->document);
    }

    /**
     * The document as JSON, exactly as read: every member and every list in the order it was written,
     * `{}` and `[]` kept apart, a map whose keys look like numbers still a map, and nothing added,
     * dropped or rewritten — so every {@see DocumentNode::$pointer} resolves in it, and a YAML document
     * and its JSON twin give the same bytes. Written by the same deterministic writer as every artifact
     * (two-space indent, LF, trailing newline), so the same document always gives the same bytes.
     *
     * Not canonical: canonical order sorts parameters and drops repeated values, which would move what a
     * pointer names. {@see contentHash()} is the answer that ignores how a document was written.
     */
    public function json(): string
    {
        return (new CanonicalJsonSerializer)->serialize($this->graph);
    }

    /** The document as the model {@see DocumentDiffer} compares. */
    public function uir(): UirDocument
    {
        return UirDocument::fromArray($this->document);
    }

    /**
     * @return list<DocumentFinding>
     */
    private function openApiFindings(): array
    {
        $format = $this->inspection->format;

        if ($format === null) {
            $declared = $this->inspection->openapi;

            return [new DocumentFinding(
                FindingSource::OpenApi,
                $declared === null ? '/' : '/openapi',
                'openapi',
                ($declared === null ? 'The document declares no OpenAPI version' : sprintf('The document declares OpenAPI "%s"', $declared))
                    .sprintf(', and no meta-schema for it ships with this package (%s); it was not validated', implode(', ', array_keys(OpenApiMetaSchema::SCHEMAS))),
            )];
        }

        return array_map(
            static fn (Finding $finding): DocumentFinding => DocumentFinding::from(FindingSource::OpenApi, $finding),
            OpenApiMetaSchema::findings($format, $this->graph),
        );
    }

    /**
     * @return list<DocumentFinding>
     */
    private function extensionFindings(): array
    {
        if ($this->inspection->capability !== Capability::Full) {
            return [];
        }

        $declared = Inspector::specVersion($this->graph);
        $version = $declared !== null && preg_match('/^(\d+\.\d+)(?:\.|$)/', $declared, $match) === 1 ? $match[1] : null;
        $schema = $version === null ? null : Validator::documentSchemaPathFor($version);

        if ($schema === null) {
            return [new DocumentFinding(
                FindingSource::Extension,
                '/x-docuccino/generator',
                'specVersion',
                ($declared === null ? 'The document states no UIR version' : sprintf('The document is written to UIR "%s"', $declared))
                    .sprintf(', and no schema for it ships with this package (%s); the extension was not validated', implode(', ', Validator::vendoredVersions())),
            )];
        }

        return array_map(
            static fn (Finding $finding): DocumentFinding => DocumentFinding::from(FindingSource::Extension, $finding),
            (new Validator($schema))->validateGraph($this->graph)->errors,
        );
    }
}
