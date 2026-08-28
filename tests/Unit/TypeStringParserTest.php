<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\IntersectionT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Tests\Fixtures\PinnedRequestClass;
use Docuccino\Core\Tests\Fixtures\SampleStatus;
use Docuccino\Core\TypeGrammar\ImportContext;
use Docuccino\Core\TypeGrammar\TypeStringParser;

/**
 * Covers every branch of the phpdoc type-grammar → {@see DType} mapping the attribute layer relies on
 * (`#[Response(type: '…')]` etc.). Silent misparses here mis-document a whole response body, so the
 * identifier-alias table, generic collection forms, array shapes, const literals, and the degradation
 * branches each get an assertion. Kind + salient payload is asserted per branch.
 */
function parseType(string $type): mixed
{
    return (new TypeStringParser)->parse($type);
}

/**
 * The identifier keywords the grammar answers a scalar (or null) for, and the DType each one lands on.
 * Named rather than inlined because three tests read the same set: this table, the declared/inferred
 * agreement, and the guard that holds the pair of tables to `mapIdentifier()`'s own arms.
 *
 * @return array<string, array{string, class-string}>
 */
function typeGrammarScalarRows(): array
{
    return [
        // int family
        'int' => ['int', ScalarT::class],
        'integer' => ['integer', ScalarT::class],
        'positive-int' => ['positive-int', ScalarT::class],
        'negative-int' => ['negative-int', ScalarT::class],
        'non-negative-int' => ['non-negative-int', ScalarT::class],
        // string family
        'string' => ['string', ScalarT::class],
        'non-empty-string' => ['non-empty-string', ScalarT::class],
        'class-string' => ['class-string', ScalarT::class],
        'numeric-string' => ['numeric-string', ScalarT::class],
        // float family
        'float' => ['float', ScalarT::class],
        'double' => ['double', ScalarT::class],
        'number' => ['number', ScalarT::class],
        // bool family
        'bool' => ['bool', ScalarT::class],
        'boolean' => ['boolean', ScalarT::class],
        'true' => ['true', ScalarT::class],
        'false' => ['false', ScalarT::class],
        // null
        'null' => ['null', NullT::class],
    ];
}

/**
 * The identifier keywords that name no shape the document can use, and the reason each degrades with.
 *
 * @return array<string, array{string, string}>
 */
function typeGrammarImpreciseRows(): array
{
    return [
        'array' => ['array', 'untyped array'],
        'iterable' => ['iterable', 'untyped array'],
        'list' => ['list', 'untyped array'],
        'mixed' => ['mixed', 'mixed'],
        'object' => ['object', 'object'],
        'void' => ['void', 'void'],
        'never' => ['never', 'never'],
        'callable' => ['callable', 'callable'],
        'scalar' => ['scalar', 'scalar'],
    ];
}

/**
 * Every identifier keyword this file tests, from the two tables above.
 *
 * @return list<string>
 */
function typeGrammarTestedKeywords(): array
{
    $keywords = array_map(
        static fn (array $row): string => $row[0],
        [...array_values(typeGrammarScalarRows()), ...array_values(typeGrammarImpreciseRows())],
    );

    // Its answer is a union rather than a kind, so neither table above fits it and it has an
    // assertion of its own further down.
    $keywords[] = 'array-key';

    sort($keywords);

    return $keywords;
}

/**
 * Every identifier keyword `TypeStringParser::mapIdentifier()` names, read off the match arms themselves.
 *
 * Tokenised rather than grepped, for the reason the boundary scans tokenise: a line-oriented read of a
 * match arm is a narrower grammar than PHP's, and the literals it wants are the ones in CONDITION
 * position — the reasons `UnknownT('mixed')` carries on the other side of the `=>` are not keywords.
 *
 * @return list<string>
 */
