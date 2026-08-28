<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Extensions\Schema\SchemaIdentity;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Tests\Fixtures\Hidden\BlankNode;
use Docuccino\Core\Tests\Fixtures\Hidden\DerivedNode;
use Docuccino\Core\Tests\Fixtures\Hidden\NamelessNode;
use Docuccino\Core\Tests\Fixtures\Hidden\RenamedNode;
use Docuccino\Core\Tests\Fixtures\Hidden\RepeatedNode;
use Docuccino\Core\Tests\Fixtures\HiddenPropertyNode;
use Docuccino\Core\Tests\Support\StubTypeEngine;

/*
 * A class-level `#[Hidden]` naming no property of the schema. It is the only half of the deny-list that
 * CAN miss — the property form sits on the property — and a miss is silent in the worst direction, so
 * these pin what is reported, what is deliberately not, and that the report reaches the document.
 */

it('reports one name that matched nothing, and nothing else', function (string $fqcn, array $published, int $count, array $fragments): void {
    $messages = array_map(
        static fn (object $diagnostic): string => (string) $diagnostic->message,
        SchemaIdentity::unmatchedHidden($fqcn, $published),
    );

    expect($messages)->toHaveCount($count);

    foreach ($fragments as $fragment) {
        expect(implode("\n", $messages))->toContain($fragment);
    }
})->with([
    // The name is there: the deny-list did its job, and the property is judged against what the mapper
    // WEIGHED, so a name that hid something must not report itself.
    'a name that matches' => [HiddenPropertyNode::class, ['id', 'internal_score', 'password_hash'], 0, []],
    // The mistake this exists for: the property was renamed and the attribute kept the old spelling, so
    // the field it was written to keep out is published under the new one.
    'a name gone stale through a rename' => [RenamedNode::class, ['id', 'password_hash'], 1, ["#[Hidden('passwordHash')]", 'It publishes id, password_hash.']],
    // One mistake, said once — otherwise the reader goes looking for a second declaration.
    'the same name twice' => [RepeatedNode::class, ['id'], 1, ["#[Hidden('token')]"]],
    // A half-written declaration. No property can carry the empty string, so it is unmatched like any
    // other name rather than a shape of its own.
    'an empty name' => [BlankNode::class, ['id'], 1, ["#[Hidden('')]"]],
    // Nothing is denied, so there is no name that could be wrong.
    'a declaration naming nothing' => [NamelessNode::class, ['id'], 0, []],
    // PHP reads a class's OWN attributes: the base's deny-list never reaches the subclass, so there is
    // nothing here that hid nothing.
    'a declaration on the parent class' => [DerivedNode::class, ['id', 'secret'], 0, []],
]);

it('says the class publishes nothing rather than naming an empty list', function (): void {
    expect(SchemaIdentity::unmatchedHidden(RenamedNode::class, [])[0]->message)
        ->toContain('It publishes no properties at all.');
});

it('answers nothing for a class that cannot be loaded', function (): void {
    // The same total contract every other reader here has: an unloadable FQCN answers "nothing".
    expect(SchemaIdentity::unmatchedHidden('App\\Nope\\Missing', ['id']))->toBe([]);
});

