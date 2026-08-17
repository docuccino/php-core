<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Contracts\RouteNoteCollector;

/**
 * The mutable bag behind {@see RouteContext::notes()}: facts one route's build found that only the whole
 * document can report — a render callback whose body would not fold, say, where one summary per callback
 * beats one warning per route. A {@see DocumentTransformer} cannot see a route, and a route cannot see
 * the other routes contesting the same summary, so the fact is written here and read back through a
 * {@see RouteNoteCollector} once every route has had its turn.
 *
 * Why not report it and be done: a warm fragment-cache hit runs no extension, so a fact recorded
 * straight into a document-level aggregate is simply absent from a warm build, and the document says
 * less than a cold one's for the same code. Notes ride the operation fragment and are replayed into their
 * collector on EVERY build, warm and cold alike, so there is one path into the aggregate rather than two
 * that can drift. That is also why a note is `(channel, key, value)` strings and nothing else: a fragment
 * is JSON, and a note that could not survive the round trip would be a note a warm build lost.
 *
 * {@see all()} is sorted throughout, so what a route contributes is a function of what it found and never
 * of the order it met it.
 */
final class RouteNotes
{
    /** @var array<string, array<string, list<string>>> channel ⇒ key ⇒ values */
    private array $notes = [];

    /**
     * Record one note. The channel picks the collector, the key is what the aggregate groups by (a
     * callback, a class), and the value is the member being added to that group.
     */
    public function record(string $channel, string $key, string $value): void
    {
        $values = $this->notes[$channel][$key] ?? [];
        if (! in_array($value, $values, true)) {
            $values[] = $value;
        }

        $this->notes[$channel][$key] = $values;
    }

    /**
     * Every note, sorted by channel, then key, then value.
     *
     * @return array<string, array<string, list<string>>>
     */
    public function all(): array
    {
        $notes = $this->notes;
        ksort($notes);

        foreach ($notes as $channel => $keys) {
            ksort($keys);
            foreach ($keys as $key => $values) {
                sort($values);
                $keys[$key] = $values;
            }

            $notes[$channel] = $keys;
        }

        return $notes;
    }
}
