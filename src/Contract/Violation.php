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

    /**
     * A `$ref` at a name the document does not define, wherever it is met: a path item, a response, a
     * request body, a delivered body, a parameter, or the node an example audit went looking behind.
     *
     * One defect, one sentence, minted in one place. It had reached five callers as five hand-typed
     * copies of the same string, which is how the halves of one product come to phrase a finding two
     * ways — and a reader who has met it once should recognise it everywhere it can happen.
     *
     * @param  string  $schemaPointer  where the reference itself stands, for the callers that know
     */
    public static function unresolvedRef(string $reference, string $location = 'the response', string $schemaPointer = ''): self
    {
        return new self(
            $location,
            '',
            sprintf('is documented at %s, which the contract does not define', $reference),
            $schemaPointer,
            ProvenanceTrail::none(),
        );
    }

    public function where(): string
    {
        return $this->pointer === '' ? $this->location : $this->location.' at '.$this->pointer;
    }
}
