<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Support\Arr;

/**
 * How every producer answers a Server Variable Object that declares no usable `default` — one fact, one
 * code, since `default` is REQUIRED by 3.0, 3.1 and 3.2 alike and a collection must publish something.
 *
 * The invariant: what the document says here is bounded by what it already says, so a declared `enum`
 * supplies the default and nothing invents one otherwise. The full policy, and where the targets part,
 * is the `server.variable-no-default` row of the diagnostics reference.
 *
 * @internal
 */
final class ServerVariables
{
    /** What an OpenAPI document does when the variable names no legal value at all. */
    private const string OMITTED = 'it declares no `enum` to take one from either, so it is left out of the emitted document rather than resolving the server URL to a value nobody serves.';

    /**
     * Whether the variable declares a `default` of its own that a URL can be built from. An empty string
     * is not one: substituting it resolves the template to a URL nobody serves, which leaves the reader
     * exactly where declaring nothing does.
     *
     * @param  array<string, mixed>  $variable
     */
    public static function declaresDefault(array $variable): bool
    {
        $default = $variable['default'] ?? null;

        return is_string($default) && $default !== '';
    }

    /**
     * The first value of the variable's declared `enum` — the API's own closed set, so a member of it is
     * a value the server serves. Null when there is no enum, or nothing usable in it.
     *
     * @param  array<string, mixed>  $variable
     */
    public static function fromEnum(array $variable): ?string
    {
        $enum = is_array($variable['enum'] ?? null) ? array_values($variable['enum']) : [];
        $first = $enum[0] ?? null;

        return is_string($first) && $first !== '' ? $first : null;
    }

    /**
     * A value the URL template can be resolved with: the declared `default`, else the first of the
     * declared `enum`, else null when the document names neither.
     *
     * @param  array<string, mixed>  $variable
     */
    public static function resolve(array $variable): ?string
    {
        /** @var string|null */
        return self::declaresDefault($variable) ? $variable['default'] : self::fromEnum($variable);
    }

    /**
     * The one diagnostic for the one fact. Where the variable's own `enum` answers it, so does the
     * message; `$unresolved` completes the sentence only for the case no document can answer, where each
     * target degrades its own way.
     *
     * @param  array<string, mixed>  $variable
     */
    public static function noDefault(string $name, array $variable, string $unresolved): Diagnostic
    {
        $fromEnum = self::fromEnum($variable);

        return new Diagnostic(
            severity: Severity::Warning,
            code: 'server.variable-no-default',
            message: sprintf(
                'Server variable `%s` declares no default, which every OpenAPI version requires of one: %s',
                $name,
                $fromEnum === null
                    ? $unresolved
                    : sprintf('the first value of the `enum` it declares (`%s`) stands in as one.', $fromEnum),
            ),
            help: 'Give the variable a `default` in the server definition — one of its `enum` values, where it declares an enum.',
        );
    }

    /**
     * A document's `servers` with every variable answered for: left alone where it already declares a
     * `default`, resolved from its own `enum` where it declares one, and dropped where it names no legal
     * value. What the OpenAPI emitters apply, since none of the three versions can publish a Server
     * Variable Object without a `default`.
     *
     * @param  array<string, mixed>  $array
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    public static function complete(array $array, array &$diagnostics): array
    {
        if (! is_array($array['servers'] ?? null)) {
            return $array;
        }

        $servers = [];
        foreach (array_values($array['servers']) as $server) {
            $servers[] = is_array($server)
                ? self::completeServer(Arr::stringKeyed($server), $diagnostics)
                : $server;
        }

        $array['servers'] = $servers;

        return $array;
    }

    /**
     * @param  array<string, mixed>  $server
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>
     */
    private static function completeServer(array $server, array &$diagnostics): array
    {
        if (! is_array($server['variables'] ?? null)) {
            return $server;
        }

        $declared = Arr::stringKeyed($server['variables']);

        // Sorted, so which variable a note names first is a function of the names rather than of the
        // order a config file happened to list them in.
        $names = array_map(strval(...), array_keys($declared));
        sort($names, SORT_STRING);

        $variables = [];
        foreach ($names as $name) {
            $variable = $declared[$name];

            if (! is_array($variable)) {
                $variables[$name] = $variable;

                continue;
            }

            $variable = Arr::stringKeyed($variable);

            if (self::declaresDefault($variable)) {
                $variables[$name] = $variable;

                continue;
            }

            $diagnostics[] = self::noDefault($name, $variable, self::OMITTED);

            $fromEnum = self::fromEnum($variable);
            if ($fromEnum === null) {
                continue;
            }

            $variable['default'] = $fromEnum;
            $variables[$name] = $variable;
        }

        if ($variables === []) {
            unset($server['variables']);

            return $server;
        }

        $server['variables'] = $variables;

        return $server;
    }
}
