<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

/**
 * The number a set of JSON Schema bounds admits nearest a caller's starting point, shared by everything
 * that has to produce a value those bounds accept: the validated field's synthesized example, the
 * collection exporter's request body, and the fill an error example puts where a member went unread. One
 * ladder, so no two of them can read a `minimum: 5` differently — the SEED is each caller's own, so where
 * the bounds pin no single value the answers may still differ by it.
 *
 * A bound is unlike a `pattern`: it CONSTRAINS a value and it also names one. `minimum: 5` is satisfied
 * by 5, so there is always a legal value to reach for, where no constant satisfies an arbitrary regex.
 * That is the whole reason these five keywords are read and that one is not.
 *
 * Every answer is a constant or arithmetic on the keywords themselves — no clock, no locale, nothing from
 * another field — so the same bounds always produce the same bytes.
 *
 * `null` means the keywords name no number this ladder may publish. Three things say that, and none of
 * them is "the value is null": a range nothing inhabits (bounds that cross, exclusive bounds that meet, a
 * step with no multiple between them); a value only an unrepresentable double could be (a step whose
 * multiples are never exact, an integer past `PHP_INT_MAX`); and a bound PHP could not read as a finite
 * number at all. Each caller decides what to do with that, because only the caller knows whether it has
 * somewhere to put the fact.
 *
 * @phpstan-type Interval array{low: int|float|null, lowOpen: bool, high: int|float|null, highOpen: bool}
 */
final class BoundedNumber
{
    /** The five keywords that bound a number. */
    private const array BOUNDS = ['minimum', 'maximum', 'multipleOf', 'exclusiveMinimum', 'exclusiveMaximum'];

