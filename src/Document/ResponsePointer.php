<?php

declare(strict_types=1);

namespace Docuccino\Core\Document;

use Docuccino\Core\Contract\Pointer;
use Docuccino\Core\Draft\SchemaKeywords;

/**
 * Whether a `$response.body#/pointer` — a workflow step's output, a response link's parameter — reads
 * something the response actually documents.
 *
 * **The claim is about the DOCUMENT, not about the wire.** An object schema with `properties` and no
 * `additionalProperties: false` permits members it does not name, so a pointer at an undocumented one
 * might still find a value at run time. It is reported anyway, because a workflow output is a promise
 * to a consumer and the document is where that promise lives: a field the response schema does not
 * describe is one no generated client has and no reader can find. Either the pointer is wrong or the
 * field is undocumented, and both are the author's to fix.
 *
 * **Silent wherever the schema cannot refute it.** A response with no schema, an empty one, an object
 * that names no properties, an array with no `items` — each says nothing about the member, and a
 * report there would fire where nothing can be done. Measured before it was written: no schema in the
 * golden corpus is closed, so a rule that only spoke about closed objects would have had zero firings.
 *
 * @internal
 */
final class ResponsePointer
{
    /** `$response.body#/a/b/0/c` — the only expression shape this judges. */
    private const string EXPRESSION = '/^\$response\.body#(\/\S*)$/';

    /** A `$ref` chain longer than this is a schema that refers to itself; stop rather than recurse. */
    private const int MAX_DEPTH = 32;

    /**
     * The pointer the response does not document, or null where it resolves or cannot be judged.
     *
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $doc
     */
    public static function undocumented(array $operation, array $doc, string $expression): ?string
    {
        if (preg_match(self::EXPRESSION, $expression, $matches) !== 1) {
            return null;
        }

        return self::judged(self::successSchema($operation, $doc), $doc, $matches[1]);
    }

    /**
     * The same judgement against one response the caller already chose — the one a link sits on.
     *
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $doc
     */
    public static function undocumentedIn(array $response, array $doc, string $expression): ?string
    {
        if (preg_match(self::EXPRESSION, $expression, $matches) !== 1) {
            return null;
        }

        return self::judged(self::jsonSchema($response), $doc, $matches[1]);
    }

    /**
     * @param  array<string, mixed>|null  $schema
     * @param  array<string, mixed>  $doc
     */
    private static function judged(?array $schema, array $doc, string $pointer): ?string
    {
        if ($schema === null) {
            return null;
        }

        $segments = array_map(
            Pointer::unescape(...),
            array_slice(explode('/', $pointer), 1),
        );

        /** @var array<string, bool> $seen */
        $seen = [];

        return self::resolves($schema, $segments, $doc, $seen) ? null : $pointer;
    }

    /**
     * The JSON schema of the operation's one documented success. Null where it documents none, more
     * than one, or no JSON body — in each case nothing here can say what the pointer should find.
     *
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>|null
     */
    private static function successSchema(array $operation, array $doc): ?array
    {
        $responses = $operation['responses'] ?? null;

        if (! is_array($responses)) {
            return null;
        }

        $successes = array_filter(
            $responses,
            static fn (mixed $_, int|string $status): bool => preg_match('/^2\d\d$/', (string) $status) === 1,
            ARRAY_FILTER_USE_BOTH,
        );

        if (count($successes) !== 1) {
            return null;
        }

        $response = reset($successes);

        return is_array($response) ? self::jsonSchema(self::node($response)) : null;
    }

    /**
     * A response's `application/json` schema, or null where it documents none.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>|null
     */
    private static function jsonSchema(array $response): ?array
    {
        $content = $response['content'] ?? null;
        $json = is_array($content) ? ($content['application/json'] ?? null) : null;
        $schema = is_array($json) ? ($json['schema'] ?? null) : null;

        return is_array($schema) ? self::node($schema) : null;
    }

