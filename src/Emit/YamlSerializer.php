<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

use stdClass;
use Symfony\Component\Yaml\Yaml;

/**
 * Deterministic YAML writer. Member order is the caller's responsibility (feed it canonical
 * output); Symfony's dumper preserves the insertion order it is given, so identical canonical
 * input yields byte-identical YAML across runs.
 *
 * Style: 2-space indent, block collections at every depth (only empty maps/lists render
 * inline, as `{  }` / `[]`), multi-line string literals preserved as literal blocks, and
 * `stdClass` empty-object markers dumped as maps.
 *
 * @internal
 */
final class YamlSerializer
{
    private const int BLOCK_DEPTH = 512;

    private const int INDENT = 2;

    public function serialize(mixed $value): string
    {
        $flags = Yaml::DUMP_OBJECT_AS_MAP
            | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK
            | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE;

        return Yaml::dump($this->normalize($value), self::BLOCK_DEPTH, self::INDENT, $flags);
    }

    /**
     * Symfony's dumper handles nested `stdClass` via DUMP_OBJECT_AS_MAP, but a *bare* empty
     * `stdClass` at any position is clearer as an explicit empty map, so normalise those.
     */
    private function normalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = (array) $value;
        }

        if (is_array($value)) {
            return array_map($this->normalize(...), $value);
        }

        return $value;
    }
}
