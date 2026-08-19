<?php

declare(strict_types=1);

namespace Docuccino\Core\Lint;

use Docuccino\Core\Document\PathItem;
use Docuccino\Core\Provenance\Source;

/**
 * One operation as the document lints see it: the signature their messages name it by, the node
 * itself, and whatever source the provenance trail recorded for it.
 *
 * A webhook is an operation too, published under a NAME rather than a path template, so it is named
 * `METHOD webhooks.<name>` — the differ's vocabulary, and what a webhook's own diagnostics already
 * say. A path template always begins with `/`, so the two spaces cannot collide and a safelist entry
 * still means exactly one node.
 *
 * {@see all()} answers the paths before the webhooks and sorts each by signature, so where a finding
 * lands never depends on the order its route or class was met, and adding one never moves another's.
 *
 * @internal
 */
final readonly class LintOperation
{
    /**
     * @param  array<string, mixed>  $operation
     * @param  bool  $webhook  whether the node is published under `webhooks` rather than `paths`
     */
    public function __construct(
        public string $signature,
        public array $operation,
        public bool $webhook = false,
    ) {}

    /**
     * Every operation in an assembled draft: the paths in signature order, then the webhooks.
     *
     * @param  array<string, mixed>  $document
     * @return list<self>
     */
    public static function all(array $document): array
    {
        return [
            ...self::under($document['paths'] ?? null, '', webhook: false),
            ...self::under($document['webhooks'] ?? null, 'webhooks.', webhook: true),
        ];
    }

    public function operationId(): ?string
    {
        $operationId = $this->operation['operationId'] ?? null;

        return is_string($operationId) && $operationId !== '' ? $operationId : null;
    }

    /**
     * The tags the operation carries, ignoring anything that isn't a non-empty string.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        $tags = $this->operation['tags'] ?? null;
        if (! is_array($tags)) {
            return [];
        }

        $names = [];
        foreach ($tags as $tag) {
            if (is_string($tag) && $tag !== '') {
                $names[] = $tag;
            }
        }

        return $names;
    }

    /**
     * Where the operation was written, from the first provenance record that recorded it. Provenance
     * is stripped at emit rather than before transformers run, so this answers the same on every
     * `--provenance` level; a closure route nothing traced simply has none.
     */
    public function source(): ?Source
    {
        $extension = $this->operation['x-docuccino'] ?? null;
        $records = is_array($extension) ? ($extension['provenance'] ?? null) : null;
        if (! is_array($records)) {
            return null;
        }

        foreach ($records as $record) {
            $source = is_array($record) ? ($record['source'] ?? null) : null;
            if (is_array($source) && is_string($source['file'] ?? null)) {
                /** @var array<string, mixed> $source */
                return Source::fromArray($source);
            }
        }

        return null;
    }

    /**
     * The operations published under one heading, in signature order. `$prefix` is what keeps a
     * webhook name out of the space a path template occupies.
     *
     * @return list<self>
     */
    private static function under(mixed $items, string $prefix, bool $webhook): array
    {
        if (! is_array($items)) {
            return [];
        }

        $operations = [];
        foreach ($items as $key => $item) {
            if (! is_array($item)) {
                continue;
            }

            foreach (PathItem::METHODS as $method) {
                $operation = $item[$method] ?? null;
                if (is_array($operation)) {
                    /** @var array<string, mixed> $operation */
                    $operations[] = new self(strtoupper($method).' '.$prefix.$key, $operation, $webhook);
                }
            }
        }

        usort($operations, static fn (self $a, self $b): int => strcmp($a->signature, $b->signature));

        return $operations;
    }
}
