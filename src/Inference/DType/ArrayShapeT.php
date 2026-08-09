<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference\DType;

/**
 * A constant array shape (`array{id: int, name?: string}`, or a positional list-shape). Field order
 * matters and is preserved verbatim — unlike union members, it's never sorted. `isList` records
 * whether the keys are a `0..n` sequence, from PHPStan's list accessory.
 */
final readonly class ArrayShapeT extends DType
{
    public const KIND = 'arrayShape';

    /**
     * @param  list<ArrayShapeField>  $fields
     */
    public function __construct(public array $fields, public bool $isList = false) {}

    public function kind(): string
    {
        return self::KIND;
    }

    /**
     * A copy with every field's value type passed through `$map`; keys, key order, optionality and
     * `isList` all survive. The seam for rewriting members of a recovered body — pinning one key to a
     * folded literal, resolving status-provenance members to a concrete status — instead of every
     * caller hand-rolling an `array_map` + rebuild.
     *
     * @param  callable(DType, string|int): DType  $map  a field's current type + key → its replacement type
     */
    public function mapFieldTypes(callable $map): self
    {
        return new self(
            array_map(
                static fn (ArrayShapeField $field): ArrayShapeField => new ArrayShapeField(
                    $field->key,
                    $map($field->type, $field->key),
                    $field->optional,
                ),
                $this->fields,
            ),
            $this->isList,
        );
    }

    public function toArray(): array
    {
        return [
            'kind' => self::KIND,
            'isList' => $this->isList,
            'fields' => array_map(static fn (ArrayShapeField $f): array => $f->toArray(), $this->fields),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $fields = $data['fields'] ?? [];

        return new self(
            is_array($fields)
                ? array_values(array_map(
                    static fn (mixed $f): ArrayShapeField => is_array($f)
                        ? ArrayShapeField::fromArray($f)
                        : new ArrayShapeField('', new UnknownT('malformed field')),
                    $fields,
                ))
                : [],
            (bool) ($data['isList'] ?? false),
        );
    }
}
