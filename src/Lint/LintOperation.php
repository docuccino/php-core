<?php

declare(strict_types=1);

namespace Docuccino\Core\Lint;

use Docuccino\Core\Contract\Refs;
use Docuccino\Core\Document\PathItem;
use Docuccino\Core\Examples\RecordedExampleAudit;
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
            ...self::under($document, $document['paths'] ?? null, '', webhook: false),
            ...self::under($document, $document['webhooks'] ?? null, 'webhooks.', webhook: true),
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
     * A path item written as a `$ref` is followed ({@see Refs::follow()}) before its methods are read:
     * every lint here — and {@see RecordedExampleAudit} with them — would otherwise see a path
     * publishing no operations at all, which is not a document that lints clean but a document nobody
     * looked at. The signature stays the USE SITE's, since that is what a safelist entry and a
     * diagnostic name.
     *
     * @param  array<string, mixed>  $document
     * @return list<self>
     */
    private static function under(array $document, mixed $items, string $prefix, bool $webhook): array
    {
        if (! is_array($items)) {
            return [];
        }

        $operations = [];
        foreach ($items as $key => $written) {
            if (! is_array($written)) {
                continue;
            }

            /** @var array<string, mixed> $written */
            [$item, , $unresolved] = Refs::follow($document, $written, []);

            // A pointer that lands nowhere leaves no methods to read. There is nothing to lint and
            // nothing to invent; the pointer itself is reported as `lint.unresolved-reference` by the example
            // audit, which reads the same fact off {@see \Docuccino\Core\Contract\ContractIndex}.
            if ($unresolved !== null) {
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