function typeGrammarSourceKeywords(): array
{
    $tokens = significantTokens((string) file_get_contents(dirname(__DIR__, 2).'/src/TypeGrammar/TypeStringParser.php'));

    $start = null;
    foreach ($tokens as $index => $token) {
        if ($token->is(T_MATCH) && $start === null && typeGrammarInMapIdentifier($tokens, $index)) {
            $start = $index;
        }
    }

    if ($start === null) {
        return [];
    }

    $keywords = [];
    $depth = 0;
    $body = false;
    $condition = false;

    for ($index = $start, $count = count($tokens); $index < $count; $index++) {
        $token = $tokens[$index];

        if (in_array($token->text, ['{', '(', '['], true)) {
            $depth++;
            if (! $body && $token->text === '{') {
                $body = true;
                $condition = true;
            }

            continue;
        }

        if (in_array($token->text, ['}', ')', ']'], true)) {
            $depth--;
            if ($body && $depth === 0) {
                break;
            }

            continue;
        }

        if (! $body || $depth !== 1) {
            continue;
        }

        // An arm's condition runs to its `=>`; the next `,` at this depth opens the following one.
        if ($token->is(T_DOUBLE_ARROW)) {
            $condition = false;
        } elseif ($token->text === ',') {
            $condition = true;
        } elseif ($condition && $token->is(T_CONSTANT_ENCAPSED_STRING)) {
            $keywords[] = trim($token->text, "'\"");
        }
    }

    sort($keywords);

    return $keywords;
}

/**
 * Whether the `match` at $index is the one `mapIdentifier()` is — the method before it, with no other
 * method declared in between.
 *
 * @param  list<PhpToken>  $tokens
 */
function typeGrammarInMapIdentifier(array $tokens, int $index): bool
{
    for ($back = $index - 1; $back >= 0; $back--) {
        if ($tokens[$back]->is(T_FUNCTION)) {
            return ($tokens[$back + 1]->text ?? null) === 'mapIdentifier';
        }
    }

    return false;
}

it('maps every scalar identifier alias to its DType', function (string $type, string $expected): void {
    expect(parseType($type)::class)->toBe($expected);
})->with(typeGrammarScalarRows());

it('resolves the scalar kinds precisely', function (): void {
    expect(parseType('int'))->toEqual(ScalarT::int())
        ->and(parseType('string'))->toEqual(ScalarT::string())
        ->and(parseType('float'))->toEqual(ScalarT::float())
        ->and(parseType('bool'))->toEqual(ScalarT::bool());
});

it('degrades imprecise identifiers to a reasoned UnknownT', function (string $type, string $reason): void {
    $result = parseType($type);
    expect($result)->toBeInstanceOf(UnknownT::class)
        ->and($result->reason)->toBe($reason);
})->with(typeGrammarImpreciseRows() + [
    // Not an identifier, so not part of the keyword list the guard below holds to the source.
    'empty string' => ['', 'empty type string'],
]);

/**
 * The datasets above are hand-maintained, and a hand-maintained full set is only as good as its last
 * edit: an arm added to `mapIdentifier()` with no row beside it ships untested, and the agreement test
 * below quietly narrows from a universal to whatever happens to be listed. So the source of truth reads
 * its own source — the match arms — and fails when the enumeration is short.
 */
it('lists every identifier keyword the parser names', function (): void {
    expect(typeGrammarSourceKeywords())->toBe(typeGrammarTestedKeywords())
        // The scan is the only reader of that rule, so it says out loud that it still sees arms at all:
        // one that stopped recognising them would agree with an empty list forever.
        ->and(count(typeGrammarSourceKeywords()))->toBeGreaterThanOrEqual(20);
});

/*
 * The author's vocabulary, which parts company with the analyser's on one word. `object` inferred from
 * PHP source is an instance of something whose wire shape is genuinely unknown, and stays an open schema
 * above; written by hand in a declaration it is the JSON word, and a declaration outranks inference.
 * Everything else is the same grammar, which is the half a regression would break silently.
 */
it('reads a declared `object` as the free-form map, wherever in the type string it appears', function (string $type, mixed $expected): void {
    expect((new TypeStringParser)->parseDeclared($type))->toEqual($expected);
})->with(function (): array {
    $map = new MapT(ScalarT::string(), new UnknownT('mixed'));

    return [
        'the bare word' => ['object', $map],
        'spelled with capitals' => ['OBJECT', $map],
        'padded' => [' object ', $map],
        'nullable' => ['?object', UnionT::of([$map, new NullT])],
        'a union member' => ['object|null', UnionT::of([$map, new NullT])],
        'a generic argument' => ['list<object>', new ListT($map)],
        'an array shorthand element' => ['object[]', new ListT($map)],
    ];
});

