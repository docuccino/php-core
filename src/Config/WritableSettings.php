<?php

declare(strict_types=1);

namespace Docuccino\Core\Config;

use Docuccino\Core\Emit\YamlSerializer;
use Docuccino\Core\Support\Arr;
use Docuccino\Core\Support\Json;

/**
 * What a `docuccino.yaml` can be written FROM: the settings a file can carry, and the paths whose value
 * it has none for.
 *
 * The writer and the reader are two halves of one fact and nothing made them agree. {@see YamlSerializer}
 * writes an enum case as `!php/enum`, which {@see ConfigFile::FLAGS} refuses outright — so the file lands
 * and the very next build reports it as not being YAML. It writes a closure, a resource or any other
 * object as `null`, which the reader accepts as a value nobody wrote. And it writes a date as a timestamp
 * the reader hands back as an integer. Three different wrong answers from one missing agreement.
 *
 * So nothing here lists the types a file can hold. {@see reads()} is the whole class: it asks the READER
 * what a written file gives back, and sameness is {@see Json::stable}, which is what decides value
 * identity everywhere else in the product — an integral float losing its decimal point is therefore not
 * a loss, and a date becoming a number is. A caller with one value to check asks {@see carries()}, which
 * is the same question about a one-key file; a caller holding the file it just wrote asks {@see reads()}
 * directly, and gets the guarantee rather than an assumption.
 *
 * @internal
 */
final readonly class WritableSettings
{
    /** The key a single value is probed under — a name the reader gives back unchanged. */
    private const string PROBE = 'value';

    /**
     * @param  array<string, mixed>  $settings  everything a file can carry, nested as it was handed over
     * @param  list<string>  $unwritable  the dotted path of each setting left out, in file order
     */
    private function __construct(
        public array $settings,
        public array $unwritable,
    ) {}

    /**
     * `$settings` split into what a configuration file can carry and the paths it cannot.
     *
     * Left out rather than written wrong, which is the only honest of the three: an absent key is a
     * setting nobody expressed and takes its documented default, where a `null` is an author's own empty
     * value and a `!php/enum` is a file the build refuses whole.
     *
     * A MAP is descended into and its members judged one at a time, because dropping one key of one
     * leaves the rest true. Anything else — a scalar, a list — is judged whole, since a list short one
     * element reads as the list somebody configured and is not.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function of(array $settings): self
    {
        $unwritable = [];

        return new self(Arr::stringKeyed(self::kept($settings, [], $unwritable)), $unwritable);
    }

    /**
     * Whether `$contents` is a configuration file that reads back as `$settings`.
     *
     * Every failure the pair can have, in one question: text the reader refuses, text it reads with a
     * diagnostic, and text it accepts and gives back something else.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function reads(string $contents, array $settings): bool
    {
        $file = ConfigFile::parse($contents);

        return $file->error === null
            && $file->diagnostics === []
            && Json::stable($file->values) === Json::stable($settings);
    }

    /**
     * Whether a configuration file can carry `$value` as itself — {@see reads()} of a one-key file.
     *
     * A non-finite float is refused without probing, because the round trip answers it differently
     * depending on the installed `symfony/yaml` minor: `.NaN` comes back as NAN on some and not on
     * others, so probing would make what a migration writes depend on a patch bump. Neither NAN nor
     * an infinity is a value a document can publish, so the refusal costs nothing and is the same
     * answer everywhere.
     */
    public static function carries(mixed $value): bool
    {
        if (is_float($value) && ! is_finite($value)) {
            return false;
        }

        $settings = [self::PROBE => $value];

        return self::reads((new YamlSerializer)->serialize($settings), $settings);
    }

    /**
     * `$bag` with every unwritable value gone, collecting the paths it dropped.
     *
     * @param  array<array-key, mixed>  $bag
     * @param  list<string>  $at
     * @param  list<string>  $unwritable
     * @return array<array-key, mixed>
     */
    private static function kept(array $bag, array $at, array &$unwritable): array
    {
        $kept = [];

        foreach ($bag as $key => $value) {
            $path = [...$at, (string) $key];

            if (self::isMap($value)) {
                /** @var array<array-key, mixed> $value */
                $kept[$key] = self::kept($value, $path, $unwritable);

                continue;
            }

            if (! self::carries($value)) {
                $unwritable[] = implode('.', $path);

                continue;
            }

            $kept[$key] = $value;
        }

        return $kept;
    }

    /**
     * Whether `$value` is a bag of settings of its own rather than a value one setting HAS.
     *
     * An empty array is not one: there is nothing under it to judge, and it is a value a file carries.
     */
    private static function isMap(mixed $value): bool
    {
        return is_array($value) && $value !== [] && ! array_is_list($value);
    }
}
