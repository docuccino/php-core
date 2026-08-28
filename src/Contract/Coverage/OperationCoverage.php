<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract\Coverage;

/** One documented operation, its documented responses, and how much of it the suite reached. */
final readonly class OperationCoverage
{
    /**
     * @param  bool  $exercised  the suite produced SOME response for this operation — the coarse half,
     *                           printed beside the gated number rather than instead of it
     * @param  list<ResponseCoverage>  $responses
     * @param  list<string>  $unreachable  documented response keys no status can name, so counted in
     *                                     neither half — a defect in the document, reported as one
     */
    public function __construct(
        public ?string $id,
        public string $label,
        public bool $exercised,
        public array $responses,
        public array $unreachable = [],
    ) {}

    /** @return list<ResponseCoverage> */
    public function unexercised(): array
    {
        return array_values(array_filter(
            $this->responses,
            static fn (ResponseCoverage $response): bool => ! $response->exercised,
        ));
    }

    public function complete(): bool
    {
        return $this->unexercised() === [];
    }
}
