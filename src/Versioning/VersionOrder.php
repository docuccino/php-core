<?php

declare(strict_types=1);

namespace Docuccino\Core\Versioning;

/**
 * How one API's versions are ordered. The product reads a version string in exactly two grammars —
 * `YYYY-MM-DD` dates and semver — and this is the one place either is parsed, so the diff policies that
 * gate a bump and the API-version machinery that walks a change list cannot disagree about which of two
 * versions is older.
 *
 * `strcmp` is the reading this replaces, and it is wrong for both grammars the moment a number grows a
 * digit: `1.10.0` sorts BEFORE `1.9.0` byte by byte. Dates survive it by luck of being fixed-width, and
 * a rule that is right by luck is the one that breaks when the second grammar arrives.
 *
 * @internal
 */
final readonly class VersionOrder
{
    private function __construct(private string $name) {}

    /**
     * The order a document's `versioning` keyword names, or null when the keyword names none —
     * `none`, and anything unrecognised, order nothing rather than guessing an order.
     */
    public static function for(string $keyword): ?self
    {
        return match ($keyword) {
            'date' => self::date(),
            'semver' => self::semver(),
            default => null,
        };
    }

    /** `YYYY-MM-DD` versions, Stripe-style. */
    public static function date(): self
    {
        return new self('date');
    }

    /** `MAJOR.MINOR.PATCH` versions, compared as three integers rather than as text. */
    public static function semver(): self
    {
        return new self('semver');
    }

    /**
     * The order the versions themselves are written in, for a document whose keyword names none. Dates
     * first, then semver, and null when they are neither or a mixture — a derived default rather than a
     * knob, so an application that spells its versions plainly never has to say so twice. A keyword the
     * document DOES state always wins; this is only consulted when it states nothing.
     *
     * @param  list<string>  $versions
     */
    public static function detect(array $versions): ?self
    {
        if ($versions === []) {
            return null;
        }

        foreach ([self::date(), self::semver()] as $order) {
            foreach ($versions as $version) {
                if (! $order->reads($version)) {
                    continue 2;
                }
            }

            return $order;
        }

        return null;
    }

    /**
     * The versions in order, oldest first. The one sorting of a version set in this product: a published
     * enum listing `1.10.0` before `1.9.0` is the exact reading this class exists to replace, said in the
     * document a consumer reads rather than in the change list.
     *
     * `$order` is the order the document resolved, where it has one; otherwise the versions themselves
     * say which grammar they are written in. Byte order is the fallback and only the fallback — for a
     * set no grammar reads whole, where it is at least deterministic. Ties break bytewise so the answer
     * never depends on the order the set was handed over.
     *
     * @param  list<string>  $versions
     * @return list<string>
     */
    public static function sorted(array $versions, ?self $order = null): array
    {
        $sorted = $versions;
        $order ??= self::detect($versions);

        foreach ($versions as $version) {
            if ($order !== null && ! $order->reads($version)) {
                $order = null;

                break;
            }
        }

        if ($order === null) {
            sort($sorted, SORT_STRING);

            return $sorted;
        }

        $reading = $order;
        usort($sorted, static fn (string $a, string $b): int => ($reading->compare($a, $b) ?? 0) ?: strcmp($a, $b));

        return $sorted;
    }

    /** `date` or `semver`, as the `versioning` keyword spells it. */
    public function name(): string
    {
        return $this->name;
    }

    /** Whether this order can read the version at all; an unreadable one is never compared. */
    public function reads(string $version): bool
    {
        return $this->key($version) !== null;
    }

    /**
     * Negative, zero or positive as `$a` is older than, the same as, or newer than `$b`. Null when
     * either side is unreadable — a caller must say what it does about that rather than take a silent 0.
     */
    public function compare(string $a, string $b): ?int
    {
        $left = $this->key($a);
        $right = $this->key($b);

        return $left === null || $right === null ? null : $left <=> $right;
    }

    /**
     * The comparable `YYYY-MM-DD` prefix, or null when the string does not begin with one. A trailing
     * suffix like `-preview` is ignored, so `2026-08-01` and `2026-08-01-rc1` compare equal.
     */
    private static function dateKey(string $version): ?string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($version), $m) === 1
            ? $m[1].'-'.$m[2].'-'.$m[3]
            : null;
    }

    /**
     * Major, minor and patch as integers, or null when the string is not semver. A pre-release or build
     * suffix is ignored: it never changes which release the version belongs to.
     *
     * Public where {@see dateKey()} is not, because `SemverPolicy` reads the three numbers themselves to
     * say which of them a change is allowed to move; nothing outside needs a date's key.
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    public static function semverParts(string $version): ?array
    {
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)(?:[-+].*)?$/', trim($version), $m) !== 1) {
            return null;
        }

        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }

    /**
     * The value `<=>` orders. A date is compared as its fixed-width string and semver as its three
     * integers, which is the whole of the difference between the two grammars.
     *
     * @return string|array{0: int, 1: int, 2: int}|null
     */
    private function key(string $version): string|array|null
    {
        return $this->name === 'date' ? self::dateKey($version) : self::semverParts($version);
    }
}
