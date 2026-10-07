<?php

declare(strict_types=1);

namespace Docuccino\Core\SpecValidation;

use Stringable;

/**
 * One place a document fails its specification, as data: where (`pointer`), which rule (`keyword`, and
 * the schema location that states it), and what is wrong (`message`).
 *
 * Every spec check reports through this — the meta-schema, the key gates and Reference Object rules
 * {@see OpenApiMetaSchema} recovers, and the rules no schema can state — so a caller that is not a person
 * (a CI annotation, a hosted build page, a test) reads the parts instead of parsing them back out of a
 * sentence. A person still gets the sentence: {@see __toString()} is the one rendering, and it is the
 * line these checks have always printed, so nothing that shows a finding to someone reads differently.
 */
final readonly class Finding implements Stringable
{
    /**
     * @param  string  $pointer  JSON pointer into the document; `/` is the root
     * @param  ?string  $keyword  the rule broken — a JSON Schema keyword, or the member a non-schema rule
     *                            is about (`$ref`, `operationId`); null where the message names it
     * @param  ?string  $schemaPointer  where in the meta-schema the rule is stated, when one states it
     */
    public function __construct(
        public string $pointer,
        public ?string $keyword,
        public string $message,
        public ?string $schemaPointer = null,
    ) {}

    public function __toString(): string
    {
        return $this->pointer
            .($this->keyword === null ? '' : ' '.$this->keyword)
            .': '.$this->message
            .($this->schemaPointer === null ? '' : ' (schema '.$this->schemaPointer.')');
    }

    /**
     * $findings in the order their sentences sort, which is the order these checks have always reported
     * in. Mostly that walks the document top to bottom, since a sentence starts with its pointer; a
     * finding that names no keyword is the exception, because its pointer is followed by `:`, which sorts
     * after the `/` of a child's.
     *
     * @param  list<self>  $findings
     * @return list<self>
     */
    public static function sorted(array $findings): array
    {
        usort($findings, static fn (self $a, self $b): int => strcmp((string) $a, (string) $b));

        return $findings;
    }
}
