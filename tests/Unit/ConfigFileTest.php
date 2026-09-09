<?php

declare(strict_types=1);

use Docuccino\Core\Config\ConfigFile;
use Docuccino\Core\Diagnostics\Severity;

/**
 * Every way reading a project's configuration can go wrong, run rather than described: each case
 * writes the file the reader should refuse and checks that it refuses it, names it, and still hands
 * back an answer the build can carry on with.
 *
 * Two invariants run through all of them. Nothing throws — a build must survive a file somebody is
 * halfway through editing. And no diagnostic carries the path the file was read from, because a
 * diagnostic can be embedded in the emitted document and a machine path there is not determinism.
 */
beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/docuccino-config-'.uniqid('', true);
    mkdir($this->root, 0755, true);
});

afterEach(function (): void {
    foreach ((array) glob($this->root.'/{,.}*', GLOB_BRACE) as $file) {
        @chmod((string) $file, 0644);
        @unlink((string) $file);
    }
    @rmdir($this->root);
});

it('reads a configuration file that is there', function (): void {
    file_put_contents($this->root.'/docuccino.yaml', "documents:\n  default:\n    title: Forms API\n");

    $read = ConfigFile::read($this->root);

    expect($read->ok())->toBeTrue()
        ->and($read->error)->toBeNull()
        ->and($read->diagnostics)->toBe([])
        ->and($read->path)->toBe($this->root.'/docuccino.yaml')
        ->and($read->values)->toBe(['documents' => ['default' => ['title' => 'Forms API']]]);
});

it('reads no configuration file as no configuration, and says nothing about it', function (): void {
    // A correct document with no configuration is the product, so this is not a degradation and gets
    // no diagnostic. The path is still reported, because the build registers it as a cache dependency
    // and has to notice the file appearing.
    $read = ConfigFile::read($this->root);

    expect($read->error)->toBe(ConfigFile::ABSENT)
        ->and($read->ok())->toBeFalse()
        ->and($read->values)->toBe([])
        ->and($read->diagnostics)->toBe([])
        ->and($read->path)->toBe($this->root.'/docuccino.yaml');
});

it('reads one name, and trailing separators on the directory do not make a second', function (): void {
    file_put_contents($this->root.'/docuccino.yaml', "cache:\n  enabled: true\n");

    expect(ConfigFile::read($this->root.'/')->values)->toBe(['cache' => ['enabled' => true]])
        ->and(ConfigFile::NAME)->toBe('docuccino.yaml');
});

it('names a file that was obviously meant to be the configuration', function (string $name, string $reason): void {
    file_put_contents($this->root.'/'.$name, "cache:\n  enabled: true\n");

    $read = ConfigFile::read($this->root);
    $diagnostic = $read->diagnostics[0] ?? null;

    expect($read->error)->toBe(ConfigFile::ABSENT)
        ->and($read->values)->toBe([])
        ->and($read->diagnostics)->toHaveCount(1)
        ->and($diagnostic?->code)->toBe('config.file-misnamed')
        ->and($diagnostic?->severity)->toBe(Severity::Warning)
        ->and($diagnostic?->message)->toContain($name)
        ->and($diagnostic?->message)->toContain($reason)
        ->and($diagnostic?->help)->toBe('Rename it to docuccino.yaml.');
})->with([
    'wrong extension' => ['docuccino.yml', 'the extension is spelled ".yaml" in full'],
    'dotfile' => ['.docuccino.yaml', 'the name is not a dotfile'],
    'dotfile, wrong extension' => ['.docuccino.yml', 'the name is not a dotfile, and the extension is spelled ".yaml" in full'],
    'template' => ['docuccino.yaml.dist', 'the file is read as-is, not from a ".dist" template'],
    'wrong format' => ['docuccino.json', 'the configuration is YAML'],
    'other tool\'s format' => ['docuccino.neon', 'the configuration is YAML'],
]);

it('covers every near miss it lists, and lists no name it would not act on', function (): void {
    // The dataset above proves the rows it lists, so the rows are held to the table itself — a name
    // added to the table without a row here fails, rather than shipping untested.
    expect(array_keys(ConfigFile::NEAR_MISSES))->toBe([
        'docuccino.yml',
        '.docuccino.yaml',
        '.docuccino.yml',
        'docuccino.yaml.dist',
        'docuccino.json',
        'docuccino.neon',
    ]);
});

it('says nothing about a file that is not trying to be the configuration', function (string $name): void {
    // The unknown-entry half: a near miss is a short, closed list, and a directory full of other
    // config files must not draw a word.
    file_put_contents($this->root.'/'.$name, "anything: 1\n");

    expect(ConfigFile::read($this->root)->diagnostics)->toBe([]);
})->with(['composer.json', 'phpstan.neon', 'pint.json', 'docuccino-notes.yaml', 'yaml']);

