<?php

declare(strict_types=1);

namespace Docuccino\Core\Document;

use Docuccino\Core\Contract\Pointer;
use Docuccino\Core\Draft\SchemaKeywords;

/**
 * Rewrites the examples a document publishes when one schema's property is renamed, so the examples go
 * on saying what the schemas beside them say.
 *
 * A schema rewritten in place takes its examples with it or the document contradicts itself: the schema
 * declares one field name and the example a consumer copies carries another, which is the one document
 * defect worse than publishing no example at all. An example is arbitrary JSON, though, so a key of that
 * name is renamed NOWHERE except where the schema governing that position says the object standing there
 * is the renamed one — a `title` under an unrelated shape is never touched.
 *
 * **Where the walk cannot be sure, the example is DROPPED and the caller told.** Publishing no example
 * is valid; publishing one the schema rejects is the defect, and a rewrite that guesses is how a wrong
 * one gets published with confidence. The walk stops being sure at a branch it cannot resolve to one
 * shape — `oneOf`, `anyOf`, `if`/`then`/`else` and every other applicator {@see SchemaKeywords} knows
 * and this does not ({@see undecidable()}) — at a `$ref` that leads back to itself, at a value whose
 * kind the schema does not describe, and where both the old and the new name are already present, so a
 * rename would collapse two fields into one.
 *
 * A `$ref` at nothing is the one pointer this does NOT drop on. It states nothing, so it never says the
 * renamed schema is under it, and refusing every example beneath a typo would take out examples a rename
 * has no business touching — the document already owes its reader `lint.unresolved-reference` about the
 * pointer itself.
 *
 * Dropping stays narrow two ways: a site is only walked at all where the schema beside it REACHES the
 * renamed schema, and an unsettled walk over a value that never mentions the renamed field is left
 * alone, because no rewrite of it was owed.
 *
 * The walk descends by POSITION rather than by key name — the OAS structure down to a media type, then
 * {@see SchemaKeywords} inside a schema — so an example is never confused with a property that happens
 * to be called `schema` or `example`, and a media type's `examples` (a map of Example Objects) is never
 * read as a schema's (a list of instances).
 *
 * Two things are deliberately left where they stand. An `externalValue` names a payload no build read,
 * so nothing here can claim it is wrong. And an entry of an `examples` map written as a `$ref` is
 * shared with every other site that references it, so rewriting it there would rewrite it for all of
 * them; nothing this product mints puts one in `components.examples`, and an overlay that does keeps
 * what it wrote.
 *
 * @internal
 */
final class RenamedFieldExamples
{
    /**
     * The subschema keywords this walk resolves. Everything else {@see SchemaKeywords} knows is
     * undecidable by default, so a keyword added there degrades honestly rather than being read wrong.
     *
     * @var list<string>
     */
    private const array RESOLVED = [
        'allOf',
        'properties',
        'patternProperties',
        'additionalProperties',
        'items',
        'prefixItems',
        'additionalItems',
    ];

    /** The components sections whose members this walks, and what kind of node each holds. */
    private const array COMPONENT_SECTIONS = [
        'schemas' => 'schema',
        'parameters' => 'parameter',
        'headers' => 'parameter',
        'requestBodies' => 'body',
        'responses' => 'response',
        'pathItems' => 'pathItem',
    ];

    /** @var array<string, bool> */
    private array $reaches;

    /** @var list<string> */
    private array $dropped = [];

    /**
     * @param  array<string, mixed>  $doc  the document the walk resolves `$ref`s against
     */
    private function __construct(
        private readonly array $doc,
        private readonly string $id,
        private readonly string $from,
        private readonly string $to,
    ) {
        $this->reaches = DocumentGraph::componentsReaching($doc, $id);
    }

    /**
     * Every example the document publishes, rewritten. `$id` is the identity of the schema whose
     * property moved, `$to` what the code calls it today and `$from` what this version publishes it as.
     *
     * @param  array<string, mixed>  $doc
     * @return array{0: array<string, mixed>, 1: list<string>} the document, and the pointers it dropped
     */
    public static function inDocument(array $doc, string $id, string $from, string $to): array
    {
        $walk = new self($doc, $id, $from, $to);

        return [$walk->document($doc), $walk->dropped];
    }

