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
     * @param  array<string, mixed>  $rest
     */
    public function __construct(
        public ?string $name = null,
        public ?string $in = null,
        public ?string $description = null,
        public ?bool $required = null,
        public ?bool $deprecated = null,
        public ?SchemaObject $schema = null,
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
        $schema = Hydrate::objectOrNull($data['schema'] ?? null, SchemaObject::fromArray(...));
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
            $out['schema'] = $this->schema->toArray();
        }

        return $out + $this->rest;
    }
}
