<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract\Examples;

use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\Pointer;
use Docuccino\Core\Contract\Refs;
use Docuccino\Core\Contract\SchemaCheck;

/**
 * Checks every example the document publishes against the schema it sits beside.
 *
 * An example is the part of a document a reader copies, and a hand-written one is the part nothing else
 * verifies: inference cannot be wrong about a shape it derived, but `#[Example]` can say anything at
 * all. It goes through the same {@see SchemaCheck} the response assertions use, so an example is held
 * to exactly the contract a client is.
 *
 * Component schemas are audited once by name rather than once per `$ref` that reaches them, and the
 * walk descends only through JSON Schema keywords — so `content.examples` (a map of Example Objects)
 * and a schema's own `examples` (a list of instances) are never confused for one another.
 */
final class ExampleAudit
{
    /** The keywords whose values are themselves schemas, keyed by how the child sits under them. */
    private const array SCHEMA_MAPS = ['properties', 'patternProperties', '$defs', 'dependentSchemas'];

    private const array SCHEMA_LISTS = ['prefixItems', 'allOf', 'anyOf', 'oneOf'];

    private const array SCHEMA_SINGLES = ['items', 'not', 'additionalProperties', 'contains', 'propertyNames'];

    private readonly SchemaCheck $schema;

    public function __construct(private readonly ContractIndex $index)
    {
        $this->schema = new SchemaCheck($index);
    }

    public function run(): ExampleReport
    {
        $checked = 0;
        $findings = [];

        foreach ($this->sites() as $site) {
            [$exampleSegments, $schemaSegments, $label] = $site;
            $checked++;

            $value = Pointer::readGraph($this->index->graph(), $exampleSegments);
            $violations = $this->schema->check($value, $schemaSegments, 'the example');

            if ($violations !== []) {
                $findings[] = new ExampleFinding(Pointer::of($exampleSegments), $label, $violations);
            }
        }

        return new ExampleReport($checked, $findings);
    }

    /**
     * Every (example, schema, label) triple in the document, in a deterministic order: operations as
     * the index lists them, then component schemas by name.
     *
     * @return list<array{0: list<string>, 1: list<string>, 2: string}>
     */
    private function sites(): array
    {
        $document = $this->index->document();
        $sites = [];

        foreach ($this->index->operations() as $operation) {
            foreach ($operation->parameters as $parameter) {
                foreach ($this->beside($parameter->definition, $parameter->segments, $parameter->schemaSegments()) as $site) {
                    $sites[] = [$site[0], $site[1], $operation->label().' → '.$parameter->label()];
                }
            }

            $body = $operation->requestBody($document);
            if ($body !== null) {
                foreach ($this->inContent($body[0], $body[1]) as $site) {
                    $sites[] = [$site[0], $site[1], $operation->label().' → request body '.$site[2]];
                }
            }

            $responses = $operation->operation['responses'] ?? null;
            if (! is_array($responses)) {
                continue;
            }

            $statuses = array_map(strval(...), array_keys($responses));
            sort($statuses);

            foreach ($statuses as $status) {
                $raw = $responses[$status];
                if (! is_array($raw)) {
                    continue;
                }

                /** @var array<string, mixed> $raw */
                [$response, $segments] = Refs::follow($document, $raw, [...$operation->segments, 'responses', $status]);

                foreach ($this->inContent($response, $segments) as $site) {
                    $sites[] = [$site[0], $site[1], $operation->label().' → '.$status.' '.$site[2]];
                }
            }
        }

        foreach ($this->componentSchemaNames() as $name) {
            foreach ($this->inSchema(['components', 'schemas', $name]) as $site) {
                $sites[] = [$site[0], $site[1], 'components/schemas/'.$name];
            }
        }

        return $sites;
    }

    /** @return list<string> */
    private function componentSchemaNames(): array
    {
        $components = $this->index->document()['components'] ?? null;
        $schemas = is_array($components) ? ($components['schemas'] ?? null) : null;

        if (! is_array($schemas)) {
            return [];
        }

        $names = array_map(strval(...), array_keys($schemas));
        sort($names);

        return $names;
    }

