<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

use Docuccino\Core\Document\Parameter;
use Docuccino\Core\Document\ResponseObject;
use Docuccino\Core\Document\UirDocument;

/**
 * A document's reusable `components.responses` and `components.parameters`, used to read a `$ref`ing node
 * back as the thing it points at.
 *
 * Resolving both sides is what keeps hoisting invisible to the diff: an inline body or parameter that
 * becomes a `$ref` (or moves between component names) compares thing-to-thing and reports nothing, while
 * an edit to a shared one reports against every operation using it.
 *
 * The CONTRACT comes from the component, never from what the referring node states beside the pointer: a
 * `required: false` written next to a `$ref` at a component that says `required: true` describes nothing,
 * and honouring it reported a parameter becoming optional, or required, for a contract that had not moved.
 * That is the MODELLED members only — `name`, `in`, `required`, `deprecated` and `schema` on a parameter,
 * `headers` and `content` on a response. A `description`, and anything left in `rest` (`style`, `explode`,
 * `example`, an extension), stay as the referring node wrote them, and so does the identity, which names
 * the USE rather than the thing the diff pairs on.
 *
 * For a parameter it is also what makes the comparison possible at all: a Reference Object states neither
 * `name` nor `in`, which is how a parameter is told from its neighbours, so unresolved they are
 * indistinguishable.
 *
 * @internal
 */
final readonly class ComponentRefs
{
    /**
     * @param  array<string, ResponseObject>  $responses
     * @param  array<string, Parameter>  $parameters
     */
    private function __construct(private array $responses, private array $parameters) {}

    public static function of(UirDocument $document): self
    {
        return new self(
            $document->components->responses ?? [],
            $document->components->parameters ?? [],
        );
    }

    /**
     * One hop, so a component that is itself a `$ref` stays marked unresolved rather than silently
     * flattening to nothing.
     */
    public function resolveResponse(ResponseObject $response): ResponseObject
    {
        $name = self::componentName($response->ref, 'responses');
        $target = $name === null ? null : ($this->responses[$name] ?? null);

        if ($target === null) {
            return $response;
        }

        return new ResponseObject(
            ref: $target->ref,
            description: $response->description ?? $target->description,
            headers: $target->headers,
            content: $target->content,
            docuccino: $response->docuccino,
            rest: $response->rest + $target->rest,
        );
    }

    /**
     * A parameter's `$ref` lives among its non-modelled members. The referring site rarely carries an
     * identity of its own — a Reference Object is usually nothing but the pointer — so the component's
     * stands in, which is the id both a UIR document and its exported artifact publish for that parameter.
     */
    public function resolveParameter(Parameter $parameter): Parameter
    {
        $ref = $parameter->rest['$ref'] ?? null;
        $name = is_string($ref) ? self::componentName($ref, 'parameters') : null;
        $target = $name === null ? null : ($this->parameters[$name] ?? null);

        if ($target === null) {
            return $parameter;
        }

        return new Parameter(
            name: $target->name,
            in: $target->in,
            description: $parameter->description ?? $target->description,
            required: $target->required,
            deprecated: $target->deprecated,
            schema: $target->schema,
            docuccino: $parameter->docuccino ?? $target->docuccino,
            rest: $parameter->rest + $target->rest,
        );
    }

    private static function componentName(?string $ref, string $section): ?string
    {
        $prefix = '#/components/'.$section.'/';

        if ($ref === null || ! str_starts_with($ref, $prefix)) {
            return null;
        }

        $name = substr($ref, strlen($prefix));

        return $name === '' ? null : $name;
    }
}