    /**
     * The same over ONE operation, for a scoped change giving it a private copy of the schema: the rest
     * of the document goes on publishing the shape the code publishes, and so do the rest of its
     * examples. `$keys` is where the operation stands, so a dropped example names its own pointer.
     *
     * @param  array<array-key, mixed>  $operation
     * @param  array<string, mixed>  $doc
     * @param  list<string>  $keys
     * @return array{0: array<array-key, mixed>, 1: list<string>}
     */
    public static function inOperation(array $operation, array $doc, string $id, string $from, string $to, array $keys): array
    {
        $walk = new self($doc, $id, $from, $to);

        return [$walk->operation($operation, $keys), $walk->dropped];
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function document(array $doc): array
    {
        foreach (['paths', 'webhooks'] as $section) {
            $items = $doc[$section] ?? null;
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $name => $item) {
                if (is_array($item)) {
                    $items[$name] = $this->pathItem($item, [$section, (string) $name]);
                }
            }

            $doc[$section] = $items;
        }

        $components = $doc['components'] ?? null;
        if (! is_array($components)) {
            return $doc;
        }

        foreach (self::COMPONENT_SECTIONS as $section => $kind) {
            $members = $components[$section] ?? null;
            if (! is_array($members)) {
                continue;
            }

            foreach ($members as $name => $body) {
                if (! is_array($body)) {
                    continue;
                }

                $keys = ['components', (string) $section, (string) $name];

                $members[$name] = match ($kind) {
                    'schema' => $this->schema($body, $keys),
                    'parameter' => $this->parameter($body, $keys),
                    'body' => $this->body($body, $keys),
                    'response' => $this->response($body, $keys),
                    default => $this->pathItem($body, $keys),
                };
            }

            $components[$section] = $members;
        }

        $doc['components'] = $components;

        return $doc;
    }

    /**
     * @param  array<array-key, mixed>  $item
     * @param  list<string>  $keys
     * @return array<array-key, mixed>
     */
    private function pathItem(array $item, array $keys): array
    {
        $item = $this->parameterList($item, $keys);

        foreach (PathItem::METHODS as $method) {
            $operation = $item[$method] ?? null;

            if (is_array($operation)) {
                $item[$method] = $this->operation($operation, [...$keys, $method]);
            }
        }

        return $item;
    }

