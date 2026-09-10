<?php

declare(strict_types=1);

namespace Docuccino\Core\Provenance\Explain;

/**
 * One node of the document that recorded provenance, with a field stack for each field a layer wrote.
 *
 * `label` is how a reader names the node relative to the operation
 * (`responses.201.content.application/json.schema`); `pointer` is the RFC 6901 pointer to it from the
 * document root, which is what a tool needs.
 *
 * `facts` is the node's own `x-docuccino.facts` verbatim — what the build decided ABOUT the node rather
 * than wrote INTO it. A field stack cannot carry those: a fact no producer owns has no rung to sit on,
 * and the one that sends people looking (a status nothing read) is exactly that kind.
 *
 * @internal
 */
final readonly class ExplainedNode
{
    /**
     * @param  list<FieldTrail>  $fields
     * @param  array<string, mixed>  $facts
     */
    public function __construct(
        public string $label,
        public string $pointer,
        public array $fields,
        public ?string $ref = null,
        public array $facts = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'label' => $this->label,
            'pointer' => $this->pointer,
        ];

        if ($this->ref !== null) {
            $out['ref'] = $this->ref;
        }

        $out['fields'] = array_map(
            static fn (FieldTrail $trail): array => $trail->toArray(),
            $this->fields,
        );

        if ($this->facts !== []) {
            $out['facts'] = $this->facts;
        }

        return $out;
    }
}
