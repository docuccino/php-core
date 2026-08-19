<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

/**
 * The node one `#[Example]` illustrates, resolved against the operation: a response status, the
 * request body, or a parameter. {@see id()} is what groups several declarations onto one node, so it
 * names every part of the address and nothing else.
 *
 * @internal
 */
final readonly class ExampleTarget
{
    public const string RESPONSE = 'response';

    public const string REQUEST = 'request';

    public const string PARAMETER = 'parameter';

    /**
     * @param  string  $kind  one of the three constants above
     * @param  string  $key  the response status, the parameter name, or `''` for the request body
     * @param  string  $mediaType  `''` for a parameter, which carries no content
     * @param  string  $in  the parameter location, `''` for anything else
     */
    public function __construct(
        public string $kind,
        public string $key,
        public string $mediaType = '',
        public string $in = '',
    ) {}

    public function id(): string
    {
        return implode("\0", [$this->kind, $this->key, $this->in, $this->mediaType]);
    }

    /** How a diagnostic names this target to the person who wrote the attribute. */
    public function label(): string
    {
        return match ($this->kind) {
            self::REQUEST => sprintf('the %s request body', $this->mediaType),
            self::PARAMETER => sprintf('the %s parameter `%s`', $this->in, $this->key),
            default => sprintf('the %s response\'s %s content', $this->key, $this->mediaType),
        };
    }
}