    /**
     * @param  array<array-key, mixed>  $operation
     * @param  list<string>  $keys
     * @return array<array-key, mixed>
     */
    private function operation(array $operation, array $keys): array
    {
        $operation = $this->parameterList($operation, $keys);

        $body = $operation['requestBody'] ?? null;
        if (is_array($body)) {
            $operation['requestBody'] = $this->body($body, [...$keys, 'requestBody']);
        }

        $responses = $operation['responses'] ?? null;
        if (! is_array($responses)) {
            return $operation;
        }

        foreach ($responses as $status => $response) {
            if (is_array($response)) {
                $responses[$status] = $this->response($response, [...$keys, 'responses', (string) $status]);
            }
        }

        $operation['responses'] = $responses;

        return $operation;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $keys
     * @return array<array-key, mixed>
     */
    private function parameterList(array $node, array $keys): array
    {
        $parameters = $node['parameters'] ?? null;
        if (! is_array($parameters)) {
            return $node;
        }

        foreach ($parameters as $index => $parameter) {
            if (is_array($parameter)) {
                $parameters[$index] = $this->parameter($parameter, [...$keys, 'parameters', (string) $index]);
            }
        }

        $node['parameters'] = $parameters;

        return $node;
    }

    /**
     * A response: its headers are Parameter-like, and the rest of it is a body.
     *
     * @param  array<array-key, mixed>  $response
     * @param  list<string>  $keys
     * @return array<array-key, mixed>
     */
    private function response(array $response, array $keys): array
    {
        $headers = $response['headers'] ?? null;

        if (is_array($headers)) {
            foreach ($headers as $name => $header) {
                if (is_array($header)) {
                    $headers[$name] = $this->parameter($header, [...$keys, 'headers', (string) $name]);
                }
            }

            $response['headers'] = $headers;
        }

        return $this->body($response, $keys);
    }

    /**
     * A request body or a response: one media type per entry of `content`.
     *
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $keys
     * @return array<array-key, mixed>
     */
    private function body(array $node, array $keys): array
    {
        $content = $node['content'] ?? null;
        if (! is_array($content)) {
            return $node;
        }

        foreach ($content as $mediaType => $media) {
            if (is_array($media)) {
                $content[$mediaType] = $this->beside($media, [...$keys, 'content', (string) $mediaType]);
            }
        }

        $node['content'] = $content;

        return $node;
    }

    /**
     * A parameter or a response header: a schema with examples beside it, or `content` instead.
     *
     * @param  array<array-key, mixed>  $parameter
     * @param  list<string>  $keys
     * @return array<array-key, mixed>
     */
    private function parameter(array $parameter, array $keys): array
    {
        return $this->body($this->beside($parameter, $keys), $keys);
    }

    /**
     * The `example` and `examples` that sit BESIDE a schema — on a media type, a parameter or a header.
     * `examples` here is a map of Example Objects, so the instance is under `value`.
     *
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $keys
     * @return array<array-key, mixed>
     */
    private function beside(array $node, array $keys): array
    {
        $schema = $node['schema'] ?? null;
        if (! is_array($schema)) {
            return $node;
        }

        if (array_key_exists('example', $node)) {
            [$value, $keep] = $this->example($node['example'], $schema, [...$keys, 'example']);

            if ($keep) {
                $node['example'] = $value;
            } else {
                unset($node['example']);
            }
        }

        $examples = $node['examples'] ?? null;

        if (is_array($examples)) {
            foreach ($examples as $name => $example) {
                if (! is_array($example) || ! array_key_exists('value', $example)) {
                    continue;
                }

                [$value, $keep] = $this->example($example['value'], $schema, [...$keys, 'examples', (string) $name, 'value']);

                if ($keep) {
                    $example['value'] = $value;
                    $examples[$name] = $example;
                } else {
                    // The whole entry: an Example Object with no value left names nothing at all.
                    unset($examples[$name]);
                }
            }

            if ($examples === []) {
                unset($node['examples']);
            } else {
                $node['examples'] = $examples;
            }
        }

        $node['schema'] = $this->schema($schema, [...$keys, 'schema']);

        return $node;
    }

    /**
     * A schema's own `example` (OAS) and `examples` (JSON Schema, a LIST of instances), plus every
     * subschema's — descending by position, and never through a `$ref`: the component it names is
     * walked where the document publishes it.
     *
     * @param  array<array-key, mixed>  $schema
     * @param  list<string>  $keys
     * @return array<array-key, mixed>
     */
    private function schema(array $schema, array $keys): array
    {
        if (array_key_exists('example', $schema)) {
            [$value, $keep] = $this->example($schema['example'], $schema, [...$keys, 'example']);

            if ($keep) {
                $schema['example'] = $value;
            } else {
                unset($schema['example']);
            }
        }

        $examples = $schema['examples'] ?? null;

        if (is_array($examples) && array_is_list($examples)) {
            $kept = [];

            foreach ($examples as $position => $instance) {
                [$value, $keep] = $this->example($instance, $schema, [...$keys, 'examples', (string) $position]);

                if ($keep) {
                    $kept[] = $value;
                }
            }

            if ($kept === []) {
                unset($schema['examples']);
            } else {
                $schema['examples'] = $kept;
            }
        }

        foreach (SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA_MAP) as $keyword) {
            $children = $schema[$keyword] ?? null;
            if (! is_array($children)) {
                continue;
            }

            foreach ($children as $name => $child) {
                if (is_array($child)) {
                    $children[$name] = $this->schema($child, [...$keys, $keyword, (string) $name]);
                }
            }

            $schema[$keyword] = $children;
        }

        foreach (SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA_LIST) as $keyword) {
            $children = $schema[$keyword] ?? null;
            if (! is_array($children)) {
                continue;
            }

            foreach ($children as $position => $child) {
                if (is_array($child)) {
                    $children[$position] = $this->schema($child, [...$keys, $keyword, (string) $position]);
                }
            }

            $schema[$keyword] = $children;
        }

