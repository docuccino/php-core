<?php

declare(strict_types=1);

namespace Docuccino\Core\Document;

use Docuccino\Core\Support\Hydrate;

/**
 * The OAS components object. Reusable schemas/responses/parameters are modelled; the
 * remaining sections (examples, headers, securitySchemes, …) are preserved in `rest`.
 *
 * @internal
 */
final readonly class Components
{
    /**
     * @param  array<string, SchemaObject|bool>  $schemas
     * @param  array<string, ResponseObject>  $responses
     * @param  array<string, Parameter>  $parameters
     * @param  array<string, mixed>  $rest
     */
    public function __construct(
        public array $schemas = [],
        public array $responses = [],
        public array $parameters = [],
        public array $rest = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $schemas = Hydrate::schemaMap($data['schemas'] ?? null, SchemaObject::fromArray(...));
        $responses = Hydrate::mapOf($data['responses'] ?? null, ResponseObject::fromArray(...));
        $parameters = Hydrate::mapOf($data['parameters'] ?? null, Parameter::fromArray(...));

        unset($data['schemas'], $data['responses'], $data['parameters']);

        return new self(
            schemas: $schemas,
            responses: $responses,
            parameters: $parameters,
            rest: $data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];

        if ($this->schemas !== []) {
            $schemas = [];
            foreach ($this->schemas as $name => $schema) {
                $schemas[$name] = is_bool($schema) ? $schema : $schema->toArray();
            }
            $out['schemas'] = $schemas;
        }

        if ($this->responses !== []) {
            $responses = [];
            foreach ($this->responses as $name => $response) {
                $responses[$name] = $response->toArray();
            }
            $out['responses'] = $responses;
        }

        if ($this->parameters !== []) {
            $parameters = [];
            foreach ($this->parameters as $name => $parameter) {
                $parameters[$name] = $parameter->toArray();
            }
            $out['parameters'] = $parameters;
        }

        return $out + $this->rest;
    }

    /**
     * The schemas as written, a boolean member as itself. Readers that walk this bucket want the Schema
     * Object rather than the wrapper, and a boolean IS one here — so they each say what a boolean means
     * to them (it carries no identity, and it references nothing) instead of a shared answer deciding
     * for them.
     *
     * @return array<string, array<string, mixed>|bool>
     */
    public function schemaValues(): array
    {
        $out = [];

        foreach ($this->schemas as $name => $schema) {
            $out[$name] = is_bool($schema) ? $schema : $schema->toArray();
        }

        return $out;
    }

    public function isEmpty(): bool
    {
        return $this->schemas === []
            && $this->responses === []
            && $this->parameters === []
            && $this->rest === [];
    }
}
