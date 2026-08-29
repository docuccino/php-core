<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Support;

/**
 * Which VALUES OpenAPI 3.2 added to a member's domain, read out of the two vendored meta-schemas the same
 * way {@see OpenApiMemberDelta} reads the members — off the one declaration reader, so the two axes cannot
 * disagree about what a version's schema reaches.
 *
 * The second axis exists because the first cannot see it. A member 3.2 added is absent from 3.1 and a
 * member-shaped table finds it; a domain 3.2 WIDENED leaves the member in place — `in` is declared by every
 * version and only the value `querystring` is 3.2's — so a downlevel guard reading members alone emits it
 * verbatim into a document whose own meta-schema rejects it.
 */
final class OpenApiValueDelta
{
    /**
     * Every value 3.2 declares that 3.1 does not, keyed `<object>.<member>`. A member whose domain only
     * 3.2 pins at all is absent: an unpinned domain in 3.1 admits the value already, so nothing is lost
     * downleveling it.
     *
     * @return array<string, list<string>>
     */
    public static function added32(): array
    {
        $declared31 = OpenApiMemberDelta::declarations('openapi-3.1');

        $added = [];

        foreach (self::domains32() as $slot => $values) {
            [$object, $member] = explode('.', $slot, 2);
            $before = $declared31[$object][$member] ?? [];

            if ($before === []) {
                continue;
            }

            $new = array_values(array_diff($values, $before));
            sort($new);

            if ($new !== []) {
                $added[$slot] = $new;
            }
        }

        ksort($added);

        return $added;
    }

    /**
     * Every position 3.2 pins a value domain at, keyed `<object>.<member>`, over the objects both versions
     * define. The count is the floor under {@see added32()}: a reader that stopped recognising a declaration
     * shape would report no additions AND far fewer positions, so the two assertions fail together instead
     * of one of them agreeing with an empty answer.
     *
     * @return array<string, list<string>>
     */
    public static function domains32(): array
    {
        $declared = OpenApiMemberDelta::declarations('openapi-3.2');

        $domains = [];

        foreach (OpenApiMemberDelta::comparableObjects() as $object) {
            foreach ($declared[$object] as $member => $values) {
                if ($values !== []) {
                    sort($values);
                    $domains[$object.'.'.$member] = $values;
                }
            }
        }

        ksort($domains);

        return $domains;
    }
}
