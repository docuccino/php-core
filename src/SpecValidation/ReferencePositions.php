<?php

declare(strict_types=1);

namespace Docuccino\Core\SpecValidation;

use Closure;
use Docuccino\Core\Draft\SchemaKeywords;
use Docuccino\Core\Support\JsonPointer;
use stdClass;

/**
 * Every position an OpenAPI document may hold a Reference Object at, walked from the fields that
 * declare one ("X Object | Reference Object") rather than from the `$ref`s that happen to be there,
 * so what a `$ref` IS follows from where it sits. {@see OpenApiMetaSchema::referenceSiblingFindings()}
 * is the reader.
 *
 * Only inline nodes are descended into; a reference is handed to the check and left there.
 *
 * @internal
 */
final readonly class ReferencePositions
{
    /** The Path Item members that hold an Operation Object, across versions. */
    private const array METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace', 'query'];

    /**
     * The `components` buckets whose entries may be references, and what an inline entry is. Schemas
     * and path items are the two walked apart, because whether theirs are references depends on the
     * version.
     */
    private const array BUCKETS = [
        'responses' => 'response',
        'parameters' => 'parameter',
        'examples' => 'leaf',
        'requestBodies' => 'requestBody',
        'headers' => 'header',
        'securitySchemes' => 'leaf',
        'links' => 'leaf',
        'callbacks' => 'callback',
        'mediaTypes' => 'mediaType',
    ];

    /**
     * @param  Closure(stdClass, string): bool  $check  called at every reference position; true where
     *                                                  the node is a reference, which ends the descent
     * @param  bool  $schemas  whether a Schema Object's `$ref` is a Reference Object too (3.0)
     * @param  bool  $referencedPathItems  whether a path item outside `paths` may be a Reference Object (3.1)
     */
    public function __construct(
        private Closure $check,
        private bool $schemas,
        private bool $referencedPathItems,
    ) {}

    /** Every reference position in a whole document. */
    public function document(stdClass $document): void
    {
        foreach (ObjectMembers::of($document->paths ?? null) as $name => $item) {
            $this->pathItem($item, JsonPointer::child('/paths', (string) $name), false);
        }

        foreach (ObjectMembers::of($document->webhooks ?? null) as $name => $item) {
            $this->pathItem($item, JsonPointer::child('/webhooks', (string) $name), $this->referencedPathItems);
        }

        if (($document->components ?? null) instanceof stdClass) {
            $this->components($document->components);
        }
    }

    private function components(stdClass $components): void
    {
        foreach (self::BUCKETS as $bucket => $kind) {
            foreach (ObjectMembers::of($components->{$bucket} ?? null) as $name => $node) {
                $this->slot($node, JsonPointer::child('/components/'.$bucket, (string) $name), $kind);
            }
        }

        foreach (ObjectMembers::of($components->pathItems ?? null) as $name => $item) {
            $this->pathItem($item, JsonPointer::child('/components/pathItems', (string) $name), $this->referencedPathItems);
        }

        foreach (ObjectMembers::of($components->schemas ?? null) as $name => $schema) {
            $this->schema($schema, JsonPointer::child('/components/schemas', (string) $name));
        }
    }

    /** @param  bool  $mayBeReference  whether a `$ref` here is a Reference Object rather than the item's own field */
    private function pathItem(mixed $item, string $pointer, bool $mayBeReference): void
    {
        if (! $item instanceof stdClass || ($mayBeReference && ($this->check)($item, $pointer))) {
            return;
        }

        $this->list($item->parameters ?? null, $pointer.'/parameters', 'parameter');

        foreach (self::METHODS as $method) {
            $this->operation($item->{$method} ?? null, $pointer.'/'.$method);
        }

        foreach (ObjectMembers::of($item->additionalOperations ?? null) as $method => $operation) {
            $this->operation($operation, JsonPointer::child($pointer.'/additionalOperations', (string) $method));
        }
    }

    private function operation(mixed $operation, string $pointer): void
    {
        if (! $operation instanceof stdClass) {
            return;
        }

        $this->list($operation->parameters ?? null, $pointer.'/parameters', 'parameter');

        if (isset($operation->requestBody)) {
            $this->slot($operation->requestBody, $pointer.'/requestBody', 'requestBody');
        }

        foreach (ObjectMembers::of($operation->responses ?? null) as $status => $response) {
            if (! str_starts_with((string) $status, 'x-')) {
                $this->slot($response, JsonPointer::child($pointer.'/responses', (string) $status), 'response');
            }
        }

        $this->map($operation->callbacks ?? null, $pointer.'/callbacks', 'callback');
    }

    /** One position that holds a $kind Object or a Reference Object. */
    private function slot(mixed $node, string $pointer, string $kind): void
    {
        if (! $node instanceof stdClass || ($this->check)($node, $pointer)) {
            return;
        }

        match ($kind) {
            'response' => $this->response($node, $pointer),
            'parameter', 'header' => $this->parameter($node, $pointer),
            'requestBody' => $this->content($node->content ?? null, $pointer.'/content'),
            'mediaType' => $this->mediaType($node, $pointer),
            'callback' => $this->callback($node, $pointer),
            default => null,
        };
    }

    private function response(stdClass $response, string $pointer): void
    {
        $this->map($response->headers ?? null, $pointer.'/headers', 'header');
        $this->map($response->links ?? null, $pointer.'/links', 'leaf');
        $this->content($response->content ?? null, $pointer.'/content');
    }

    /** A Parameter Object, or a Header Object, which states the same members bar `name` and `in`. */
    private function parameter(stdClass $parameter, string $pointer): void
    {
        $this->schema($parameter->schema ?? null, $pointer.'/schema');
        $this->map($parameter->examples ?? null, $pointer.'/examples', 'leaf');
        $this->content($parameter->content ?? null, $pointer.'/content');
    }

    /** A `content` map: its entries are Media Type Objects, which 3.2 also lets be references. */
    private function content(mixed $content, string $pointer): void
    {
        $this->map($content, $pointer, 'mediaType');
    }

    private function mediaType(stdClass $mediaType, string $pointer): void
    {
        $this->schema($mediaType->schema ?? null, $pointer.'/schema');
        $this->schema($mediaType->itemSchema ?? null, $pointer.'/itemSchema');
        $this->map($mediaType->examples ?? null, $pointer.'/examples', 'leaf');
        $this->encodings($mediaType, $pointer);
    }

    /** The Encoding Objects a media type — or, in 3.2, an encoding — carries, and their headers. */
    private function encodings(stdClass $node, string $pointer): void
    {
        $encodings = [];

        foreach (ObjectMembers::of($node->encoding ?? null) as $name => $encoding) {
            $encodings[JsonPointer::child($pointer.'/encoding', (string) $name)] = $encoding;
        }

        foreach (is_array($node->prefixEncoding ?? null) ? $node->prefixEncoding : [] as $index => $encoding) {
            $encodings[$pointer.'/prefixEncoding/'.$index] = $encoding;
        }

        if (isset($node->itemEncoding)) {
            $encodings[$pointer.'/itemEncoding'] = $node->itemEncoding;
        }

        foreach ($encodings as $at => $encoding) {
            if ($encoding instanceof stdClass) {
                $this->map($encoding->headers ?? null, $at.'/headers', 'header');
                $this->encodings($encoding, $at);
            }
        }
    }

    /** A Callback Object: runtime expressions to path items, which 3.1 lets be references as well. */
    private function callback(stdClass $callback, string $pointer): void
    {
        foreach (ObjectMembers::of($callback) as $expression => $item) {
            if (! str_starts_with((string) $expression, 'x-')) {
                $this->pathItem($item, JsonPointer::child($pointer, (string) $expression), $this->referencedPathItems);
            }
        }
    }

    /**
     * A Schema Object and its subschemas, which are reference positions only where a Schema Object's
     * `$ref` is a Reference Object: in 3.0, before OpenAPI adopted JSON Schema whole.
     */
    private function schema(mixed $schema, string $pointer): void
    {
        if (! $this->schemas || ! $schema instanceof stdClass || ($this->check)($schema, $pointer)) {
            return;
        }

        foreach (ObjectMembers::of($schema) as $keyword => $value) {
            $at = JsonPointer::child($pointer, (string) $keyword);
            $position = SchemaKeywords::positionOf((string) $keyword);

            if ($position === SchemaKeywords::POSITION_SCHEMA) {
                $this->schema($value, $at);
            } elseif ($position === SchemaKeywords::POSITION_SCHEMA_LIST && is_array($value)) {
                foreach ($value as $index => $item) {
                    $this->schema($item, $at.'/'.$index);
                }
            } elseif ($position === SchemaKeywords::POSITION_SCHEMA_MAP) {
                foreach (ObjectMembers::of($value) as $name => $item) {
                    $this->schema($item, JsonPointer::child($at, (string) $name));
                }
            }
        }
    }

    private function map(mixed $map, string $pointer, string $kind): void
    {
        foreach (ObjectMembers::of($map) as $name => $node) {
            $this->slot($node, JsonPointer::child($pointer, (string) $name), $kind);
        }
    }

    private function list(mixed $list, string $pointer, string $kind): void
    {
        foreach (is_array($list) ? $list : [] as $index => $node) {
            $this->slot($node, $pointer.'/'.$index, $kind);
        }
    }
}
