<?php

declare(strict_types=1);

namespace Docuccino\Core\Overlay;

use Docuccino\Core\Support\Arr;

/**
 * Resolves an OpenAPI Overlay `target` (a JSONPath expression) against a document, returning the
 * concrete key-paths it matches. Only a documented subset of JSONPath is supported — enough for
 * object-member targeting over an OAS/UIR document:
 *
 * - root `$`;
 * - dot-member `.name`;
 * - bracket-member `['name']` / `["name"]` — any characters, so `$.paths['/pets']` works;
 * - array index `[0]`;
 * - array equality filter `[?(@.field=='value')]`, string values only.
 *
 * Anything else — wildcards, recursive descent `..`, slices, unions, comparisons other than `==` —
 * raises {@see UnsupportedSelectorException}, which the applier turns into an error diagnostic.
 *
 * @internal
 */
final class TargetResolver
{
    /**
     * @param  array<string, mixed>  $document
     * @return list<list<int|string>> concrete key-paths that exist in the document
     */
    public function resolve(string $target, array $document): array
    {
        $segments = $this->parse($target);

        /** @var list<list<int|string>> $paths */
        $paths = [[]];

        foreach ($segments as $segment) {
            $paths = $this->expand($segment, $paths, $document);
        }

        return $paths;
    }

    /**
     * @return list<array{type: string, key?: string, index?: int, field?: string, value?: string}>
     */
    private function parse(string $target): array
    {
        $trimmed = trim($target);

        if ($trimmed === '' || $trimmed[0] !== '$') {
            throw UnsupportedSelectorException::for($target, 'a target must start with the root "$".');
        }

        $offset = 1;
        $length = strlen($trimmed);
        $segments = [];

        while ($offset < $length) {
            $char = $trimmed[$offset];

            if ($char === '.') {
                if (($trimmed[$offset + 1] ?? '') === '.') {
                    throw UnsupportedSelectorException::for($target, 'recursive descent ".." is not supported.');
                }

                $offset++;
                $segments[] = $this->parseDotMember($target, $trimmed, $offset, $length);

                continue;
            }

            if ($char === '[') {
                $segments[] = $this->parseBracket($target, $trimmed, $offset, $length);

                continue;
            }

            throw UnsupportedSelectorException::for($target, sprintf('unexpected character "%s" at position %d.', $char, $offset));
        }

        return $segments;
    }

    /**
     * @return array{type: string, key: string}
     */
    private function parseDotMember(string $target, string $source, int &$offset, int $length): array
    {
        if (($source[$offset] ?? '') === '*') {
            throw UnsupportedSelectorException::for($target, 'wildcard ".*" is not supported.');
        }

        $start = $offset;
        while ($offset < $length && preg_match('/[A-Za-z0-9_-]/', $source[$offset]) === 1) {
            $offset++;
        }

        if ($offset === $start) {
            throw UnsupportedSelectorException::for($target, sprintf('empty or invalid member name at position %d.', $start));
        }

        return ['type' => 'member', 'key' => substr($source, $start, $offset - $start)];
    }

    /**
     * @return array{type: string, key?: string, index?: int, field?: string, value?: string}
     */
    private function parseBracket(string $target, string $source, int &$offset, int $length): array
    {
        $close = strpos($source, ']', $offset);
        if ($close === false) {
            throw UnsupportedSelectorException::for($target, 'unterminated "[" bracket selector.');
        }

        $inner = substr($source, $offset + 1, $close - $offset - 1);
        $offset = $close + 1;

        if ($inner === '*') {
            throw UnsupportedSelectorException::for($target, 'wildcard "[*]" is not supported.');
        }

        if (preg_match('/^\'(.*)\'$/s', $inner, $m) === 1 || preg_match('/^"(.*)"$/s', $inner, $m) === 1) {
            return ['type' => 'member', 'key' => $m[1]];
        }

        if (preg_match('/^-?\d+$/', $inner) === 1) {
            return ['type' => 'index', 'index' => (int) $inner];
        }

        if (preg_match('/^\?\(\s*@\.([A-Za-z0-9_-]+)\s*==\s*(?:\'([^\']*)\'|"([^"]*)")\s*\)$/', $inner, $m) === 1) {
            $single = $m[2] ?? '';
            $value = ($single !== '' || ! isset($m[3])) ? $single : $m[3];

            return ['type' => 'filter', 'field' => $m[1], 'value' => $value];
        }

        if (str_contains($inner, ':')) {
            throw UnsupportedSelectorException::for($target, 'array slice selectors are not supported.');
        }

        throw UnsupportedSelectorException::for($target, sprintf('unsupported bracket selector "[%s]".', $inner));
    }

    /**
     * @param  array{type: string, key?: string, index?: int, field?: string, value?: string}  $segment
     * @param  list<list<int|string>>  $paths
     * @param  array<string, mixed>  $document
     * @return list<list<int|string>>
     */
    private function expand(array $segment, array $paths, array $document): array
    {
        $out = [];

        foreach ($paths as $path) {
            $node = Arr::valueAt($document, $path);

            match ($segment['type']) {
                'member' => $this->expandMember($segment, $path, $node, $out),
                'index' => $this->expandIndex($segment, $path, $node, $out),
                'filter' => $this->expandFilter($segment, $path, $node, $out),
                default => null,
            };
        }

        return $out;
    }

    /**
     * @param  array{type: string, key?: string, index?: int, field?: string, value?: string}  $segment
     * @param  list<int|string>  $path
     * @param  list<list<int|string>>  $out
     */
    private function expandMember(array $segment, array $path, mixed $node, array &$out): void
    {
        $key = $segment['key'] ?? '';

        if (is_array($node) && array_key_exists($key, $node)) {
            $out[] = [...$path, $key];
        }
    }

    /**
     * @param  array{type: string, key?: string, index?: int, field?: string, value?: string}  $segment
     * @param  list<int|string>  $path
     * @param  list<list<int|string>>  $out
     */
    private function expandIndex(array $segment, array $path, mixed $node, array &$out): void
    {
        $index = $segment['index'] ?? 0;

        if (is_array($node) && array_key_exists($index, $node)) {
            $out[] = [...$path, $index];
        }
    }

    /**
     * @param  array{type: string, key?: string, index?: int, field?: string, value?: string}  $segment
     * @param  list<int|string>  $path
     * @param  list<list<int|string>>  $out
     */
    private function expandFilter(array $segment, array $path, mixed $node, array &$out): void
    {
        if (! is_array($node)) {
            return;
        }

        $field = $segment['field'] ?? '';
        $value = $segment['value'] ?? '';

        foreach ($node as $index => $element) {
            if (is_array($element) && array_key_exists($field, $element) && $element[$field] === $value) {
                $out[] = [...$path, $index];
            }
        }
    }
}