it('leaves every other declared type to the one grammar', function (string $type): void {
    // Compared against `parse()` itself rather than against a second expectation, so the declared
    // reading cannot quietly fork from the inferred one for anything but the word above.
    $parser = new TypeStringParser;

    expect($parser->parseDeclared($type))->toEqual($parser->parse($type));
})->with(function (): array {
    $cases = [];

    // EVERY keyword the identifier table names, minus the one word that is allowed to differ — a
    // hand-picked handful would leave the rest free to fork without anything noticing.
    foreach (typeGrammarTestedKeywords() as $keyword) {
        if ($keyword !== 'object') {
            $cases[$keyword] = [$keyword];
        }
    }

    // And the node kinds that never reach the identifier table at all: a composite, a generic, a shape,
    // a const literal, a class name, and the empty string the parser answers before it parses anything.
    // `array` is deliberately NOT the object word: a PHP array is a JSON array or a JSON object, which
    // is the very ambiguity the rule vocabulary has, so it decides nothing an author could be held to.
    $composites = [
        'a nullable' => '?int',
        'a union' => 'int|string',
        'an intersection' => 'Countable&Traversable',
        'a generic list' => 'list<int>',
        'a keyed generic array' => 'array<string, mixed>',
        'the square-bracket shorthand' => 'int[]',
        'a bounded int' => 'int<0, max>',
        'an array shape' => 'array{id: int, name?: string}',
        'a const string literal' => "'draft'",
        'a const int literal' => '42',
        'a class name' => 'App\\Models\\User',
        'an enum name' => SampleStatus::class,
        'the empty string' => '',
    ];

    foreach ($composites as $label => $type) {
        $cases[$label] = [$type];
    }

    return $cases;
});

/**
 * And it has ONE way in. As a promoted constructor parameter the mode was a second, public one — on a
 * class carrying no `@internal` marker, so `new TypeStringParser($stack, declared: true)` was API — and
 * nothing in the packages ever set it, which left `parseDeclared()`'s own branch for a pre-flagged
 * instance unreachable. A reading that outranks inference is worth guarding the entrance to.
 */
it('offers one way in to the declared reading', function (): void {
    $constructor = (new ReflectionClass(TypeStringParser::class))->getConstructor();

    expect(array_map(
        static fn (ReflectionParameter $parameter): string => $parameter->getName(),
        $constructor?->getParameters() ?? [],
    ))->toBe(['stack']);
});

it('keeps `object` an open schema when a type string was inferred rather than declared', function (): void {
    // The other half, and the one that keeps the split honest: an analyser reading `object` off PHP
    // source has learnt nothing about the wire, and a `JsonSerializable` may make it anything.
    expect(parseType('object'))->toEqual(new UnknownT('object'));
});

it('treats an unknown bareword as a class reference and strips a leading slash', function (): void {
    expect(parseType('App\\Models\\User'))->toEqual(new ClassT('App\\Models\\User'))
        ->and(parseType('\\App\\Models\\User'))->toEqual(new ClassT('App\\Models\\User'));
});

it('maps generic list and array forms to List/Map DTypes', function (): void {
    expect(parseType('list<int>'))->toEqual(new ListT(ScalarT::int()))
        ->and(parseType('non-empty-list<string>'))->toEqual(new ListT(ScalarT::string()))
        ->and(parseType('array<int>'))->toEqual(new ListT(ScalarT::int()))
        ->and(parseType('array<string, int>'))->toEqual(new MapT(ScalarT::string(), ScalarT::int()))
        ->and(parseType('non-empty-array<string, int>'))->toEqual(new MapT(ScalarT::string(), ScalarT::int()));
});

it('maps the square-bracket array shorthand to a List DType', function (): void {
    expect(parseType('int[]'))->toEqual(new ListT(ScalarT::int()));
});

