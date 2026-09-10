<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

use Docuccino\Core\Lint\OperationIdStyle;

/**
 * The `operationId` an operation carries when nothing else named it — the operation's own HTTP method
 * and path template, spelled so that they can be read back off the name. A generated client turns an
 * `operationId` into a method name, so the alternative to minting one is not "no name": it is the
 * name whatever generator the consumer runs invents, which differs per generator and per run.
 *
 * The name is one-to-one over what a document can hold, and it is the SPELLING that makes it so, not
 * the choice of inputs. A reduction that folded `/`, `-` and `_` together and dropped the bytes it
 * could not carry would hand `/api/user-profile`, `/api/user_profile` and `/api/user/profile` one
 * name between them, and a document cannot publish two operations under one method name. So `.`
 * separates the parts, `@` opens a parameter, and everything else is a letter, a digit, a `-`, or is
 * written behind a `_`: `__` for a `_`, and `_` plus two upper-case hex digits for any other byte —
 * non-ASCII included, which is spelled out rather than deleted because deleting it leaves `/api/形式`
 * and `/api` sharing a name. So a `.` or an `@` in the name is always the structure and never the
 * path, an escape never contains either, and the parts can be found again by splitting — which is
 * what proves there is one path behind each name. Nothing is rung against another route, so there is
 * no contest between routes and no order to depend on.
 *
 * The domain is the path TEMPLATE, which is what a document keys an operation by. `{form?}` and
 * `{form:slug}` are spellings of the template `{form}` — the marker and the binding column are route
 * syntax rather than parts of the path — so they mint the same name, and a document holding both
 * holds one path, not two. A segment mixing a parameter with literal text (`{name}.{ext}`) has no
 * short spelling that could be read back, so its braces are escaped like any other byte.
 *
 * @internal
 */
final class RouteOperationId
{
    /** What separates the method from each path segment. Reserved, so a literal one is escaped. */
    private const SEPARATOR = '.';

    /** What opens a parameter. Reserved, so a raw one can only ever mean this. */
    private const PARAMETER = '@';

    /**
     * The bytes that stand for themselves — everything else is escaped, the two reserved characters
     * included. Spelled out rather than asked of ctype, which reads a locale.
     */
    private const CARRIED = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-';

    /** `get` + `/api/forms/{form}` → `get.api.forms.@form`. */
    public static function mint(string $method, string $uri): string
    {
        $parts = [self::verb($method)];

        // Exactly one leading slash comes off: every path a document publishes carries one, and the
        // root is no segments rather than one empty one.
        $path = str_starts_with($uri, '/') ? substr($uri, 1) : $uri;

        foreach ($path === '' ? [] : explode('/', $path) as $segment) {
            $parts[] = self::isParameter($segment)
                ? self::PARAMETER.self::escape(self::parameterName($segment))
                : self::escape($segment);
        }

        return implode(self::SEPARATOR, $parts);
    }

    /**
     * The leading part. `_` stands in for no method at all — the one part no method can escape to,
     * since `_` is only ever produced in front of something — and a digit at the head is escaped, so
     * the name never opens with one and stays inside {@see OperationIdStyle}'s alphabet by
     * construction rather than by inspection.
     */
    private static function verb(string $method): string
    {
        $verb = self::escape(strtolower($method));

        if ($verb === '') {
            return '_';
        }

        return str_contains('0123456789', $verb[0]) ? self::hex($verb[0]).substr($verb, 1) : $verb;
    }

    /** A segment that is one parameter and nothing else. A brace inside is text, not nesting. */
    private static function isParameter(string $segment): bool
    {
        return preg_match('/^\{[^{}]*}$/', $segment) === 1;
    }

    /**
     * The name inside `{…}`, without the trailing `?` an optional parameter carries or the `:field` a
     * bound one names its column with — both are how the route was written, not what the path is.
     */
    private static function parameterName(string $segment): string
    {
        $name = substr($segment, 1, -1);
        $colon = strpos($name, ':');

        return rtrim($colon === false ? $name : substr($name, 0, $colon), '?');
    }

    /**
     * Every byte outside {@see self::CARRIED} written behind a `_`. One-to-one: a raw `_` is never
     * produced, and what follows one says which of the two forms it is — a second `_` is a `_`, and
     * two hex digits are a byte. Neither form spends a `.` or an `@`, so those stay structure.
     */
    private static function escape(string $text): string
    {
        $out = '';

        for ($i = 0, $length = strlen($text); $i < $length; $i++) {
            $byte = $text[$i];

            $out .= match (true) {
                $byte === '_' => '__',
                str_contains(self::CARRIED, $byte) => $byte,
                default => self::hex($byte),
            };
        }

        return $out;
    }

    /** One byte as `_` and two upper-case hex digits — the form that is two characters wide. */
    private static function hex(string $byte): string
    {
        return sprintf('_%02X', ord($byte));
    }
}
