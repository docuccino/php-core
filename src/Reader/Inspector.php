<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Document\NodeIdentity;
use Docuccino\Core\SpecValidation\OpenApiMetaSchema;
use stdClass;

/**
 * Reads {@see DocumentInspection}'s facts off a decoded document's object graph — the graph rather than
 * the array, because "is the root extension an object" is a question only the graph can answer about `{}`.
 *
 * @internal
 */
final class Inspector
{
    /** The extension member a document's root carries. */
    public const string EXTENSION = 'x-docuccino';

    public static function inspect(stdClass $graph, Syntax $syntax): DocumentInspection
    {
        $openapi = is_string($graph->openapi ?? null) ? $graph->openapi : null;
        $root = $graph->{self::EXTENSION} ?? null;
        $generator = $root instanceof stdClass ? self::generator($root) : null;

        $members = [];
        $flatIds = false;
        self::walk($graph, $members, $flatIds);

        $capability = match (true) {
            $root instanceof stdClass => Capability::Full,
            $flatIds => Capability::Identities,
            default => Capability::Plain,
        };

        $extensions = array_values(array_filter(
            ExtensionMember::cases(),
            static fn (ExtensionMember $member): bool => isset($members[$member->value]),
        ));

        return new DocumentInspection(
            $syntax,
            $openapi,
            self::format($openapi),
            $capability,
            $extensions,
            $generator,
            property_exists($graph, 'uir') || property_exists($graph, '$schema') || self::major($generator?->specVersion) < 2,
            self::uirVersion(self::specVersion($graph)),
            self::info($graph->info ?? null),
        );
    }

    /**
     * The format id of the OpenAPI version $openapi declares, read by major.minor, or null where this
     * package carries no meta-schema for it. `3.1` without a patch still answers `openapi-3.1`: the
     * meta-schema is what reports the missing patch.
     */
    public static function format(?string $openapi): ?string
    {
        if ($openapi === null || preg_match('/^(\d+)\.(\d+)(?:\.|$)/', $openapi, $match) !== 1) {
            return null;
        }

        $format = sprintf('openapi-%d.%d', (int) $match[1], (int) $match[2]);

        return array_key_exists($format, OpenApiMetaSchema::SCHEMAS) ? $format : null;
    }

    /**
     * The UIR version a full document says it was written to: its generator's `specVersion`, or the root
     * `uir` member an artifact written before UIR 2.0 carries in its place.
     */
    public static function specVersion(stdClass $graph): ?string
    {
        $root = $graph->{self::EXTENSION} ?? null;
        $generator = $root instanceof stdClass ? ($root->generator ?? null) : null;
        $version = $generator instanceof stdClass ? ($generator->specVersion ?? null) : null;

        if (is_string($version)) {
            return $version;
        }

        $legacy = $graph->uir ?? null;

        return is_string($legacy) ? $legacy : null;
    }

    /** The major.minor $version names — `2.1` for `2.1.0` or `2.1` — or null where it names none. */
    private static function uirVersion(?string $version): ?string
    {
        return $version !== null && preg_match('/^(\d+\.\d+)(?:\.|$)/', $version, $match) === 1 ? $match[1] : null;
    }

    private static function generator(stdClass $root): ?DocumentGenerator
    {
        $generator = $root->generator ?? null;

        if (! $generator instanceof stdClass) {
            return null;
        }

        $string = static fn (string $member): ?string => is_string($generator->{$member} ?? null) ? $generator->{$member} : null;

        return new DocumentGenerator($string('name'), $string('version'), $string('specVersion'), $string('schema'));
    }

    private static function info(mixed $info): DocumentInfo
    {
        if (! $info instanceof stdClass) {
            return new DocumentInfo;
        }

        return new DocumentInfo(self::text($info->title ?? null), self::text($info->version ?? null));
    }

    /** A string as written, a number as the document's JSON spells it ({@see DocumentInfo}), else null. */
    private static function text(mixed $value): ?string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => rtrim((new CanonicalJsonSerializer)->serialize($value), "\n"),
            default => null,
        };
    }

    /** The major version $version names; PHP_INT_MAX where it names none, so "below 2" never fires on nothing. */
    private static function major(?string $version): int
    {
        return $version !== null && preg_match('/^(\d+)\./', $version, $match) === 1 ? (int) $match[1] : PHP_INT_MAX;
    }

    /**
     * Records every extension member any `x-docuccino` object in $node carries, and whether a flat
     * `x-docuccino-id` appears anywhere.
     *
     * @param  array<array-key, true>  $members
     */
    private static function walk(mixed $node, array &$members, bool &$flatIds): void
    {
        if (is_array($node)) {
            foreach ($node as $member) {
                self::walk($member, $members, $flatIds);
            }

            return;
        }

        if (! $node instanceof stdClass) {
            return;
        }

        foreach (get_object_vars($node) as $name => $member) {
            if ($name === NodeIdentity::FLAT_KEY) {
                $flatIds = true;
            } elseif ($name === self::EXTENSION && $member instanceof stdClass) {
                foreach (array_keys(get_object_vars($member)) as $carried) {
                    $members[$carried] = true;
                }
            }

            self::walk($member, $members, $flatIds);
        }
    }
}
