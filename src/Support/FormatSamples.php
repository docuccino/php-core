<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

/**
 * One sample value per JSON Schema `format`, shared by everything that has to produce a value the
 * format would accept: the collection exporter's whole-body builder and the synthesized property
 * example a recovered rule set earns. One table, so the two can never disagree about what an email
 * looks like.
 *
 * Every value is a constant. Nothing here is derived from the clock, the locale or the environment —
 * a sample that moved with the date would move the document with it.
 *
 * A document can replace individual samples (`representation.examples.formats`). The merge happens HERE,
 * at the one lookup, so the table stays the single answer to "what does an email look like" for both
 * callers: the synthesized property example is handed the map by the representation policy that reaches
 * it, and the collection exporter by `EmitOptions::$formatSamples`.
 *
 * @internal
 */
final class FormatSamples
{
    /**
     * `format` → a value of that format. Documentation-reserved hosts and addresses throughout
     * (RFC 2606 / RFC 5737 / RFC 3849), so nothing here names anybody's real resource.
     *
     * @var array<string, string>
     */
    private const array SAMPLES = [
        'date-time' => '2024-01-01T00:00:00Z',
        'date' => '2024-01-01',
        'time' => '00:00:00',
        'duration' => 'P1D',
        'email' => 'user@example.com',
        'idn-email' => 'user@example.com',
        'uuid' => '3fa85f64-5717-4562-b3fc-2c963f66afa6',
        'ulid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'uri' => 'https://example.com',
        'uri-reference' => '/example',
        'url' => 'https://example.com',
        'hostname' => 'example.com',
        // Laravel's `ip` rule accepts either family; the v4 sample is the one every client can read.
        'ip' => '192.0.2.1',
        'ipv4' => '192.0.2.1',
        'ipv6' => '2001:db8::1',
        'byte' => 'ZXhhbXBsZQ==',
        'binary' => '',
        'password' => 'secret',
    ];

    /**
     * The sample for a format, or null where neither the overrides nor this table knows it. An override
     * wins for the format it names and changes nothing else — the merge is per format, so a document
     * that restates one sample keeps every other constant below.
     *
     * @param  array<string, string>  $overrides  the document's configured samples, by format
     */
    public static function for(string $format, array $overrides = []): ?string
    {
        return $overrides[$format] ?? self::SAMPLES[$format] ?? null;
    }

    /**
     * Every format this table answers for — the source of truth a catalogue test reads.
     *
     * @return list<string>
     */
    public static function formats(): array
    {
        return array_keys(self::SAMPLES);
    }
}
