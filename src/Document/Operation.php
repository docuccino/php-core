<?php

declare(strict_types=1);

namespace Docuccino\Core\Document;

use Docuccino\Core\Support\Hydrate;

/**
 * An OAS operation object. Parameters and responses are modelled; every other member
 * (requestBody, callbacks, externalDocs, servers, x-*) is preserved verbatim in `rest`.
 *
 * @internal
 */
final readonly class Operation
{
    /**
     * @param  list<string>  $tags
     * @param  list<Parameter>  $parameters
     * @param  array<string, ResponseObject>  $responses
     * @param  list<array<string, mixed>>|null  $security
     * @param  array<string, mixed>  $rest
     */
    public function __construct(
        public ?string $operationId = null,
        public ?string $summary = null,
        public ?string $description = null,
        public array $tags = [],
        public ?bool $deprecated = null,
        public array $parameters = [],
        public array $responses = [],
        public ?array $security = null,
        public ?NodeExtension $docuccino = null,
        public array $rest = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $operationId = Hydrate::stringOrNull($data['operationId'] ?? null);
        $summary = Hydrate::stringOrNull($data['summary'] ?? null);
        $description = Hydrate::stringOrNull($data['description'] ?? null);
        $deprecated = Hydrate::boolOrNull($data['deprecated'] ?? null);
        $tags = Hydrate::stringList($data['tags'] ?? null);
        $parameters = Hydrate::listOf($data['parameters'] ?? null, Parameter::fromArray(...));
        $responses = Hydrate::mapOf($data['responses'] ?? null, ResponseObject::fromArray(...));
        $security = Hydrate::listOfMaps($data['security'] ?? null);
        $docuccino = Hydrate::objectOrNull($data['x-docuccino'] ?? null, NodeExtension::fromArray(...));

        unset($data['operationId'], $data['summary'], $data['description'], $data['deprecated'], $data['tags'], $data['parameters'], $data['responses'], $data['security'], $data['x-docuccino']);

        return new self(
            operationId: $operationId,
            summary: $summary,
            description: $description,
            tags: $tags,
            deprecated: $deprecated,
            parameters: $parameters,
            responses: $responses,
            security: $security,
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

        if ($this->operationId !== null) {
            $out['operationId'] = $this->operationId;
        }

        if ($this->summary !== null) {
            $out['summary'] = $this->summary;
        }

        if ($this->description !== null) {
            $out['description'] = $this->description;
        }

        if ($this->deprecated !== null) {
            $out['deprecated'] = $this->deprecated;
        }

        if ($this->tags !== []) {
            $out['tags'] = $this->tags;
        }

        if ($this->security !== null) {
            $out['security'] = $this->security;
        }

        if ($this->parameters !== []) {
            $out['parameters'] = array_map(
                static fn (Parameter $parameter): array => $parameter->toArray(),
                $this->parameters,
            );
        }

        if ($this->responses !== []) {
            $responses = [];
            foreach ($this->responses as $status => $response) {
                $responses[$status] = $response->toArray();
            }
            $out['responses'] = $responses;
        }

        return $out + $this->rest;
    }
}
