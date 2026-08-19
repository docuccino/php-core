<?php

declare(strict_types=1);

namespace Docuccino\Core\Examples;

/**
 * What one run has recorded for one operation: the file as it was BEFORE the run, and the best body
 * seen for each status, media type and name since.
 *
 * The two are kept apart on purpose. The published file is a function of both — the committed bytes
 * stand while their shape is unchanged, and this run's winner takes over when it moves — so a session
 * that folded the two together could not tell a body it wrote a moment ago from the one an author
 * committed last week, and re-recording would answer differently depending on when it was asked.
 *
 * Every merge is a minimum over {@see RecordedExample::outranks()}, which is a total order on content,
 * so a session is the same whatever order it met the responses in. That is what a
 * {@see SharedRecordingLedger} leans on when several test-runner workers record one operation at once.
 *
 * @internal
 */
final readonly class RecordingSession
{
    /**
     * @param  array<string, RecordedExample>  $best  keyed by {@see RecordedExample::key()}
     */
    private function __construct(
        public ?ExampleRecording $original,
        public array $best,
    ) {}

    public static function opening(?ExampleRecording $original): self
    {
        return new self($original, []);
    }

    /** This session with $example if it is the better illustration of its slot, unchanged if it is not. */
    public function with(RecordedExample $example): self
    {
        $key = $example->key();
        $incumbent = $this->best[$key] ?? null;

        if ($incumbent !== null && ! $example->outranks($incumbent)) {
            return $this;
        }

        $best = $this->best;
        $best[$key] = $example;
        ksort($best);

        return new self($this->original, $best);
    }

    /** The file this session publishes: what was committed, with this run's winners over it. */
    public function recording(string $operationId, string $endpoint): ExampleRecording
    {
        $recording = ($this->original ?? ExampleRecording::of($operationId, ''))->labelled($endpoint);

        foreach ($this->best as $example) {
            $recording = $recording->with($example);
        }

        return $recording;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'original' => $this->original?->toArray(),
            'best' => array_values(array_map(static fn (RecordedExample $e): array => $e->toArray(), $this->best)),
        ];
    }

    /**
     * The session a decoded sidecar describes, or null when the file is not one.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $committed = $data['original'] ?? null;
        $best = $data['best'] ?? null;

        if (! is_array($best) || ! array_is_list($best)) {
            return null;
        }

        $original = null;
        if ($committed !== null) {
            if (! is_array($committed)) {
                return null;
            }

            /** @var array<string, mixed> $committed */
            $original = ExampleRecording::fromArray($committed);

            if ($original === null) {
                return null;
            }
        }

        $session = new self($original, []);
        foreach ($best as $entry) {
            if (! is_array($entry)) {
                return null;
            }

            /** @var array<string, mixed> $entry */
            $example = RecordedExample::fromArray($entry);

            if ($example === null) {
                return null;
            }

            $session = $session->with($example);
        }

        return $session;
    }
}
