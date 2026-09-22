<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit\Arazzo;

use Docuccino\Core\Document\DocumentGraph;

/**
 * What an Arazzo step needs to know about the operation its node id names: the `operationId` the
 * OpenAPI artifact addresses it by, and the success status a criterion can check.
 *
 * The lookup exists because the two documents speak different languages about the same operation. A
 * workflow step holds the operation's IDENTITY, which is what keeps it pointing at the right operation
 * across a rename; Arazzo addresses operations by `operationId`, which is what an OpenAPI consumer has.
 * Resolving one to the other is the whole of the translation, and it is done against the document being
 * emitted rather than against any recollection of it.
 *
 * @internal
 */
final readonly class OperationIndex
{
    /**
     * @param  array<string, array{operationId: string|null, success: string|null}>  $entries  by node id
     */
    private function __construct(private array $entries) {}

    /**
     * @param  array<string, mixed>  $document
     */
    public static function of(array $document): self
    {
        $entries = [];

        foreach (DocumentGraph::operationSites($document) as $site) {
            $operation = DocumentGraph::at($document, $site['keys']);
            if (! is_array($operation)) {
                continue;
            }

            $docuccino = $operation['x-docuccino'] ?? null;
            $id = is_array($docuccino) ? $docuccino['id'] ?? null : null;

            if (is_string($id) && $id !== '') {
                $entries[$id] = [
                    'operationId' => $site['operationId'],
                    'success' => self::documentedSuccess($operation),
                ];
            }
        }

        return new self($entries);
    }

    public function knows(string $nodeId): bool
    {
        return isset($this->entries[$nodeId]);
    }

    /** The name Arazzo addresses the operation by, or null where the document publishes none. */
    public function operationId(string $nodeId): ?string
    {
        return $this->entries[$nodeId]['operationId'] ?? null;
    }

    /**
     * The status a step is expected to come back with, or null where the operation documents no single
     * success. Null rather than a guess: a criterion naming the wrong status fails a workflow that
     * worked, which is worse for a consumer than a step with nothing asserted about it.
     */
    public function successStatus(string $nodeId): ?string
    {
        return $this->entries[$nodeId]['success'] ?? null;
    }

    /**
     * The one documented success status of an operation. A range (`2XX`) is not one — nothing can check
     * `$statusCode == 2XX` — and two successes are the operation telling us it has more than one
     * outcome, which is a fact about the API rather than something to pick between.
     *
     * @param  array<array-key, mixed>  $operation
     */
    private static function documentedSuccess(array $operation): ?string
    {
        $responses = $operation['responses'] ?? null;
        if (! is_array($responses)) {
            return null;
        }

        $successes = array_values(array_filter(
            array_map(strval(...), array_keys($responses)),
            static fn (string $status): bool => preg_match('/^2\d\d$/', $status) === 1,
        ));

        return count($successes) === 1 ? $successes[0] : null;
    }
}