it('decides list vs map on the key type of every keyed array form', function (string $type, mixed $expected): void {
    // Only a string key makes a PHP array serialize to a JSON object, so an int-capable key is a JSON
    // array. Every key identifier the grammar can produce is here, plus the unreasonable ones, which
    // degrade to a map rather than guess a list. The rule itself is core's ArrayKey, which the engine's
    // translator calls too (ArrayKeyTest); this dataset pins what the GRAMMAR hands it.
    expect(parseType($type))->toEqual($expected);
})->with([
    // int-capable keys → a list, and the value type is what survives.
    'int' => ['array<int, string>', new ListT(ScalarT::string())],
    'integer' => ['array<integer, string>', new ListT(ScalarT::string())],
    'positive-int' => ['array<positive-int, string>', new ListT(ScalarT::string())],
    'negative-int' => ['array<negative-int, string>', new ListT(ScalarT::string())],
    'non-negative-int' => ['array<non-negative-int, string>', new ListT(ScalarT::string())],
    'int range' => ['array<int<0, max>, string>', new ListT(ScalarT::string())],
    'int-mask' => ['array<int-mask<1, 2>, string>', new ListT(ScalarT::string())],
    'array-key' => ['array<array-key, string>', new ListT(ScalarT::string())],
    'int|string' => ['array<int|string, string>', new ListT(ScalarT::string())],
    'int literal' => ['array<0, string>', new ListT(ScalarT::string())],
    'int literal union' => ["array<0|'a', string>", new ListT(ScalarT::string())],
    // string-only keys → a map, which keeps the key type.
    'string' => ['array<string, int>', new MapT(ScalarT::string(), ScalarT::int())],
    'non-empty-string' => ['array<non-empty-string, int>', new MapT(ScalarT::string(), ScalarT::int())],
    'class-string' => ['array<class-string, int>', new MapT(ScalarT::string(), ScalarT::int())],
    // A numeric-string key is written by an author thinking in strings; PHP's runtime cast of `'1'` to an
    // int key is not something a declared type says, so the declaration is taken at its word.
    'numeric-string' => ['array<numeric-string, int>', new MapT(ScalarT::string(), ScalarT::int())],
    'string literal' => ["array<'a', int>", new MapT(new LiteralT('a'), ScalarT::int())],
    'string literal union' => ["array<'a'|'b', int>", new MapT(UnionT::of([new LiteralT('a'), new LiteralT('b')]), ScalarT::int())],
    // Keys we cannot reason about degrade to a map, the shape that survives being wrong about ordering.
    'mixed' => ['array<mixed, int>', new MapT(new UnknownT('mixed'), ScalarT::int())],
    'template parameter' => ['array<TKey, int>', new MapT(new ClassT('TKey'), ScalarT::int())],
]);

it('applies the same key rule to every generic array spelling', function (string $type, mixed $expected): void {
    expect(parseType($type))->toEqual($expected);
})->with([
    'iterable list' => ['iterable<int, string>', new ListT(ScalarT::string())],
    'iterable map' => ['iterable<string, string>', new MapT(ScalarT::string(), ScalarT::string())],
    'non-empty-array list' => ['non-empty-array<int, string>', new ListT(ScalarT::string())],
    'non-empty-array map' => ['non-empty-array<string, string>', new MapT(ScalarT::string(), ScalarT::string())],
]);

it('reads array-key as the int|string union it is', function (): void {
    // It has to be in the identifier table, or it falls through and builds a bogus `ClassT('array-key')`.
    expect(parseType('array-key'))->toEqual(UnionT::of([ScalarT::int(), ScalarT::string()]));
});

it('maps a bounded or masked int to a plain int', function (string $type): void {
    expect(parseType($type))->toEqual(ScalarT::int());
})->with([
    'int range' => ['int<0, max>'],
    'int range with bounds' => ['int<1, 5>'],
    'int-mask' => ['int-mask<1, 2, 4>'],
    'int-mask-of' => ['int-mask-of<int>'],
]);

it('maps a backed-enum name to an EnumT carrying its case names', function (): void {
    // The same answer the reflection and PHPStan-type mappers give, so a column whose only declaration is
    // a docblock (`@property ListingStatus $status`) documents as a string enum, not as an object of the
    // enum's own `name`/`value` members.
    expect(parseType(SampleStatus::class))->toEqual(new EnumT(SampleStatus::class, ['Draft', 'Published']));
});

it('resolves a docblock enum through the import context, and degrades without one', function (): void {
    $parser = new TypeStringParser;
    $imports = ImportContext::forFile(dirname(__DIR__).'/Fixtures/ImportSample.php');

    // An import context qualifies the short name, so the enum is found…
    expect($parser->parse('SampleStatus', $imports))->toEqual(new EnumT(SampleStatus::class, ['Draft', 'Published']));

    // …and without one the short name resolves to nothing loadable, so it stays a ClassT rather than
    // throwing or inventing an empty enum.
    expect($parser->parse('SampleStatus'))->toEqual(new ClassT('SampleStatus'));
});

