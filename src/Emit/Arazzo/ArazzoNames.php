<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit\Arazzo;

/**
 * The character sets Arazzo allows in the names a description publishes.
 *
 * Here rather than left to the schema, because **the published schema cannot see these**. Arazzo types
 * `workflowId` and `stepId` as plain strings — the character set is stated in the specification's prose
 * and nowhere in the JSON Schema — and it constrains an `outputs` key with `patternProperties` and no
 * `additionalProperties: false`, which means a key that matches nothing is SILENTLY ACCEPTED. So the
 * vendored oracle passes a name a real workflow runner rejects, and the emitter is the only gate left.
 *
 * @internal
 */
final class ArazzoNames
{
    /** A `workflowId` or a `stepId`. */
    private const string ID = '/^[A-Za-z0-9_-]+$/';

    /** An `outputs` key, which Arazzo allows a dot in where an id is not. */
    private const string OUTPUT = '/^[A-Za-z0-9._-]+$/';

    public static function isId(string $name): bool
    {
        return preg_match(self::ID, $name) === 1;
    }

    public static function isOutput(string $name): bool
    {
        return preg_match(self::OUTPUT, $name) === 1;
    }

    /**
     * The outputs whose names Arazzo can carry, and the names it cannot.
     *
     * @param  array<string, string>  $outputs
     * @return array{0: array<string, string>, 1: list<string>}
     */
    public static function usableOutputs(array $outputs): array
    {
        $kept = [];
        $refused = [];

        foreach ($outputs as $name => $expression) {
            if (self::isOutput($name)) {
                $kept[$name] = $expression;
            } else {
                $refused[] = $name;
            }
        }

        return [$kept, $refused];
    }
}
