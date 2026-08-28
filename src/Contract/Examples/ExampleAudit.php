<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract\Examples;

use Docuccino\Core\Contract\ContractChecker;
use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\ContractParameter;
use Docuccino\Core\Contract\Pointer;
use Docuccino\Core\Contract\Refs;
use Docuccino\Core\Contract\RefusedSchema;
use Docuccino\Core\Contract\ResponseHeaders;
use Docuccino\Core\Contract\SchemaCheck;
use Docuccino\Core\Contract\Violation;
use Docuccino\Core\Draft\SchemaKeywords;
use Throwable;

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
 * and a schema's own `examples` (a list of instances) are never confused for one another. WHICH
 * keywords those are comes from {@see SchemaKeywords}, not from a list here: three hand lists stood
 * here and their single-subschema one was short by five, so an example inside an `if`/`then`/`else`
 * went unaudited with the whole suite green.
 *
 * **One site can never cost the audit the rest.** The validator parses each subject as it reaches it,
 * and a schema it will not parse throws rather than failing — so a single unreadable keyword would
 * otherwise take every example after it, and the build that asked, down with it. Such a site is
 * recorded as {@see ExampleUncheckable} and the walk carries on.
 *
 * **A reference that names nothing is a finding, not a silence.** A path item, response or request body
 * written as a `$ref` the document does not define has no `content` to read, so every example under it
 * would otherwise drop out of the walk and out of the count — one typo, and the audit reports that
 * everything it could find was fine. {@see ContractChecker} already fails on the identical situation,
 * so this records an {@see ExampleFinding} naming the pointer, in the same words.
 */
final class ExampleAudit
{
    private readonly SchemaCheck $schema;

    public function __construct(private readonly ContractIndex $index)
    {
        $this->schema = new SchemaCheck($index);
    }

    public function run(): ExampleReport
    {
        $checked = 0;
        $uncheckable = [];

        // The broken references come first and the walk that finds them is the walk that finds the
        // sites: a reference naming nothing is why a whole response's or body's examples are missing
        // from the list below, so the two are read together or the report explains itself with the
        // half that is silent.
        $findings = [];
        $sites = $this->sites($findings);

        foreach ($sites as $site) {
            [$exampleSegments, $schemaSegments, $label] = $site;

            $value = Pointer::readGraph($this->index->graph(), $exampleSegments);

            // No schema beside it is the same nothing a refused one leaves. `check()` answers `[]` to
            // both — "nothing disagreed" — and counting that as checked makes the report claim an
            // example was held to a contract that was never there.
            if (! $this->schema->has($schemaSegments)) {
                $uncheckable[] = new ExampleUncheckable(
                    Pointer::of($exampleSegments),
                    Pointer::of($schemaSegments),
                    $label,
                    'the contract puts no schema beside it',
                );

                continue;
            }

            try {
                $violations = $this->schema->check($value, $schemaSegments, 'the example');
            } catch (Throwable $refused) {
                $uncheckable[] = new ExampleUncheckable(
                    Pointer::of($exampleSegments),
                    Pointer::of($schemaSegments),
                    $label,
                    RefusedSchema::reason($refused),
                );

                continue;
            }

            // Counted here rather than at the top of the loop: an example the validator refused is one
            // the audit knows nothing about, and counting it as checked makes the report read as having
            // proved more than it did.
            $checked++;

            if ($violations !== []) {
                $findings[] = new ExampleFinding(Pointer::of($exampleSegments), $label, $violations);
            }
        }

        return new ExampleReport($checked, $findings, $uncheckable);
    }

