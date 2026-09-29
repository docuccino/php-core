<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\DocumentMembers;
use Docuccino\Core\Support\NameList;
use Docuccino\Core\Support\PlainText;

/**
 * Turns each `anyOf` of component `$ref`s whose bodies all require one property pinned to a value no
 * other member shares into a `oneOf` with a `discriminator`, reading the FINISHED document — so the answer
 * is a function of the component bodies alone, never of which was still being built when a union met it.
 * Rule and spelling: `docs/design/uir-and-extensions.md` §Discriminated unions.
 *
 * @internal
 */
final class DiscriminatedUnion
{
    private const string PREFIX = '#/components/schemas/';

    /**
     * The empty object, as a member written beside tagged ones: every tagged member requires its tag, so
     * none admits it, and it stays outside the `oneOf` as `null` does.
     */
    public const array EMPTY_OBJECT = ['type' => 'object', 'maxProperties' => 0];

    /**
     * The document with every provable union discriminated, plus one info per union written to be tagged
     * that falls short, in member-name order.
     *
     * @param  array<string, mixed>  $doc
     * @return array{array<string, mixed>, list<Diagnostic>}
     */
    public static function settle(array $doc): array
    {
        $components = $doc['components'] ?? null;
        $schemas = is_array($components) && is_array($components['schemas'] ?? null) ? $components['schemas'] : [];

        /** @var array<string, Diagnostic> $shortfalls */
        $shortfalls = [];
        /** @var array<string, mixed> $settled */
        $settled = self::walk($doc, $schemas, $shortfalls);

        ksort($shortfalls, SORT_STRING);

        return [$settled, array_values($shortfalls)];
    }

