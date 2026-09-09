<?php

declare(strict_types=1);

use Docuccino\Core\Config\ConfigFile;
use Docuccino\Core\Config\WritableSettings;
use Docuccino\Core\Emit\YamlSerializer;
use Docuccino\Core\Support\Json;

/**
 * What a `docuccino.yaml` can be written FROM.
 *
 * The verdict per kind of value is TYPED OUT below rather than asked of the code, and the reason each
 * one is what it is gets its own assertion: what the writer produces for a value the file cannot carry,
 * and what the reader then does with it. The pair is the whole defect — a writer emitting `!php/enum`
 * at a reader configured to refuse it — so a test that only compared the predicate against itself would
 * agree with whichever half moved.
 */
enum WritableBackedEnum: string
{
    case Scalar = 'scalar';
}

enum WritablePureEnum
{
    case Scalar;
}

final class WritablePlainObject
{
    public int $x = 1;
}

it('says which values a configuration file can carry, and which it cannot', function (bool $carried, mixed $value): void {
    expect(WritableSettings::carries($value))->toBe($carried);
})->with([
    // Everything YAML has a form for, including the two the reader deliberately settles: an integral
    // float comes back as an int and that is not a loss, because `Json::stable()` is what decides value
    // identity everywhere else in the product and it fingerprints the two alike.
    'null' => [true, null],
    'a boolean' => [true, true],
    'an integer' => [true, 7],
    'zero' => [true, 0],
    'a float' => [true, 1.5],
    'an integral float' => [true, 1.0],
    'a float past the exact-integer limit' => [true, 1e20],
    'a string' => [true, 'semver'],
    'a string that looks like a number' => [true, '1.0'],
    'a string that looks like a boolean' => [true, 'no'],
    'the empty string' => [true, ''],
    'a multi-line string' => [true, "two\nlines\n"],
    'a list' => [true, ['a', 'b']],
    'a map' => [true, ['a' => 1]],
    'the empty array' => [true, []],
    'a stdClass, which the writer writes as a map' => [true, (object) ['a' => 1]],
    // And everything it has none for. Three different wrong answers, which is why the predicate is a
    // round trip and not a list of types: the enums are REFUSED by the reader, the closure, the
    // resource and the plain object come back as null, and the date comes back as an integer. The
    // non-finite floats are the one pair NOT probed: the round trip answers them differently on
    // different symfony/yaml minors, so they are refused outright rather than asked about.
    'a backed enum case' => [false, WritableBackedEnum::Scalar],
    'a pure enum case' => [false, WritablePureEnum::Scalar],
    'an object of an ordinary class' => [false, new WritablePlainObject],
    'a date' => [false, new DateTimeImmutable('2020-01-01')],
    'an empty stdClass, which stops being an object' => [false, new stdClass],
    'not a number' => [false, NAN],
    'an infinity' => [false, INF],
    // Nesting does not launder one: a list is carried whole or not at all.
    'a list holding an enum case' => [false, [WritableBackedEnum::Scalar]],
    'a map holding a date' => [false, ['at' => new DateTimeImmutable('2020-01-01')]],
]);

it('carries a closure or a resource nowhere', function (): void {
    // Kept out of the dataset above: a closure and a resource have no place in one, and these are the
    // two shapes an application really writes — a route filter and an already-open stream.
    expect(WritableSettings::carries(fn (): int => 1))->toBeFalse()
        ->and(WritableSettings::carries(STDOUT))->toBeFalse()
        ->and(WritableSettings::carries([['at' => fn (): int => 1]]))->toBeFalse();
});

it('writes an enum as a tag the reader is configured to refuse', function (): void {
    // The two halves of the defect, stated against each other. The flag that refuses this is
    // deliberate, and the writer knew nothing about it.
    $written = (new YamlSerializer)->serialize(['versioning' => WritableBackedEnum::Scalar]);

    expect($written)->toContain('!php/enum')
        ->and(ConfigFile::parse($written)->error)->toBe(ConfigFile::INVALID);
});

