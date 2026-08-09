<?php

declare(strict_types=1);

namespace Docuccino\Core\Content;

use Docuccino\Core\Support\Arr;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A minimal YAML-frontmatter splitter: a leading `---` fenced block is parsed (symfony/yaml) into
 * the metadata map, the remainder is the markdown body. A file with no fence is all body; a
 * malformed fence yields an empty map (the caller treats missing metadata as "derive from path").
 *
 * @internal
 */
final class Frontmatter
{
    /**
     * @return array{0: array<string, mixed>, 1: string} [frontmatter, body]
     */
    public static function parse(string $raw): array
    {
        // Normalise CRLF so the fence detection and body are line-ending independent (determinism).
        $normalized = str_replace(["\r\n", "\r"], "\n", $raw);

        // A leading `---` fence closed by a `---` on its own line; everything after it is the body.
        if (! str_starts_with($normalized, "---\n")
            || preg_match('/^---\n(.*?)\n---\n?(.*)$/s', $normalized, $matches) !== 1) {
            return [[], $normalized];
        }

        try {
            $parsed = Yaml::parse($matches[1]);
        } catch (ParseException) {
            return [[], $matches[2]];
        }

        return [is_array($parsed) ? Arr::stringKeyed($parsed) : [], $matches[2]];
    }
}