    /**
     * Every node but data, read by {@see DocumentMembers} — so a union under `properties.default` or
     * `responses.default` is read like one under any other name, and one written inside an example is not.
     *
     * @param  array<mixed>  $node
     * @param  array<mixed>  $schemas  the component bodies as registered, which every decision reads
     * @param  array<string, Diagnostic>  $shortfalls
     * @param  ?string  $inNameMap  the name map $node is, or null where its keys are keywords
     * @return array<mixed>
     */
    private static function walk(array $node, array $schemas, array &$shortfalls, ?string $inNameMap = null): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value) && ! DocumentMembers::holdsData((string) $key, $value, $inNameMap)) {
                $node[$key] = self::walk($value, $schemas, $shortfalls, DocumentMembers::nameMap((string) $key, $inNameMap));
            }
        }

        return $inNameMap === null && is_array($node['anyOf'] ?? null) && array_is_list($node['anyOf'])
            ? self::discriminate($node, $node['anyOf'], $schemas, $shortfalls)
            : $node;
    }

    /**
     * One `anyOf` node, discriminated when its members prove it; a `null` branch and an empty-object one
     * stay outside the `oneOf`.
     *
     * @param  array<mixed>  $node
     * @param  array<mixed>  $branches
     * @param  array<mixed>  $schemas
     * @param  array<string, Diagnostic>  $shortfalls
     * @return array<mixed>
     */
    private static function discriminate(array $node, array $branches, array $schemas, array &$shortfalls): array
    {
        $names = [];
        $bodies = [];
        $nullable = false;
        $empty = false;
        foreach ($branches as $branch) {
            if ($branch === ['type' => 'null'] && ! $nullable) {
                $nullable = true;

                continue;
            }

            if (self::isEmptyObject($branch) && ! $empty) {
                $empty = true;

                continue;
            }

            // An inline member has no name for a mapping to point at.
            $ref = is_array($branch) && count($branch) === 1 ? ($branch['$ref'] ?? null) : null;
            $name = is_string($ref) && str_starts_with($ref, self::PREFIX) ? substr($ref, strlen(self::PREFIX)) : null;
            $body = $name === null ? null : ($schemas[$name] ?? null);
            if ($name === null || ! is_array($body) || in_array($name, $names, true)) {
                return $node;
            }

            $names[] = $name;
            $bodies[] = $body;
        }

        if (count($names) < 2) {
            return $node;
        }

        $tags = array_map(self::tags(...), $bodies);
        $discriminator = self::discriminator($names, $tags);
        if ($discriminator === null) {
            $shortfall = self::shortfall($names, $bodies, $tags);
            if ($shortfall !== null) {
                $shortfalls[NameList::of(self::sorted($names))] = $shortfall;
            }

            return $node;
        }

        $tagged = [
            'oneOf' => array_map(static fn (string $name): array => ['$ref' => self::PREFIX.$name], $names),
            'discriminator' => $discriminator,
        ];

        $outside = [...($empty ? [self::EMPTY_OBJECT] : []), ...($nullable ? [['type' => 'null']] : [])];
        if ($outside !== []) {
            $node['anyOf'] = [$tagged, ...$outside];

            return $node;
        }

        unset($node['anyOf']);

        return self::repointProvenance($node + $tagged);
    }

    /** Whether a member is {@see EMPTY_OBJECT}, in whichever key order a producer wrote it. */
    private static function isEmptyObject(mixed $branch): bool
    {
        return is_array($branch) && count($branch) === 2
            && ($branch['type'] ?? null) === 'object' && ($branch['maxProperties'] ?? null) === 0;
    }

    /**
     * The first property, by name, that every member requires and pins to a value of its own.
     *
     * @param  list<string>  $names
     * @param  list<array<string, string>>  $tags
     * @return array{propertyName: string, mapping: array<string, string>}|null
     */
    private static function discriminator(array $names, array $tags): ?array
    {
        $candidates = array_map(strval(...), array_keys(array_intersect_key(...$tags)));
        sort($candidates, SORT_STRING);

        foreach ($candidates as $property) {
            $values = array_map(static fn (array $tag): string => $tag[$property], $tags);
            if (count(array_unique($values)) === count($values)) {
                $mapping = array_combine($values, array_map(static fn (string $name): string => self::PREFIX.$name, $names));
                ksort($mapping, SORT_STRING);

                return ['propertyName' => $property, 'mapping' => $mapping];
            }
        }

        return null;
    }

    /**
     * The properties a body requires and pins to one string, less any PHP would read back as an int key.
     *
     * @param  array<mixed>  $body
     * @return array<string, string>
     */
    private static function tags(array $body): array
    {
        $properties = $body['properties'] ?? null;
        $required = $body['required'] ?? null;
        if (! is_array($properties) || ! is_array($required)) {
            return [];
        }

        $tags = [];
        foreach ($properties as $name => $schema) {
            if (! is_string($name) || ! is_array($schema) || ! in_array($name, $required, true)) {
                continue;
            }

            $enum = $schema['enum'] ?? null;
            $value = $schema['const'] ?? (is_array($enum) && count($enum) === 1 ? reset($enum) : null);
            if (is_string($value) && (string) (int) $value !== $value) {
                $tags[$name] = $value;
            }
        }

        return $tags;
    }

    /**
     * Why a union that looks tagged — every member publishes a property at least two pin — got no discriminator.
     *
     * @param  list<string>  $names
     * @param  list<array<mixed>>  $bodies
     * @param  list<array<string, string>>  $tags
     */
    private static function shortfall(array $names, array $bodies, array $tags): ?Diagnostic
    {
        $published = array_map(
            static fn (array $body): array => is_array($body['properties'] ?? null) ? $body['properties'] : [],
            $bodies,
        );

        $shared = array_map(strval(...), array_keys(array_intersect_key(...$published)));
        sort($shared, SORT_STRING);

        foreach ($shared as $property) {
            $pinned = array_filter($tags, static fn (array $tag): bool => isset($tag[$property]));
            if (count($pinned) < 2) {
                continue;
            }

            $open = [];
            $holders = [];
            foreach ($tags as $i => $tag) {
                if (isset($tag[$property])) {
                    $holders[$tag[$property]][] = $names[$i];
                } else {
                    $open[] = $names[$i];
                }
            }
            ksort($holders, SORT_STRING);

            // Every member pinning it would have made it the discriminator unless two share a value.
            $reason = sprintf('%s leaves it open', NameList::of(self::sorted($open)));
            foreach ($holders as $value => $holding) {
                if ($open === [] && count($holding) > 1) {
                    $reason = sprintf("%s share the value '%s'", NameList::of(self::sorted($holding)), PlainText::of((string) $value));

                    break;
                }
            }

            return new Diagnostic(
                severity: Severity::Info,
                code: 'components.union-undiscriminated',
                message: sprintf(
                    'The union of %s is published as anyOf without a discriminator: its members all publish `%s`, but %s.',
                    NameList::of(self::sorted($names)),
                    PlainText::of($property),
                    $reason,
                ),
                help: 'Fix the value per class on every member — a readonly property that the constructor of a final class assigns once, from a string literal or a backed enum case — with a value no other member shares. A generated client can then tell the members apart by that property.',
            );
        }

        return null;
    }

    /**
     * The node's provenance, repointed from the `anyOf` a producer wrote to the two keywords it became.
     *
     * @param  array<mixed>  $node
     * @return array<mixed>
     */
    private static function repointProvenance(array $node): array
    {
        $extension = $node['x-docuccino'] ?? null;
        if (! is_array($extension) || ! is_array($extension['provenance'] ?? null)) {
            return $node;
        }

        foreach ($extension['provenance'] as $i => $record) {
            if (is_array($record) && is_array($record['fields'] ?? null) && in_array('anyOf', $record['fields'], true)) {
                $kept = array_filter($record['fields'], static fn (mixed $field): bool => $field !== 'anyOf');
                $record['fields'] = [...array_values($kept), 'discriminator', 'oneOf'];
                $extension['provenance'][$i] = $record;
            }
        }

        $node['x-docuccino'] = $extension;

        return $node;
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private static function sorted(array $names): array
    {
        sort($names, SORT_STRING);

        return $names;
    }
}