it('writes a closure, a resource and a date as something else entirely', function (): void {
    // The quiet half. Nothing refuses these — the file parses, and the value in it is not the one the
    // application configured.
    $closure = ConfigFile::parse((new YamlSerializer)->serialize(['at' => fn (): int => 1]));
    $resource = ConfigFile::parse((new YamlSerializer)->serialize(['at' => STDOUT]));
    $date = ConfigFile::parse((new YamlSerializer)->serialize(['at' => new DateTimeImmutable('2020-01-01')]));

    expect($closure->error)->toBeNull()
        ->and($closure->values['at'])->toBeNull()
        ->and($resource->values['at'])->toBeNull()
        ->and($date->error)->toBeNull()
        ->and($date->values['at'])->toBeInt();
});

it('agrees with the reader about every value it says a file can carry', function (): void {
    // The predicate and the write-then-read pair are one fact, and this states the pair independently:
    // for each value the predicate accepts, the reader really does hand it back.
    $carried = [null, true, 7, 1.5, 1.0, 'semver', '1.0', "two\nlines\n", ['a'], ['a' => 1], []];

    foreach ($carried as $value) {
        $file = ConfigFile::parse((new YamlSerializer)->serialize(['at' => $value]));

        expect($file->error)->toBeNull()
            ->and($file->diagnostics)->toBe([])
            ->and(Json::stable($file->values['at']))->toBe(Json::stable($value));
    }
});

it('keeps the settings it can write and names the paths it cannot', function (): void {
    $split = WritableSettings::of([
        'documents' => [
            'default' => [
                'versioning' => WritableBackedEnum::Scalar,
                'info' => ['title' => 'Billing', 'version' => new DateTimeImmutable('2020-01-01')],
                'tags' => ['mapper' => fn (string $t): string => $t],
            ],
        ],
        'on_route_error' => 'omit',
    ]);

    expect($split->settings)->toBe([
        'documents' => ['default' => ['info' => ['title' => 'Billing'], 'tags' => []]],
        'on_route_error' => 'omit',
    ])->and($split->unwritable)->toBe([
        'documents.default.versioning',
        'documents.default.info.version',
        'documents.default.tags.mapper',
    ]);
});

it('drops one key of a map and the whole of a list', function (): void {
    // Different because the reader cannot tell a short list from the list somebody configured, where an
    // absent map key is a setting nobody expressed and takes its documented default.
    $split = WritableSettings::of([
        'overlays' => ['resources/a.yaml', new WritablePlainObject],
        'lint' => ['leakage' => ['enabled' => true, 'allow' => ['reset_token']], 'at' => NAN],
    ]);

    expect($split->settings)->toBe([
        'lint' => ['leakage' => ['enabled' => true, 'allow' => ['reset_token']]],
    ])->and($split->unwritable)->toBe(['overlays', 'lint.at']);
});

it('leaves a bag that was already empty alone', function (): void {
    // A document declaring nothing but a viewer arrives as an empty bag, and dropping the KEY would
    // delete the document rather than one of its settings.
    expect(WritableSettings::of(['documents' => ['public' => []]]))
        ->settings->toBe(['documents' => ['public' => []]])
        ->unwritable->toBe([]);
});

it('reads a written file back as the settings it was written from, or says it does not', function (): void {
    $settings = ['documents' => ['default' => ['info' => ['title' => 'Billing', 'version' => '1.0']]]];

    expect(WritableSettings::reads((new YamlSerializer)->serialize($settings), $settings))->toBeTrue()
        // Text the reader refuses outright.
        ->and(WritableSettings::reads("versioning: !php/enum Foo::Bar\n", ['versioning' => 'x']))->toBeFalse()
        // Text it reads, and to something other than a map of settings.
        ->and(WritableSettings::reads("- a\n", ['0' => 'a']))->toBeFalse()
        // Text it reads with a diagnostic, which is a setting it could not use.
        ->and(WritableSettings::reads("at: .NaN\n", ['at' => NAN]))->toBeFalse()
        // Text it reads cleanly, as different settings.
        ->and(WritableSettings::reads("at: 1\n", ['at' => 2]))->toBeFalse();
});
