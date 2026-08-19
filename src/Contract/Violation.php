<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

/**
 * One way the observed exchange disagreed with the contract: where in the payload, what was wrong, and
 * which schema node said so.
 */
final readonly class Violation
{
    /**
     * @param  string  $location  the payload — `the response body`, `?page`
     * @param  string  $pointer  where inside it, as a JSON pointer; empty for the payload as a whole
     */
    public function __construct(
        public string $location,
        public string $pointer,
        public string $message,
        public string $schemaPointer,
        public ProvenanceTrail $provenance,
    ) {}

    /** A violation of one half as a whole — an undocumented status, a media type nobody declared. */
    public static function ofExchange(string $message, string $location = 'the response'): self
    {
        return new self($location, '', $message, '', ProvenanceTrail::none());
    }

    public function where(): string
    {
        return $this->pointer === '' ? $this->location : $this->location.' at '.$this->pointer;
    }
}
