<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use stdClass;

/**
 * A deterministic, order-insensitive JSON encoder for equality and fingerprinting. Not for canonical
 * document emission — that's {@see CanonicalJsonSerializer}.
 *
 * Arrays are re-keyed in ascending key order before encoding, so structurally-equal values encode to
 * the same bytes whatever their insertion order. List keys (`0,1,2,…`) are already ordered, so element
 * order survives. Objects collapse to their class-string, since closures and mappers have no stable
 * serialisable identity — except {@see stdClass}, whose members ARE its identity ({@see JsonValue}). It
 * descends, and stays an object so `{}` and `[]` do not fingerprint alike.
 *
 * Callers hand this arbitrary values — an extension's own properties, a schema an integration built —
 * so the normalizer is TOTAL: anything `json_encode` would refuse is carried as a marker instead of
 * making the encode fail: a failed encode answers `''` for every such value, and `''` is one
 * fingerprint shared by all of them. The descent is bounded for the same reason: a
 * self-referential array is a stack overflow, which is SIGSEGV with no message rather than an
 * exception.
 *
 * @internal
 */
final class Json
{
    /**
     * How deep the descent goes before it stops. Nothing encoded here is a document — these are
     * fingerprints — so this only has to sit above any real structure, and it is far under
     * `json_encode`'s own 512.
     */
    private const MAX_DEPTH = 128;

    /** What stands in for a value below {@see MAX_DEPTH}. */
    private const TRUNCATED = '@docuccino:depth';

    /** A stable string fingerprint of an arbitrary JSON-ish value; `''` when it cannot be encoded. */
    public static function stable(mixed $value): string
    {
        $encoded = json_encode(self::normalize($value));

        return $encoded === false ? '' : $encoded;
    }

    private static function normalize(mixed $value, int $depth = 0): mixed
    {
        if ($value instanceof stdClass) {
            return $depth >= self::MAX_DEPTH ? self::TRUNCATED : (object) self::members((array) $value, $depth);
        }

        if (is_array($value)) {
            return $depth >= self::MAX_DEPTH ? self::TRUNCATED : self::members($value, $depth);
        }

        if (is_object($value)) {
            return $value::class;
        }

        if (is_string($value)) {
            return self::text($value);
        }

        if (is_float($value) && ! is_finite($value)) {
            return is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF');
        }

        return is_resource($value) ? 'resource:'.get_resource_type($value) : $value;
    }

    /**
     * Members in ascending key order, each normalized. List keys are already ordered, so element order
     * survives.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>
     */
    private static function members(array $value, int $depth): array
    {
        $keys = array_keys($value);
        sort($keys);
        $out = [];
        foreach ($keys as $key) {
            $out[self::text((string) $key)] = self::normalize($value[$key], $depth + 1);
        }

        return $out;
    }

    /**
     * A string `json_encode` will take. Bytes that are not valid UTF-8 make it fail, so they travel
     * base64-encoded — two different blobs then still fingerprint differently, which is the whole job.
     */
    private static function text(string $value): string
    {
        return preg_match('//u', $value) === 1 ? $value : 'base64:'.base64_encode($value);
    }
}
