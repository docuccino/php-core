<?php

declare(strict_types=1);

namespace Docuccino\Core\Document;

use Docuccino\Core\Support\Hydrate;

/**
 * An OAS parameter object. Modelled fields are typed; every other member (style, explode,
 * example, content, $ref, x-*) is preserved verbatim in `rest`.
 *
 * @internal
 */
final readonly class Parameter
{
    /**
     * The parameter locations that NAME a parameter, in the order a document publishes them. The one
     * owner — the canonicaliser ranks by this list and the example extension searches it, and neither
     * keeps its own copy, because one conventional order means a reader meeting two of them never has
     * to work out which is which.
     *
     * OAS 3.2's fifth location is deliberately absent. `querystring` describes the WHOLE query string
     * as one value and so names no parameter: there is nothing for a rename to move, for an example
     * declaration to find, or for an attribute to own, and nothing this product mints publishes one. A
     * location this does not name is ordered after the ones it does, which is a position rather than a
     * refusal.
     *
     * @var list<string>
     */
    public const array LOCATIONS = ['path', 'query', 'header', 'cookie'];

    /**
     * @param  array<string, mixed>  $rest
     */
    public function __construct(
        public ?string $name = null,
        public ?string $in = null,
        public ?string $description = null,
        public ?bool $required = null,
        public ?bool $deprecated = null,
        public SchemaObject|bool|null $schema = null,
        public ?NodeExtension $docuccino = null,
        public array $rest = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $name = Hydrate::stringOrNull($data['name'] ?? null);
        $in = Hydrate::stringOrNull($data['in'] ?? null);
        $description = Hydrate::stringOrNull($data['description'] ?? null);
        $required = Hydrate::boolOrNull($data['required'] ?? null);
        $deprecated = Hydrate::boolOrNull($data['deprecated'] ?? null);
        $schema = Hydrate::schemaOrNull($data['schema'] ?? null, SchemaObject::fromArray(...));
        $docuccino = Hydrate::objectOrNull($data['x-docuccino'] ?? null, NodeExtension::fromArray(...));

        unset($data['name'], $data['in'], $data['description'], $data['required'], $data['deprecated'], $data['schema'], $data['x-docuccino']);

        return new self(
            name: $name,
            in: $in,
            description: $description,
            required: $required,
            deprecated: $deprecated,
            schema: $schema,
            docuccino: $docuccino,
            rest: $data,
        );
    }

    /**
     * The same parameter carrying `$id` in its `x-docuccino` — {@see OperationIdentities} stamps it
     * after the fragment cache, so a stored fragment holds none.
     */
    public function withIdentity(?string $id): self
    {
        $docuccino = ($this->docuccino ?? new NodeExtension)->withId($id);

        return new self(
            name: $this->name,
            in: $this->in,
            description: $this->description,
            required: $this->required,
            deprecated: $this->deprecated,
            schema: $this->schema,
            docuccino: $docuccino->isEmpty() ? null : $docuccino,
            rest: $this->rest,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];

        if ($this->docuccino !== null && ! $this->docuccino->isEmpty()) {
            $out['x-docuccino'] = $this->docuccino->toArray();
        }

        if ($this->name !== null) {
            $out['name'] = $this->name;
        }

        if ($this->in !== null) {
            $out['in'] = $this->in;
        }

        if ($this->description !== null) {
            $out['description'] = $this->description;
        }

        if ($this->required !== null) {
            $out['required'] = $this->required;
        }

        if ($this->deprecated !== null) {
            $out['deprecated'] = $this->deprecated;
        }

        if ($this->schema !== null) {
            $out['schema'] = is_bool($this->schema) ? $this->schema : $this->schema->toArray();
        }

        return $out + $this->rest;
    }
}