    /**
     * Whether the schema documents the member the segments name — or cannot say, which answers the same
     * way, because only a document that positively describes the shape may contradict a pointer.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $segments
     * @param  array<string, mixed>  $doc
     * @param  array<string, bool>  $seen
     */
    private static function resolves(array $schema, array $segments, array $doc, array &$seen, int $depth = 0): bool
    {
        if ($depth > self::MAX_DEPTH) {
            return true;
        }

        $schema = self::dereference($schema, $doc, $depth);

        if ($segments === []) {
            return true;
        }

        // Memoised on the node and how much of the pointer is left, which is what bounds the WORK
        // rather than merely the depth. A branch keyword recurses on the SAME segments, so a schema
        // whose `$ref`s fan out re-enters the same subtree once per path through it — the product of
        // the fan-outs, not their sum, and a build that never finishes on a document nothing is wrong
        // with. Answering `true` while a node is still being answered also breaks a cycle the way the
        // rest of this class degrades: reached again, it adds nothing, so it cannot refute.
        $key = count($segments).':'.hash('xxh128', (string) json_encode($schema));

        if (array_key_exists($key, $seen)) {
            return $seen[$key];
        }

        $seen[$key] = true;
        $answer = self::answer($schema, $segments, $doc, $seen, $depth);
        $seen[$key] = $answer;

        return $answer;
    }

    /**
     * Whether this one node documents the member, with the branches it composes from tried first.
     *
     * A branch answering yes is enough — a value validating against that branch has the member — and a
     * node that composes AND names properties of its own is the ordinary `allOf` + `properties` idiom,
     * so a failed branch set falls through to them rather than deciding on its own. Only when nothing
     * else on the node can speak does an exhausted branch set refute: there the schema really did
     * describe its members, and none of them is this one.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $segments
     * @param  array<string, mixed>  $doc
     * @param  array<string, bool>  $seen
     */
    private static function answer(array $schema, array $segments, array $doc, array &$seen, int $depth): bool
    {
        $segment = $segments[0];
        $rest = array_slice($segments, 1);

        // Derived from the keyword table rather than listed here, so a position added there is read by
        // this walk the day it lands. `prefixItems` rides along and costs nothing: its subschemas
        // describe items rather than members, so a NAME segment finds nothing in one.
        $composed = false;

        foreach (SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA_LIST) as $keyword) {
            $branches = $schema[$keyword] ?? null;

            if (! is_array($branches)) {
                continue;
            }

            foreach ($branches as $branch) {
                if (! is_array($branch)) {
                    continue;
                }

                $composed = true;

                if (self::resolves(self::node($branch), $segments, $doc, $seen, $depth + 1)) {
                    return true;
                }
            }
        }

        // An index into a list. Where the schema states no `items`, it describes no member at that
        // position and cannot refute one — unless it composed, in which case it did describe them.
        if (preg_match('/^\d+$/', $segment) === 1 || $segment === '-') {
            $items = $schema['items'] ?? null;

            return is_array($items)
                ? self::resolves(self::node($items), $rest, $doc, $seen, $depth + 1)
                : ! $composed;
        }

        $properties = $schema['properties'] ?? null;

        if (is_array($properties) && $properties !== []) {
            $property = $properties[$segment] ?? null;

            return is_array($property) && self::resolves(self::node($property), $rest, $doc, $seen, $depth + 1);
        }

        // Nothing on this node says anything about its members: silent, unless the branches already
        // did and none of them had it.
        return ! $composed;
    }

    /**
     * A node read out of a document as the JSON object it is. A reader hands back whatever keys were in
     * the file; every position this walks is an object by the schema's own contract.
     *
     * @param  array<array-key, mixed>  $node
     * @return array<string, mixed>
     */
    private static function node(array $node): array
    {
        $out = [];

        foreach ($node as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * The schema a `$ref` names, or the schema itself. Components only: an external pointer names a file
     * no build read, and a schema this cannot see is one it must not contradict.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private static function dereference(array $schema, array $doc, int $depth): array
    {
        $pointer = DocumentGraph::componentRef($schema);

        if ($pointer === null || $depth > self::MAX_DEPTH) {
            return $schema;
        }

        $body = DocumentGraph::componentBody($doc, $pointer);

        return $body === null ? $schema : self::dereference(self::node($body), $doc, $depth + 1);
    }
}
