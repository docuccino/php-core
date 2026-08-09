<?php

declare(strict_types=1);

namespace Docuccino\Core\Overlay;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Support\Arr;

/**
 * Applies an {@see OverlayDocument} to a document array as the Overlay(45) precedence layer
 * (design §7). Each action's `target` is resolved by {@see TargetResolver}; `update` merges an
 * object (deep, update wins) or replaces a scalar/array, `remove` deletes the matched node.
 *
 * Every value an `update` changes is recorded in the affected node's `x-docuccino.provenance` with
 * `producer: overlay`, `layer: overlay`, and the prior value captured in `overrode` — so the
 * "why is it documented this way" trail survives regeneration. An unsupported selector yields an
 * error diagnostic; a target matching nothing yields a warning — never a silent skip.
 *
 * @internal
 */
final class OverlayApplier
{
    public function __construct(
        private readonly TargetResolver $resolver = new TargetResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $document
     */
    public function apply(array $document, OverlayDocument $overlay): OverlayResult
    {
        /** @var list<Diagnostic> $diagnostics */
        $diagnostics = [];

        foreach ($overlay->actions as $action) {
            $document = $this->applyAction($document, $action, $diagnostics);
        }

        return new OverlayResult(Arr::stringKeyed($document), $diagnostics);
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private function applyAction(array $document, OverlayAction $action, array &$diagnostics): array
    {
        if ($action->remove && $action->hasUpdate) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Error,
                code: 'overlay.conflicting-operation',
                message: sprintf('Overlay action for target "%s" declares both "update" and "remove"; an action must carry exactly one operation.', $action->target),
                help: 'Split the action into two: one with "update" and one with "remove".',
            );

            return $document;
        }

        try {
            $paths = $this->resolver->resolve($action->target, $document);
        } catch (UnsupportedSelectorException $e) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Error,
                code: 'overlay.unsupported-selector',
                message: $e->getMessage(),
                help: 'Rewrite the target using the supported subset: object members, array indexes, and [?(@.field==\'value\')] equality filters.',
            );

