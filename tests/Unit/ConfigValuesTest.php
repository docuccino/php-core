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
        'bool' => $values->bool('setting', $default),
        'int' => $values->int('setting', $default),
        default => $values->strings('setting', $default),
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

    // The Norway problem as it actually lands: these arrive as TEXT, so anything willing to convert
    // reads the author's "off" as ON.
    'no' => ["setting: no\n", 'bool', true, true, 'the text "no"', 'true or false'],
    'off' => ["setting: off\n", 'bool', true, true, 'the text "off"', 'true or false'],
    'yes against a false default' => ["setting: yes\n", 'bool', false, false, 'the text "yes"', 'true or false'],
    'on against a false default' => ["setting: on\n", 'bool', false, false, 'the text "on"', 'true or false'],
    'n' => ["setting: n\n", 'bool', true, true, 'the text "n"', 'true or false'],
    'a number as a boolean' => ["setting: 1\n", 'bool', false, false, 'the whole number 1', 'true or false'],
    'the text "false"' => ["setting: 'false'\n", 'bool', true, true, 'the text "false"', 'true or false'],

    // `0777` and `08` are text, not numbers, because neither is a notation YAML has.
    'a file mode' => ["setting: 0777\n", 'int', 8, 8, 'the text "0777"', 'a whole number'],
    'a padded number' => ["setting: 08\n", 'int', 8, 8, 'the text "08"', 'a whole number'],
    // A float that is not some integer stays a float, and is still refused.
    'a fractional number' => ["setting: 1.5\n", 'int', 8, 8, 'the decimal number 1.5', 'a whole number'],
    'a version that became a float' => ["setting: 1.10\n", 'int', 8, 8, 'the decimal number 1.1', 'a whole number'],
    'past PHP_INT_MAX' => ["setting: 9223372036854775808\n", 'int', 8, 8, 'the text "9223372036854775808"', 'a whole number'],

    // A list is refused WHOLE. A list of paths short by one silently changes what the build looks at.
    'a list with a number in it' => ["setting:\n  - app\n  - 5\n", 'strings', ['src'], ['src'], 'a list whose entry 2 is the whole number 5', 'a list of text'],
    'a list with a null in it' => ["setting:\n  - app\n  -\n", 'strings', ['src'], ['src'], 'a list whose entry 2 is empty', 'a list of text'],
    'a list with a map in it' => ["setting:\n  - a: 1\n", 'strings', ['src'], ['src'], 'a list whose entry 1 is a map', 'a list of text'],
    'a map where a list belongs' => ["setting:\n  a: 1\n", 'strings', ['src'], ['src'], 'a map', 'a list of text'],
    'text where a list belongs' => ["setting: app\n", 'strings', ['src'], ['src'], 'the text "app"', 'a list of text'],
    'a list with no default' => ["setting: app\n", 'strings', null, null, 'the text "app"', 'a list of text'],
]);

it('takes a value of the right type without a word', function (string $yaml, string $type, mixed $expected): void {
    $values = ConfigFile::parse($yaml)->values();

    $answer = match ($type) {
        'string' => $values->string('setting', 'fallback'),
        'bool' => $values->bool('setting', true),
        'int' => $values->int('setting', 99),
        default => $values->strings('setting', ['fallback']),
    };

    expect($answer)->toBe($expected)
        ->and($values->diagnostics())->toBe([]);
})->with([
    'quoted version' => ["setting: '1.10'\n", 'string', '1.10'],
    'quoted number' => ["setting: '3'\n", 'string', '3'],
    'quoted date' => ["setting: '2024-01-15'\n", 'string', '2024-01-15'],
    'three-segment version' => ["setting: 0.14.0\n", 'string', '0.14.0'],
    'plain text' => ["setting: strict\n", 'string', 'strict'],
    'false' => ["setting: false\n", 'bool', false],
    'true' => ["setting: true\n", 'bool', true],
    'a number' => ["setting: 2048\n", 'int', 2048],
    // Taken, not refused — and this row reverses what it used to assert, so here is why the new
    // answer is right. The parser is free to hand back either the int 2 or the float 2.0 for a
    // spelling like `+2`, so the reader settles an integral float to its integer before any typed
    // read happens. Refusing it, as this used to, made the same file behave differently on two
    // machines whose lockfiles differed by one patch release of the YAML parser.
    'a whole float' => ["setting: 2.0\n", 'int', 2],
    'a signed whole number' => ["setting: +2\n", 'int', 2],
    'a signed zero' => ["setting: -0.0\n", 'int', 0],
    'a hex number' => ["setting: 0x1A\n", 'int', 26],
    'an underscored number' => ["setting: 1_000\n", 'int', 1000],
    'a list of text' => ["setting:\n  - app\n  - modules\n", 'strings', ['app', 'modules']],
    'an empty list' => ["setting: []\n", 'strings', []],
]);