it('reports every near miss it found in one diagnostic, in the table\'s order', function (): void {
    // Table order, not the filesystem's, so two machines with two directory orderings report the same
    // bytes.
    file_put_contents($this->root.'/docuccino.json', '{}');
    file_put_contents($this->root.'/docuccino.yml', "a: 1\n");

    $message = ConfigFile::read($this->root)->diagnostics[0]->message;

    expect(ConfigFile::read($this->root)->diagnostics)->toHaveCount(1)
        ->and($message)->toContain('files named')
        ->and(strpos($message, 'docuccino.yml'))->toBeLessThan((int) strpos($message, 'docuccino.json'));
});

it('reports a file it cannot read, and builds from defaults', function (): void {
    $path = $this->root.'/docuccino.yaml';
    file_put_contents($path, "cache:\n  enabled: true\n");
    chmod($path, 0000);

    if (is_readable($path)) {
        // Running as a user permissions do not apply to; there is nothing to measure here.
        expect(true)->toBeTrue();

        return;
    }

    $read = ConfigFile::read($this->root);

    expect($read->error)->toBe(ConfigFile::UNREADABLE)
        ->and($read->values)->toBe([])
        ->and($read->path)->toBe($path)
        ->and($read->diagnostics[0]->code)->toBe('config.file-unreadable')
        ->and($read->diagnostics[0]->severity)->toBe(Severity::Error)
        ->and($read->diagnostics[0]->message)->toContain('docuccino.yaml is there and could not be read')
        ->and($read->diagnostics[0]->message)->not->toContain($this->root);
});

it('never lets a parse error out, and names the line instead', function (string $label, string $yaml, string $expected): void {
    $read = ConfigFile::parse($yaml);

    expect($read->error)->toBe(ConfigFile::INVALID)
        ->and($read->values)->toBe([])
        ->and($read->diagnostics)->toHaveCount(1)
        ->and($read->diagnostics[0]->code)->toBe('config.file-invalid')
        ->and($read->diagnostics[0]->severity)->toBe(Severity::Error)
        ->and($read->diagnostics[0]->message)->toStartWith('docuccino.yaml is not valid YAML')
        ->and($read->diagnostics[0]->message)->toContain($expected)
        ->and($read->diagnostics[0]->help)->toBe('Fix the line named above and run the build again.');
})->with([
    // The one worth having on its own: YAML refuses a duplicate key where PHP silently keeps the last
    // of the two. Stricter and better, and only if the author is told.
    'duplicate key' => ['duplicate key', "cache:\n  enabled: true\n  enabled: false\n", 'Duplicate key "enabled" detected at line 3'],
    'tab indentation' => ['tab', "cache:\n\tenabled: true\n", 'A YAML file cannot contain tabs as indentation at line 2'],
    'unclosed inline' => ['unclosed', "engine:\n  paths: [app\n", 'Malformed inline YAML string at line 3'],
    'second document' => ['second document', "cache: {}\n---\nlint: {}\n", 'Multiple documents are not supported at line 2'],
    'unknown tag' => ['unknown tag', "cache: !mine {}\n", 'Tags support is not enabled'],
    'missing anchor' => ['missing anchor', "cache: *nope\n", 'Reference "nope" does not exist at line 1'],
    // Each of these three parses to a silent null unless the reader asks to be told; the flag is what
    // turns them into this.
    'php constant' => ['php constant', "cache:\n  enabled: !php/const PHP_INT_MAX\n", 'could not be parsed as a constant'],
    'php enum' => ['php enum', "cache:\n  enabled: !php/enum Foo::Bar\n", 'could not be parsed as an enum'],
    'php object' => ['php object', "cache: !php/object O:8:\"stdClass\":0:{}\n", 'Object support when parsing a YAML file has been disabled'],
]);

it('escapes a parse error that quotes file bytes', function (): void {
    // The message quotes the offending line, so it carries whatever was in the file — and a diagnostic
    // goes to a terminal and to a CI log, both of which a control sequence can rewrite.
    // A duplicate key, because that message quotes the line it choked on and so carries file bytes.
    $read = ConfigFile::parse("title: a\ntitle: \x1b[31mred\n");

    expect($read->error)->toBe(ConfigFile::INVALID)
        ->and($read->diagnostics[0]->message)->toContain('\x1B')
        ->and($read->diagnostics[0]->message)->not->toContain("\x1b");
});

