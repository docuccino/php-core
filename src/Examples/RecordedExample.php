<?php

declare(strict_types=1);

namespace Docuccino\Core\Examples;

use Docuccino\Core\Support\Json;

/**
 * One response body a test suite produced, kept as the example for a status, a media type and — when
 * the test that produced it said which scenario it was — a name.
 *
 * The shape is derived, never stored: a body and a fingerprint that disagreed would be a recording
 * that lies about when it needs re-recording.
 *
 * @internal
 */
final readonly class RecordedExample
{
    /**
     * What a name may be: the character set an OpenAPI component key carries, which is the bar a key
     * a code generator might read has to clear. A name is a call-site literal, so this is checked
     * where it is written rather than reported later.
     */
    private const string NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D';

    private function __construct(
        public string $status,
        public string $mediaType,
        public mixed $body,
        public string $name = '',
    ) {}

    public static function of(string $status, string $mediaType, mixed $body, string $name = ''): self
    {
        return new self($status, $mediaType, $body, $name);
    }

    public static function isLegalName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $status = $data['status'] ?? null;
        $mediaType = $data['mediaType'] ?? null;
        $name = $data['name'] ?? '';

        if (! is_string($status) || $status === '' || ! is_string($mediaType) || $mediaType === '') {
            return null;
        }

        if (! is_string($name) || ($name !== '' && ! self::isLegalName($name))) {
            return null;
        }

        if (! array_key_exists('body', $data)) {
            return null;
        }

        return new self($status, $mediaType, $data['body'], $name);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = ['status' => $this->status, 'mediaType' => $this->mediaType];

        // Only a named recording spells a name, so turning naming on somewhere else in a suite leaves
        // every file it did not touch byte-identical.
        if ($this->name !== '') {
            $data['name'] = $this->name;
        }

        return $data + ['body' => $this->body];
    }

    public function isNamed(): bool
    {
        return $this->name !== '';
    }

    /** What this example is the example FOR — one per status, media type and name. */
    public function key(): string
    {
        return $this->isNamed() ? $this->slot().' '.$this->name : $this->slot();
    }

    /** The media type it illustrates, which every name for it shares. */
    public function slot(): string
    {
        return $this->status.' '.$this->mediaType;
    }

    public function shape(): string
    {
        return ResponseShape::of($this->body);
    }

    /**
     * Whether this body is the better illustration of the two.
     *
     * A suite produces many responses per operation and one of them has to be published, so the choice
     * has to be a function of the bodies themselves — pick by which arrived first and the published
     * example changes when someone reorders a test file. The most POPULATED body wins, because a
     * response with its optional members filled in shows a reader more of the contract; then the
     * shorter one, because a compact illustration reads better than a long one saying the same thing;
     * then the lexicographically smaller, which decides nothing but decides it the same way every run.
     *
     * That it is a TOTAL order on content alone is what lets several test-runner workers record one
     * operation at once ({@see SharedRecordingLedger}): the best of a set is the same whichever worker
     * met which member of it. Which is why the encoding it ranks on has to descend into an object as
     * well as an array — a body that reads as one value ties with every other, and a tie hands the
     * choice back to merge order.
     */
    public function outranks(self $other): bool
    {
        return $this->rank() < $other->rank();
    }

    /**
     * @return array{0: int, 1: int, 2: string}
     */
    private function rank(): array
    {
        $encoded = Json::stable($this->body);

        return [-ResponseShape::populatedPaths($this->body), strlen($encoded), $encoded];
    }
}
