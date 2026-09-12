<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation;

use Docuccino\Attributes\BodyParameter;
use Docuccino\Attributes\QueryParameter;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\TypeGrammar\TypeStringParser;

/**
 * What the author's parameter declarations already say about a route's validated fields — the two
 * questions a rules recoverer asks before reporting what became of a field it could not read: is the
 * field in the document anyway ({@see publishes()}), and did a declaration decide which container it is
 * ({@see decidesContainer()}). A note that speaks without asking asserts what a later layer settled.
 *
 * One class for both layers because the question is one, and one flag inside it because the WRITERS
 * differ — a guard reads the same grammar as the write it guards:
 *
 * - a `#[BodyParameter]` is written INTO the recovered body ({@see DeclaredBodyFields}), the declared
 *   node going in whole, so a declaration anywhere on a field's BRANCH decides that field. Above it,
 *   the field is replaced and no rule the author writes can put it back; at or inside it, the field is
 *   published as what the declaration says, its container settled by the writing.
 * - a `#[QueryParameter]` mints ONE parameter per name ({@see RecoveredRequest::apply()}) and leaves
 *   every other parameter as the recovery left it, so it answers for the field it NAMES and no other,
 *   and it decides a container only by stating a type — with none it writes no schema at all.
 *
 * @phpstan-type DeclaredField array{path: string, type: string|null}
 */
final class DeclaredFields
{
    private readonly TypeStringParser $types;

    /**
     * @param  list<DeclaredField>  $declarations
     * @param  bool  $wholeBranch  whether a declaration decides a field's whole branch — see the header
     */
    private function __construct(
        private readonly array $declarations,
        private readonly bool $wholeBranch,
    ) {
        $this->types = new TypeStringParser;
    }

    /**
     * The declarations that document a request BODY.
     *
     * @param  list<BodyParameter>  $declarations
     */
    public static function inBody(array $declarations): self
    {
        return new self(
            array_map(static fn (BodyParameter $each): array => ['path' => $each->name, 'type' => $each->type], $declarations),
            wholeBranch: true,
        );
    }

    /**
     * The declarations that document QUERY parameters, read back into the path grammar the rules are
     * keyed by ({@see FieldPath::queryNameAsPath()}) — one with no spelling there answers for no field.
     *
     * @param  list<QueryParameter>  $declarations
     */
    public static function inQuery(array $declarations): self
    {
        /** @var list<DeclaredField> $paths */
        $paths = [];

        foreach ($declarations as $each) {
            $path = FieldPath::queryNameAsPath($each->name);
            if ($path !== null) {
                $paths[] = ['path' => $path, 'type' => $each->type];
            }
        }

        return new self($paths, wholeBranch: false);
    }

    /**
     * Whether a declaration answers for `$field` — asked before a recoverer says the field is omitted,
     * or that a constraint of it was left off.
     */
    public function publishes(string $field): bool
    {
        foreach ($this->declarations as $declaration) {
            if ($this->reaches($declaration['path'], $field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a declaration settles which container `$field` is — narrower than {@see publishes()},
     * because a declaration can publish a field without saying which of the two shapes it takes. Read
     * off the two writers the class header describes: one that is not AT the field settles it by
     * existing, which only the body layer reaches; one AT the field settles it as far as its type does,
     * through the parser that will do the writing, and `array`/`mixed` resolve to no shape at all.
     */
    public function decidesContainer(string $field): bool
    {
        foreach ($this->declarations as $declaration) {
            if (! $this->reaches($declaration['path'], $field)) {
                continue;
            }

            if (FieldPath::segments($declaration['path']) !== FieldPath::segments($field)) {
                return true;
            }

            if ($declaration['type'] === null) {
                if ($this->wholeBranch) {
                    return true;
                }

                continue;
            }

            if (! $this->types->parseDeclared($declaration['type']) instanceof UnknownT) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether one declared path answers for `$field`; a path with an empty segment names none. A body
     * path the body turns out not to carry still counts — this runs during recovery, with no body to ask,
     * and that refusal is reported against the declaration itself.
     */
    private function reaches(string $path, string $field): bool
    {
        if (! FieldPath::isWellFormed($path)) {
            return false;
        }

        if (! $this->wholeBranch) {
            return FieldPath::segments($path) === FieldPath::segments($field);
        }

        return FieldPath::isAtOrUnder($path, $field) || FieldPath::isAtOrUnder($field, $path);
    }
}