it('reads a root that is not a map as no configuration, loudly', function (string $yaml, string $described): void {
    // Never `[]` on the quiet. A build that ran on every default here would produce a plausible
    // document, so the author's file would look applied and would not be.
    $read = ConfigFile::parse($yaml);

    expect($read->error)->toBe(ConfigFile::NOT_A_MAP)
        ->and($read->ok())->toBeFalse()
        ->and($read->values)->toBe([])
        ->and($read->diagnostics)->toHaveCount(1)
        ->and($read->diagnostics[0]->code)->toBe('config.file-not-a-map')
        ->and($read->diagnostics[0]->severity)->toBe(Severity::Error)
        ->and($read->diagnostics[0]->message)->toContain($described)
        ->and($read->diagnostics[0]->help)->toBe('Write the settings as top-level `key: value` pairs.');
})->with([
    'empty file' => ['', 'nothing (the file is empty, or its only content is a comment)'],
    'whitespace only' => ["\n   \n", 'nothing (the file is empty, or its only content is a comment)'],
    'comment only' => ["# to be written\n", 'nothing (the file is empty, or its only content is a comment)'],
    'explicit null' => ["~\n", 'nothing (the file is empty, or its only content is a comment)'],
    'a list' => ["- documents\n- lint\n", 'a list'],
    // Both spellings of an empty root, which parse to the same empty array — so the wording says what
    // is true of either rather than guessing which one was typed.
    'an empty map' => ["{}\n", 'no settings at all'],
    'an empty list' => ["[]\n", 'no settings at all'],
    'one line of text' => ["documents\n", 'a single line of text'],
    'a number' => ["42\n", 'a single number'],
    'a float' => ["4.2\n", 'a single number'],
    'true' => ["true\n", 'the single value true'],
    'false' => ["false\n", 'the single value false'],
]);

it('reads an empty file through the directory the same way it reads one directly', function (): void {
    file_put_contents($this->root.'/docuccino.yaml', '');

    $read = ConfigFile::read($this->root);

    expect($read->error)->toBe(ConfigFile::NOT_A_MAP)
        ->and($read->path)->toBe($this->root.'/docuccino.yaml')
        ->and($read->diagnostics[0]->code)->toBe('config.file-not-a-map');
});

it('drops a byte-order mark rather than letting it into the first key', function (): void {
    // Without this the mark lands INSIDE the key's name, the parse succeeds, and the whole file goes
    // quietly unapplied. No exception to catch and nothing to report — which is why it is stripped
    // rather than diagnosed: once the mark is gone the file means exactly what it says.
    $read = ConfigFile::parse("\u{FEFF}documents:\n  default:\n    title: Forms API\n");

    expect($read->ok())->toBeTrue()
        ->and($read->diagnostics)->toBe([])
        ->and(array_keys($read->values))->toBe(['documents'])
        ->and($read->values)->toBe(['documents' => ['default' => ['title' => 'Forms API']]]);
});

it('reads a file the same whichever line ending it was checked out with', function (): void {
    $lf = "documents:\n  default:\n    title: Forms API\n    description: |\n      one\n      two\n";

    $expected = ConfigFile::parse($lf)->values;

    expect($expected)->not->toBe([])
        ->and(ConfigFile::parse(str_replace("\n", "\r\n", $lf))->values)->toBe($expected)
        ->and(ConfigFile::parse(str_replace("\n", "\r", $lf))->values)->toBe($expected);
});

it('keeps a key that is present and empty, and does not invent one that is absent', function (): void {
    // The distinction some settings read as opposites: a key nobody wrote has expressed nothing, and a
    // key written with an unreadable value has an author behind it. Stripping nulls here would collapse
    // the two into one, silently.
    $read = ConfigFile::parse("error_responses:\nservers:\ntags: []\n");

    expect($read->values)->toBe(['error_responses' => null, 'servers' => null, 'tags' => []])
        ->and(array_key_exists('error_responses', $read->values))->toBeTrue()
        ->and(array_key_exists('cache', $read->values))->toBeFalse();
});

it('carries no machine path in anything it reports', function (): void {
    // A diagnostic can be embedded in the emitted document, and a path from the machine that built it
    // is a determinism break. The file is named by the one name it has and never by where it lives.
    file_put_contents($this->root.'/docuccino.yaml', "cache:\n  enabled: true\n  enabled: false\n");
    file_put_contents($this->root.'/docuccino.yml', "a: 1\n");

    $messages = [];
    foreach ([ConfigFile::read($this->root), ConfigFile::parse('- a'), ConfigFile::parse("a: [1\n")] as $read) {
        foreach ($read->diagnostics as $diagnostic) {
            $messages[] = $diagnostic->message.' '.($diagnostic->help ?? '');
        }
    }

    expect($messages)->not->toBe([]);
    foreach ($messages as $message) {
        expect($message)->not->toContain($this->root)
            ->and($message)->not->toContain(sys_get_temp_dir())
            ->and($message)->toContain('docuccino.yaml');
    }
});

it('hands back the same reader every time, so its refusals accumulate', function (): void {
    // A fresh reader per call would lose every refusal but the last caller's, and the build would
    // report a number that depended on who asked last.
    $read = ConfigFile::parse("title: 1.10\nenabled: no\n");

    expect($read->values()->string('title', 'API'))->toBe('API')
        ->and($read->values()->bool('enabled', true))->toBeTrue()
        ->and($read->values())->toBe($read->values())
        ->and($read->values()->diagnostics())->toHaveCount(2);
});
