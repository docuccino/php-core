<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation;

use Docuccino\Core\Extensions\Context\RepresentationPolicy;

/**
 * One node of the request schema tree the rule builder assembles. A node is an object (it has
 * named {@see $properties}), an array (it has an {@see $items} node, created by a `*` path segment),
 * or a leaf scalar (its {@see $keywords} carry `type`/`format`/constraints). `required` and
 * `nullable` are node flags a parent lifts into its `required` list and its type expression.
 *
 * @internal builder state; the public surface is {@see ValidationField}.
 */
final class FieldNode
{
    /**
     * @var array<string, mixed>
     */
    public array $keywords = [];

    public bool $required = false;

    /**
     * A `sometimes`-style rule was seen, so the field is validated only when present and stays out of
     * the parent's `required` list even if a `required` rule came too — Laravel's `sometimes|required`
     * means "required when present", i.e. optional in the request contract. Tracked separately from
     * {@see $required} so presence resolution doesn't depend on rule order.
     */
    public bool $presenceOptional = false;

    public bool $nullable = false;

    /**
     * @var array<string, FieldNode>
     */
    public array $properties = [];

    public ?FieldNode $items = null;

    /** The object property child of the given name, created on first access (order-preserving). */
    public function child(string $name): self
    {
        return $this->properties[$name] ??= new self;
    }

    /** The array-items child, created (and marking this node an array) on first access. */
    public function itemsNode(): self
    {
        return $this->items ??= new self;
    }

    /**
     * Assemble this node into a JSON Schema fragment, applying the nullable policy last so a
     * single-type node renders `type: [t, null]` (or an `anyOf` null branch) consistently with the
     * rest of the document.
     *
     * @return array<string, mixed>
     */
    public function build(RepresentationPolicy $policy): array
    {
        $schema = $this->keywords;

        if ($this->properties !== []) {
            $schema['type'] ??= 'object';
            $properties = [];
            $required = [];
            // A numeric path segment (`coords.0`) reaches PHP as an INT array key, hence the casts:
            // `required` carries strings only.
            foreach ($this->properties as $name => $node) {
                $properties[(string) $name] = $node->build($policy);
                if ($node->required && ! $node->presenceOptional) {
                    $required[] = (string) $name;
                }
            }
            $schema['properties'] = $properties;
            if ($required !== []) {
                $schema['required'] = $required;
            }
        } elseif ($this->items !== null) {
            $schema['type'] ??= 'array';
            $schema['items'] = $this->items->build($policy);
        }

        return $this->nullable ? self::applyNullable($schema, $policy) : $schema;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function applyNullable(array $schema, RepresentationPolicy $policy): array
    {
        $type = $schema['type'] ?? null;

        if (! is_string($type)) {
            return $schema;
        }

        if ($policy->nullable === 'anyof') {
            unset($schema['type']);

            return ['anyOf' => [['type' => $type], ['type' => 'null']]] + $schema;
        }

        $schema['type'] = [$type, 'null'];

        return $schema;
    }
}
