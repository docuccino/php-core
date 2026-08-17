<?php

declare(strict_types=1);

namespace Docuccino\Core\Provenance;

/**
 * Relativises the machine paths inside a fragment of diagnostic text — a third-party exception's
 * message, or a locator naming the file something anonymous was written in — so a diagnostic built
 * from it can be published. Diagnostics are embedded in the document, so a diagnostic that names the
 * build machine breaks byte-identical output where it is hardest to notice; every absolute run this
 * finds goes through {@see SourcePathResolver}, so the ladder and its degradation are the ones
 * {@see RootRelativeSourcePathResolver} already owns and there is no second notion of a publishable
 * path.
 *
 * Hand it only a fragment whose absolute-looking runs are all really machine paths, and compose our
 * own words AROUND the result rather than scrubbing a finished message. That rule cannot be moved to
 * the crossing where a diagnostic is reported, because syntax does not say where a path came from: a
 * route signature (`GET /api/forms`), a JSON pointer (`#/components/schemas/User/properties/password`)
 * and a path an attribute or a config key states in the source are absolute-looking runs too, and each
 * is identical on every machine and worth exactly the characters it is written with. Only the code
 * composing the message knows which half reflection supplied.
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
