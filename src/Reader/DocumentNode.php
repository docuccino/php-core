<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

/**
 * One addressable node of a read document — an operation, parameter, response, component schema, page
 * or workflow — as a manifest entry.
 *
 * - `identity` is the node's Docuccino id (`op:`, `par:`, `res:`, `sch:`, `page:`), or null where the
 *   document carries none;
 * - `key` is structural and needs no id: `GET /forms/{p0}` for an operation (path parameters numbered,
 *   so renaming one moves nothing; a webhook is `POST webhook:<name>`), the operation's key plus
 *   `query:page` for a parameter or `404` for a response, the reference its uses write for a shared
 *   parameter or response (`#/components/responses/NotFound`), the component name for a schema, the
 *   slug for a page and the id for a workflow;
 * - `pointer` is where the node is written in the bytes that were read;
 * - `digest` is the SHA-256 of the node's canonical bytes with provenance stripped, so it moves when
 *   what the node says moves, and not when only the record of who said it does.
 */
final readonly class DocumentNode
{
    public function __construct(
        public NodeKind $kind,
        public ?string $identity,
        public string $key,
        public string $pointer,
        public ?string $label,
        public bool $deprecated,
        public string $digest,
    ) {}

    /**
     * The node as values a store or an API response can hold: the kind as its value, every member in
     * the order above.
     *
     * @return array{kind: string, identity: ?string, key: string, pointer: string, label: ?string, deprecated: bool, digest: string}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'identity' => $this->identity,
            'key' => $this->key,
            'pointer' => $this->pointer,
            'label' => $this->label,
            'deprecated' => $this->deprecated,
            'digest' => $this->digest,
        ];
    }
}
