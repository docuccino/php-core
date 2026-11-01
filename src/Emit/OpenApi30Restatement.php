<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

use Docuccino\Core\Support\JsonPointer;

/**
 * The way back from a 3.0 document as {@see OpenApi30Upgrade} restates it to where each part was written.
 * Everything stays where it stood but one: a `nullable` with no `type` beside it becomes an `anyOf` whose
 * first branch is what the schema asserted and whose second admits null, so a pointer through that `anyOf`
 * names a place the source does not have.
 *
 * @internal
 */
final readonly class OpenApi30Restatement
{
    /**
     * @param  array<string, bool>  $wrapped  each wrapped schema's pointer in the source, true where its
     *                                        branch is the lone `allOf` member it had
     */
    public function __construct(private array $wrapped) {}

    /**
     * Pointer segments into the restatement, as the segments naming the same place in the source: a
     * wrapper's first branch is the schema itself, or its lone `allOf` member, and its null branch is the
     * `nullable` that admitted null.
     *
     * @param  list<string>  $segments
     * @return list<string>
     */
    public function written(array $segments): array
    {
        $written = [];
        $at = '#';
        $unwrapped = null;

        for ($step = 0, $count = count($segments); $step < $count; $step++) {
            // Once past a wrapper, the branch stands where its schema did, and an `anyOf` there is its own.
            $hoisted = $at === $unwrapped ? null : ($this->wrapped[$at] ?? null);
            $branch = $segments[$step + 1] ?? null;

            if ($hoisted !== null && $segments[$step] === 'anyOf' && ($branch === '0' || $branch === '1')) {
                if ($branch === '1') {
                    return [...$written, 'nullable'];
                }

                $step++;
                $unwrapped = $at;

                if ($hoisted) {
                    $written = [...$written, 'allOf', '0'];
                    $at = JsonPointer::child(JsonPointer::child($at, 'allOf'), '0');
                }

                continue;
            }

            $written[] = $segments[$step];
            $at = JsonPointer::child($at, $segments[$step]);
        }

        return $written;
    }
}
