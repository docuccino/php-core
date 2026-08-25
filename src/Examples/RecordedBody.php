<?php

declare(strict_types=1);

namespace Docuccino\Core\Examples;

use Docuccino\Core\Support\JsonValue;
use JsonException;

/**
 * Reading a recorded response body into the shape the rest of the pipeline expects, and writing it
 * back.
 *
 * The reading itself is {@see JsonValue}, which is where the `{}`-versus-`[]` convention lives and why
 * — a recorded body is one of four callers that must agree about it. This class is the recording FILE:
 * that reading, plus the bytes a file on disk carries.
 *
 * @internal
 */
final class RecordedBody
{
    /**
     * @throws JsonException when the string is not JSON
     */
    public static function decode(string $json): mixed
    {
        return JsonValue::decode($json);
    }

    /**
     * A recording file's bytes: pretty-printed for review, LF-terminated, deterministic for one value.
     *
     * @param  array<string, mixed>  $data
     */
    public static function encode(array $data): ?string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? null : $json."\n";
    }
}