it('answers the default for a setting nobody wrote, saying nothing', function (): void {
    $values = ConfigFile::parse("other: 1\n")->values();

    expect($values->string('setting', 'API'))->toBe('API')
        ->and($values->bool('setting', true))->toBeTrue()
        ->and($values->int('setting', 8))->toBe(8)
        ->and($values->strings('setting', ['src']))->toBe(['src'])
        ->and($values->diagnostics())->toBe([]);
});

it('answers the default for a setting written empty, and still says it was written', function (): void {
    // The distinction some settings read as opposites, kept all the way to the reader: a typed read
    // has no value either way, and only has() can tell the two apart.
    $values = ConfigFile::parse("setting:\nother: 1\n")->values();

    expect($values->has('setting'))->toBeTrue()
        ->and($values->has('missing'))->toBeFalse()
        ->and($values->raw('setting'))->toBeNull()
        ->and($values->string('setting', 'API'))->toBe('API')
        ->and($values->bool('setting', true))->toBeTrue()
        ->and($values->int('setting', 8))->toBe(8)
        ->and($values->strings('setting', ['src']))->toBe(['src'])
        // Present and empty is not a defect, so nothing is reported. What the two readings MEAN is
        // the caller's to decide, which is what has() is for.
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
        ->and($values->has('documents.default.title'))->toBeTrue()
        ->and($values->has('documents.default.version'))->toBeFalse()
        ->and($values->diagnostics()[0]->message)->toStartWith('documents.default.title is ');
});

it('stops walking a path at the first thing that is not a map', function (): void {
    $values = ConfigFile::parse("documents: strict\n")->values();

    expect($values->has('documents.default.title'))->toBeFalse()
        ->and($values->raw('documents.default.title'))->toBeNull()
        ->and($values->string('documents.default.title', 'API'))->toBe('API')
        ->and($values->diagnostics())->toBe([]);
});

it('reads a section as a reader of its own, and its refusals come back up', function (): void {
    // A section reader records into the reader the build made, so a refusal deep in the file reaches
    // the build's diagnostics rather than being reported by whoever happened to hold the child.
    $values = ConfigFile::parse("cache:\n  enabled: no\n  ttl: 0777\n")->values();
    $cache = $values->map('cache');

    expect($cache->bool('enabled', true))->toBeTrue()
        ->and($cache->int('ttl', 60))->toBe(60)
        ->and($values->diagnostics())->toHaveCount(2)
        ->and(array_map(static fn (object $d): string => $d->message, $values->diagnostics()))->toBe([
            'cache.enabled is the text "no", where the setting takes true or false — true is used instead.',
            'cache.ttl is the text "0777", where the setting takes a whole number — 60 is used instead.',
        ])
        // Asked of the section, the answer is still the build's.
        ->and($cache->diagnostics())->toBe($values->diagnostics());
});

it('reads an absent or empty section as a section with nothing in it', function (string $yaml): void {
    // `{}` and `[]` parse to the same PHP array, so there is nothing in the parsed value to tell an
    // empty map from an empty list. Refusing it would name a defect in a file that says what it means.
    $values = ConfigFile::parse($yaml)->values();

    expect($values->map('cache')->bool('enabled', true))->toBeTrue()
        ->and($values->map('cache')->has('enabled'))->toBeFalse()
        ->and($values->diagnostics())->toBe([]);
})->with([
    'absent' => ["other: 1\n"],
    'present and empty' => ["cache:\n"],
    'written as an empty map' => ["cache: {}\n"],
    'written as an empty list' => ["cache: []\n"],
]);

it('refuses a section written as something other than a map', function (string $yaml, string $found): void {
    $values = ConfigFile::parse($yaml)->values();

    expect($values->map('cache')->bool('enabled', true))->toBeTrue()
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
    $values = ConfigFile::parse("setting: no\n")->values();

    $values->bool('setting', true);
    $values->bool('setting', false);
    $values->int('setting', 1);

    expect($values->diagnostics())->toHaveCount(1)
        ->and($values->diagnostics()[0]->message)->toContain('true is used instead');
});

it('reports refusals in setting order, whatever order they were asked in', function (): void {
    // Insertion order is read order, and read order is whichever extension ran first. Sorted by the
    // setting's own name, the build reports the same bytes every run.
    $yaml = "zeta: no\nalpha: no\nmiddle:\n  beta: no\n";

    $first = ConfigFile::parse($yaml)->values();
    $first->bool('zeta', true);
    $first->map('middle')->bool('beta', true);
    $first->bool('alpha', true);

    $second = ConfigFile::parse($yaml)->values();
    $second->bool('alpha', true);
    $second->bool('zeta', true);
    $second->map('middle')->bool('beta', true);

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

    $escape->bool('setting', true);
    $override->bool('setting', true);

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

    expect($patterns->has('host.internal'))->toBeFalse()
        ->and($patterns->string('host.internal', 'x'))->toBe('x')
        ->and($values->diagnostics())->toBe([])
        // Reached the way a map's own keys are reached, it is there.
        ->and($patterns->map('host')->has('internal'))->toBeFalse();
});
