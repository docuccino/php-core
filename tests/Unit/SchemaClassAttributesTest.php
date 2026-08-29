<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Schema\SchemaClassAttributes;
use Docuccino\Core\Tests\Fixtures\HonouredDeclarationsNode;
use Docuccino\Core\Tests\Fixtures\MisreadDeclarationsNode;

/**
 * `Attribute::TARGET_CLASS` means an action class or a schema class, and only a handful of the
 * attributes are read on the second. The tables in `SchemaClassAttributes` say which, and these guards
 * keep them exhaustive over what the attributes package actually SHIPS — an attribute added there with
 * no decision made about it fails here rather than becoming the twenty-eighth silent drop.
 */

/**
 * Every attribute the package ships whose flags admit a class, derived from the package. By the
 * `#[Attribute]` FLAGS rather than by a source grep, because `TARGET_CLASS_CONSTANT` contains
 * `TARGET_CLASS` as a substring and a grep counts `#[CaseDescription]` as a class attribute.
 *
 * @return list<class-string>
 */
function shippedClassTargetAttributes(): array
{
    $shipped = [];

    foreach (glob(dirname(__DIR__, 3).'/attributes/src/*.php') ?: [] as $file) {
        /** @var class-string $class */
        $class = 'Docuccino\\Attributes\\'.basename($file, '.php');

        foreach ((new ReflectionClass($class))->getAttributes(Attribute::class) as $marker) {
            /** @var Attribute $flags */
            $flags = $marker->newInstance();

            if (($flags->flags & Attribute::TARGET_CLASS) === Attribute::TARGET_CLASS) {
                $shipped[] = $class;
            }
        }
    }

    sort($shipped);

    return $shipped;
}

/**
 * The guard itself, as a function so it can be RUN against a set it should refuse rather than only
 * asserted about: the class-target attributes nobody has decided a schema class's answer for.
 *
 * @param  list<class-string>  $shipped
 * @return list<string>
 */
function undecidedSchemaClassAttributes(array $shipped): array
{
    return array_values(array_diff(
        $shipped,
        array_keys(SchemaClassAttributes::HONOURED),
        array_keys(SchemaClassAttributes::ELSEWHERE),
    ));
}

it('decides every class-target attribute the package ships', function (): void {
    $shipped = shippedClassTargetAttributes();

    // A scan that stopped recognising the flag would pass forever with an empty set.
    expect(count($shipped))->toBeGreaterThanOrEqual(20)
        ->and(undecidedSchemaClassAttributes($shipped))->toBe([]);
});

it('refuses an attribute nobody has decided about', function (): void {
    // The guard EXECUTED rather than asserted: a new class-target attribute lands in neither table, and
    // this is the failure it has to produce there.
    $withNewcomer = [...shippedClassTargetAttributes(), 'Docuccino\\Attributes\\Imaginary'];

    expect(undecidedSchemaClassAttributes($withNewcomer))->toBe(['Docuccino\\Attributes\\Imaginary']);
});

it('classifies each class-target attribute exactly once', function (): void {
    expect(array_intersect_key(SchemaClassAttributes::HONOURED, SchemaClassAttributes::ELSEWHERE))->toBe([]);
});

it('classifies nothing the package does not target at a class', function (): void {
    $shipped = shippedClassTargetAttributes();

    $classified = [...array_keys(SchemaClassAttributes::HONOURED), ...array_keys(SchemaClassAttributes::ELSEWHERE)];
    sort($classified);

    // Both directions: a row for an attribute that stopped targeting classes — or was deleted — is a
    // table describing something that is not there any more.
    expect($classified)->toBe($shipped);
});

it('says where every unread attribute IS read, in words that finish the sentence', function (string $attribute, string $where): void {
    expect(class_exists($attribute))->toBeTrue()
        ->and($where)->toStartWith('on ');
})->with(array_map(
    static fn (string $attribute): array => [$attribute, SchemaClassAttributes::ELSEWHERE[$attribute]],
    array_keys(SchemaClassAttributes::ELSEWHERE),
));

it('reports every unread declaration on a class, once each, and nothing else', function (): void {
    $diagnostics = SchemaClassAttributes::unread(MisreadDeclarationsNode::class);

    $reported = array_map(
        static fn (Diagnostic $d): string => (string) preg_replace('/^The #\[(\w+)].*$/', '$1', $d->message),
        $diagnostics,
    );
    sort($reported);

    $expected = array_map(
        static fn (string $attribute): string => substr($attribute, strlen('Docuccino\\Attributes\\')),
        array_keys(SchemaClassAttributes::ELSEWHERE),
    );
    sort($expected);

    // Every ELSEWHERE row, through real reflection — and the two `#[QueryParameter]`s on the fixture are
    // ONE mistake, so a repeatable attribute reports once rather than once per declaration.
    expect($reported)->toBe($expected);

    foreach ($diagnostics as $diagnostic) {
        expect($diagnostic->severity)->toBe(Severity::Warning)
            ->and($diagnostic->code)->toBe('attribute.schema-class-unread');
    }
});

it('names the class and where the declaration belongs', function (): void {
    $summary = array_values(array_filter(
        SchemaClassAttributes::unread(MisreadDeclarationsNode::class),
        static fn (Diagnostic $d): bool => str_contains($d->message, '#[Summary]'),
    ))[0];

    expect($summary->message)->toBe(
        'The #[Summary] on '.MisreadDeclarationsNode::class.' is not read on a type; it was ignored.',
    )->and($summary->help)->toBe(
        '#[Summary] is read on the action. A type is read for #[Description], #[SchemaName], '
        .'#[SchemaId], #[Hidden], #[Mock] and #[BodyParameter].',
    );
});

it('says nothing about the attributes a type IS read for, or about a foreign one', function (): void {
    expect(SchemaClassAttributes::unread(HonouredDeclarationsNode::class))->toBe([]);
});

it('says nothing about a class it cannot load', function (): void {
    expect(SchemaClassAttributes::unread('App\\Nowhere\\Missing'))->toBe([]);
});