it('keeps a non-enum class name a ClassT', function (string $type): void {
    // The enum_exists() miss, for a class that loads and for a name that never will.
    expect(parseType($type))->toEqual(new ClassT($type));
})->with([
    'a loadable class' => [PinnedRequestClass::class],
    'a name nothing autoloads' => ['App\\Nope\\NotAThing'],
]);

it('degrades an over-parameterised array generic', function (): void {
    $result = parseType('array<int, string, bool>');
    expect($result)->toBeInstanceOf(UnknownT::class)
        ->and($result->reason)->toBe('untyped array');
});

it('keeps a user generic as a parameterised ClassT', function (): void {
    $result = parseType('Illuminate\\Support\\Collection<int, App\\Models\\User>');
    expect($result)->toBeInstanceOf(ClassT::class)
        ->and($result->fqcn)->toBe('Illuminate\\Support\\Collection')
        ->and($result->typeArgs)->toHaveCount(2)
        ->and($result->typeArgs[1])->toEqual(new ClassT('App\\Models\\User'));
});

it('maps a keyed array shape with optional and numeric keys', function (): void {
    $result = parseType('array{id: int, name?: string, 0: bool}');
    expect($result)->toBeInstanceOf(ArrayShapeT::class)
        ->and($result->fields)->toHaveCount(3);

    [$id, $name, $zero] = $result->fields;
    expect($id->key)->toBe('id')
        ->and($id->type)->toEqual(ScalarT::int())
        ->and($id->optional)->toBeFalse()
        ->and($name->key)->toBe('name')
        ->and($name->optional)->toBeTrue()
        ->and($zero->key)->toBe(0)
        ->and($zero->type)->toEqual(ScalarT::bool());
});

it('maps an unkeyed array shape to positional integer keys', function (): void {
    $result = parseType('array{int, string}');
    expect($result)->toBeInstanceOf(ArrayShapeT::class);
    [$first, $second] = $result->fields;
    expect($first->key)->toBe(0)
        ->and($second->key)->toBe(1);
});

it('maps const-expression literal types', function (): void {
    expect(parseType("'draft'"))->toEqual(new LiteralT('draft'))
        ->and(parseType('42'))->toEqual(new LiteralT(42))
        ->and(parseType('3.14'))->toEqual(new LiteralT(3.14))
        ->and(parseType('true'))->toEqual(ScalarT::bool());
});

it('maps nullable, union and intersection composites', function (): void {
    expect(parseType('?int'))->toEqual(UnionT::of([ScalarT::int(), new NullT]))
        ->and(parseType('int|string'))->toEqual(UnionT::of([ScalarT::int(), ScalarT::string()]));

    $intersection = parseType('Countable&Traversable');
    expect($intersection)->toBeInstanceOf(IntersectionT::class);
});

it('resolves unqualified class names against a file import context', function (): void {
    $imports = ImportContext::forFile(dirname(__DIR__).'/Fixtures/ImportSample.php');
    $parser = new TypeStringParser;

    // A union of unqualified short names is resolved through the file's `use` imports (one aliased).
    $union = $parser->parse('MfaChallengeData|Enrollment', $imports);
    expect($union)->toBeInstanceOf(UnionT::class);
    expect(array_map(static fn (ClassT $c): string => $c->fqcn, $union->members))
        ->toBe(['App\\Data\\MfaChallengeData', 'App\\Data\\MfaEnrollmentChallengeData']);

    // A name under an imported namespace prefix, a same-namespace name, and an absolute name.
    expect($parser->parse('Models\\User', $imports))->toEqual(new ClassT('App\\Models\\User'))
        ->and($parser->parse('LocalData', $imports))->toEqual(new ClassT('Docuccino\\Sample\\Http\\LocalData'))
        ->and($parser->parse('\\App\\Already\\Qualified', $imports))->toEqual(new ClassT('App\\Already\\Qualified'));
});

it('leaves short class names unqualified without an import context (back-compat)', function (): void {
    expect(parseType('MfaChallengeData'))->toEqual(new ClassT('MfaChallengeData'));
});