    /**
     * Every (example, schema, label) triple in the document, in a deterministic order: operations as
     * the index lists them, then webhooks as it lists them, then component schemas by name.
     *
     * A parameter's own broken reference is not collected here: the index already builds one of those
     * as a {@see ContractParameter} carrying its `danglingRef`, and {@see ContractChecker} fails on it —
     * a second report of the same fact from the same document would read as two defects.
     *
     * @param  list<ExampleFinding>  $unresolvedRefs  filled with the references that name nothing
     * @return list<array{0: list<string>, 1: list<string>, 2: string}>
     */
    private function sites(array &$unresolvedRefs): array
    {
        $document = $this->index->document();
        $sites = [];

        // A path item behind a pointer that lands nowhere is the widest case of all: it is not one
        // response that went unread but every operation of that path, and the index cannot report it
        // because there is nothing left to index. It comes first for the same reason.
        foreach (['paths' => $this->index->unresolvedPaths(), 'webhooks' => $this->index->unresolvedWebhooks()] as $member => $unresolved) {
            foreach ($unresolved as $key => $reference) {
                $unresolvedRefs[] = self::unresolvedReference(
                    $member === 'paths' ? (string) $key : 'webhooks.'.$key,
                    'the path item',
                    [$member, (string) $key],
                    $reference,
                );
            }
        }

        foreach ($this->index->operations() as $operation) {
            foreach ($operation->parameters as $parameter) {
                $where = $operation->label().' → '.$parameter->label();

                foreach ($this->beside($parameter->definition, $parameter->segments, $parameter->schemaSegments(), $where, $unresolvedRefs) as $site) {
                    $sites[] = [$site[0], $site[1], $where];
                }
            }

            foreach ($this->inOperation($operation->label(), $operation->operation, $operation->segments, $operation->requestBody($document), $unresolvedRefs) as $site) {
                $sites[] = $site;
            }
        }

        // A webhook publishes a body and response headers of exactly the same kind, and an example
        // beside one is copied by exactly the same reader — the outbound half is not a different sort of
        // document, only a different half of the same one. It has no parameters: nothing routes to it.
        foreach ($this->index->webhooks() as $webhook) {
            foreach ($this->inOperation($webhook->label(), $webhook->operation, $webhook->segments, $webhook->requestBody($document), $unresolvedRefs) as $site) {
                $sites[] = $site;
            }
        }

        foreach ($this->componentSchemaNames() as $name) {
            foreach ($this->inSchema(['components', 'schemas', $name]) as $site) {
                $sites[] = [$site[0], $site[1], 'components/schemas/'.$name];
            }
        }

        return $sites;
    }

    /**
     * The sites of one operation object — a route's or a webhook's: its request body, and the headers
     * and content of each documented response, statuses in sorted order.
     *
     * @param  array<string, mixed>  $operation
     * @param  list<string>  $segments  pointer segments addressing the operation
     * @param  array{0: array<string, mixed>, 1: list<string>, 2: string|null}|null  $body  its request body, `$ref` followed
     * @param  list<ExampleFinding>  $unresolvedRefs
     * @return list<array{0: list<string>, 1: list<string>, 2: string}>
     */
    private function inOperation(string $label, array $operation, array $segments, ?array $body, array &$unresolvedRefs): array
    {
        $sites = [];

        if ($body !== null) {
            // The third element is the reference that landed nowhere. Reading `content` off the `$ref`
            // node it degrades to would find none, and the body's examples would simply stop being
            // audited — which is the one outcome a broken pointer must never buy.
            if ($body[2] !== null) {
                $unresolvedRefs[] = self::unresolvedReference($label.' → request body', 'the request body', $body[1], $body[2]);
            } else {
                foreach ($this->inContent($body[0], $body[1], $label.' → request body ', $unresolvedRefs) as $site) {
                    $sites[] = [$site[0], $site[1], $label.' → request body '.$site[2]];
                }
            }
        }

        $responses = $operation['responses'] ?? null;

        if (! is_array($responses)) {
            return $sites;
        }

        $statuses = array_map(strval(...), array_keys($responses));
        sort($statuses);

        foreach ($statuses as $status) {
            $raw = $responses[$status];
            if (! is_array($raw)) {
                continue;
            }

            /** @var array<string, mixed> $raw */
            [$response, $where, $dangling] = Refs::follow($this->index->document(), $raw, [...$segments, 'responses', $status]);

            if ($dangling !== null) {
                $unresolvedRefs[] = self::unresolvedReference($label.' → '.$status, 'the response', $where, $dangling);

                continue;
            }

            $prefix = $label.' → '.$status.' ';

            foreach ([...$this->inHeaders($response, $where, $prefix, $unresolvedRefs), ...$this->inContent($response, $where, $prefix, $unresolvedRefs)] as $site) {
                $sites[] = [$site[0], $site[1], $label.' → '.$status.' '.$site[2]];
            }
        }

        return $sites;
    }

