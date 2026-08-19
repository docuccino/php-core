<?php

declare(strict_types=1);

namespace Docuccino\Core\Examples;

/**
 * Where a recorder puts what it has just seen, and what decides whether the file moves.
 *
 * One process recording alone keeps its run in memory ({@see ProcessRecordingLedger}); several test
 * workers recording at once share it through the filesystem ({@see SharedRecordingLedger}). Both
 * answer the same thing for the same set of responses, which is the whole reason the second one is
 * allowed to exist.
 *
 * @internal
 */
abstract class RecordingLedger
{
    /** @var array<string, array<string, RecordedExample>> operation id → slot → best this process has offered */
    private array $offered = [];

    /**
     * Offer a response as the example for its operation.
     *
     * A candidate that loses to one this process already offered cannot change what any ledger holds,
     * so it costs nothing at all — no read, no lock, no write. Everything else goes to {@see commit()}.
     */
    final public function record(string $operationId, string $endpoint, RecordedExample $example): void
    {
        $key = $example->key();
        $incumbent = $this->offered[$operationId][$key] ?? null;

        if ($incumbent !== null && ! $example->outranks($incumbent)) {
            return;
        }

        $this->offered[$operationId][$key] = $example;

        $this->commit($operationId, $endpoint, $example);
    }

    abstract protected function commit(string $operationId, string $endpoint, RecordedExample $example): void;
}
