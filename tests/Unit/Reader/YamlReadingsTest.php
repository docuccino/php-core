<?php

declare(strict_types=1);

use Docuccino\Core\Reader\DocumentReader;
use Docuccino\Core\Reader\ReadFailure;
use Docuccino\Core\Reader\UnreadableDocumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/*
 * What symfony/yaml reads differently from what was written, read as YAML 1.2 reads it where that has a clear
 * answer, and refused where the text is plainly not what it claims. The answer each row holds the reader to is
 * the document's JSON twin, the same document written as JSON: what a YAML 1.2 reader, or any reader that
 * does not move a date into the next month, makes of it.
 */

$head = "openapi: 3.1.0\ninfo: {title: Orders, version: '1'}\npaths: {}\n";

/** What read() makes of `x` in $yaml, as JSON: the twin is compared as JSON, so a number and a string stay apart. */
$x = static fn (string $yaml): string => json_encode(json_decode((new DocumentReader)->read($yaml)->json())->x, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

it('reads a timestamp no calendar holds as the text it was written as, wherever it is written', function (string $written) use ($head, $x): void {
    // The premise: left to itself the parser moves it into the next month, day or minute, or refuses it.
    try {
        $parsed = Yaml::parse('k: '.$written, Yaml::PARSE_DATETIME)['k'];
        expect($parsed)->toBeInstanceOf(DateTimeInterface::class);
    } catch (ParseException $exception) {
        expect($exception->getMessage())->toContain('could not be parsed as it is an invalid date');
    }

    $twin = json_encode($written, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    expect($x($head."x: {$written}\n"))->toBe($twin)
        ->and($x($head."x: [{$written}, 1]\n"))->toBe("[{$twin},1]")
        ->and($x($head."x: {a: {$written}}\n"))->toBe("{\"a\":{$twin}}")
        ->and($x($head."y: &d {$written}\nx: *d\n"))->toBe($twin)
        ->and($x($head."x: {$written} # when\n"))->toBe($twin)
        ->and($x(str_replace("\n", "\r\n", $head."x: {$written}\n")))->toBe($twin);
})->with([
    // Moved on by PHP's calendar.
    'the 30th of February' => ['2024-02-30'],
    'the 29th of February outside a leap year' => ['2023-02-29'],
    'the 31st of April' => ['2024-04-31'],
    'month 0' => ['2024-00-10'],
    'day 0' => ['2024-01-00'],
    'hour 24' => ['2024-01-01T24:00:00Z'],
    'a leap second' => ['2016-12-31T23:59:60Z'],
    'an impossible date under a possible time' => ['2024-02-30 10:00:00'],
    // Refused by the parser.
    'month 13' => ['2024-13-01'],
    'day 32' => ['2024-01-32'],
    'minute 60' => ['2024-01-01T10:60:00Z'],
    'hour 25' => ['2024-01-01 25:61:61'],
    'a zone 25 hours out' => ['2024-01-01T10:00:00+25:00'],
    'a zone minute of 99' => ['2024-01-01T10:00:00+01:99'],
]);

it('reads every timestamp a calendar holds exactly as it read before', function (string $written, string $read) use ($head, $x): void {
    expect($x($head."x: {$written}\n"))->toBe(json_encode($read, JSON_THROW_ON_ERROR));
})->with([
    'a date' => ['2024-01-01', '2024-01-01'],
    'a date unpadded' => ['2024-1-5', '2024-01-05'],
    'a leap day' => ['2024-02-29', '2024-02-29'],
    'a leap day in a century' => ['2000-02-29', '2000-02-29'],
    'the first year' => ['0001-01-01', '0001-01-01'],
    'the last day' => ['9999-12-31', '9999-12-31'],
    'midnight in UTC' => ['2024-01-01T00:00:00Z', '2024-01-01T00:00:00Z'],
    'a lower-case separator' => ['2024-01-01t10:00:00Z', '2024-01-01T10:00:00Z'],
    'a space for a separator' => ['2024-01-01 10:30:00', '2024-01-01T10:30:00'],
    'a tab for a separator' => ["2024-01-01\t10:30:00", '2024-01-01T10:30:00'],
    'a fraction and an offset' => ['2024-01-01T10:00:00.5+02:00', '2024-01-01T10:00:00.5+02:00'],
    'a space before the offset' => ['2024-01-01T10:00:00 +02:00', '2024-01-01T10:00:00+02:00'],
    'an offset in hours alone' => ['2024-01-01T10:00:00-14', '2024-01-01T10:00:00-14:00'],
    'an offset of one digit' => ['2024-01-01T10:00:00+5', '2024-01-01T10:00:00+05:00'],
    'a fraction past microseconds' => ['2024-01-01T10:00:00.123456789Z', '2024-01-01T10:00:00.123456Z'],
    'an empty fraction' => ['2024-01-01T10:00:00.Z', '2024-01-01T10:00:00Z'],
    'the last second of a day' => ['2024-01-01T23:59:59Z', '2024-01-01T23:59:59Z'],
    'a negative zero offset' => ['2024-12-31T00:00:00-00:00', '2024-12-31T00:00:00+00:00'],
    'an hour of one digit' => ['2024-01-01T9:05:07Z', '2024-01-01T09:05:07Z'],
]);

it('reads a timestamp written as a key as that text, where the parser makes it a count of seconds', function (string $yaml, string $read) use ($head, $x): void {
    expect($x($head.$yaml))->toBe($read);
})->with([
    'a date' => ["x:\n  2024-01-01: a\n", '{"2024-01-01":"a"}'],
    'an impossible date' => ["x:\n  2024-02-30: a\n", '{"2024-02-30":"a"}'],
    'a date and time' => ["x:\n  2024-01-01 10:00:00: a\n", '{"2024-01-01 10:00:00":"a"}'],
    'a date in a list item' => ["x:\n  - 2024-01-01: a\n", '[{"2024-01-01":"a"}]'],
    // In flow style the parser refused it.
    'a date in flow style' => ["x: {2024-01-01: a}\n", '{"2024-01-01":"a"}'],
]);

it('keeps the premise of the key rows: left to itself the parser makes a date key a count of seconds', function (): void {
    expect(Yaml::parse("2024-01-01: a\n", Yaml::PARSE_DATETIME))->toBe([1704067200 => 'a']);
});

it('reads true, false, null, .inf and .nan as text in any case YAML does not spell them', function (string $written, string $read) use ($head, $x): void {
    expect($x($head."x: {$written}\n"))->toBe($read)
        ->and($x($head."x:\n  {$written}: a\n"))->toBe('{'.json_encode($written, JSON_THROW_ON_ERROR).':"a"}');
})->with([
    'tRUE' => ['tRUE', '"tRUE"'],
    'TrUe' => ['TrUe', '"TrUe"'],
    'fALSE' => ['fALSE', '"fALSE"'],
    'nULL' => ['nULL', '"nULL"'],
    'NuLl' => ['NuLl', '"NuLl"'],
    '.iNf' => ['.iNf', '".iNf"'],
    '-.InF' => ['-.InF', '"-.InF"'],
    '.nAn' => ['.nAn', '".nAn"'],
]);

it('reads the spellings YAML does give those keywords as it always has', function (string $written, string $read) use ($head, $x): void {
    expect($x($head."x: {$written}\n"))->toBe($read);
})->with([
    'true' => ['true', 'true'], 'True' => ['True', 'true'], 'TRUE' => ['TRUE', 'true'],
    'false' => ['false', 'false'], 'False' => ['False', 'false'], 'FALSE' => ['FALSE', 'false'],
    'null' => ['null', 'null'], 'Null' => ['Null', 'null'], 'NULL' => ['NULL', 'null'],
    // Neither of which JSON has, so the document is refused for holding one, as before.
]);

it('reads what the non-specific tag is on as a string, as YAML does', function (string $tagged, string $read) use ($head, $x): void {
    expect($x($head.'x: '.$tagged."\n"))->toBe($read);
})->with([
    'a number' => ['! 12', '"12"'],
    'a decimal' => ['! 1.5', '"1.5"'],
    'a boolean' => ['! true', '"true"'],
    'null' => ['! ~', '"~"'],
    'a date' => ['! 2024-01-01', '"2024-01-01"'],
    'text' => ['! word', '"word"'],
    'in flow style' => ['[! 12]', '["12"]'],
]);

it('keeps the premise of the non-specific tag rows: left to itself the parser reads what it is on untagged', function (): void {
    expect(Yaml::parse('k: ! 12')['k'])->toBe(12)
        ->and(Yaml::parse('k: ! true')['k'])->toBeTrue();
});

it('lets no mark reach a value, a name or a refusal', function (string $yaml, string $read) use ($head, $x): void {
    expect($x($head.$yaml))->toBe($read);
})->with([
    'in single quotes' => ["x: 'due 2024-02-30, or tRUE'\n", '"due 2024-02-30, or tRUE"'],
    'in double quotes' => ["x: \"2024-02-30\"\n", '"2024-02-30"'],
    'an escape before the digits' => ["x: \"\\x2024-02-30\"\n", '" 24-02-30"'],
    'in a block scalar' => ["x: |\n  2024-02-30\n  tRUE\n", '"2024-02-30\\ntRUE\\n"'],
    'in a comment' => ["# 2024-02-30\nx: 1\n", '1'],
    'inside a longer plain scalar' => ["x: from 2024-02-30 on\n", '"from 2024-02-30 on"'],
    'tagged as a string' => ["x: !!str 2024-02-30\n", '"2024-02-30"'],
    'after a non-specific tag in prose' => ["x: a ! 12\n", '"a ! 12"'],
    'in an anchor\'s name' => ["y: &2024-02-30 a\nx: *2024-02-30\n", '"a"'],
    // The first character the reader would mark with is already there, as text and as an escape.
    "beside the mark's own character" => ["y: \"\u{E000}\\uE001\"\nx: [2024-02-30, \"\u{E000}\"]\n", '["2024-02-30","\ue000"]'],
]);

it('refuses a document that spells every character a mark could be, rather than mark it with one of them', function (): void {
    // Every private-use character of the first plane as text, and of the fifteenth as escapes: 600 KB.
    $first = implode('', array_map(mb_chr(...), range(0xE000, 0xF8FF)));
    $fifteenth = implode('', array_map(static fn (int $point): string => sprintf('\\U%08X', $point), range(0xF0000, 0xFFFFD)));

    expect(static fn (): mixed => (new DocumentReader)->read("openapi: 3.1.0\ny: \"{$first}{$fifteenth}\"\nx: 2024-02-30\n"))
        ->toThrow(UnreadableDocumentException::class, 'The document spells every private-use character, one of which reading it needs.');
});

it('says nothing about a document in a mark it wrote', function (): void {
    try {
        (new DocumentReader)->read("openapi: 3.1.0\nx: 2024-02-30\n  y: 1\n");
        $this->fail('A broken document was read.');
    } catch (UnreadableDocumentException $exception) {
        expect($exception->reason)->toBe('The document is not valid YAML: A colon cannot be used in an unquoted mapping value.')
            ->and(preg_match('/[\x{E000}-\x{F8FF}]/u', $exception->getMessage()))->toBe(0);
    }
});

it('refuses a key written twice, however its two spellings were told apart', function (string $yaml, int $line) use ($head): void {
    try {
        (new DocumentReader)->read($head.$yaml);
        $this->fail('A key written twice was read.');
    } catch (UnreadableDocumentException $exception) {
        expect($exception->failure)->toBe(ReadFailure::Syntax)
            ->and($exception->reason)->toMatch('/^The document is not valid YAML: Duplicate key "[^"]+" detected\.$/');
        if ($line > 0) {
            expect($exception->parsedLine)->toBe($line);
        }
    }
})->with([
    // Told apart by a mark, then one name once it is taken out.
    'a date key, plain and quoted' => ["x:\n  2024-02-30: a\n  '2024-02-30': b\n", 0],
    // Told apart by nothing: 7.4 reads past the second when the first is null, saying so in a deprecation,
    // and 8.1 refuses it.
    'a key whose first value is null' => ["x:\n  a: ~\n  a: 1\n", 6],
    // Read past by every release once a merge is in the map, because a merge lets a key be written over.
    'a key written twice after a merge' => ["m: &m {a: 1}\nx:\n  <<: *m\n  b: 1\n  b: 2\n", 8],
]);

it('reads a flow mapping that writes over a key whose first value is null, as the parser always has', function () use ($head, $x): void {
    // The one duplicate key no release says anything about, so nothing here can see it: the honest reading
    // today, pinned so it moves when something can.
    expect($x($head."x: {a: ~, a: 1}\n"))->toBe('{"a":1}');
});

it('reads a float tag as YAML 1.2 reads a float, and refuses one on what is not a number', function (string $tagged, string $read) use ($head, $x): void {
    try {
        expect($x($head.'x: '.$tagged."\n"))->toBe($read);
    } catch (UnreadableDocumentException $exception) {
        expect($exception->failure->value.': '.$exception->reason)->toBe($read);
    }
})->with([
    'a decimal' => ['!!float 1.5', '1.5'],
    'an integer' => ['!!float 12', '12'],
    'an exponent' => ['!!float 1e3', '1000'],
    'a leading point' => ['!!float -.5', '-0.5'],
    'a trailing point' => ['!!float +1.', '1'],
    // The parser casts what the tag is on, quotes and all, to 0.
    'a quoted number' => ['!!float "1.5"', '1.5'],
    'a single-quoted number' => ["!!float '2'", '2'],
    'a block scalar' => ["!!float |\n  1.5", '1.5'],
    'text' => ['!!float abc', 'syntax: The document is not valid YAML: the !!float tag at /x is on "abc", which is not a number.'],
    'a number with text after it' => ['!!float 1.5abc', 'syntax: The document is not valid YAML: the !!float tag at /x is on "1.5abc", which is not a number.'],
    // YAML 1.1 spellings YAML 1.2 does not have.
    'underscores' => ['!!float 1_000.5', 'syntax: The document is not valid YAML: the !!float tag at /x is on "1_000.5", which is not a number.'],
    'hexadecimal' => ['!!float 0x1A', 'syntax: The document is not valid YAML: the !!float tag at /x is on "0x1A", which is not a number.'],
    'text in a block scalar' => ["!!float |\n  abc", 'syntax: The document is not valid YAML: the !!float tag at /x is on "abc", which is not a number.'],
    'text in flow style' => ['[!!float abc]', 'syntax: The document is not valid YAML: the !!float tag at /x/0 is on "abc", which is not a number.'],
    'an impossible date' => ['!!float 2024-02-30', 'syntax: The document is not valid YAML: the !!float tag at /x is on "2024-02-30", which is not a number.'],
    // Numbers JSON has no spelling for, refused as an untagged one is.
    'infinity' => ['!!float .inf', 'unrepresentable: The document holds an infinite or NaN number at /x, which JSON cannot represent.'],
    'NaN' => ['!!float .NaN', 'unrepresentable: The document holds an infinite or NaN number at /x, which JSON cannot represent.'],
]);

it('keeps the premise of the float rows: left to itself the parser casts what the tag is on', function (): void {
    expect(Yaml::parse('k: !!float abc')['k'])->toBe(0.0)
        ->and(Yaml::parse('k: !!float "1.5"')['k'])->toBe(0.0)
        ->and(Yaml::parse('k: !!float .inf')['k'])->toBe(0.0);
});

it('reads the other core-schema tags as it always has: a string, binary text, or a refusal', function (string $tagged, string $read) use ($head, $x): void {
    try {
        expect($x($head.'x: '.$tagged."\n"))->toBe($read);
    } catch (UnreadableDocumentException $exception) {
        expect($exception->failure->value)->toBe($read);
    }
})->with([
    '!!str' => ['!!str 12', '"12"'],
    '!!str on a block scalar' => ["!!str |\n  12", '"12\n"'],
    'the non-specific tag' => ['! 12', '"12"'],
    '!!binary' => ['!!binary aGk=', '"hi"'],
    '!!int' => ['!!int 12', 'syntax'],
    '!!bool' => ['!!bool true', 'syntax'],
    '!!null' => ['!!null ~', 'syntax'],
    '!!timestamp' => ['!!timestamp 2024-01-01', 'syntax'],
    '!!merge' => ['!!merge <<', 'syntax'],
    '!!value' => ['!!value =', 'syntax'],
    '!!map' => ['!!map {a: 1}', 'syntax'],
    '!!seq' => ['!!seq [1]', 'syntax'],
    '!!set' => ['!!set {a}', 'syntax'],
    '!!omap' => ['!!omap [a: 1]', 'syntax'],
    // Handed back still tagged by every release, which JSON has no spelling for.
    '!!int on a block scalar' => ["!!int |\n  12", 'unrepresentable'],
]);

it('refuses a merge of anything but a map or a list of maps, saying a merge takes maps', function (string $yaml, int $line, string $named) use ($head): void {
    try {
        (new DocumentReader)->read($head.$yaml);
        $this->fail('A merge of what is not a map was read.');
    } catch (UnreadableDocumentException $exception) {
        // Releases before 7.4.21 and 8.1.9 refuse a merge in a flow mapping before it can be read.
        $flowRefused = 'The document is not valid YAML: A merge key (<<) inside a flow mapping is not supported; write that mapping in block style.';

        expect($exception->failure)->toBe(ReadFailure::Syntax)
            ->and($exception->reason)->toBeIn(["The document is not valid YAML: a merge key (<<) takes a map or a list of maps, and the one at /x names {$named}.", ...(str_contains($yaml, '{<<') ? [$flowRefused] : [])]);
        if ($exception->reason !== $flowRefused) {
            expect($exception->parsedLine)->toBe($line);
        }
    }
})->with([
    // Each of these the parser reads as a map of the list's positions.
    'an alias to a list of strings' => ["l: &l [a, b]\nx:\n  <<: *l\n", 6, 'a list holding a string'],
    'an alias to a list of numbers' => ["l: &l [1, 2]\nx:\n  <<: *l\n", 6, 'a list holding a number'],
    'a list holding a list' => ["l: &l [a, b]\nx:\n  <<: [*l]\n", 6, 'a list holding a list'],
    'a block list holding a list' => ["l: &l [a]\nx:\n  <<:\n    - *l\n", 6, 'a list holding a list'],
    'an alias to a list in flow style' => ["l: &l [a, b]\nx: {<<: *l}\n", 5, 'a list holding a string'],
]);

it('refuses a merge the parser reads otherwise than YAML, saying how to write it', function (string $yaml, int $line) use ($head): void {
    try {
        (new DocumentReader)->read($head.$yaml);
        $this->fail('A merge the parser misreads was read.');
    } catch (UnreadableDocumentException $exception) {
        expect($exception->failure)->toBe(ReadFailure::Syntax)
            ->and($exception->reason)->toBe('The document merges into the map at /x in a way symfony/yaml reads otherwise than YAML does. Write each map to merge in place, as <<: *map or <<: [*first, *second].')
            ->and($exception->parsedLine)->toBe($line);
    }
})->with([
    // YAML merges each map; the parser copies the list's positions.
    'an alias to a list of maps' => ["l: &l [{a: 1}, {b: 2}]\nx:\n  <<: *l\n", 6],
    'an anchored list of maps' => ["x:\n  <<: &l\n    - {a: 1}\n", 5],
    // YAML merges the map; the parser reads `<<` as a key holding null.
    'an anchored map on the merge key\'s line' => ["x:\n  <<: &m {a: 1}\n", 5],
    // YAML merges a map with a 0 key; the parser reads it as a list of maps.
    'a map with a 0 key' => ["x:\n  <<: {0: {a: 1}}\n", 5],
    'a block map with a 0 key' => ["x:\n  <<:\n    0: {a: 1}\n", 5],
]);

it('reads every merge YAML and the parser agree on as the JSON it stands for', function (string $yaml, array $merged) use ($head): void {
    $read = (new DocumentReader)->read($head.$yaml);

    expect(json_decode($read->json(), true, 512, JSON_THROW_ON_ERROR)['x'])->toBe($merged);
})->with([
    'an alias to a map' => ["m: &m {a: 1, b: 2}\nx:\n  <<: *m\n  c: 3\n", ['a' => 1, 'b' => 2, 'c' => 3]],
    'a member of its own over one merged' => ["m: &m {a: 1, b: 2}\nx:\n  b: 9\n  <<: *m\n", ['b' => 9, 'a' => 1]],
    'a member of its own after the merge' => ["m: &m {a: 1, b: 2}\nx:\n  <<: *m\n  b: 9\n", ['a' => 1, 'b' => 9]],
    'a list of maps, the first winning' => ["m: &m {a: 1}\nn: &n {a: 2, b: 2}\nx:\n  <<: [*m, *n]\n", ['a' => 1, 'b' => 2]],
    'a block list of maps' => ["m: &m {a: 1}\nn: &n {b: 2}\nx:\n  <<:\n    - *m\n    - *n\n", ['a' => 1, 'b' => 2]],
    'a map written in place' => ["x:\n  <<: {a: 1}\n  b: 2\n", ['a' => 1, 'b' => 2]],
    'an anchored map on the lines below' => ["x:\n  <<: &m\n    a: 1\n  b: 2\n", ['a' => 1, 'b' => 2]],
    'two merge keys' => ["m: &m {a: 1}\nn: &n {b: 2}\nx:\n  <<: *m\n  <<: *n\n", ['a' => 1, 'b' => 2]],
    'a map that merged, merged again' => ["m: &m {a: 1}\nn: &n\n  <<: *m\n  b: 2\nx:\n  <<: *n\n  c: 3\n", ['a' => 1, 'b' => 2, 'c' => 3]],
    'a merge inside a merged map' => ["m: &m {a: 1}\nn: &n\n  inner:\n    <<: *m\nx:\n  <<: *n\n", ['inner' => ['a' => 1]]],
    'a merge of nothing' => ["x:\n  <<: []\n  a: 1\n", ['a' => 1]],
]);

it('reads a quoted << as the key YAML reads it as, or refuses it where the parser merges at it', function () use ($head): void {
    $yaml = $head."m: &m {a: 1}\nx:\n  '<<': *m\n";
    $merges = property_exists(Yaml::parse("m: &m {a: 1}\nx:\n  '<<': *m\n", Yaml::PARSE_OBJECT_FOR_MAP)->x, 'a');

    try {
        $read = json_decode((new DocumentReader)->read($yaml)->json(), true, 512, JSON_THROW_ON_ERROR);
        expect($merges)->toBeFalse()
            ->and($read['x'])->toBe(['<<' => ['a' => 1]]);
    } catch (UnreadableDocumentException $exception) {
        // Releases before 7.4.21 and 8.1.9.
        expect($merges)->toBeTrue()
            ->and($exception->reason)->toBe('The document has a quoted "<<" key at /x, which YAML reads as a key and symfony/yaml before 7.4.21 and 8.1.9 reads as a merge key. Upgrade symfony/yaml, or name the key otherwise.')
            ->and($exception->parsedLine)->toBe(6);
    }
});

it('reads what it must mark or hold to a second reading, within a time and a memory bound', function (string $yaml, int $megabytes): void {
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $started = hrtime(true);

    (new DocumentReader)->read($yaml);

    expect((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0)
        ->and(memory_get_peak_usage() - $before)->toBeLessThan($megabytes * 1024 * 1024);
})->with(static function () use ($head): array {
    return [
        // Every one a candidate to check against the calendar or the keywords, and a mark to put in and take out.
        '10,000 impossible dates' => [$head."x:\n".str_repeat("- 2024-02-30\n", 10_000), 32],
        '10,000 possible dates' => [$head."x:\n".str_repeat("- 2024-02-28\n", 10_000), 32],
        '10,000 mixed-case keywords' => [$head."x:\n".str_repeat("- tRUE\n", 10_000), 32],
        '10,000 non-specific tags' => [$head."x:\n".str_repeat("- ! 12\n", 10_000), 32],
        // Each read twice and held to the second reading.
        '5,000 float tags' => [$head."x:\n".str_repeat("- !!float 1.5\n", 5_000), 32],
        // Each `<<` a name of the twin's own, all after 100,000 lines: the line each is on is counted on from the
        // last, where counting from the top would count 500 million line breaks.
        '100,000 lines, then 5,000 `<<`' => [$head.str_repeat("#\n", 100_000)."x:\n".str_repeat("- 'a <<'\n", 5_000), 64],
        // Matched one at a time: held together, 40,000 matches of the candidate pattern take 100 MB.
        '40,000 possible dates in one string' => [$head.'x: "'.str_repeat('2024-01-01, ', 40_000)."\"\n", 32],
        // A megabyte that is all near-timestamps, none of them one.
        'a line of near-timestamps' => [$head.'x: "'.str_repeat('2024-01-01 1:2 ', 70_000)."\"\n", 32],
        // The marks it would reach for first, all spelt, with a date to mark.
        'every private-use character of the first plane' => [$head.'y: "'.implode('', array_map(mb_chr(...), range(0xE000, 0xF8FF)))."\"\nx: 2024-02-30\n", 32],
    ];
});
