<?php

declare(strict_types=1);

namespace Docuccino\Core\Pipeline;

use Closure;

/**
 * The answer to one task handed to {@see BuildWorkers::later()}: what its worker sends, where one was started
 * and answers whole, and the task run here otherwise, made at the first ask. Let go before anyone asked — a
 * build that threw first — its worker is ended and reaped there, since nothing else will.
 *
 * @template T of array<mixed>|scalar|null
 *
 * @internal
 */
final class TaskAnswer
{
    /** @var array{T}|null */
    private ?array $answer = null;

    /** The process that started the worker: one forked after it inherits this, and must leave that worker be. */
    private readonly int $owner;

    /**
     * @param  Closure(): T  $task
     * @param  resource|null  $channel  what the worker answers on, where one was started
     * @param  (Closure(bool): void)|null  $reaped  told, once the worker is reaped, whether its answer is the one used
     */
    public function __construct(
        private readonly Closure $task,
        private ?int $pid = null,
        private mixed $channel = null,
        private readonly ?Closure $reaped = null,
    ) {
        $this->owner = (int) getmypid();
    }

    /** An answer as a worker sends it: its length first, so one cut short by a dying worker is told from a whole one. */
    public static function frame(string $answer): string
    {
        return pack('J', strlen($answer)).$answer;
    }

    /** @return T */
    public function __invoke(): mixed
    {
        if ($this->answer === null) {
            $frame = $this->receive();

            // What crossed from the worker is what the task returned, and only ever as data.
            /** @var T $made */
            $made = $frame === null ? ($this->task)() : unserialize($frame, ['allowed_classes' => false]);
            $this->answer = [$made];
        }

        return $this->answer[0];
    }

    public function __destruct()
    {
        if (getmypid() === $this->owner) {
            $this->reap(end: true, used: false);
        }
    }

    /** The whole answer the worker sent, once it has ended; null where there is none to use. */
    private function receive(): ?string
    {
        if ($this->channel === null) {
            return null;
        }

        $frame = stream_get_contents($this->channel);
        $length = is_string($frame) && strlen($frame) >= 8 ? unpack('J', $frame) : false;
        $answer = is_string($frame) ? substr($frame, 8) : '';
        $whole = is_array($length) && $length[1] === strlen($answer);

        // Read to its end, a whole frame ends with the worker; one that stopped short is ended here instead.
        $this->reap(end: ! $whole, used: $whole);

        return $whole ? $answer : null;
    }

    private function reap(bool $end, bool $used): void
    {
        if ($this->pid === null || $this->channel === null) {
            return;
        }

        if ($end) {
            posix_kill($this->pid, SIGKILL);
        }

        fclose($this->channel);
        pcntl_waitpid($this->pid, $status);
        $this->pid = null;
        $this->channel = null;

        if ($this->reaped !== null) {
            ($this->reaped)($used);
        }
    }
}
