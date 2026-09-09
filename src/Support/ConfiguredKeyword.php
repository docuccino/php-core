<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

/**
 * The one reading of a configured CLOSED-SET keyword: a key holding one of the values the setting
 * takes answers itself, an absent key answers the documented default, and anything else answers the
 * default and SAYS SO.
 *
 * It is {@see ConfiguredFlag} for a setting whose domain is a handful of words rather than two, and it
 * exists for the same reason. `nullable: anyOf` mistyped, `versioning: smever`, a `no` that YAML hands
 * back as the string `'no'`, a `1.10` it hands back as the float 1.1 — every one of those used to be
 * read as the default with nothing said, so the author was left with a document built against a policy
 * they did not choose and no line to go and fix. Passing the value THROUGH is no better: a keyword
 * nothing recognises reaches a `match` that falls to its default arm, so the document says the same
 * thing while the policy object claims a keyword the product does not have.
 *
 * A refusal is never silent. {@see refusal()} is the one sentence every reader reports it with, and it
 * names the accepted set rather than describing it, so the author does not have to go and look the
 * values up.
 *
 * @internal
 */
final readonly class ConfiguredKeyword
{
    /**
     * @param  non-empty-list<string>  $accepted  every value the setting takes, in the order the
     *                                            shipped configuration lists them
     * @param  mixed  $refusedValue  what the key held when it held no keyword; null when it held one
     *                               (a key holding a literal null is a refusal, and `refused` says so)
     */
    private function __construct(
        public string $keyword,
        public array $accepted,
        public bool $refused,
        public mixed $refusedValue = null,
    ) {}

    /**
     * `$bag[$key]` as a keyword, falling back to `$default`.
     *
     * Presence is asked with `array_key_exists()` rather than `??`, exactly as {@see ConfiguredFlag}
     * asks it and for the same reason: a key holding `null` is a key somebody wrote — what a key with
     * nothing after the colon holds — and only a key nobody wrote is silent.
     *
     * @param  array<string, mixed>  $bag
     * @param  non-empty-list<string>  $accepted
     */
    public static function read(array $bag, string $key, string $default, array $accepted): self
    {
        if (! array_key_exists($key, $bag)) {
            return new self($default, $accepted, false);
        }

        $value = $bag[$key];

        return is_string($value) && in_array($value, $accepted, true)
            ? new self($value, $accepted, false)
            : new self($default, $accepted, true, $value);
    }

    /**
     * What to tell the author — the key, what it holds, the values it takes and what was read instead
     * — or null when the keyword was read as written. The dotted path is the reader's to supply: it
     * knows where in the configuration the bag came from and this does not.
     */
    public function refusal(string $key): ?string
    {
        if (! $this->refused) {
            return null;
        }

        return sprintf(
            '%s is %s, which is none of the values it takes — it is read as %s, its default.',
            $key,
            ConfiguredValue::described($this->refusedValue),
            ConfiguredValue::rendered($this->keyword),
        );
    }

    /**
     * The one thing to do about this refusal: write one of the values the setting takes. The set is
     * spelled out rather than pointed at, because a reader who mistyped a keyword is exactly the reader
     * who does not know what the alternatives were.
     */
    public function help(): string
    {
        return sprintf(
            'Write one of: %s.',
            implode(', ', array_map(static fn (string $keyword): string => ConfiguredValue::rendered($keyword), $this->accepted)),
        );
    }
}
