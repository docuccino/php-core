<?php

declare(strict_types=1);

namespace Docuccino\Core\Examples;

/**
 * The ledger for a suite running in one process: the session lives in memory, and the file is rewritten
 * from it whenever a better body turns up.
 *
 * Always from the ORIGINAL committed recording, never from what an earlier write in this run left
 * behind, so the result is the same whatever order the suite ran in.
 *
 * @internal
 */
final class ProcessRecordingLedger extends RecordingLedger
{
    /** @var array<string, RecordingSession> */
    private array $sessions = [];

    public function __construct(private readonly RecordingStore $store) {}

    protected function commit(string $operationId, string $endpoint, RecordedExample $example): void
    {
        $session = $this->sessions[$operationId] ??= RecordingSession::opening($this->store->read($operationId));
        $session = $this->sessions[$operationId] = $session->with($example);

        $this->store->put($session->recording($operationId, $endpoint));
    }
}
