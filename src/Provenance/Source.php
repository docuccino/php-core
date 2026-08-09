<?php

declare(strict_types=1);

namespace Docuccino\Core\Provenance;

use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Support\Hydrate;

/**
 * A project-root-relative source location for the provenance trail. Never an identity input.
 *
 * Not the same thing as {@see SourceLocation}, which is the engine's raw absolute-path finding
 * (file + line + byte `pos`, for engine-internal ordering). This is the emitted `provenance.source`
 * (file + line + human `symbol`), and {@see fromLocation()} is the one crossing point that owns the
 * absolute→relative normalisation.
 */
final readonly class Source
{
    public function __construct(
        public string $file,
        public ?int $line = null,
        public ?string $symbol = null,
    ) {}

    /**
     * Converts an inference {@see SourceLocation} into a provenance source, relativising the engine's
     * absolute path. Files already relative, or outside `$projectRoot`, are kept verbatim.
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
