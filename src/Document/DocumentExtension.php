<?php

declare(strict_types=1);

namespace Docuccino\Core\Document;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Document\Content\ContentExtension;
use Docuccino\Core\Document\Workflow\WorkflowExtension;
use Docuccino\Core\Support\Hydrate;

/**
 * Document-level `x-docuccino` member: identity, generator, content tree, workflows and diagnostics.
 *
 * @internal
 */
final readonly class DocumentExtension
{
    /**
     * @param  list<Diagnostic>  $diagnostics
     * @param  array<string, mixed>  $rest
     */
    public function __construct(
        public ?DocumentMeta $document = null,
        public ?Generator $generator = null,
        public ?ContentExtension $content = null,
        public ?WorkflowExtension $workflows = null,
        public array $diagnostics = [],
        public array $rest = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $document = Hydrate::objectOrNull($data['document'] ?? null, DocumentMeta::fromArray(...));
        unset($data['document']);

        $generator = Hydrate::objectOrNull($data['generator'] ?? null, Generator::fromArray(...));
        unset($data['generator']);

        $content = Hydrate::objectOrNull($data['content'] ?? null, ContentExtension::fromArray(...));
        unset($data['content']);

        // A LIST rather than a map, so it is hydrated from the member itself rather than through
        // `objectOrNull`, which answers null for one.
        $workflows = isset($data['workflows']) && is_array($data['workflows'])
            ? WorkflowExtension::fromArray(array_values($data['workflows']))
            : null;
        unset($data['workflows']);

        $diagnostics = Hydrate::listOf($data['diagnostics'] ?? null, Diagnostic::fromArray(...));
        unset($data['diagnostics']);

        return new self(
            document: $document,
            generator: $generator,
            content: $content,
            workflows: $workflows,
            diagnostics: $diagnostics,
            rest: $data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];

        if ($this->document !== null) {
            $out['document'] = $this->document->toArray();
        }

        if ($this->generator !== null) {
            $out['generator'] = $this->generator->toArray();
        }

        if ($this->content !== null) {
            $out['content'] = $this->content->toArray();
        }

        if ($this->workflows !== null && ! $this->workflows->isEmpty()) {
            $out['workflows'] = $this->workflows->toArray();
        }

        if ($this->diagnostics !== []) {
            $out['diagnostics'] = array_map(
                static fn (Diagnostic $diagnostic): array => $diagnostic->toArray(),
                $this->diagnostics,
            );
        }

        return $out + $this->rest;
    }

    public function withDocument(DocumentMeta $document): self
    {
        return new self($document, $this->generator, $this->content, $this->workflows, $this->diagnostics, $this->rest);
    }
}
