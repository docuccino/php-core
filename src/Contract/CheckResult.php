<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

/**
 * What checking one exchange found: the operation it was matched to, and an {@see Outcome} per half —
 * null where that half was not asked for.
 *
 * A null operation means the document describes no such endpoint, which is a failure of a different
 * kind: there is no contract to be wrong about.
 */
final readonly class CheckResult
{
    public function __construct(
        public ?ContractOperation $operation,
        public ?Outcome $request = null,
        public ?Outcome $response = null,
    ) {}

    public function matched(): bool
    {
        return $this->operation !== null;
    }

    public function ok(): bool
    {
        return $this->matched() && $this->failures() === [];
    }

    /**
     * The halves that disagreed with the contract, request first.
     *
     * @return list<Outcome>
     */
    public function failures(): array
    {
        return array_values(array_filter(
            [$this->request, $this->response],
            static fn (?Outcome $outcome): bool => $outcome !== null && ! $outcome->ok(),
        ));
    }

    /**
     * Every note the check recorded — the honest "this could not be checked" cases.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        $notes = [];
        foreach ([$this->request, $this->response] as $outcome) {
            if ($outcome?->note !== null) {
                $notes[] = $outcome->note;
            }
        }

        return $notes;
    }
}
