<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference\DType;

/**
 * A constant array shape (`array{id: int, name?: string}`, or a positional list-shape). Field order
 * matters and is preserved verbatim — unlike union members, it's never sorted. `isList` records whether
 * the keys are a `0..n` sequence, which is exactly when PHP renders the array as a JSON array; it is
 * derived from the keys here as well as taken from the caller (PHPStan's list accessory), so a path that
 * only knows the keys can't leave a tuple looking like an object with `"0"`/`"1"` property names.
 * `isObject` marks an object's shape (`object{…}`, what `(object) [...]` is typed as): a JSON object
 * whatever its keys, and one Laravel never filters or sends as `[]` when it is empty.
 */
final readonly class ArrayShapeT extends DType
{
    public const KIND = 'arrayShape';

    public bool $isList;

    /**
     * @param  list<ArrayShapeField>  $fields
     */
    public function __construct(public array $fields, bool $isList = false, public bool $isObject = false)
    {
        $this->isList = ! $isObject && ($isList || self::keysArePositional($fields));
    }

    /**
     * A copy holding `$fields` instead, as a list or an object exactly as this one is.
     *
     * @param  list<ArrayShapeField>  $fields
     */
    public function withFields(array $fields): self
    {
        return new self($fields, $this->isList, $this->isObject);
    }

    /**
     * @param  list<ArrayShapeField>  $fields
     */
    private static function keysArePositional(array $fields): bool
    {
        if ($fields === []) {
            return false;
        }

        foreach ($fields as $index => $field) {
            if ($field->key !== $index) {
                return false;
            }
        }

        return true;
    }

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
        return $this->withFields(
            array_map(
                static fn (ArrayShapeField $field): ArrayShapeField => new ArrayShapeField(
                    $field->key,
                    $map($field->type, $field->key),
                    $field->optional,
                ),
                $this->fields,
            ),
        );
    }

    public function toArray(): array
    {
        $data = [
            'kind' => self::KIND,
            'isList' => $this->isList,
            'fields' => array_map(static fn (ArrayShapeField $f): array => $f->toArray(), $this->fields),
        ];
        // Written only when set, so an array's shape serializes exactly as it always has.
        if ($this->isObject) {
            $data['isObject'] = true;
        }

        return $data;
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
            (bool) ($data['isObject'] ?? false),
        );
    }
}
