<?php

declare(strict_types=1);

use Docuccino\Core\Config\ConfigFile;
use Docuccino\Core\Config\ConfigValues;
use Docuccino\Core\Diagnostics\Severity;

/**
 * The half of the reader that refuses. Every case here writes a value of the wrong type and checks
 * three things at once: the setting answers its documented default, a diagnostic names the setting and
 * the line to go and change, and the value the diagnostic SAYS was used is the value that was used.
 *
 * That last one is not padding. A message naming a fallback the reader does not return is worse than
 * no message, because it is checkable and wrong — and it is the bug this file found while it was being
 * written, where every refusal answered null and claimed the default.
 */
it('refuses a wrong type and answers the default it names', function (string $yaml, string $type, mixed $default, mixed $expected, string $found, string $required): void {
    $values = ConfigFile::parse($yaml)->values();

    $answer = match ($type) {
        'string' => $values->string('setting', $default),
        default => $values->entries('setting'),
    };

    $diagnostic = $values->diagnostics()[0] ?? null;

    expect($answer)->toBe($expected)
        ->and($values->diagnostics())->toHaveCount(1)
        ->and($diagnostic?->code)->toBe('config.value-type')
        ->and($diagnostic?->severity)->toBe(Severity::Warning)
        ->and($diagnostic?->message)->toBe(sprintf(
            'setting is %s, where the setting takes %s — %s is used instead.',
            $found,
            $required,
            $expected === null || $expected === [] ? 'the built-in default' : json_encode($expected, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
        ))
        ->and($diagnostic?->help)->not->toBe('');
})->with([
    // The trap the reader exists for. `(string) 1.1` publishes "1.1" where the author wrote 1.10 —
    // a different version number in a document somebody's client is generated from.
    'a version that became a float' => ["setting: 1.10\n", 'string', 'API', 'API', 'the decimal number 1.1', 'text'],
    // Settled to the int 1 before this reader sees it, so it is described as one — see
    // ConfigVersionStabilityTest for why the reader may not leave that spelling as a float. The
    // refusal is the same either way, which is the point: the answer does not depend on the parser.
    'a version that lost its zero' => ["setting: 1.0\n", 'string', 'API', 'API', 'the whole number 1', 'text'],
    'a whole number as text' => ["setting: 3\n", 'string', 'API', 'API', 'the whole number 3', 'text'],
    'a date as text' => ["setting: 2024-01-15\n", 'string', 'API', 'API', 'the whole number 1705276800', 'text'],
    'a boolean as text' => ["setting: true\n", 'string', 'API', 'API', 'the boolean true', 'text'],
    'a map as text' => ["setting:\n  a: 1\n", 'string', 'API', 'API', 'a map', 'text'],
    'a list as text' => ["setting: [a]\n", 'string', 'API', 'API', 'a list', 'text'],

    // Listness is all a list read judges, so the whole value is refused and the caller answers its own
    // built-in default — which is why the fallback this names is never a list of ours.
    'a map where a list belongs' => ["setting:\n  a: 1\n", 'entries', null, null, 'a map', 'a list'],
    'text where a list belongs' => ["setting: app\n", 'entries', null, null, 'the text "app"', 'a list'],
    'a number where a list belongs' => ["setting: 5\n", 'entries', null, null, 'the whole number 5', 'a list'],
    'a boolean where a list belongs' => ["setting: true\n", 'entries', null, null, 'the boolean true', 'a list'],
]);

it('takes a value of the right type without a word', function (string $yaml, string $type, mixed $expected): void {
    $values = ConfigFile::parse($yaml)->values();

    $answer = match ($type) {
        'string' => $values->string('setting', 'fallback'),
        default => $values->entries('setting'),
    };

    expect($answer)->toBe($expected)
        ->and($values->diagnostics())->toBe([]);
})->with([
    'quoted version' => ["setting: '1.10'\n", 'string', '1.10'],
    'quoted number' => ["setting: '3'\n", 'string', '3'],
    'quoted date' => ["setting: '2024-01-15'\n", 'string', '2024-01-15'],
    'three-segment version' => ["setting: 0.14.0\n", 'string', '0.14.0'],
    'plain text' => ["setting: strict\n", 'string', 'strict'],
    'a list of text' => ["setting:\n  - app\n  - modules\n", 'entries', ['app', 'modules']],
    'a list of mixed entries' => ["setting:\n  - app\n  - 5\n", 'entries', ['app', 5]],
    // An empty map arrives from YAML as an empty list, so there is nothing left to refuse it by.
    'an empty list' => ["setting: []\n", 'entries', []],
]);

it('answers the default for a setting nobody wrote, saying nothing', function (): void {
    $values = ConfigFile::parse("other: 1\n")->values();

    expect($values->string('setting', 'API'))->toBe('API')
        ->and($values->entries('setting'))->toBeNull()
        ->and($values->diagnostics())->toBe([]);
});

it('answers the default for a setting written empty, and keeps the key it was written under', function (): void {
    // A typed read has no value either way, so it answers the default for both. The distinction stays
    // readable off all(): the key an author wrote empty is still a key in the parsed map, which is
    // what the passes that walk it go on.
    $values = ConfigFile::parse("setting:\nother: 1\n")->values();

    expect($values->raw('setting'))->toBeNull()
        ->and($values->string('setting', 'API'))->toBe('API')
        ->and($values->entries('setting'))->toBeNull()
        ->and(array_key_exists('setting', $values->all()))->toBeTrue()
        ->and(array_key_exists('missing', $values->all()))->toBeFalse()
        // Present and empty is not a defect, so nothing is reported.
        ->and($values->diagnostics())->toBe([]);
});

it('hands back a value exactly as parsed when asked for it raw', function (): void {
    $values = ConfigFile::parse("a: 1.10\nb: no\nc: 2024-01-15\nd:\n")->values();

    expect($values->raw('a'))->toBe(1.1)
        ->and($values->raw('b'))->toBe('no')
        ->and($values->raw('c'))->toBe(1705276800)
        ->and($values->raw('d'))->toBeNull()
        ->and($values->raw('nope'))->toBeNull()
        ->and($values->diagnostics())->toBe([]);
});

it('addresses a nested setting by its path, and reports a refusal under its full name', function (): void {
    $values = ConfigFile::parse("documents:\n  default:\n    title: 1.10\n")->values();

    expect($values->string('documents.default.title', 'API'))->toBe('API')
        ->and($values->raw('documents.default.title'))->toBe(1.1)
        ->and($values->raw('documents.default.version'))->toBeNull()
        ->and($values->diagnostics()[0]->message)->toStartWith('documents.default.title is ');
});

it('refuses the section a path stops walking at, rather than reading it as an absent key', function (): void {
    // The answers are the same as for a key nobody wrote — there is no value under text either way —
    // and the REPORT cannot be. A section is refused when it is asked for directly, on the stated
    // ground that a build would otherwise run on every default and produce a plausible document, so
    // the author's file looks applied and is not; a path walking through the same section reaches the
    // same defaults by the same route, so the same refusal is owed. Silence here is the worse half of
    // the two, because nobody asked about `documents` and so nobody could report it either.
    $values = ConfigFile::parse("documents: strict\n")->values();

    expect($values->raw('documents.default.title'))->toBeNull()
        ->and($values->string('documents.default.title', 'API'))->toBe('API')
        // Named `documents` and not `documents.default.title`: the section is what the author has to
        // go and rewrite, and the key under it does not exist to be wrong.
        ->and(array_map(static fn (object $d): string => $d->message, $values->diagnostics()))->toBe([
            'documents is the text "strict", where the setting takes a map of settings — an empty section is used instead.',
        ]);
});

it('refuses a section written as a list on the way through it, like one asked for directly', function (): void {
    // The list reading is the one a walk used to pass over in silence: a list IS an array, so it
    // answered array_key_exists() for every key it does not have.
    $values = ConfigFile::parse("documents:\n  default:\n    - info\n    - routes\n")->values();

    expect($values->string('documents.default.info.title', 'API'))->toBe('API')
        ->and(array_map(static fn (object $d): string => $d->message, $values->diagnostics()))->toBe([
            'documents.default is a list, where the setting takes a map of settings — an empty section is used instead.',
        ]);
});

it('reports one refusal for a section however many keys were read under it', function (): void {
    // One defect is one line to go and fix. Two readers asking two keys under the same unreadable
    // section is still that one line, and reporting it twice would make the count depend on how many
    // readers there happened to be.
    $values = ConfigFile::parse("lint: 'yes'\n")->values();

    $values->string('lint.leakage.mode', 'strict');
    $values->entries('lint.leakage.allow');

    expect($values->diagnostics())->toHaveCount(1)
        ->and($values->diagnostics()[0]->message)->toStartWith('lint is the text "yes", ');

    // And the section asked for by name is that same one fact rather than a second line, which is
    // what keeps map() and the walk from reporting a section twice between them.
    $values->map('lint');

    expect($values->diagnostics())->toHaveCount(1);
});

it('reads a section as a reader of its own, and its refusals come back up', function (): void {
    // A section reader records into the reader the build made, so a refusal deep in the file reaches
    // the build's diagnostics rather than being reported by whoever happened to hold the child.
    $values = ConfigFile::parse("cache:\n  path: 1.10\n  stores: fast\n")->values();
    $cache = $values->map('cache');

    expect($cache->string('path', 'fragments'))->toBe('fragments')
        ->and($cache->entries('stores'))->toBeNull()
        ->and($values->diagnostics())->toHaveCount(2)
        ->and(array_map(static fn (object $d): string => $d->message, $values->diagnostics()))->toBe([
            'cache.path is the decimal number 1.1, where the setting takes text — "fragments" is used instead.',
            'cache.stores is the text "fast", where the setting takes a list — the built-in default is used instead.',
        ])
        // Asked of the section, the answer is still the build's.
        ->and($cache->diagnostics())->toBe($values->diagnostics());
});

it('reads an absent or empty section as a section with nothing in it', function (string $yaml): void {
    // `{}` and `[]` parse to the same PHP array, so there is nothing in the parsed value to tell an
    // empty map from an empty list. Refusing it would name a defect in a file that says what it means.
    $values = ConfigFile::parse($yaml)->values();

    expect($values->map('cache')->string('path', 'fragments'))->toBe('fragments')
        ->and($values->map('cache')->all())->toBe([])
        ->and($values->diagnostics())->toBe([]);
})->with([
    'absent' => ["other: 1\n"],
    'present and empty' => ["cache:\n"],
    'written as an empty map' => ["cache: {}\n"],
    'written as an empty list' => ["cache: []\n"],
]);

it('refuses a section written as something other than a map', function (string $yaml, string $found): void {
    $values = ConfigFile::parse($yaml)->values();

    expect($values->map('cache')->string('path', 'fragments'))->toBe('fragments')
        ->and($values->diagnostics())->toHaveCount(1)
        ->and($values->diagnostics()[0]->message)->toBe(sprintf(
            'cache is %s, where the setting takes a map of settings — an empty section is used instead.',
            $found,
        ))
        ->and($values->diagnostics()[0]->help)->toBe('Write the section as `key: value` pairs, indented under the section name.');
})->with([
    // A section written as a list is a different document from a section written as keys, and reading
    // the first as the second would invent structure the author did not write.
    'a list' => ["cache:\n  - enabled\n", 'a list'],
    'text' => ["cache: enabled\n", 'the text "enabled"'],
    'a number' => ["cache: 1\n", 'the whole number 1'],
    'a boolean' => ["cache: true\n", 'the boolean true'],
]);

it('reports one setting once, however many readers ask', function (): void {
    // Two extensions reading the same bad setting is one defect with one line to fix — and a count
    // that grew with the number of askers would also make the answer depend on who asked.
    $values = ConfigFile::parse("setting: 1.10\n")->values();

    $values->string('setting', 'first');
    $values->string('setting', 'second');
    $values->entries('setting');

    expect($values->diagnostics())->toHaveCount(1)
        ->and($values->diagnostics()[0]->message)->toContain('"first" is used instead');
});

it('reports refusals in setting order, whatever order they were asked in', function (): void {
    // Insertion order is read order, and read order is whichever extension ran first. Sorted by the
    // setting's own name, the build reports the same bytes every run.
    $yaml = "zeta: 1.10\nalpha: 1.10\nmiddle:\n  beta: 1.10\n";

    $first = ConfigFile::parse($yaml)->values();
    $first->string('zeta', 'API');
    $first->map('middle')->string('beta', 'API');
    $first->string('alpha', 'API');

    $second = ConfigFile::parse($yaml)->values();
    $second->string('alpha', 'API');
    $second->string('zeta', 'API');
    $second->map('middle')->string('beta', 'API');

    $names = static fn (ConfigValues $v): array => array_map(
        static fn (object $d): string => explode(' ', $d->message)[0],
        $v->diagnostics(),
    );

    expect($names($first))->toBe(['alpha', 'middle.beta', 'zeta'])
        ->and($names($second))->toBe($names($first));
});

it('escapes a value it reads back to its author', function (): void {
    // Every byte in a refusal came out of a file, and a diagnostic goes to a terminal and to a CI log,
    // both of which an escape sequence recolours and a direction override reorders.
    //
    // Two characters, because two different passes catch them and only one of those is JSON. An ESC
    // byte is escaped by the JSON encoding of the value; U+202E survives that untouched, under the
    // unescaped-unicode flag that keeps a legitimate name readable, and is caught by the text pass
    // after it — so this fails if either half is dropped.
    $escape = ConfigFile::parse("setting: \"\x1b[31mred\"\n")->values();
    $override = ConfigFile::parse("setting: \"a\u{202E}b\"\n")->values();

    // Asked as a list, so the refusal reads the text back rather than naming its type.
    $escape->entries('setting');
    $override->entries('setting');

    expect($escape->diagnostics()[0]->message)->toContain('\\u001b')
        ->and($escape->diagnostics()[0]->message)->not->toContain("\x1b")
        ->and($override->diagnostics()[0]->message)->toContain('\u{202E}')
        ->and($override->diagnostics()[0]->message)->not->toContain("\u{202E}");
});

it('files a refusal under a setting name and not under a key that has a dot in it', function (): void {
    // A dot addresses structure. A setting whose own key holds a dot — an author-supplied pattern
    // name — is reached by taking its section and reading the key there.
    $values = ConfigFile::parse("lint:\n  leakage:\n    patterns:\n      host.internal: 5\n")->values();
    $patterns = $values->map('lint')->map('leakage')->map('patterns');

    expect($patterns->raw('host.internal'))->toBeNull()
        ->and($patterns->string('host.internal', 'x'))->toBe('x')
        ->and($values->diagnostics())->toBe([])
        // Reached the way a map's own keys are reached, it is there.
        ->and($patterns->all())->toBe(['host.internal' => 5]);
});