    /**
     * Whether the keywords bound the number at all. Asked separately from {@see nearest()} because a
     * caller may owe an example only where something was pinned — `type: integer` already tells a reader
     * everything the seed would. A bound this ladder cannot reach a value from is still a bound STATED,
     * so the two answer independently: stated, and no value to show for it.
     *
     * @param  array<array-key, mixed>  $keywords
     */
    public static function stated(array $keywords): bool
    {
        foreach (self::BOUNDS as $bound) {
            if (self::bound($keywords, $bound) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The value the bounds admit nearest `$seed`: raised to the floor, dropped to the ceiling, stepped off
     * an exclusive bound it landed on, then moved onto a multiple of a `multipleOf`. Wrapped, so `null` is
     * unmistakably "no number to publish".
     *
     * An exclusive bound is where the two types part. On an `integer` it converts to an inclusive one
     * exactly — the nearest integer it admits — so neither end of the range is ever open. On a `number`
     * the nearest admitted value is the bound plus an epsilon no deterministic table can name, so the step
     * off it is one WHOLE unit, and half the room to the opposite bound where a whole unit would leave the
     * range: `exclusiveMinimum: 0` is 1 on its own and 0.5 beside `exclusiveMaximum: 1`. Both are
     * arithmetic on the keywords, so both are the same bytes on every machine.
     *
     * A `multipleOf` is applied LAST — a step is the one keyword that can move a value back out of the
     * range the bounds put it in — and it is stepped AWAY from whichever bound the value was pushed
     * against, since that is the direction that stays inside. The other direction is tried whenever the
     * first leaves the range, because a range narrower than the step admits at most one of the two.
     *
     * @param  array<array-key, mixed>  $keywords
     * @return array{int|float}|null null where the keywords name no number this ladder may publish
     */
    public static function nearest(array $keywords, int|float $seed, bool $integer): ?array
    {
        $step = self::bound($keywords, 'multipleOf');
        $range = self::range($keywords, $integer);

        if ($range === null || ($step !== null && ! is_finite($step))) {
            return null;
        }

        $value = $seed;
        $pushed = 0;

        if ($range['low'] !== null && $value < $range['low']) {
            $value = $range['low'];
            $pushed = 1;
        }
        if ($range['high'] !== null && $value > $range['high']) {
            $value = $range['high'];
            $pushed = -1;
        }

        if ($range['lowOpen'] && $range['low'] !== null && $value <= $range['low']) {
            $value = self::stepOff($range['low'], $range['high'], $range['highOpen'], 1);
            $pushed = 1;
        }
        if ($range['highOpen'] && $range['high'] !== null && $value >= $range['high']) {
            $value = self::stepOff($range['high'], $range['low'], $range['lowOpen'], -1);
            $pushed = -1;
        }

        foreach (self::candidates($value, $step, $pushed) as $candidate) {
            if (self::admits($candidate, $range, $step, $integer)) {
                return [self::shortest($candidate)];
            }
        }

        return null;
    }

    /**
     * The value stepped onto a multiple of the step, in the direction that stays inside the range first —
     * see {@see nearest()} for why there are two and why the order is the pressure the bounds applied. A
     * step no `multipleOf` may take (zero, negative) names no step, so the value stands alone.
     *
     * @return list<int|float>
     */
    private static function candidates(int|float $value, int|float|null $step, int $pushed): array
    {
        if ($step === null || $step <= 0) {
            return [$value];
        }

        $up = ceil($value / $step) * $step;
        $down = floor($value / $step) * $step;

        return $pushed < 0 ? [$down, $up] : [$up, $down];
    }

    /**
     * The value just past an exclusive bound: one whole unit, or half the room to the opposite bound where
     * a whole unit would leave the range. `$away` is 1 stepping up off a floor and -1 stepping down off a
     * ceiling.
     *
     * Half the room is `$bound + ($opposite - $bound) / 2` rather than the mean of the two, which
     * overflows to INF for a pair of finite bounds near the top of the double range.
     */
    private static function stepOff(int|float $bound, int|float|null $opposite, bool $oppositeOpen, int $away): int|float
    {
        $stepped = $bound + $away;

        if ($opposite === null) {
            return $stepped;
        }

        $overshot = $away > 0
            ? ($stepped > $opposite || ($oppositeOpen && $stepped >= $opposite))
            : ($stepped < $opposite || ($oppositeOpen && $stepped <= $opposite));

        return $overshot ? $bound + ($opposite - $bound) / 2 : $stepped;
    }

    /**
     * Whether the keywords really admit this value, and whether this ladder may publish it: inside both
     * bounds with an open one excluding its own value, an exact multiple of any step, finite, and — on an
     * `integer` — a whole number PHP can hold as one.
     *
     * Nothing here is trusted from the walk above. A step is re-divided because a fractional one rarely
     * has an exactly representable multiple (0.1 three times over is 0.30000000000000004 in every IEEE
     * double) and `multipleOf` is checked by exact division; the answer is re-checked for finiteness
     * because two finite bounds can overflow to INF between them, and INF is not a number JSON can carry;
     * and the integer arm is checked for representability because a bound past `PHP_INT_MAX` names an
     * integer no `int` can hold, which is a value not to publish rather than one to truncate.
     *
     * @param  Interval  $range
     */
    private static function admits(int|float $value, array $range, int|float|null $step, bool $integer): bool
    {
        if (! is_finite($value)) {
            return false;
        }

        if ($range['low'] !== null && ($value < $range['low'] || ($range['lowOpen'] && $value <= $range['low']))) {
            return false;
        }

        if ($range['high'] !== null && ($value > $range['high'] || ($range['highOpen'] && $value >= $range['high']))) {
            return false;
        }

        if ($step !== null && $step > 0) {
            $quotient = (float) ($value / $step);
            if ($quotient !== floor($quotient)) {
                return false;
            }
        }

        return ! $integer || is_int($value) || ($value === floor($value) && abs($value) < (float) PHP_INT_MAX);
    }

    /**
     * A whole float publishes as an integer literal, which validates against `number` just the same and
     * keeps the bytes the shortest true form — so they do not turn on which keyword the value came out of.
     */
    private static function shortest(int|float $value): int|float
    {
        return is_float($value) && $value === floor($value) && abs($value) < (float) PHP_INT_MAX
            ? (int) $value
            : $value;
    }

    /**
     * The interval the four bounds describe, or null where one of them is no finite number. `1e400` is an
     * ordinary number in JSON's grammar and `json_decode` saturates it to INF, so a bound PHP holds as
     * INF or NAN leaves what the author wrote unknown in both directions — nothing is published rather
     * than a value that may be refused.
     *
     * Both spellings of one end may be stated at once, and the conjunction is the tighter of the two;
     * where they are EQUAL the exclusive one wins, since `>= 5` and `> 5` together admit only `> 5`.
     *
     * @param  array<array-key, mixed>  $keywords
     * @return Interval|null
     */
    private static function range(array $keywords, bool $integer): ?array
    {
        $low = self::bound($keywords, 'minimum');
        $high = self::bound($keywords, 'maximum');
        $exclusiveLow = self::bound($keywords, 'exclusiveMinimum');
        $exclusiveHigh = self::bound($keywords, 'exclusiveMaximum');

        foreach ([$low, $high, $exclusiveLow, $exclusiveHigh] as $bound) {
            if ($bound !== null && ! is_finite($bound)) {
                return null;
            }
        }

        if ($integer) {
            // An exclusive bound converts to an inclusive one exactly, and an inclusive one rounds INWARD
            // so a fractional bound names the integer it really admits.
            if ($exclusiveLow !== null) {
                $admitted = floor($exclusiveLow) + 1;
                $low = $low === null ? $admitted : max($low, $admitted);
            }
            if ($exclusiveHigh !== null) {
                $admitted = ceil($exclusiveHigh) - 1;
                $high = $high === null ? $admitted : min($high, $admitted);
            }

            return [
                'low' => $low === null ? null : ceil($low),
                'lowOpen' => false,
                'high' => $high === null ? null : floor($high),
                'highOpen' => false,
            ];
        }

        $lowOpen = $exclusiveLow !== null && ($low === null || $exclusiveLow >= $low);
        $highOpen = $exclusiveHigh !== null && ($high === null || $exclusiveHigh <= $high);

        return [
            'low' => $lowOpen ? $exclusiveLow : $low,
            'lowOpen' => $lowOpen,
            'high' => $highOpen ? $exclusiveHigh : $high,
            'highOpen' => $highOpen,
        ];
    }

    /**
     * One bound's value, or null where the keywords state none. A draft-04 document spells
     * `exclusiveMinimum` as a BOOLEAN modifying `minimum`, which names no value and is read as unstated —
     * this ladder answers 2020-12, the dialect the UIR is written in.
     *
     * @param  array<array-key, mixed>  $keywords
     */
    private static function bound(array $keywords, string $name): int|float|null
    {
        $value = $keywords[$name] ?? null;

        return is_int($value) || is_float($value) ? $value : null;
    }
}
