<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

use Docuccino\Core\Document\UirDocument;

/**
 * Which `components.schemas` entries a document actually uses. The roots are the names anything outside
 * `components.schemas` mentions — operations, webhooks, the other component sections — closed transitively
 * over the schemas those reach, so a schema named only by an unreachable one is unreachable too and a cycle
 * between them terminates on the visited set.
 *
 * It reads every string, not only `$ref`, because the two mistakes cost differently: reading a used schema
 * as unused would downgrade a real breaking change, while naming one that a `discriminator` mapping or a
 * description merely mentions costs nothing but a report line.
 *
 * The scan and the transitive closure are shared with {@see SchemaDirection}, which runs them from
 * direction-partitioned roots instead of all of them.
 *
 * @internal
 */
final readonly class SchemaReachability
{
    private const string PREFIX = '#/components/schemas/';

    /**
     * @param  array<string, true>  $names
     */
    private function __construct(private array $names) {}

    public static function of(UirDocument $document): self
    {
        return new self(self::close(self::rootsOf($document), self::schemaArrays($document)));
    }

    public function reaches(string $name): bool
    {
        return isset($this->names[$name]);
    }

    /**
     * @return array<string, array<array-key, mixed>>
     */
    public static function schemaArrays(UirDocument $document): array
    {
        $schemas = [];
        foreach ($document->components?->schemaValues() ?? [] as $name => $schema) {
            // A boolean schema holds no strings, so it reaches nothing — and it keeps its NAME here, or a
            // `$ref` pointing at it would read as naming a schema this document does not declare.
            $schemas[(string) $name] = is_array($schema) ? $schema : [];
        }

        return $schemas;
    }

    /**
     * The roots, closed transitively over the schemas they reach.
     *
     * @param  array<string, true>  $roots
     * @param  array<string, array<array-key, mixed>>  $schemas
     * @return array<string, true>
     */
    public static function close(array $roots, array $schemas): array
    {
        $reached = $roots;
        $pending = array_keys($reached);

        while ($pending !== []) {
            $schema = $schemas[array_pop($pending)] ?? null;
            if ($schema === null) {
                continue;
            }

            foreach (array_keys(self::namesIn($schema)) as $name) {
                if (! isset($reached[$name])) {
                    $reached[$name] = true;
                    $pending[] = $name;
                }
            }
        }

        return $reached;
    }

    /**
     * @return array<string, true>
     */
    private static function rootsOf(UirDocument $document): array
    {
        $data = $document->toArray();
        $components = $data['components'] ?? null;

        if (is_array($components)) {
            unset($components['schemas']);
            $data['components'] = $components;
        }

        return self::namesIn($data);
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return array<string, true>
     */
    public static function namesIn(array $node): array
    {
        $out = [];
        self::collect($node, $out);

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  array<string, true>  $out
     */
    private static function collect(array $node, array &$out): void
    {
        foreach ($node as $value) {
            if (is_array($value)) {
                self::collect($value, $out);
            } elseif (is_string($value)) {
                self::namesInString($value, $out);
            }
        }
    }

    /**
     * @param  array<string, true>  $out
     */
    private static function namesInString(string $value, array &$out): void
    {
        $offset = 0;

        while (($at = strpos($value, self::PREFIX, $offset)) !== false) {
            $start = $at + strlen(self::PREFIX);
            $end = strpos($value, '/', $start);
            $segment = $end === false ? substr($value, $start) : substr($value, $start, $end - $start);
            $offset = $start;

            if ($segment === '') {
                continue;
            }

            // Both spellings: a pointer escapes `/` and `~`, a URI fragment escapes more again, and which
            // one a hand-written artifact used is not worth guessing at.
            $out[$segment] = true;
            $out[str_replace(['~1', '~0'], ['/', '~'], rawurldecode($segment))] = true;
        }
    }
}
