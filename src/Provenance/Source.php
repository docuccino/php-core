<?php

declare(strict_types=1);

namespace Docuccino\Core\Provenance;

use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Support\Hydrate;

/**
 * A project-root-relative source location for the provenance trail. Never used as
 * an identity input.
 *
 * Deliberately distinct from {@see SourceLocation}: that is the inference engine's
 * raw, absolute-path finding (file + line + byte `pos`, used for engine-internal
 * ordering); this is the emitted, project-root-relative `provenance.source`
 * (file + line + human `symbol`). {@see fromLocation()} is the single crossing
 * point that owns the absolute→relative path normalization.
 */
final readonly class Source
{
    public function __construct(
        public string $file,
        public ?int $line = null,
        public ?string $symbol = null,
    ) {}

    /**
     * Convert an inference {@see SourceLocation} into a provenance source,
     * normalizing the (absolute) engine file path to a project-root-relative one.
     * A file already relative, or one outside `$projectRoot`, is kept verbatim.
     */
    public static function fromLocation(SourceLocation $location, string $projectRoot, ?string $symbol = null): self
    {
        return new self(
            file: self::relativize($location->file, $projectRoot),
            line: $location->line,
            symbol: $symbol,
        );
    }

    private static function relativize(string $file, string $projectRoot): string
    {
        $prefix = rtrim($projectRoot, '/');

        if ($prefix === '') {
            return $file;
        }

        return str_starts_with($file, $prefix.'/')
            ? substr($file, strlen($prefix) + 1)
            : $file;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $file = $data['file'] ?? '';

        return new self(
            file: is_string($file) ? $file : '',
            line: Hydrate::intOrNull($data['line'] ?? null),
            symbol: Hydrate::stringOrNull($data['symbol'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['file' => $this->file];

        if ($this->line !== null) {
            $out['line'] = $this->line;
        }

        if ($this->symbol !== null) {
            $out['symbol'] = $this->symbol;
        }

        return $out;
    }
}