    /**
     * A reference the document does not define. The sentence is {@see Violation::unresolvedRef()}'s,
     * which is where {@see ContractChecker} draws it from too — one product, one sentence for one
     * defect, minted rather than copied.
     *
     * @param  list<string>  $segments  where the reference stands
     */
    private static function unresolvedReference(string $label, string $location, array $segments, string $reference): ExampleFinding
    {
        return new ExampleFinding(
            Pointer::of($segments),
            $label,
            [Violation::unresolvedRef($reference, $location)],
            unresolvedRef: $reference,
        );
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
     * The `example` / `examples` beside each documented response header, and every example inside that
     * header's own schema. A header object is Parameter-like, so its examples sit exactly where a
     * parameter's do — and a hand-written one is as copyable, and as unverified, as a body's.
     *
     * WHICH headers those are is {@see ResponseHeaders}'s answer, the same one the assertions check
     * against, so the audit can never hold an example to a header the check ignores or miss one it does.
     *
     * @param  array<string, mixed>  $node
     * @param  list<string>  $segments
     * @param  string  $prefix  how the caller will name a site found here, for a finding that has no site
     * @param  list<ExampleFinding>  $unresolvedRefs
     * @return list<array{0: list<string>, 1: list<string>, 2: string}>
     */
    private function inHeaders(array $node, array $segments, string $prefix, array &$unresolvedRefs): array
    {
        $sites = [];

        foreach (ResponseHeaders::of($this->index->document(), $node, $segments) as $header) {
            foreach ($this->beside($header->definition, $header->segments, $header->schemaSegments(), $prefix.'header '.$header->name, $unresolvedRefs) as $site) {
                $sites[] = [$site[0], $site[1], 'header '.$header->name];
            }

            foreach ($this->inSchema($header->schemaSegments()) as $site) {
                $sites[] = [$site[0], $site[1], 'header '.$header->name];
            }
        }

        return $sites;
    }

    /**
     * The `example` / `examples` of every media type under a response or request-body object.
     *
     * @param  array<string, mixed>  $node
     * @param  list<string>  $segments
     * @param  string  $prefix  how the caller will name a site found here, for a finding that has no site
     * @param  list<ExampleFinding>  $unresolvedRefs
     * @return list<array{0: list<string>, 1: list<string>, 2: string}>
     */
    private function inContent(array $node, array $segments, string $prefix, array &$unresolvedRefs): array
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

            foreach ($this->beside($media, $mediaSegments, [...$mediaSegments, 'schema'], $prefix.$mediaType, $unresolvedRefs) as $site) {
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
     * An entry of that map is an Example Object OR a Reference Object naming one in
     * `components.examples`, so the chain is followed before `value` is looked for: an example audited
     * only where it was written out is an example whose checking depends on how the document was
     * spelled, and a shared one is the copyable half for every site that references it.
     *
     * @param  array<string, mixed>  $node
     * @param  list<string>  $segments
     * @param  list<string>  $schemaSegments
     * @param  string  $label  how a reader would name this position
     * @param  list<ExampleFinding>  $unresolvedRefs
     * @return list<array{0: list<string>, 1: list<string>}>
     */
    private function beside(array $node, array $segments, array $schemaSegments, string $label, array &$unresolvedRefs): array
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

                if (! is_array($example)) {
                    continue;
                }

                /** @var array<string, mixed> $example */
                [$resolved, $where, $dangling] = Refs::follow($this->index->document(), $example, [...$segments, 'examples', $name]);

                if ($dangling !== null) {
                    $unresolvedRefs[] = self::unresolvedReference($label.' → example '.$name, 'the example', $where, $dangling);

                    continue;
                }

                if (array_key_exists('value', $resolved)) {
                    $sites[] = [[...$where, 'value'], $schemaSegments];
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

        foreach (SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA_MAP) as $keyword) {
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

        foreach (SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA_LIST) as $keyword) {
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

        // A boolean schema carries no example, so `is_array` is the whole guard a single position needs.
        foreach (SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA) as $keyword) {
            if (is_array($node[$keyword] ?? null)) {
                foreach ($this->inSchema([...$segments, $keyword]) as $site) {
                    $sites[] = $site;
                }
            }
        }

        return $sites;
    }
}