        foreach (SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA) as $keyword) {
            $child = $schema[$keyword] ?? null;

            if (is_array($child)) {
                $schema[$keyword] = $this->schema($child, [...$keys, $keyword]);
            }
        }

        return $schema;
    }

    /**
     * One example, held to the schema beside it. Dropped where the walk did not settle AND the value
     * mentions the field that moved — an unsettled walk over a value that never names it owed no
     * rewrite, and dropping it would cost a reader an example to correct nothing.
     *
     * @param  list<string>  $keys
     * @return array{0: mixed, 1: bool} the value, and whether it may still be published
     */
    private function example(mixed $value, mixed $schema, array $keys): array
    {
        [$rewritten, $settled] = $this->value($value, $schema, []);

        if ($settled || ! $this->mentions($value)) {
            return [$settled ? $rewritten : $value, true];
        }

        $this->dropped[] = Pointer::of($keys);

        return [null, false];
    }

    /**
     * Walks one value in step with the schema that governs it.
     *
     * @param  list<string>  $visited  the components followed without consuming any value depth
     * @return array{0: mixed, 1: bool} the value, and whether the walk settled
     */
    private function value(mixed $value, mixed $schema, array $visited): array
    {
        // A scalar has no key to rename, and `[]` has no member either way it is read.
        if (! is_array($schema) || ! is_array($value) || $value === []) {
            return [$value, true];
        }

        // Nothing under this schema IS the renamed one, so nothing under this value can be owed a
        // rewrite. This is what keeps an unrelated example out of the walk entirely.
        if (! DocumentGraph::nodeReaches($schema, $this->id, $this->reaches)) {
            return [$value, true];
        }

        $applying = [];
        if (! $this->flatten($schema, $visited, $applying)) {
            return [$value, false];
        }

        $list = array_is_list($value);

        $types = self::declaredTypes($applying);
        if ($types !== [] && ! in_array($list ? 'array' : 'object', $types, true)) {
            // The example is not the kind of value the schema describes, so there is no position in it
            // this walk can honestly say is the renamed object.
            return [$value, false];
        }

        $renameHere = self::carriesId($applying, $this->id);

        if ($renameHere && $list) {
            return [$value, false];
        }

        $rewritten = [];

        foreach ($value as $key => $child) {
            $settled = true;
            $governing = $list
                ? self::governingItem($applying, $key)
                : $this->governingProperty($applying, (string) $key, $settled);

            if (! $settled) {
                return [$value, false];
            }

            foreach ($governing as $sub) {
                [$child, $ok] = $this->value($child, $sub, []);

                if (! $ok) {
                    return [$value, false];
                }
            }

            $rewritten[$key] = $child;
        }

        if (! $renameHere || ! array_key_exists($this->to, $rewritten)) {
            return [$rewritten, true];
        }

        if (array_key_exists($this->from, $rewritten)) {
            // Renaming onto a name the example already carries would collapse two fields into one.
            return [$value, false];
        }

        $renamed = [];
        foreach ($rewritten as $key => $child) {
            $renamed[$key === $this->to ? $this->from : $key] = $child;
        }

        return [$renamed, true];
    }

    /**
     * The schemas that apply to a value unconditionally — the node itself, plus whatever its `$ref` and
     * its `allOf` branches resolve to. False where an applicator this walk does not resolve stands over
     * the renamed schema, or a `$ref` leads nowhere or back to itself.
     *
     * @param  array<array-key, mixed>  $schema
     * @param  list<string>  $visited
     * @param  list<array<array-key, mixed>>  $applying
     */
    private function flatten(array $schema, array $visited, array &$applying): bool
    {
        $ref = DocumentGraph::componentRef($schema);

        if ($ref !== null && ($this->reaches[$ref] ?? false)) {
            if (in_array($ref, $visited, true)) {
                return false;
            }

            $body = DocumentGraph::componentBody($this->doc, $ref);

            if ($body === null || ! $this->flatten($body, [...$visited, $ref], $applying)) {
                return false;
            }
        }

        $applying[] = $schema;

        foreach (self::undecidable() as $keyword) {
            $branch = $schema[$keyword] ?? null;

            if (is_array($branch) && DocumentGraph::nodeReaches($branch, $this->id, $this->reaches)) {
                return false;
            }
        }

        // A draft-07 tuple `items` is a LIST of subschemas, which this reads as a single one.
        $items = $schema['items'] ?? null;
        if (is_array($items) && $items !== [] && array_is_list($items) && DocumentGraph::nodeReaches($items, $this->id, $this->reaches)) {
            return false;
        }

        $allOf = $schema['allOf'] ?? null;

        if (is_array($allOf)) {
            foreach ($allOf as $branch) {
                if (is_array($branch) && ! $this->flatten($branch, $visited, $applying)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The subschema keywords this walk does not resolve — every one {@see SchemaKeywords} knows, less
     * the ones it reads and the ones that say nothing about the value carrying them ({@see $defs} and
     * its draft-07 spelling, which a `$ref` points INTO rather than applying to anything). Derived
     * rather than listed, so a keyword added to the model is undecidable until somebody teaches this to
     * read it, which is the degradation that stays true.
     *
     * @return list<string>
     */
    public static function undecidable(): array
    {
        $positions = [
            SchemaKeywords::POSITION_SCHEMA,
            SchemaKeywords::POSITION_SCHEMA_MAP,
            SchemaKeywords::POSITION_SCHEMA_LIST,
            SchemaKeywords::POSITION_STRING_LIST_MAP,
        ];

        $keywords = [];
        foreach ($positions as $position) {
            $keywords = [...$keywords, ...SchemaKeywords::at($position)];
        }

        return array_values(array_filter(
            array_diff($keywords, self::RESOLVED),
            static fn (string $keyword): bool => ! SchemaKeywords::saysNothingAboutTheInstance($keyword),
        ));
    }

    /**
     * The subschemas governing one member of an object: what `properties` and `patternProperties` say,
     * or `additionalProperties` where neither names it. `$settled` goes false where a pattern will not
     * compile over the renamed schema — an unreadable pattern decides nothing, and guessing which way
     * it went is how a wrong example gets published.
     *
     * @param  list<array<array-key, mixed>>  $applying
     * @return list<mixed>
     */
    private function governingProperty(array $applying, string $key, bool &$settled): array
    {
        $governing = [];

        foreach ($applying as $schema) {
            $named = [];

            $properties = $schema['properties'] ?? null;
            if (is_array($properties) && array_key_exists($key, $properties)) {
                $named[] = $properties[$key];
            }

            $patterns = $schema['patternProperties'] ?? null;
            if (is_array($patterns)) {
                foreach ($patterns as $pattern => $child) {
                    $matched = @preg_match('/'.str_replace('/', '\\/', (string) $pattern).'/u', $key);

                    if ($matched === false) {
                        if (is_array($child) && DocumentGraph::nodeReaches($child, $this->id, $this->reaches)) {
                            $settled = false;

                            return [];
                        }

                        continue;
                    }

                    if ($matched === 1) {
                        $named[] = $child;
                    }
                }
            }

            if ($named !== []) {
                $governing = [...$governing, ...$named];

                continue;
            }

            $additional = $schema['additionalProperties'] ?? null;
            if (is_array($additional)) {
                $governing[] = $additional;
            }
        }

        return $governing;
    }

    /**
     * The subschemas governing one element of an array: its `prefixItems` position, else `items`, else
     * `additionalItems`.
     *
     * @param  list<array<array-key, mixed>>  $applying
     * @return list<mixed>
     */
    private static function governingItem(array $applying, int|string $index): array
    {
        $governing = [];

        foreach ($applying as $schema) {
            $prefix = $schema['prefixItems'] ?? null;
            if (is_array($prefix) && array_key_exists($index, $prefix)) {
                $governing[] = $prefix[$index];

                continue;
            }

            $items = $schema['items'] ?? null;
            if (is_array($items)) {
                $governing[] = $items;

                continue;
            }

            $additional = $schema['additionalItems'] ?? null;
            if (is_array($additional)) {
                $governing[] = $additional;
            }
        }

        return $governing;
    }

    /**
     * Whether any schema applying here IS the renamed one.
     *
     * @param  list<array<array-key, mixed>>  $applying
     */
    private static function carriesId(array $applying, string $id): bool
    {
        foreach ($applying as $schema) {
            $docuccino = $schema['x-docuccino'] ?? null;

            if (is_array($docuccino) && ($docuccino['id'] ?? null) === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * The instance types the schemas applying here name between them. A union rather than an
     * intersection: this decides only whether the example is a kind of value NONE of them describes,
     * and a narrower reading would drop examples over schemas that merely disagree with each other.
     *
     * @param  list<array<array-key, mixed>>  $applying
     * @return list<string>
     */
    private static function declaredTypes(array $applying): array
    {
        $types = [];

        foreach ($applying as $schema) {
            $type = $schema['type'] ?? null;

            if (is_string($type)) {
                $types[$type] = true;

                continue;
            }

            if (is_array($type)) {
                foreach ($type as $one) {
                    if (is_string($one)) {
                        $types[$one] = true;
                    }
                }
            }
        }

        return array_keys($types);
    }

    /** Whether the field that moved is named anywhere in this value. */
    private function mentions(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $key => $child) {
            if ($key === $this->to || $this->mentions($child)) {
                return true;
            }
        }

        return false;
    }
}
