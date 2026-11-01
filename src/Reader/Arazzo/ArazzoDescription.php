<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader\Arazzo;

use Docuccino\Core\Document\Workflow\Workflow;
use Docuccino\Core\Reader\ReadArazzo;

/**
 * What an Arazzo description states: its `info`, the source descriptions its steps call into, its
 * workflows in core's one workflow model, and its components as written.
 *
 * Workflows and their steps are in the order written, one for each item, so the workflow at index `i` is
 * written at `/workflows/i` and its step `j` at `/workflows/i/steps/j`. Each step holds what applies to
 * it: its own parameters and actions, those the workflow states for every step that it does not override,
 * and every `$components` reference resolved — a reference that resolves to nothing is left out, and is a
 * finding of {@see ReadArazzo::validate()}. A workflow's `inputs` written as a `$ref` to
 * `#/components/inputs/{name}` is that input.
 */
final readonly class ArazzoDescription
{
    /**
     * @param  list<SourceDescription>  $sourceDescriptions
     * @param  list<Workflow>  $workflows
     * @param  array<string, mixed>  $components  as written
     */
    public function __construct(
        public array $sourceDescriptions,
        public array $workflows,
        public array $components = [],
        public ?string $title = null,
        public ?string $summary = null,
        public ?string $description = null,
        public ?string $version = null,
    ) {}

    /** The first workflow with $id, or null. */
    public function workflow(string $id): ?Workflow
    {
        foreach ($this->workflows as $workflow) {
            if ($workflow->id === $id) {
                return $workflow;
            }
        }

        return null;
    }

    /** The first source description named $name, or null. */
    public function source(string $name): ?SourceDescription
    {
        foreach ($this->sourceDescriptions as $source) {
            if ($source->name === $name) {
                return $source;
            }
        }

        return null;
    }
}
