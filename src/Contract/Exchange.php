<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

/**
 * One observed request/response pair, in terms no framework owns: strings, arrays and a status code.
 *
 * This is the seam that keeps contract checking framework-neutral. An adapter turns whatever its test
 * suite produces — a Laravel `TestResponse` and the `Request` behind it, a PSR-7 pair, a HAR entry —
 * into one of these; everything downstream is the same code for all of them.
 */
final readonly class Exchange
{
    /**
     * @param  string  $path  the concrete request path, `/api/invoices/42`
     * @param  array<string, mixed>  $query  decoded query parameters, nesting preserved
     * @param  array<string, list<string>>  $headers  every value sent under each name, for the same
     *                                                reason the response half is a list: a MESSAGE may
     *                                                send one name twice, and a request is a message.
     *                                                `Accept`, `Cookie` and an `X-Forwarded-For` a
     *                                                proxy appended to rather than replaced all arrive
     *                                                that way. Keeping the first alone would let the
     *                                                second violate the documented schema unseen
     * @param  array<string, string>  $cookies
     * @param  array<string, list<string>>  $responseHeaders  the same, for the response
     * @param  bool  $ambiguousEmptyRequestBody  whether whatever serialised `$requestBody` writes an
     *                                           empty list and an empty map as the same bytes, so `[]`
     *                                           on the wire is not evidence the sender meant a list.
     *                                           An adapter states it; nothing reads it off the bytes,
     *                                           because the bytes are the thing that cannot tell the
     *                                           two apart. Only the REQUEST half asks: a response body
     *                                           is what a consumer really receives, and a client
     *                                           generated from an object schema really does break on
     *                                           `[]`.
     */
    public function __construct(
        public string $method,
        public string $path,
        public int $status,
        public array $query = [],
        public array $headers = [],
        public array $cookies = [],
        public string $requestBody = '',
        public ?string $requestContentType = null,
        public bool $ambiguousEmptyRequestBody = false,
        public string $responseBody = '',
        public ?string $responseContentType = null,
        public array $responseHeaders = [],
    ) {}

    /**
     * Every value the REQUEST sent under this name, empty when it sent none.
     *
     * @return list<string>
     */
    public function header(string $name): array
    {
        return self::valuesUnder($this->headers, $name);
    }

    /**
     * The same for the response. The two halves answer alike because they are asked alike — one reader,
     * so neither can start telling a caller less than the other.
     *
     * @return list<string>
     */
    public function responseHeader(string $name): array
    {
        return self::valuesUnder($this->responseHeaders, $name);
    }

    /**
     * Header names are case-insensitive, so the lookup is.
     *
     * @param  array<string, list<string>>  $headers
     * @return list<string>
     */
    private static function valuesUnder(array $headers, string $name): array
    {
        foreach ($headers as $header => $values) {
            if (strcasecmp($header, $name) === 0) {
                return $values;
            }
        }

        return [];
    }

    /** `GET /api/invoices/42` — how a failure message names the exchange itself. */
    public function label(): string
    {
        return strtoupper($this->method).' '.$this->path;
    }
}
