<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

/**
 * An exception the engine believes can escape an action.
 *
 * Identity is `(exceptionFqcn, httpStatusHint)`: two `abort()`s with 403 and 404 are two distinct API
 * error responses and must never dedupe into one. `httpStatusHint` is nullable — a bare
 * `HttpException` with no constant-foldable status has a known family but an unknown status. The
 * engine stops at exceptions plus status hints; response bodies are the pipeline's job — except for an
 * exception that carries the response it is sent as (an `HttpResponseException` an exception map
 * translates to), which holds that response as the return it is read as, and is identified by it too.
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
        public ?ReturnSite $response = null,
    ) {}

    /**
     * The same throw, raised where it was, rendered as `$fqcn` at `$status`, carrying `$response` where that
     * class sends one — a translation, or a reading of its status.
     */
    public function as(string $fqcn, ?int $status, ?ReturnSite $response = null): self
    {
        return new self($fqcn, $status, $this->callChain, $this->confidence, $this->disposition, $response);
    }

    /** Identity key: `(fqcn, status)`, and the response carried where there is one. */
    public function identityKey(): string
    {
        $key = $this->exceptionFqcn.'@'.($this->httpStatusHint ?? 'null');

        return $this->response === null ? $key : $key.' '.json_encode($this->response->type->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'exceptionFqcn' => $this->exceptionFqcn,
            'httpStatusHint' => $this->httpStatusHint,
            'callChain' => array_map(static fn (Frame $f): array => $f->toArray(), $this->callChain),
            'confidence' => $this->confidence->value,
            'disposition' => $this->disposition->value,
        ];
        if ($this->response !== null) {
            $data['response'] = $this->response->toArray();
        }

        return $data;
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
        $response = $data['response'] ?? null;

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
            is_array($response) ? ReturnSite::fromArray($response) : null,
        );
    }
}
