<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

/**
 * An exception the engine believes can escape an action (design §6).
 *
 * Identity is `(exceptionFqcn, httpStatusHint)` — two `abort()`s with statuses
 * 403 and 404 are two distinct API error responses and must never dedupe to one
 * (Spike C finding D). `httpStatusHint` is nullable: a bare `HttpException`
 * without a constant-foldable status has a known family but unknown status.
 * The engine stops at "exceptions + status hints"; response bodies are the
 * pipeline's job.
 */
final readonly class ThrownException
{
    /**
     * @param  list<Frame>  $callChain
     */
    public function __construct(
        public string $exceptionFqcn,
        public ?int $httpStatusHint,
        public array $callChain,
        public ThrowConfidence $confidence,
        public ThrowDisposition $disposition,
    ) {}

    /** Identity key: `(fqcn, status)`. */
    public function identityKey(): string
    {
        return $this->exceptionFqcn.'@'.($this->httpStatusHint ?? 'null');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'exceptionFqcn' => $this->exceptionFqcn,
            'httpStatusHint' => $this->httpStatusHint,
            'callChain' => array_map(static fn (Frame $f): array => $f->toArray(), $this->callChain),
            'confidence' => $this->confidence->value,
            'disposition' => $this->disposition->value,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $fqcn = $data['exceptionFqcn'] ?? '';
        $status = $data['httpStatusHint'] ?? null;
        $chain = $data['callChain'] ?? [];
        $confidence = $data['confidence'] ?? ThrowConfidence::Likely->value;
        $disposition = $data['disposition'] ?? ThrowDisposition::Signal->value;

        return new self(
            is_string($fqcn) ? $fqcn : '',
            is_int($status) ? $status : null,
            is_array($chain)
                ? array_values(array_map(
                    static fn (mixed $f): Frame => is_array($f) ? Frame::fromArray($f) : new Frame('', new SourceLocation('')),
                    $chain,
                ))
                : [],
            is_string($confidence) ? (ThrowConfidence::tryFrom($confidence) ?? ThrowConfidence::Likely) : ThrowConfidence::Likely,
            is_string($disposition) ? (ThrowDisposition::tryFrom($disposition) ?? ThrowDisposition::Signal) : ThrowDisposition::Signal,
        );
    }
}
