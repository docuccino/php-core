<?php

declare(strict_types=1);

namespace Docuccino\Core\Document\Workflow;

use Docuccino\Core\Support\Hydrate;

/**
 * The workflow layer (`x-docuccino.workflows`): the multi-step sequences an application declares over
 * its own operations, which the Arazzo emitter publishes and a consumer renders as a playbook.
 *
 * A first-class UIR citizen for the same reason the content layer is — it participates in
 * `contentHash`, so adding a step or re-ordering one is a visible, non-breaking changelog entry rather
 * than a silent change to what the document says you can do with the API.
 *
 * @internal
 */
final readonly class WorkflowExtension
{
    /**
     * @param  list<Workflow>  $workflows
     */
    public function __construct(public array $workflows = []) {}

    /**
     * @param  list<mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        return new self(Hydrate::listOf($data, Workflow::fromArray(...)));
    }

    public function isEmpty(): bool
    {
        return $this->workflows === [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn (Workflow $workflow): array => $workflow->toArray(), $this->workflows);
    }
}
