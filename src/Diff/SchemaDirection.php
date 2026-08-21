<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

use Docuccino\Core\Document\PathItem;
use Docuccino\Core\Document\UirDocument;

/**
 * Which side of the wire each `components.schemas` entry serves. A name reached only from writer
 * positions — operation and path-item `parameters`, `requestBody`, `components.parameters`,
 * `components.requestBodies` — is request-only. Everything else counts as read: response bodies
 * and headers, webhooks and callbacks (payloads the API consumer RECEIVES, whichever HTTP role
 * they play), and any mention the walk can't place. Both sets close transitively through the
 * schemas they reach, so ambiguity only ever widens the response set, never the request one.
 *
 * @internal
 */
final readonly class SchemaDirection
{
    /**
     * @param  array<string, true>  $request
     * @param  array<string, true>  $response
     */
    private function __construct(private array $request, private array $response) {}

    public static function of(UirDocument $document): self
    {
        $schemas = SchemaReachability::schemaArrays($document);
        [$requestRoots, $responseRoots] = self::roots($document);

        return new self(
            SchemaReachability::close($requestRoots, $schemas),
            SchemaReachability::close($responseRoots, $schemas),
        );
    }

    /**
     * Reached from a writer position and from nowhere else — the one case where request semantics
     * are safe to apply to a component.
     */
    public function requestOnly(string $name): bool
    {
        return isset($this->request[$name]) && ! isset($this->response[$name]);
    }

    /**
     * Splits the document into writer positions and the rest. Writer subtrees are CUT from the
     * remainder before it is scanned, so a name appearing on both sides keeps both memberships
     * while one named only where a client writes stays out of the response set.
     *
     * @return array{0: array<string, true>, 1: array<string, true>}
     */
    private static function roots(UirDocument $document): array
    {
        $data = $document->toArray();
        $writer = [];

        $components = is_array($data['components'] ?? null) ? $data['components'] : [];
        foreach (['parameters', 'requestBodies'] as $section) {
            if (is_array($components[$section] ?? null)) {
                $writer[] = $components[$section];
            }
            unset($components[$section]);
        }
        unset($components['schemas']);
        $data['components'] = $components;

        // Only `paths` carries writer positions; a webhook's parameters and body are read.
        if (is_array($data['paths'] ?? null)) {
            foreach ($data['paths'] as $path => $item) {
                if (is_array($item)) {
                    $data['paths'][$path] = self::cutWriterPositions($item, $writer);
                }
            }
        }

        return [SchemaReachability::namesIn($writer), SchemaReachability::namesIn($data)];
    }

    /**
     * @param  array<array-key, mixed>  $item
     * @param  list<array<array-key, mixed>>  $writer
     * @return array<array-key, mixed>
     */
    private static function cutWriterPositions(array $item, array &$writer): array
    {
        if (is_array($item['parameters'] ?? null)) {
            $writer[] = $item['parameters'];
            unset($item['parameters']);
        }

        foreach (PathItem::METHODS as $method) {
            $operation = $item[$method] ?? null;
            if (! is_array($operation)) {
                continue;
            }

            foreach (['parameters', 'requestBody'] as $position) {
                if (is_array($operation[$position] ?? null)) {
                    $writer[] = $operation[$position];
                    unset($operation[$position]);
                }
            }

            $item[$method] = $operation;
        }

        return $item;
    }
}
