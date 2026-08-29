<?php

declare(strict_types=1);

namespace Docuccino\Core\Provenance;

/**
 * What the two path passes over a diagnostic read, and what they publish when they cannot read it.
 * Both {@see MessagePaths} and {@see ClassNames} rewrite through `preg_replace_callback`, and PCRE
 * answers null on a resource limit rather than throwing — so handing the ORIGINAL back publishes the
 * machine path the pass exists to remove, and one over-long run takes every other path in the same
 * message with it. The input is therefore bounded before either pass reads it, and a pass that still
 * answers null gives up the text rather than publishing it: a diagnostic nobody can read costs a
 * reader one message, and a diagnostic naming the build machine costs every document byte-identical
 * output.
 *
 * @internal
 */
final class PublishableText
{
    /**
     * How much of a message the passes read. Beyond it the text is cut and marked, because PCRE
     * stops answering long before a message that long could be worth reading: a run-dense message
     * exhausts the JIT stack at about 8 KB, and the reduction is super-linear in the number of runs
     * it has to attribute, so a kilobyte of unattributable runs costs tens of milliseconds and four
     * kilobytes costs seconds. A kilobyte is several times the longest message the product has ever
     * been handed, and no shape measured reaches a PCRE limit at it.
     */
    public const int MAX_BYTES = 1024;

    /**
     * What a pass publishes instead of text it could not check. One fixed sentence, so two machines
     * that both give up still emit the same bytes.
     */
    public const string REFUSED = '(text withheld: it could not be checked for paths naming the build machine)';

    /** The message the passes may read, cut at {@see MAX_BYTES} and marked where it was cut. */
    public static function bounded(string $text): string
    {
        return strlen($text) <= self::MAX_BYTES
            ? $text
            : self::whole(substr($text, 0, self::MAX_BYTES)).'…';
    }

    /** A rewrite's answer, or the refusal where PCRE gave up and answered null instead. */
    public static function orRefused(?string $rewritten): string
    {
        return $rewritten ?? self::REFUSED;
    }

    /**
     * The cut, less any character it landed in the middle of. UTF-8 is self-synchronising, so a
     * partial character is always the last few bytes and never anything earlier: a lead byte says
     * how many continuations it needs, and one short of them is not something a JSON encoder will
     * carry.
     */
    private static function whole(string $cut): string
    {
        for ($back = 1; $back <= 4 && $back <= strlen($cut); $back++) {
            $byte = ord($cut[-$back]);

            if (($byte & 0xC0) === 0x80) {
                continue;
            }

            $needs = match (true) {
                $byte >= 0xF0 => 4,
                $byte >= 0xE0 => 3,
                $byte >= 0xC0 => 2,
                default => 1,
            };

            return $needs > $back ? substr($cut, 0, -$back) : $cut;
        }

        return $cut;
    }
}