it('caps the list it names and escapes what it did not write', function (): void {
    $published = ["a\x1b[31m", 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i'];

    $diagnostic = SchemaIdentity::unmatchedHidden(RenamedNode::class, $published)[0];

    expect($diagnostic->severity)->toBe(Severity::Warning)
        ->and($diagnostic->code)->toBe('attribute.hidden-unmatched')
        // A property name is recovered from the application's own code, so an escape in one would steer
        // the terminal it is printed to.
        ->and($diagnostic->message)->toContain('a\\x1B[31m')
        ->and($diagnostic->message)->not->toContain("\x1b")
        ->and($diagnostic->message)->toContain('and 1 more.')
        // Anti-vacuity: the entry that was cut really is one of the inputs, so a cap that stopped
        // cutting fails here rather than agreeing with any list at all.
        ->and($published)->toContain('i')
        ->and($diagnostic->message)->not->toContain(', i');
});

/**
 * The document a plain DTO produces, plus the diagnostics the conversion raised.
 *
 * @param  list<string>  $properties
 * @return array{0: array<string, mixed>, 1: list<string>}
 */
function hiddenConversion(string $fqcn, array $properties): array
{
    $engine = new StubTypeEngine(classes: [
        $fqcn => new ClassMetadata($fqcn, array_map(
            static fn (string $name): PropertyMetadata => new PropertyMetadata($name, ScalarT::string()),
            $properties,
        )),
    ]);

    $registry = new ComponentRegistry;
    (new SchemaConverter(DefaultTypeMappers::all(), $engine, $registry))->toSchema(new ClassT($fqcn));

    return [
        $registry->schemas(),
        array_map(static fn (object $diagnostic): string => (string) $diagnostic->code, $registry->diagnostics()),
    ];
}

it('carries the report out of the fallback mapper with the property still published', function (): void {
    // End to end through the one mapper a plain DTO reaches: the report is only worth having if it
    // travels, and the property it names is in the document beside it, which is the whole point.
    [$schemas, $codes] = hiddenConversion(RenamedNode::class, ['id', 'password_hash']);

    expect($codes)->toContain('attribute.hidden-unmatched')
        ->and(array_keys($schemas['RenamedNode']['properties']))->toBe(['id', 'password_hash']);
});

it('stays silent, and hides nothing, where the deny-list is on the parent class', function (): void {
    // Both halves of the same fact, because either alone would be a lie: nothing was hidden, so nothing
    // is missing that a report could have been about.
    [$schemas, $codes] = hiddenConversion(DerivedNode::class, ['id', 'secret']);

    expect($codes)->toBe([])
        ->and(array_keys($schemas['DerivedNode']['properties']))->toBe(['id', 'secret']);
});

it('says nothing where the deny-list worked', function (): void {
    [, $codes] = hiddenConversion(HiddenPropertyNode::class, ['id', 'internal_score', 'password_hash']);

    expect($codes)->toBe([]);
});

it('is asked only by the mappers whose property list is the class\'s own declaration', function (): void {
    // Stated in SchemaIdentity and unenforceable there: core cannot tell an Eloquent model from a DTO,
    // and a model's columns are recovered evidence rather than declarations, so a name outside them is
    // usually an undocumented column and the report would send its reader to delete the very entry
    // protecting it. A new caller is a decision somebody makes rather than a warning that quietly starts
    // firing on models.
    $root = dirname(__DIR__, 4);
    $callers = [];

    foreach (['core', 'laravel'] as $package) {
        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/php/'.$package.'/src', FilesystemIterator::SKIP_DOTS)),
            '/\.php$/',
        );

        foreach ($files as $file) {
            $path = (string) $file;
            if (str_contains((string) file_get_contents($path), 'unmatchedHidden(')) {
                $callers[] = basename($path);
            }
        }
    }

    sort($callers);

    expect($callers)->toBe([
        'ClassTypeToSchema.php',
        'DataSchema.php',
        'SchemaIdentity.php',
    ]);
});

it('names every place that reads #[Hidden] off reflection rather than through the owner', function (): void {
    // A second reader of the deny-list is free to disagree with the one that reports a name as
    // unmatched — which is how the Eloquent reflector's own copy came to exist, and it is gone. The one
    // remaining is the PROPERTY form in the Data reflector, which also has to accept spatie's own
    // `#[Hidden]` and so cannot be SchemaIdentity's. Adding a row here is a decision somebody makes.
    $root = dirname(__DIR__, 4);
    $readers = [];
    $mentions = 0;

    foreach (['core', 'laravel'] as $package) {
        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/php/'.$package.'/src', FilesystemIterator::SKIP_DOTS)),
            '/\.php$/',
        );

        foreach ($files as $file) {
            $path = (string) $file;
            $contents = (string) file_get_contents($path);

            if (! str_contains($contents, 'Hidden::class')) {
                continue;
            }

            $mentions++;

            // Any spelling of the argument, aliased or fully qualified: a guard that reads a narrower
            // grammar than the call it guards is a hole, and `\Docuccino\Attributes\Hidden::class`
            // walks straight through one that only knows the aliases.
            if (preg_match('/getAttributes\(\s*[\\\\A-Za-z0-9_]*Hidden::class/', $contents) === 1) {
                $readers[] = basename($path);
            }
        }
    }

    sort($readers);

    // Anti-vacuity: a scan that stopped seeing the attribute at all would agree with an empty list.
    expect($mentions)->toBeGreaterThanOrEqual(2)
        ->and($readers)->toBe([
            'DataClassReflector.php',
            'SchemaIdentity.php',
        ]);
});
