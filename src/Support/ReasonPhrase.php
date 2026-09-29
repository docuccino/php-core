<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

/**
 * The reason phrase a response's status carries, which is what a documented response is described as
 * when nobody wrote a description. Only ever the phrase of the status it is asked about: a code the IANA
 * registry does not name gets the name of its class (RFC 9110 §15), never another code's phrase. 422 keeps
 * its RFC 4918 name, "Unprocessable Entity", which every error response is already published under.
 *
 * Public, not `@internal` — built-in integrations describe and name error responses by it directly, so
 * no second, partial table of phrases can drift from this one.
 */
final class ReasonPhrase
{
    /**
     * The IANA HTTP status code registry, 418 and 306 ("Unused") left out, 422 under its RFC 4918 name.
     *
     * @var array<int, string>
     */
    private const PHRASES = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        103 => 'Early Hints',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        208 => 'Already Reported',
        226 => 'IM Used',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Content Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        421 => 'Misdirected Request',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Too Early',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
    ];

    /** What a key naming no status is called: all that is known is that it is a response. */
    private const UNNAMED = 'Response';

    /** Each status class by its leading digit, for a code the registry leaves unnamed. */
    private const CLASSES = [
        1 => 'Informational',
        2 => 'Successful',
        3 => 'Redirection',
        4 => 'Client Error',
        5 => 'Server Error',
    ];

    /**
     * What a response keyed at `$status` is called: a code's registered phrase, an unregistered code's
     * class, an OAS range key's class (`4XX`), and for any other key only that it is a response — never
     * another code's phrase, which is how a 503 came to be described as "OK".
     */
    public static function of(int|string $status): string
    {
        $key = (string) $status;

        if (preg_match('/^[1-5]\d\d$/D', $key) === 1) {
            return self::PHRASES[(int) $key] ?? self::CLASSES[intdiv((int) $key, 100)];
        }

        if (preg_match('/^([1-5])XX$/D', $key, $range) === 1) {
            return self::CLASSES[(int) $range[1]];
        }

        return self::UNNAMED;
    }

    /**
     * The registry's own phrase for a code, or null for a key it does not register — so a caller naming
     * something after a phrase never names two unregistered codes after the one class they share.
     */
    public static function registeredPhrase(int|string $status): ?string
    {
        $key = (string) $status;

        return preg_match('/^[1-5]\d\d$/D', $key) === 1 ? (self::PHRASES[(int) $key] ?? null) : null;
    }

    /**
     * Every registered code with its phrase, for the dataset test over every entry.
     *
     * @return array<int, string>
     */
    public static function registered(): array
    {
        return self::PHRASES;
    }
}
