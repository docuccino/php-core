<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\ArrayKey;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;

/**
 * The `array<K, V>` list-vs-map rule, owned here so the docblock grammar and the PHPStan translator
 * cannot answer it differently — both call this rather than carrying a copy. The tradeoff the rule makes
 * for an ambiguous key is argued in docs/design/uir-and-extensions.md §8.
 */
it('answers whether a key can carry an int, over every DType a key can be', function (DType $key, bool $expected): void {
    expect(ArrayKey::mayBeInt($key))->toBe($expected);
})->with([
    // Int-capable.
    'int' => [ScalarT::int(), true],
    'int literal' => [new LiteralT(3), true],
    'array-key (int|string)' => [UnionT::of([ScalarT::int(), ScalarT::string()]), true],
    'union of int literals' => [UnionT::of([new LiteralT(1), new LiteralT(2)]), true],
    'union mixing an int literal in' => [UnionT::of([new LiteralT(1), new LiteralT('a')]), true],
    'union nesting an int a level down' => [UnionT::of([new LiteralT('a'), UnionT::of([ScalarT::int(), new NullT])]), true],
    // Not int-capable — string keys, other scalars, and everything we can't reason about.
    'string' => [ScalarT::string(), false],
    'string literal' => [new LiteralT('a'), false],
    'union of string literals' => [UnionT::of([new LiteralT('a'), new LiteralT('b')]), false],
    'float' => [ScalarT::float(), false],
    'float literal' => [new LiteralT(1.5), false],
    'bool' => [ScalarT::bool(), false],
    'bool literal' => [new LiteralT(true), false],
    'a class name' => [new ClassT('App\\Key'), false],
    'unknown' => [new UnknownT('mixed'), false],
]);

it('turns the key answer into the array DType, keeping the value either way', function (DType $key, string $expected): void {
    $type = ArrayKey::arrayOf($key, ScalarT::string());

    expect($type)->toBeInstanceOf($expected);

    // A list drops the key (a JSON array has none); a map keeps it, so `additionalProperties` and any
    // later re-reading of the key still have it.
    if ($type instanceof MapT) {
        expect($type->key)->toEqual($key)->and($type->value)->toEqual(ScalarT::string());
    } else {
        expect($type)->toEqual(new ListT(ScalarT::string()));
    }
})->with([
    'int key' => [ScalarT::int(), ListT::class],
    'array-key key' => [UnionT::of([ScalarT::int(), ScalarT::string()]), ListT::class],
    'string key' => [ScalarT::string(), MapT::class],
    'unreasonable key' => [new UnknownT('mixed'), MapT::class],
]);