    /**
     * The `example` / `examples` of every media type under a response or request-body object.
     *
     * @param  array<string, mixed>  $node
     * @param  list<string>  $segments
     * @return list<array{0: list<string>, 1: list<string>, 2: string}>
     */
    private function inContent(array $node, array $segments): array
    {
        $content = $node['content'] ?? null;

        if (! is_array($content)) {
            return [];
        }

        $mediaTypes = array_map(strval(...), array_keys($content));
        sort($mediaTypes);

        $sites = [];
        foreach ($mediaTypes as $mediaType) {
            $media = $content[$mediaType];
            if (! is_array($media)) {
                continue;
            }

            /** @var array<string, mixed> $media */
            $mediaSegments = [...$segments, 'content', $mediaType];

            foreach ($this->beside($media, $mediaSegments, [...$mediaSegments, 'schema']) as $site) {
                $sites[] = [$site[0], $site[1], $mediaType];
            }

            // The media type's schema can carry examples of its own, all the way down.
            foreach ($this->inSchema([...$mediaSegments, 'schema']) as $site) {
                $sites[] = [$site[0], $site[1], $mediaType];
            }
        }

        return $sites;
    }

    /**
     * The OAS `example` and `examples` members that sit BESIDE a schema — on a media type or a
     * parameter. `examples` there is a map of Example Objects, so the instance is under `value`.
     *
     * @param  array<string, mixed>  $node
     * @param  list<string>  $segments
     * @param  list<string>  $schemaSegments
     * @return list<array{0: list<string>, 1: list<string>}>
     */
    private function beside(array $node, array $segments, array $schemaSegments): array
    {
        $sites = [];

        if (array_key_exists('example', $node)) {
            $sites[] = [[...$segments, 'example'], $schemaSegments];
        }

        $examples = $node['examples'] ?? null;

        if (is_array($examples)) {
            $names = array_map(strval(...), array_keys($examples));
            sort($names);

            foreach ($names as $name) {
                $example = $examples[$name];

                if (is_array($example) && array_key_exists('value', $example)) {
                    $sites[] = [[...$segments, 'examples', $name, 'value'], $schemaSegments];
                }
            }
        }

        return $sites;
    }

    /**
     * A schema's own `example` (OAS) and `examples` (JSON Schema, a list of instances), plus every
     * nested schema's, descending only through schema keywords and never through a `$ref`.
     *
     * @param  list<string>  $segments
     * @return list<array{0: list<string>, 1: list<string>}>
     */
    private function inSchema(array $segments): array
    {
        $node = Pointer::read($this->index->document(), $segments);

        if (! is_array($node)) {
            return [];
        }

        $sites = [];

        if (array_key_exists('example', $node)) {
            $sites[] = [[...$segments, 'example'], $segments];
        }

        $examples = $node['examples'] ?? null;

        if (is_array($examples) && array_is_list($examples)) {
            foreach (array_keys($examples) as $position) {
                $sites[] = [[...$segments, 'examples', (string) $position], $segments];
            }
        }

        foreach (self::SCHEMA_MAPS as $keyword) {
            $children = $node[$keyword] ?? null;
            if (! is_array($children)) {
                continue;
            }

            $names = array_map(strval(...), array_keys($children));
            sort($names);

            foreach ($names as $name) {
                foreach ($this->inSchema([...$segments, $keyword, $name]) as $site) {
                    $sites[] = $site;
                }
            }
        }

        foreach (self::SCHEMA_LISTS as $keyword) {
            $children = $node[$keyword] ?? null;
            if (! is_array($children)) {
                continue;
            }

            foreach (array_keys($children) as $position) {
                foreach ($this->inSchema([...$segments, $keyword, (string) $position]) as $site) {
                    $sites[] = $site;
                }
            }
        }

        foreach (self::SCHEMA_SINGLES as $keyword) {
            if (is_array($node[$keyword] ?? null)) {
                foreach ($this->inSchema([...$segments, $keyword]) as $site) {
                    $sites[] = $site;
                }
            }
        }

        return $sites;
    }
}
