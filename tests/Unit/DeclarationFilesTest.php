<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Tests\Fixtures\Declaration\BaseShape;
use Docuccino\Core\Tests\Fixtures\Declaration\ChildShape;
use Docuccino\Core\Tests\Fixtures\Declaration\DeepTrait;
use Docuccino\Core\Tests\Fixtures\Declaration\LoneShape;
use Docuccino\Core\Tests\Fixtures\Declaration\OuterTrait;

/*
 * Which files a class's declaration spans. Real reflection over real fixtures, because the whole point
 * is what PHP reports about inheritance and trait flattening — a hand-built answer would prove nothing.
 */

/** The fixture directory's copy of a file, as reflection reports it. */
function declarationFixture(string $basename): string
{
    return dirname(__DIR__).'/Fixtures/Declaration/'.$basename.'.php';
}

it('names its own file, its parents and every trait flattened into any of them', function (): void {
    // `deep` reaches ChildShape through OuterTrait, through BaseShape — and PHP reports it as declared
    // by BaseShape, so no per-property walk could ever name DeepTrait.php.
    expect(DeclarationFiles::of(ChildShape::class))->toEqualCanonicalizing([
        declarationFixture('ChildShape'),
        declarationFixture('BaseShape'),
        declarationFixture('OuterTrait'),
        declarationFixture('DeepTrait'),
    ]);
});

it('names only its own file for a class that inherits nothing', function (): void {
    // The list is a function of the declaration, not a blanket over the fixture directory.
    expect(DeclarationFiles::of(LoneShape::class))->toBe([declarationFixture('LoneShape')]);
});

it('answers for a trait as it does for a class', function (): void {
    expect(DeclarationFiles::of(OuterTrait::class))
        ->toEqualCanonicalizing([declarationFixture('OuterTrait'), declarationFixture('DeepTrait')])
        ->and(DeclarationFiles::of(DeepTrait::class))->toBe([declarationFixture('DeepTrait')]);
});

it('degrades to nothing for a name with no file to point at', function (?string $fqcn): void {
    // Every one of these reaches the product: an unresolvable action class, an interface-typed property
    // whose class was never autoloaded, a route with no action class at all, an internal class with no
    // source file. None may throw, and none may pretend to a dependency.
    expect(DeclarationFiles::of($fqcn))->toBe([]);
})->with([
    'a class that does not exist' => ['App\\Nothing\\AtAll'],
    'no class at all' => [null],
    'an empty name' => [''],
    // ArrayObject is compiled into PHP, so getFileName() is false all the way up.
    'an internal class with no source file' => [ArrayObject::class],
]);

it('leads with the class asked about, so a caller reading the first entry gets the subject', function (): void {
    expect(DeclarationFiles::of(ChildShape::class)[0])->toBe(declarationFixture('ChildShape'))
        ->and(DeclarationFiles::of(BaseShape::class)[0])->toBe(declarationFixture('BaseShape'));
});
