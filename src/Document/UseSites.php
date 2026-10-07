<?php

declare(strict_types=1);

namespace Docuccino\Core\Document;

use Docuccino\Core\Contract\Refs;

/**
 * Where a USE of a shared response or parameter keeps its own `x-docuccino` — the use's id, provenance
 * and facts, which belong to this operation's use rather than to the component it points at.
 *
 * Inside a build that member sits beside the use's `$ref`, which is where every producer writes it and
 * every reader of the model finds it. A published document cannot keep it there: OpenAPI's Reference
 * Object may not be extended, and a strict reader refuses the whole document over one. So a published
 * full artifact (UIR 2.1 on) carries it on the operation instead, under `x-docuccino.uses`:
 *
 * ```json
 * "x-docuccino": {
 *   "uses": {
 *     "responses": { "404": { "id": "res:v1:…", "provenance": […] } },
 *     "parameters": { "header": { "Api-Version": { "id": "par:v1:…" } } }
 *   }
 * }
 * ```
 *
 * A response is keyed by its status. A parameter is keyed by `in` then `name`, read from the component
 * the `$ref` names, because those two are what make a parameter itself within its operation — its
 * position in the list is not.
 *
 * {@see lift()} is the move a published document makes, and {@see lower()} the move back that every
 * reader of a published document makes before anything else, so a UIR 2.0 artifact (member beside the
 * `$ref`) and a 2.1 one (member under `uses`) read as the same document. Ids never change across the
 * move: they were minted from the operation, status and parameter, never from where they are written.
 *
 * @internal
 */
final class UseSites
{
    public const string KEY = 'uses';

    /**
     * $document with every use-site `x-docuccino` moved off its `$ref` and onto its operation — every
     * operation, under `paths`, `webhooks`, `components.pathItems` and any callback they declare.
     *
     * Two uses stay where they are, because there is nothing true to key them by: a parameter whose `$ref`
     * names nothing this document defines (no `in`, no `name`), and a second use resolving to an `in` and
     * `name` another use of the same operation already holds (a document OpenAPI calls invalid). Each is
     * left beside its `$ref`, where the Reference Object check reports it. A response is keyed by its
     * status alone, so it always moves. An operation that already carries `uses` keeps every entry; this
     * adds to it.
     *
     * Path-item-level parameters are not walked: Docuccino writes a parameter on its operation, and a
     * use found there is left to the Reference Object check rather than moved somewhere 2.1 has no key for.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function lift(array $document): array
    {
        return self::eachOperation($document, static fn (array $operation): array => self::liftOperation($operation, $document));
    }

    /**
     * $document with every `uses` entry put back beside the `$ref` it describes — the shape the model and
     * every reader expect. A no-op on a UIR 2.0 document, which never had `uses`, and on its own output.
     * An entry whose `$ref` is no longer there stays under `uses` rather than being dropped: it is still an
     * id the document published, and losing it would break whatever addressed it.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function lower(array $document): array
    {
        return self::eachOperation($document, static fn (array $operation): array => self::lowerOperation($operation, $document));
    }

    /**
     * @param  array<array-key, mixed>  $operation
     * @param  array<string, mixed>  $document
     * @return array<array-key, mixed>
     */
    private static function liftOperation(array $operation, array $document): array
    {
        $extension = is_array($operation['x-docuccino'] ?? null) ? $operation['x-docuccino'] : [];
        $uses = is_array($extension[self::KEY] ?? null) ? $extension[self::KEY] : [];
        $usedResponses = is_array($uses['responses'] ?? null) ? $uses['responses'] : [];
        $usedParameters = is_array($uses['parameters'] ?? null) ? $uses['parameters'] : [];
        $moved = false;

        $responses = is_array($operation['responses'] ?? null) ? $operation['responses'] : [];
        foreach ($responses as $status => $response) {
            if (self::isUse($response)) {
                $usedResponses[(string) $status] = $response['x-docuccino'];
                unset($response['x-docuccino']);
                $responses[$status] = $response;
                $moved = true;
            }
        }

        $parameters = is_array($operation['parameters'] ?? null) ? $operation['parameters'] : [];
        foreach ($parameters as $index => $parameter) {
            $key = self::isUse($parameter) ? self::parameterKey($parameter, $document) : null;
            if ($key === null) {
                continue;
            }

            [$in, $name] = $key;
            $named = is_array($usedParameters[$in] ?? null) ? $usedParameters[$in] : [];

            if (! array_key_exists($name, $named)) {
                $named[$name] = $parameter['x-docuccino'];
                $usedParameters[$in] = $named;
                unset($parameter['x-docuccino']);
                $parameters[$index] = $parameter;
                $moved = true;
            }
        }

        if (! $moved) {
            return $operation;
        }

        $operation['responses'] = $responses;
        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        $extension[self::KEY] = array_filter(['responses' => $usedResponses, 'parameters' => $usedParameters]) + $uses;
        $operation['x-docuccino'] = $extension;

        return $operation;
    }

