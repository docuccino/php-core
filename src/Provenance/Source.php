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
 * (file + line + human `symbol`), and {@see fromLocation()} is the crossing point between them.
 */
final readonly class Source
{
    public function __construct(
        public string $file,
        public ?int $line = null,
        public ?string $symbol = null,
    ) {}

    /**
     * Converts an inference {@see SourceLocation} into a provenance source. The path is relativised by
     * {@see RootRelativeSourcePathResolver}, which owns the one rule for it — so a file outside
     * `$projectRoot` degrades the same way here as everywhere else, and never arrives absolute.
     */
    public static function fromLocation(SourceLocation $location, string $projectRoot, ?string $symbol = null): self
    {
        return new self(
            file: (new RootRelativeSourcePathResolver($projectRoot))->relative($location->file),
            line: $location->line,
            symbol: $symbol,
        );
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
