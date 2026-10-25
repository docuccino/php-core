<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

use Docuccino\Core\Document\Parameter;
use Docuccino\Core\Document\PathItem;
use Docuccino\Core\Document\ResponseObject;
use Docuccino\Core\Document\UirDocument;

/**
 * How much a diff may read through one document's references. The differ reads a shared node once per
 * use — a path item per path, a response per operation, a schema per position pointing differently on
 * the two sides — so a document using one large node a thousand times is a thousand nodes to compare,
 * and pointers that repoint at every level multiply level by level. Every resolution is charged the size
 * of what it lands on, against {@see RATIO} times the document's own size, or {@see MINIMUM} for a small
 * one, and past that the comparison is refused rather than made.
 *
 * Sized from what real comparisons read: the most measured was 13 times the documents' size, for
 * Stripe's description diffed against itself with every schema renamed, and an ordinary diff of a real
 * description reads under once its size.
 *
 * @internal
 */
final class ReferenceBudget
{
    /** How many times its own size a document may be read through its references. */
    private const int RATIO = 32;

    /** The bytes any document may be read through its references, so a small one is never refused. */
    private const int MINIMUM = 1 << 20;

    private int $read = 0;

    /** The document's own size, measured the first time a comparison reads past {@see MINIMUM}. */
    private ?int $size = null;

    /** @var array<string, int> */
    private array $sizes = [];

    public function __construct(private readonly UirDocument $document) {}

    /**
     * Charges reading $target, the component at $section/$name, once more.
     *
     * @throws IncomparableDocumentsException past the budget
     */
    public function charge(string $section, string $name, mixed $target): void
    {
        $this->read += $this->sizes[$section."\0".$name] ??= self::size($target);

        if ($this->read <= self::MINIMUM) {
            return;
        }

        $this->size ??= self::size($this->document);
        $limit = max(self::MINIMUM, self::RATIO * $this->size);

        if ($this->read > $limit) {
            throw IncomparableDocumentsException::referenceExpansion($limit, $this->size);
        }
    }

    /** $value's size as compact JSON, with floats written as every host writes them. */
    private static function size(mixed $value): int
    {
        if ($value instanceof UirDocument || $value instanceof PathItem || $value instanceof Parameter || $value instanceof ResponseObject) {
            $value = $value->toArray();
        }

        $previous = ini_set('serialize_precision', '-1');

        try {
            return strlen((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        } finally {
            if (is_string($previous)) {
                ini_set('serialize_precision', $previous);
            }
        }
    }
}
