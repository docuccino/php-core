<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

use Symfony\Component\Yaml\Yaml;

/**
 * Deterministic YAML writer. Member order is the caller's job — feed it canonical output; Symfony's
 * dumper keeps the insertion order it's given, so the same canonical input gives byte-identical YAML.
 *
 * Style: 2-space indent, block collections at every depth (only empty maps/lists go inline, as
 * `{  }` / `[]`), multi-line strings as literal blocks.
 *
 * The invariant: a value reaches the dumper with its carrier intact, so a `stdClass` writes `{  }` and
 * an empty `array` writes `[]` — casting objects to arrays here would emit a spec-invalid `paths: []`
 * (docs/design/uir-and-extensions.md §1 "The empty-object invariant"). DUMP_NUMERIC_KEY_AS_STRING is
 * the same care for map KEYS, which PHP coerces to ints before the dumper sees them: an unquoted `200:`
 * is a YAML integer where JSON's `"200"` is a string.
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
            | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE
            | Yaml::DUMP_NUMERIC_KEY_AS_STRING;

        return Yaml::dump($value, self::BLOCK_DEPTH, self::INDENT, $flags);
    }
}