    /**
     * @param  array<array-key, mixed>  $operation
     * @param  array<string, mixed>  $document
     * @return array<array-key, mixed>
     */
    private static function lowerOperation(array $operation, array $document): array
    {
        $extension = $operation['x-docuccino'] ?? null;
        $uses = is_array($extension) ? ($extension[self::KEY] ?? null) : null;

        if (! is_array($extension) || ! is_array($uses)) {
            return $operation;
        }

        $published = is_array($uses['responses'] ?? null) ? $uses['responses'] : [];
        $responses = is_array($operation['responses'] ?? null) ? $operation['responses'] : [];
        foreach ($published as $status => $member) {
            $response = $responses[$status] ?? null;

            if (is_array($response) && is_string($response['$ref'] ?? null)) {
                $responses[$status] = ['x-docuccino' => $member] + $response;
                unset($published[$status]);
            }
        }

        $byLocation = is_array($uses['parameters'] ?? null) ? $uses['parameters'] : [];
        $parameters = is_array($operation['parameters'] ?? null) ? $operation['parameters'] : [];
        foreach ($parameters as $index => $parameter) {
            $key = is_array($parameter) && is_string($parameter['$ref'] ?? null) ? self::parameterKey($parameter, $document) : null;
            if ($key === null) {
                continue;
            }

            [$in, $name] = $key;
            $named = is_array($byLocation[$in] ?? null) ? $byLocation[$in] : [];

            if (array_key_exists($name, $named)) {
                $parameters[$index] = ['x-docuccino' => $named[$name]] + $parameter;
                unset($named[$name]);
                $byLocation[$in] = $named;
            }
        }

        if ($responses !== []) {
            $operation['responses'] = $responses;
        }
        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        // What found no `$ref` to stand beside stays where it was published.
        $left = array_filter([
            'responses' => $published,
            'parameters' => array_filter($byLocation, static fn (mixed $names): bool => is_array($names) && $names !== []),
        ]);

        if ($left === []) {
            unset($extension[self::KEY]);
        } else {
            $extension[self::KEY] = $left;
        }

        if ($extension === []) {
            unset($operation['x-docuccino']);
        } else {
            $operation['x-docuccino'] = $extension;
        }

        return $operation;
    }

    /**
     * Whether $node is a Reference Object carrying a use-site `x-docuccino` beside its `$ref`.
     *
     * @phpstan-assert-if-true array{'$ref': string, 'x-docuccino': mixed} $node
     */
    private static function isUse(mixed $node): bool
    {
        return is_array($node) && is_string($node['$ref'] ?? null) && array_key_exists('x-docuccino', $node);
    }

    /**
     * The `in` and `name` of the parameter a `$ref` resolves to, following a chain of them, or null where
     * it resolves to nothing this document defines.
     *
     * @param  array<array-key, mixed>  $parameter
     * @param  array<string, mixed>  $document
     * @return array{string, string}|null
     */
    private static function parameterKey(array $parameter, array $document): ?array
    {
        /** @var array<string, mixed> $parameter */
        [$target, , $dangling] = Refs::follow($document, $parameter, []);

        if ($dangling !== null || ! is_string($target['in'] ?? null) || ! is_string($target['name'] ?? null)) {
            return null;
        }

        return [$target['in'], $target['name']];
    }

    /**
     * $document with $change applied to every operation it declares: under `paths`, `webhooks` and
     * `components.pathItems`, the fixed methods and OpenAPI 3.2's `additionalOperations`, and the
     * operations of every callback those declare.
     *
     * @param  array<string, mixed>  $document
     * @param  callable(array<array-key, mixed>): array<array-key, mixed>  $change
     * @return array<string, mixed>
     */
    private static function eachOperation(array $document, callable $change): array
    {
        foreach (['paths', 'webhooks'] as $member) {
            if (is_array($document[$member] ?? null)) {
                $document[$member] = self::eachPathItem($document[$member], $change);
            }
        }

        if (is_array($document['components'] ?? null) && is_array($document['components']['pathItems'] ?? null)) {
            $document['components']['pathItems'] = self::eachPathItem($document['components']['pathItems'], $change);
        }

        return $document;
    }

    /**
     * @param  array<array-key, mixed>  $items  a map of Path Items
     * @param  callable(array<array-key, mixed>): array<array-key, mixed>  $change
     * @return array<array-key, mixed>
     */
    private static function eachPathItem(array $items, callable $change): array
    {
        foreach ($items as $key => $item) {
            if (! is_array($item)) {
                continue;
            }

            foreach (PathItem::METHODS as $method) {
                if (is_array($item[$method] ?? null)) {
                    $item[$method] = self::operation($item[$method], $change);
                }
            }

            if (is_array($item['additionalOperations'] ?? null)) {
                foreach ($item['additionalOperations'] as $method => $operation) {
                    if (is_array($operation)) {
                        $item['additionalOperations'][$method] = self::operation($operation, $change);
                    }
                }
            }

            $items[$key] = $item;
        }

        return $items;
    }

    /**
     * $operation changed, and the operations of each callback it declares with it.
     *
     * @param  array<array-key, mixed>  $operation
     * @param  callable(array<array-key, mixed>): array<array-key, mixed>  $change
     * @return array<array-key, mixed>
     */
    private static function operation(array $operation, callable $change): array
    {
        if (is_array($operation['callbacks'] ?? null)) {
            foreach ($operation['callbacks'] as $name => $callback) {
                if (is_array($callback)) {
                    $operation['callbacks'][$name] = self::eachPathItem($callback, $change);
                }
            }
        }

        return $change($operation);
    }
}
