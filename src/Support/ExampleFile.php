<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use JsonException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * One read of the file `#[Example(file: …)]` names: the confined absolute path, the decoded payload,
 * and why it isn't one when it isn't. The path is confined to the app base ({@see ConfinedPath}) and
 * the extension picks the parser, so a file this can't make sense of comes back as a failure rather
 * than as a half-decoded example.
 *
 * A failed read still carries {@see $path} whenever one was resolved, because the caller registers it
 * as a cache dependency either way — a file that isn't there yet must still rebuild the route when it
 * appears.
 *
 * A file that parses is not yet a file that publishes: YAML spells values JSON has no form for, so the
 * decoded value is held to what the canonical writer will take before it is handed back.
 *
 * @internal
 */
final readonly class ExampleFile
{
    /** The extensions this decodes. Anything else reads as {@see INVALID}. */
    public const array FORMATS = ['json', 'yaml', 'yml'];

    /** The path escaped the app base and nothing was read. */
    public const string ESCAPED = 'escaped';

    /** Confined, but absent or unreadable. */
    public const string MISSING = 'missing';

    /** Read, but not a format this decodes — or not valid in the format it claims. */
    public const string INVALID = 'invalid';

    private function __construct(
        public ?string $path,
        public mixed $value,
        public ?string $error,
        public string $detail = '',
    ) {}

    public static function read(string $basePath, string $relative): self
    {
        $resolved = ConfinedPath::resolve($basePath, $relative);
        if ($resolved === null) {
            return new self(null, null, self::ESCAPED);
        }

        $contents = @file_get_contents($resolved);
        if ($contents === false) {
            return new self($resolved, null, self::MISSING);
        }

        $extension = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
        if (! in_array($extension, self::FORMATS, true)) {
            return new self($resolved, null, self::INVALID, $extension === ''
                ? 'the file has no extension, so there is nothing to say how to read it'
                : sprintf('".%s" is not a format examples are read from', $extension));
        }

        try {
            $value = $extension === 'json'
                ? json_decode($contents, true, 512, JSON_THROW_ON_ERROR)
                : Yaml::parse($contents);
        } catch (JsonException|ParseException $exception) {
            return new self($resolved, null, self::INVALID, PlainText::of($exception->getMessage()));
        }

        // Parsing is not the same as being publishable. YAML has spellings JSON does not — `.nan` and
        // `.inf` decode to floats with no JSON form — and the first thing that noticed used to be the
        // canonical writer, which throws naming neither the file nor the attribute that asked for it.
        $rejected = (new CanonicalJsonSerializer)->rejects($value);
        if ($rejected !== null) {
            return new self($resolved, null, self::INVALID, lcfirst($rejected));
        }

        return new self($resolved, $value, null);
    }

    public function ok(): bool
    {
        return $this->error === null;
    }
}
