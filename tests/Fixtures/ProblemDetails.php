<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use InvalidArgumentException;

/** An RFC 9457 problem document whose optional members are left unset rather than null; twin of the fixture app's. */
final class ProblemDetails
{
    public string $type;

    public string $title;

    public int $status;

    /** Only sent when there is something to say. */
    public string $detail;

    public ?string $instance;

    public readonly string $traceId;

    public function __construct(int $status, string $title, ?string $detail = null, ?string $instance = null, ?string $traceId = null)
    {
        if ($status < 400) {
            throw new InvalidArgumentException('A problem has an error status.');
        }

        $this->type = 'about:blank';
        $this->title = $title;
        $this->status = $status;
        $this->instance = $instance;

        if ($detail !== null) {
            $this->detail = $detail;
        }

        if ($traceId !== null) {
            $this->traceId = $traceId;
        }
    }
}
