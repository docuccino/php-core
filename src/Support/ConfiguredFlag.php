<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

/**
 * The one reading of a configured on/off switch: a key holding `true` or `false` answers itself, an
 * absent key answers the caller's default, and anything else answers the default and SAYS SO.
 *
 * It refuses rather than coerces, and that is the whole point. `(bool) 'no'` is `true`, so a cast
 * reads a switch its author turned off as turned on — the worst answer available, and worse than
 * ignoring them. Only `true` and `false` are switches: `no`, `off`, `yes`, `on`, `y` and `n` are the
 * words an author reaches for and the STRINGS a YAML parser hands back for every one of them.
 *
 * A refusal is never silent. {@see refusal()} is the one sentence every reader reports it with, so a
 * switch that could not be honoured still tells its author which key to fix and what was used
 * instead — {@see HELP} is what to do about it.
 *
 * @internal
 */
final readonly class ConfiguredFlag
{
    /** The one thing to do about a refusal, whichever key raised it. */
    public const string HELP = 'Write true or false. Any other value is refused rather than coerced: '
        ."'no' and 'off' are strings, and a coerced string is true — so coercing one would turn the "
        .'switch on.';

    /**
     * @param  string|null  $refusedType  the type the key held when it held no switch, as
     *                                    `get_debug_type()` names it; null when it held one
     */
    private function __construct(
        public bool $on,
        public ?string $refusedType,
    ) {}

    /**
     * `$bag[$key]` as a switch, falling back to `$default`.
     *
     * Presence is asked with `array_key_exists()` rather than `??`, so a key holding `null` is a
     * refusal and not an absence: it is a key someone wrote — what an unset `env()` leaves behind —
     * and only a key nobody wrote is silent. The callers that separately need "did the author write
     * this at all" ask the bag, which is a different question from what the switch reads as.
     *
     * @param  array<string, mixed>  $bag
     */
    public static function read(array $bag, string $key, bool $default): self
    {
        if (! array_key_exists($key, $bag)) {
            return new self($default, null);
        }

        $value = $bag[$key];

        return is_bool($value) ? new self($value, null) : new self($default, get_debug_type($value));
    }

    /** Whether the key held something that is no switch, so {@see $on} is the caller's default. */
    public function refused(): bool
    {
        return $this->refusedType !== null;
    }

    /**
     * What to tell the author — the key, what it holds, and what was read instead — or null when the
     * switch was read as written. The dotted path is the reader's to supply: it knows where in the
     * configuration the bag came from and this does not.
     */
    public function refusal(string $key): ?string
    {
        if ($this->refusedType === null) {
            return null;
        }

        return sprintf(
            '%s is %s rather than true or false, so it names no switch — it is read as %s, its default.',
            $key,
            $this->refusedType,
            $this->on ? 'true' : 'false',
        );
    }
}
