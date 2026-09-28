<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Schema\DeclaredReturnType;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Tests\Fixtures\Returns\Returning;
use Docuccino\Core\Tests\Fixtures\Returns\UsesTrait;
use Docuccino\Core\Tests\Fixtures\SampleStatus;
use Docuccino\Core\TypeGrammar\DocBlockReader;

/*
 * A method's DECLARED return type, which a body never carries where the declaration narrows it — a
 * relation's generic over `$this->morphTo()` is the case that asks. Names resolve as PHP resolves them in
 * the file the method is written in.
 */
it('reads the first @return type in tag precedence, and null when there is none', function (): void {
    $reader = new DocBlockReader;

    expect($reader->returnType("/**\n * @return list<int> the ids\n */"))->toBe('list<int>')
        ->and($reader->returnType("/**\n * @return int\n * @phpstan-return positive-int\n */"))->toBe('positive-int')
        ->and($reader->returnType("/**\n * @return int\n * @psalm-return non-empty-string\n */"))->toBe('non-empty-string')
        ->and($reader->returnType("/**\n * Just prose.\n */"))->toBeNull()
        ->and($reader->returnType(null))->toBeNull();
});

it('resolves a declared return against the imports of the file the method is written in', function (string $method, ?object $expected): void {
    $class = $method === 'fromTrait' ? UsesTrait::class : Returning::class;

    expect(DeclaredReturnType::of(new ReflectionMethod($class, $method)))->toEqual($expected);
})->with([
    'an aliased import, a trailing description ignored' => ['aliased', new ListT(new EnumT(SampleStatus::class, ['Draft', 'Published']))],
    'the analyser-prefixed tag over the plain one, same-namespace names qualified' => ['prefixed', new ClassT(
        'Docuccino\\Core\\Tests\\Fixtures\\Returns\\Holder',
        [UnionT::of([new EnumT(SampleStatus::class, ['Draft', 'Published']), new ClassT('Docuccino\\Core\\Tests\\Fixtures\\Returns\\Sibling')]), ScalarT::int()],
    )],
    // A trait's method is reported as the using class's; its docblock is still read in the trait's file.
    'a trait method, read against the trait file' => ['fromTrait', new EnumT(SampleStatus::class, ['Draft', 'Published'])],
    'no docblock' => ['undeclared', null],
    'a docblock with no tag' => ['prose', null],
]);
