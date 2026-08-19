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
     * @param  array<string, string>  $headers  request headers; lookup is case-insensitive
     * @param  array<string, string>  $cookies
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
        public string $responseBody = '',
        public ?string $responseContentType = null,
    ) {}

    public function header(string $name): ?string
    {
        foreach ($this->headers as $header => $value) {
            if (strcasecmp($header, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /** `GET /api/invoices/42` — how a failure message names the exchange itself. */
    public function label(): string
    {
        return strtoupper($this->method).' '.$this->path;
    }
}
