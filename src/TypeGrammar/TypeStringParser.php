<?php

declare(strict_types=1);

namespace Docuccino\Core\TypeGrammar;

use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Core\Inference\DType\ArrayKey;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\IntersectionT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFalseNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFloatNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprTrueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;

/**
 * Parses a phpstan/phpdoc-parser type string — as written in `#[Response(type: '…')]` and the parameter
 * attributes — into a {@see DType} through the shared {@see PhpDocParserStack}, the same grammar the engine
 * uses for docblocks.
 */
final class TypeStringParser
{
    public function __construct(
        private readonly PhpDocParserStack $stack = new PhpDocParserStack,
    ) {}

    public function parse(string $type, ?ImportContext $imports = null): DType
    {
        $type = trim($type);
        if ($type === '') {
            return new UnknownT('empty type string');
        }

        $node = $this->stack->parseType($type);
        if ($node === null) {
            return new UnknownT('unparseable type: '.$type);
        }

        return $this->map($node, $imports);
    }

    private function map(TypeNode $node, ?ImportContext $imports): DType
    {
        return match (true) {
            $node instanceof NullableTypeNode => UnionT::of([$this->map($node->type, $imports), new NullT]),
            $node instanceof UnionTypeNode => UnionT::of(array_values(array_map(fn (TypeNode $t): DType => $this->map($t, $imports), $node->types))),
            $node instanceof IntersectionTypeNode => IntersectionT::of(array_values(array_map(fn (TypeNode $t): DType => $this->map($t, $imports), $node->types))),
            // `V[]` is `array<array-key, V>` written short, so it takes the same key rule.
            $node instanceof ArrayTypeNode => ArrayKey::arrayOf(self::arrayKey(), $this->map($node->type, $imports)),
            $node instanceof GenericTypeNode => $this->mapGeneric($node, $imports),
            $node instanceof ArrayShapeNode => $this->mapArrayShape($node, $imports),
            $node instanceof ConstTypeNode => $this->mapConst($node),
            $node instanceof IdentifierTypeNode => $this->mapIdentifier($node->name, $imports),
            default => new UnknownT('unsupported type node'),
        };
    }

    private function mapIdentifier(string $name, ?ImportContext $imports): DType
    {
        return match (strtolower($name)) {
            'int', 'integer', 'positive-int', 'negative-int', 'non-negative-int' => ScalarT::int(),
            'string', 'non-empty-string', 'class-string', 'numeric-string' => ScalarT::string(),
            'float', 'double', 'number' => ScalarT::float(),
            'bool', 'boolean', 'true', 'false' => ScalarT::bool(),
            'null' => new NullT,
            // `array-key` is `int|string` — the honest answer, and what makes {@see ArrayKey} read
            // `array<array-key, V>` as int-capable.
            'array-key' => self::arrayKey(),
            'array', 'iterable', 'list' => new UnknownT('untyped array'),
            'mixed' => new UnknownT('mixed'),
            'object' => new UnknownT('object'),
            'void', 'never', 'callable', 'scalar' => new UnknownT($name),
            default => $this->classOrEnum($this->resolveClass($name, $imports)),
        };
    }

    /**
     * An enum written in a docblock answers `EnumT`, matching the reflection and PHPStan-type mappers —
     * otherwise `@property ListingStatus $status` would document as an object of the enum's `name`/`value`
     * members. A name that doesn't resolve to a loadable enum stays a `ClassT`.
     */
    private function classOrEnum(string $fqcn): DType
    {
        return enum_exists($fqcn) ? new EnumT($fqcn, EnumReflection::names($fqcn)) : new ClassT($fqcn);
    }

    private function mapGeneric(GenericTypeNode $node, ?ImportContext $imports): DType
    {
        $base = strtolower($node->type->name);

        // A bounded or masked int is still an int, and the bounds document nothing — so settle these
        // before the arguments are mapped, since `int<0, max>` carries a `max` that means nothing alone.
        if ($base === 'int' || $base === 'int-mask' || $base === 'int-mask-of') {
            return ScalarT::int();
        }

        $args = array_map(fn (TypeNode $t): DType => $this->map($t, $imports), $node->genericTypes);

        if (($base === 'list' || $base === 'non-empty-list') && count($args) === 1) {
            return new ListT($args[0]);
        }

        if (($base === 'array' || $base === 'iterable' || $base === 'non-empty-array')) {
            return match (count($args)) {
                // One argument is `array<array-key, V>` written short, so it takes the same key rule.
                1 => ArrayKey::arrayOf(self::arrayKey(), $args[0]),
                2 => ArrayKey::arrayOf($args[0], $args[1]),
                default => new UnknownT('untyped array'),
            };
        }

        return new ClassT($this->resolveClass($node->type->name, $imports), array_values($args));
    }

    /** `array-key`, spelled out: the key `V[]` and the one-argument `array<V>` leave implicit. */
    private static function arrayKey(): DType
    {
        return UnionT::of([ScalarT::int(), ScalarT::string()]);
    }

    /** Resolve a class name against the file's imports + namespace (when given), else strip a leading slash. */
    private function resolveClass(string $name, ?ImportContext $imports): string
    {
        return $imports !== null ? $imports->resolve($name) : ltrim($name, '\\');
    }

    private function mapArrayShape(ArrayShapeNode $node, ?ImportContext $imports): DType
    {
        $fields = [];
        $index = 0;
        foreach ($node->items as $item) {
            $fields[] = new ArrayShapeField(
                key: $this->shapeKey($item, $index),
                type: $this->map($item->valueType, $imports),
                optional: $item->optional,
            );
            $index++;
        }

        return new ArrayShapeT($fields);
    }

    private function shapeKey(ArrayShapeItemNode $item, int $index): string|int
    {
        if ($item->keyName === null) {
            return $index;
        }

        $key = (string) $item->keyName;
        $trimmed = trim($key, '\'"');

        return is_numeric($trimmed) && ! str_contains($trimmed, '.') ? (int) $trimmed : $trimmed;
    }

    private function mapConst(ConstTypeNode $node): DType
    {
        $expr = $node->constExpr;

        return match (true) {
            $expr instanceof ConstExprStringNode => new LiteralT($expr->value),
            $expr instanceof ConstExprIntegerNode => new LiteralT((int) $expr->value),
            $expr instanceof ConstExprFloatNode => new LiteralT((float) $expr->value),
            $expr instanceof ConstExprTrueNode => new LiteralT(true),
            $expr instanceof ConstExprFalseNode => new LiteralT(false),
            default => new UnknownT('unsupported const expression'),
        };
    }
}
