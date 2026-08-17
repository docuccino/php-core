<?php

declare(strict_types=1);

namespace Docuccino\Core\Provenance;

/**
 * Relativises the machine paths inside a free-text message — a third-party exception's, nearly
 * always — so a diagnostic built from it can be published. Every absolute run it finds goes through
 * {@see SourcePathResolver}, so the ladder and its degradation are the ones
 * {@see RootRelativeSourcePathResolver} already owns and there is no second notion of a publishable
 * path.
 *
 * Only ever hand it text we did not write. A route signature (`GET /api/forms`) and a JSON pointer
 * (`/paths/~1forms/get`) are absolute-looking runs too, so compose our own words AROUND a scrubbed
 * fragment rather than scrubbing the finished message.
 */
final readonly class MessagePaths
{
    /** A path body: anything but whitespace and the punctuation that delimits a path in prose. */
    private const BODY = '[^\\s\'"(),;:<>]';

    /**
     * The two shapes the resolver calls absolute, and nothing else. A POSIX run needs an interior
     * separator, so a lone `/tmp` in a sentence stays prose; a drive letter needs none, since nothing
     * else looks like one. The lookbehinds keep `https://host/v1/x` and `App\Http\Controllers\Foo`
     * out — a URL's slashes follow a `:` or another `/`, and a namespace separator follows a word
     * character.
     */
    private const RUN = '#(?<![\\w:/])/(?:'.self::BODY.'*/)+'.self::BODY.'*'
        .'|(?<![\\w:])[A-Za-z]:[\\\\/](?:'.self::BODY.'*[\\\\/])*'.self::BODY.'*#';

    public function __construct(private SourcePathResolver $paths) {}

    public function relative(string $message): string
    {
        $replaced = preg_replace_callback(
            self::RUN,
            fn (array $match): string => $this->paths->relative($match[0]),
            $message,
        );

        return $replaced ?? $message;
    }
}
