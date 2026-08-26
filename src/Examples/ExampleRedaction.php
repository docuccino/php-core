<?php

declare(strict_types=1);

namespace Docuccino\Core\Examples;

use Docuccino\Core\Lint\CredentialShapes;
use Docuccino\Core\Lint\LintSafelist;
use Docuccino\Core\Lint\SensitiveFieldLint;
use Docuccino\Core\Lint\SensitiveFieldLintOptions;
use stdClass;

/**
 * Takes the secrets out of a recorded response body, and — separately — says whether any are still in
 * one.
 *
 * It is the same knowledge {@see SensitiveFieldLint} publishes on: {@see SensitiveFieldLintOptions}
 * for member names that mean a secret, {@see CredentialShapes} for strings only a real credential
 * plausibly matches. The lint reports; this replaces, because a recording is captured from a live
 * request and a document is a thing people publish.
 *
 * Two rules, both narrow on purpose. Only STRINGS are replaced, so `token_count: 5` keeps its type and
 * the example goes on satisfying its own schema. And a sensitive member name taints everything beneath
 * it, so `credentials: {id, value}` loses both halves rather than only the half whose own name gave it
 * away.
 *
 * A number under a credential name is REPORTED and never replaced. `{"cvv": 123}` is as much a secret
 * as the string would be, and reporting the pointer is enough to keep it out of the document, because
 * the audit refuses to publish a body with any finding in it; replacing it could not be done without
 * making the example contradict its own schema. The evidence there has to be the name EXACTLY — nothing
 * else can speak for a number, since {@see CredentialShapes} reads string shapes — so `token` counts
 * and `token_count` does not, and a bool or a null is not a secret anyone can leak.
 *
 * Only half of the safelist is honoured here, and deliberately. A POINTER names one value in one body,
 * which is a decision about that value; a bare NAME blankets every member of that name everywhere,
 * which is a decision about a schema. The lint is a report, so a name silencing it costs a warning; a
 * name silencing this would write a live credential into a committed file and publish it.
 *
 * @internal
 */
final readonly class ExampleRedaction
{
    /** What a removed value is replaced with. Recognisable in a diff, and obviously not a credential. */
    public const string PLACEHOLDER = '[redacted]';

    /** As deep as a response body is walked; past that a value is left exactly as it is. */
    private const int MAX_DEPTH = 64;

    public function __construct(
        private SensitiveFieldLintOptions $options = new SensitiveFieldLintOptions,
    ) {}

    /**
     * The body with every credential string replaced, and the JSON pointer of everything unsafe in it —
     * including the numbers it deliberately leaves in place.
     *
     * @return array{0: mixed, 1: list<string>}
     */
    public function apply(mixed $body): array
    {
        $pointers = [];
        $redacted = $this->walk($body, '', false, false, $pointers, true, 0);
        sort($pointers);

        return [$redacted, $pointers];
    }

    /**
     * The pointers of every value in an already-committed body that still looks like a credential —
     * a hand edit, or a heuristics table that learned something after the recording was made.
     *
     * @return list<string>
     */
    public function findings(mixed $body): array
    {
        $pointers = [];
        $this->walk($body, '', false, false, $pointers, false, 0);
        sort($pointers);

        return $pointers;
    }

    /**
     * One walk for both jobs: $replace says whether to hand back a cleaned value or only to collect.
     * $tainted is "a sensitive name is somewhere above this", $named the stronger "this value's OWN name
     * is a credential name" that a non-string is judged on.
     *
     * @param  list<string>  $pointers
     */
    private function walk(mixed $value, string $pointer, bool $tainted, bool $named, array &$pointers, bool $replace, int $depth): mixed
    {
        if ($depth >= self::MAX_DEPTH) {
            return $value;
        }

        // An object stays an object: `{}` is how a body says "a map with nothing in it", and a list
        // member's index is never a member NAME, so it can never taint what sits under it.
        $map = $value instanceof stdClass;
        if ($map) {
            $value = (array) $value;
        }

        if (is_array($value)) {
            $list = ! $map && array_is_list($value);
            $out = [];
            foreach ($value as $key => $child) {
                $name = (string) $key;
                $childPointer = $pointer.'/'.self::escape($name);
                $childTainted = $tainted || (! $list && $this->sensitive($name));
                $childNamed = ! $list && $this->options->matchExact($name) !== null;
                $out[$name] = $this->walk($child, $childPointer, $childTainted, $childNamed, $pointers, $replace, $depth + 1);
            }

            if ($map) {
                return (object) $out;
            }

            return $list ? array_values($out) : $out;
        }

        if (! is_string($value)) {
            // Reported, never replaced — see the class docblock for both halves of why.
            return $named && (is_int($value) || is_float($value))
                ? $this->flag($pointer, $pointers, $value, false)
                : $value;
        }

        if ($value === '' || $value === self::PLACEHOLDER) {
            return $value;
        }

        if (! $tainted && CredentialShapes::label($value) === null) {
            return $value;
        }

        return $this->flag($pointer, $pointers, $value, $replace);
    }

    /**
     * Records one unsafe pointer and hands back what should stand in the body. Pointers only — see the
     * class docblock for why a bare name is not enough to publish a value. These pointers are
     * body-relative where a lint's are document-absolute, and both always open with `/`, so the leading
     * `#` {@see LintSafelist} takes off only ever normalises the safelist entry.
     *
     * @param  list<string>  $pointers
     */
    private function flag(string $pointer, array &$pointers, mixed $value, bool $replace): mixed
    {
        if (LintSafelist::matches($this->options->allow, $pointer)) {
            return $value;
        }

        $pointers[] = $pointer;

        return $replace ? self::PLACEHOLDER : $value;
    }

    /** Whether a member name means "a secret lives here". */
    private function sensitive(string $name): bool
    {
        return $this->options->match($name) !== null;
    }

    /** RFC 6901 escaping, so a member called `a/b` addresses one place rather than two. */
    private static function escape(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }
}
