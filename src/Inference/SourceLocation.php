<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

use Docuccino\Core\Provenance\Source;

/**
 * Where something was found in source. `line` is nullable — PHPStan reports
 * `-1` for some synthesised throw points and execution-end nodes (Spike C trap
 * #3), which we normalise to `null`. `pos` is the byte offset used for
 * deterministic ordering (Spike B trap #5), also nullable.
 *
 * Deliberately distinct from {@see Source}: this is the
 * engine's raw, absolute-path finding; that is the emitted, project-root-relative
 * provenance source. Cross via {@see Source::fromLocation()}.
 */
final readonly class SourceLocation
{
    public ?int $line;

    public function __construct(
        public string $file,
        ?int $line = null,
        public ?int $pos = null,
    ) {
        $this->line = ($line === null || $line < 0) ? null : $line;
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

        if ($this->pos !== null) {
            $out['pos'] = $this->pos;
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $file = $data['file'] ?? '';
        $line = $data['line'] ?? null;
        $pos = $data['pos'] ?? null;

        return new self(
            is_string($file) ? $file : '',
            is_int($line) ? $line : null,
            is_int($pos) ? $pos : null,
        );
    }
}
