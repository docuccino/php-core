<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit\Postman;

use Docuccino\Core\Support\Arr;

/**
 * Turns the document's tags into Postman's folder tree and files each request into one.
 *
 * Sibling order is the order tags are DECLARED, not alphabetical: that order is already a published,
 * deliberate member of the document (it drives navigation everywhere else), so re-sorting it here
 * would make the collection disagree with every other rendering of the same API. Locality follows
 * from that — a folder can only move if the document's own tag list moved.
 *
 * A tag an operation uses but no definition declares is appended after the declared ones, sorted by
 * name: derived from the set of such tags, never from which operation was met first.
 *
 * @internal
 */
final readonly class Folders
{
    /**
     * @param  list<mixed>  $tags  the document's `tags` array
     * @param  list<array{tags: list<string>, item: array<string, mixed>}>  $operations
     * @return list<array<string, mixed>>
     */
    public function tree(array $tags, array $operations): array
    {
        $definitions = $this->definitions($tags);
        $order = $this->order($definitions, $operations);
        $placed = $this->place($order, $operations);

        $roots = [];
        foreach ($order as $name) {
            if ($this->parentOf($definitions, $name, $order) === null) {
                $folder = $this->folder($name, $definitions, $order, $placed);
                if ($folder !== null) {
                    $roots[] = $folder;
                }
            }
        }

        // Untagged operations sit at the root AFTER every folder. Not in a synthesized "Default"
        // folder — naming one would mint a name the document never published.
        return [...$roots, ...($placed[''] ?? [])];
    }

    /**
     * @param  list<mixed>  $tags
     * @return array<string, array<string, mixed>>
     */
    private function definitions(array $tags): array
    {
        $out = [];
        foreach ($tags as $tag) {
            $tag = is_array($tag) ? Arr::stringKeyed($tag) : [];
            $name = $tag['name'] ?? null;

            if (is_string($name) && $name !== '' && ! isset($out[$name])) {
                $out[$name] = $tag;
            }
        }

        return $out;
    }

    /**
     * Folder order: declared tags first in declaration order, then tags only operations mention,
     * sorted by name.
     *
     * @param  array<string, array<string, mixed>>  $definitions
     * @param  list<array{tags: list<string>, item: array<string, mixed>}>  $operations
     * @return list<string>
     */
    private function order(array $definitions, array $operations): array
    {
        $declared = array_keys($definitions);

        $undeclared = [];
        foreach ($operations as $operation) {
            foreach ($operation['tags'] as $tag) {
                if (! isset($definitions[$tag]) && ! in_array($tag, $undeclared, true)) {
                    $undeclared[] = $tag;
                }
            }
        }

        sort($undeclared, SORT_STRING);

        return [...$declared, ...$undeclared];
    }

    /**
     * The parent folder of $name, or null when it is a root. A parent naming nothing is ignored, and
     * a cycle is broken by refusing any parent that would not settle — the walk must terminate.
     *
     * @param  array<string, array<string, mixed>>  $definitions
     * @param  list<string>  $order
     */
    private function parentOf(array $definitions, string $name, array $order): ?string
    {
        $parent = $definitions[$name]['parent'] ?? null;

        if (! is_string($parent) || $parent === '' || ! in_array($parent, $order, true)) {
            return null;
        }

        // Walk up: if we come back to where we started, the link closes a cycle and is dropped.
        $seen = [$name];
        $cursor = $parent;
        while (true) {
            if (in_array($cursor, $seen, true)) {
                return null;
            }

            $seen[] = $cursor;
            $next = $definitions[$cursor]['parent'] ?? null;

            if (! is_string($next) || $next === '' || ! in_array($next, $order, true)) {
                return $parent;
            }

            $cursor = $next;
        }
    }

    /**
     * Each operation filed under exactly one tag: whichever of its own tags comes first in folder
     * order. A function of the operation's tag set plus the document's tag order — never of which
     * folder happened to be built first. Duplicating it into every tag would give a consumer N copies
     * of one endpoint to edit independently.
     *
     * @param  list<string>  $order
     * @param  list<array{tags: list<string>, item: array<string, mixed>}>  $operations
     * @return array<string, list<array<string, mixed>>>
     */
    private function place(array $order, array $operations): array
    {
        $placed = [];

        foreach ($operations as $operation) {
            $home = '';
            foreach ($order as $tag) {
                if (in_array($tag, $operation['tags'], true)) {
                    $home = $tag;
                    break;
                }
            }

            $placed[$home][] = $operation['item'];
        }

        return $placed;
    }

    /**
     * One folder and its descendants, or null when nothing in the subtree holds a request — an empty
     * folder is navigation for something that is not there.
     *
     * @param  array<string, array<string, mixed>>  $definitions
     * @param  list<string>  $order
     * @param  array<string, list<array<string, mixed>>>  $placed
     * @return array<string, mixed>|null
     */
    private function folder(string $name, array $definitions, array $order, array $placed): ?array
    {
        $children = [];
        foreach ($order as $candidate) {
            if ($this->parentOf($definitions, $candidate, $order) === $name) {
                $child = $this->folder($candidate, $definitions, $order, $placed);
                if ($child !== null) {
                    $children[] = $child;
                }
            }
        }

        $requests = $placed[$name] ?? [];
        if ($requests === [] && $children === []) {
            return null;
        }

        $folder = ['name' => $name];

        $description = Description::folder($definitions[$name] ?? []);
        if ($description !== '') {
            $folder['description'] = $description;
        }

        // Requests before sub-folders: a folder's own endpoints are what someone opening it wants.
        $folder['item'] = [...$requests, ...$children];

        return $folder;
    }
}
