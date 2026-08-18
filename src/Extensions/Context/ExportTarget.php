<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

use Docuccino\Core\Emit\Formats;

/**
 * One artifact a document writes: a format and where it lands.
 *
 * There is deliberately no `yaml` member. The path already states it — `docs/openapi.yaml` is a YAML
 * file — so reading it back off the extension turns a knob into a fact, and makes it impossible to
 * configure a `.yaml` path that quietly holds JSON.
 *
 * @internal
 */
final readonly class ExportTarget
{
    public function __construct(
        public string $format,
        public string $path,
    ) {}

    /** Whether this target serialises as YAML, read off the path extension. */
    public function yaml(): bool
    {
        return in_array(strtolower(pathinfo($this->path, PATHINFO_EXTENSION)), ['yaml', 'yml'], true);
    }

    /** A `.yaml` path on a format with no YAML serialisation — rejected rather than filled with JSON. */
    public function yamlUnsupported(): bool
    {
        return $this->yaml() && ! Formats::serialisesYaml($this->format);
    }
}