            return $document;
        }

        if ($paths === []) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'overlay.target-missing',
                message: sprintf('Overlay target "%s" matched no node; the action had no effect.', $action->target),
            );

            return $document;
        }

        return $action->remove
            ? $this->applyRemove($document, $paths)
            : $this->applyUpdate($document, $paths, $action->update);
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<list<int|string>>  $paths
     * @return array<string, mixed>
     */
    private function applyRemove(array $document, array $paths): array
    {
        // Remove deepest / highest-index matches first so earlier list indexes never shift.
        usort($paths, static function (array $a, array $b): int {
            $n = min(count($a), count($b));
            for ($i = 0; $i < $n; $i++) {
                if ($a[$i] === $b[$i]) {
                    continue;
                }

                if (is_int($a[$i]) && is_int($b[$i])) {
                    return $b[$i] <=> $a[$i];
                }

                return strcmp((string) $b[$i], (string) $a[$i]);
            }

            return count($b) <=> count($a);
        });

        foreach ($paths as $path) {
            $document = Arr::stringKeyed($this->removeAt($document, $path));
        }

        return $document;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<list<int|string>>  $paths
     * @return array<string, mixed>
     */
    private function applyUpdate(array $document, array $paths, mixed $update): array
    {
        foreach ($paths as $path) {
            $old = Arr::valueAt($document, $path);

            if (self::isMap($old) && self::isMap($update)) {
                /** @var array<string, mixed> $oldMap */
                $oldMap = $old;
                /** @var array<string, mixed> $updateMap */
                $updateMap = $update;

                $merged = self::deepMerge($oldMap, $updateMap);
                $merged = self::attachProvenance($merged, array_keys($updateMap), $oldMap);
                $document = Arr::stringKeyed($this->setAt($document, $path, $merged));

                continue;
            }

            $document = Arr::stringKeyed($this->setAt($document, $path, $update));
            $document = $this->recordScalarProvenance($document, $path, $old);
        }

        return $document;
    }

    /**
     * Attaches overlay provenance to the parent node when a scalar/array leaf member is replaced.
     *
     * @param  array<string, mixed>  $document
     * @param  list<int|string>  $path
     * @return array<string, mixed>
     */
    private function recordScalarProvenance(array $document, array $path, mixed $old): array
    {
        if ($path === []) {
            return $document;
        }

        $field = $path[count($path) - 1];
        if (! is_string($field)) {
            return $document;
        }

        $parentPath = array_slice($path, 0, -1);
        if ($parentPath === []) {
            return $document;
        }

        $parent = Arr::valueAt($document, $parentPath);
        if (! self::isMap($parent)) {
            return $document;
        }

        /** @var array<string, mixed> $parentMap */
        $parentMap = $parent;
        $overrode = $old === null ? [] : [$field => $old];
        $parentMap = self::attachProvenance($parentMap, [$field], $overrode);

        return Arr::stringKeyed($this->setAt($document, $parentPath, $parentMap));
    }

    /**
     * @param  array<array-key, mixed>  $document
     * @param  list<int|string>  $path
     * @return array<array-key, mixed>
     */
    private function setAt(array $document, array $path, mixed $value): array
    {
        if ($path === []) {
            return is_array($value) ? $value : $document;
        }

        $key = $path[0];
        $rest = array_slice($path, 1);

        if ($rest === []) {
            $document[$key] = $value;

            return $document;
        }

        $child = $document[$key] ?? null;
        $document[$key] = $this->setAt(is_array($child) ? $child : [], $rest, $value);

        return $document;
    }

    /**
     * @param  array<array-key, mixed>  $document
     * @param  list<int|string>  $path
     * @return array<array-key, mixed>
     */
    private function removeAt(array $document, array $path): array
    {
        if ($path === []) {
            return $document;
        }

        $key = $path[0];
        $rest = array_slice($path, 1);

        if ($rest === []) {
            if (! array_key_exists($key, $document)) {
                return $document;
            }

            $wasList = array_is_list($document);
            unset($document[$key]);

            return $wasList ? array_values($document) : $document;
        }

        $child = $document[$key] ?? null;
        if (is_array($child)) {
            $document[$key] = $this->removeAt($child, $rest);
        }

        return $document;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<int|string>  $fields
     * @param  array<string, mixed>  $previousValues  prior values keyed by field, for `overrode`
     * @return array<string, mixed>
     */
    private static function attachProvenance(array $node, array $fields, array $previousValues): array
    {
        $stringFields = array_map(static fn (int|string $f): string => (string) $f, $fields);

        $docuccino = isset($node['x-docuccino']) && is_array($node['x-docuccino']) ? Arr::stringKeyed($node['x-docuccino']) : [];
        $provenance = isset($docuccino['provenance']) && is_array($docuccino['provenance']) ? array_values($docuccino['provenance']) : [];

        $overrode = [];
        foreach ($stringFields as $field) {
            if (array_key_exists($field, $previousValues)) {
                $overrode[] = ['field' => $field, 'value' => $previousValues[$field]];
            }
        }

        $record = ['producer' => 'overlay', 'layer' => 'overlay', 'fields' => $stringFields];
        if ($overrode !== []) {
            $record['overrode'] = $overrode;
        }

        $provenance[] = $record;
        $docuccino['provenance'] = $provenance;
        $node['x-docuccino'] = $docuccino;

        return $node;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private static function deepMerge(array $a, array $b): array
    {
        foreach ($b as $key => $value) {
            if (array_key_exists($key, $a) && self::isMap($a[$key]) && self::isMap($value)) {
                /** @var array<string, mixed> $existing */
                $existing = $a[$key];
                /** @var array<string, mixed> $incoming */
                $incoming = $value;
                $a[$key] = self::deepMerge($existing, $incoming);

                continue;
            }

            $a[$key] = $value;
        }

        return $a;
    }

    private static function isMap(mixed $value): bool
    {
        return is_array($value) && $value !== [] && ! array_is_list($value);
    }
}
