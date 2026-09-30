<?php

declare(strict_types=1);

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Tests\Fixtures\EpochDate;
use Docuccino\Core\Tests\Fixtures\LegacySerializedValue;
use Docuccino\Core\Tests\Fixtures\SleepingValue;

beforeEach(function (): void {
    $this->serializer = new CanonicalJsonSerializer;
});

it('uses two-space indentation and a trailing newline', function (): void {
    $json = $this->serializer->serialize(['a' => ['b' => 1]]);

    expect($json)->toBe("{\n  \"a\": {\n    \"b\": 1\n  }\n}\n");
});

it('emits empty arrays as [] and empty objects as {}', function (): void {
    expect($this->serializer->serialize(['list' => [], 'object' => new stdClass]))
        ->toBe("{\n  \"list\": [],\n  \"object\": {}\n}\n");
});

it('does not escape forward slashes or unicode', function (): void {
    $json = $this->serializer->serialize(['ref' => '#/components/schemas/Fôo']);

    expect($json)->toContain('#/components/schemas/Fôo');
});

it('preserves the member order it is given', function (): void {
    $json = $this->serializer->serialize(['z' => 1, 'a' => 2, 'm' => 3]);

    expect($json)->toBe("{\n  \"z\": 1,\n  \"a\": 2,\n  \"m\": 3\n}\n");
});

it('formats floats deterministically and round-trips them', function (): void {
    $json = $this->serializer->serialize(['x' => 1.5, 'y' => 0.1]);

    expect($json)->toBe("{\n  \"x\": 1.5,\n  \"y\": 0.1\n}\n");

    $decoded = json_decode(trim($json), true);
    expect($decoded)->toBe(['x' => 1.5, 'y' => 0.1]);
});

it('rejects non-finite floats', function (): void {
    $this->serializer->serialize(['x' => INF]);
})->throws(RuntimeException::class);

it('says in advance what it would refuse, so a reader can name where the value came from', function (mixed $value, ?string $reason): void {
    // Whoever reads a value INTO a document — an example out of a YAML file, an attribute argument —
    // has the file and the route in hand; this writer has neither, so it can only throw.
    expect($this->serializer->rejects($value))->toBe($reason);
})->with([
    'nan' => [['x' => NAN], 'Non-finite floats cannot be serialised to JSON'],
    'an infinity' => [INF, 'Non-finite floats cannot be serialised to JSON'],
    'a nested infinity' => [['a' => ['b' => [-INF]]], 'Non-finite floats cannot be serialised to JSON'],
    'a value with no JSON form at all' => [[new SplStack], 'Value is not JSON-serialisable: SplStack'],
    'an ordinary payload' => [['id' => 1, 'name' => 'Sprocket', 'tags' => ['a', 'b']], null],
    'an empty object' => [new stdClass, null],
    'null' => [null, null],
]);

it('refuses a value nested past its depth bound rather than overflowing the stack', function (): void {
    // Unbounded recursion here is a stack overflow — SIGSEGV with no message, no partial output and
    // nothing to catch. The bound turns that into the one failure a caller can be told about.
    $deep = 'leaf';
    for ($i = 0; $i < 200; $i++) {
        $deep = ['k' => $deep];
    }

    expect($this->serializer->rejects($deep))->toBe('Value nests more than 128 levels deep');

    // What the goldens actually reach is 17 levels, so the bound is headroom rather than a ceiling any
    // real document approaches. A list nests the same way an object does.
    $wide = 'leaf';
    for ($i = 0; $i < 120; $i++) {
        $wide = [$wide];
    }

    expect($this->serializer->rejects($wide))->toBeNull();
});

it('encodes floats identically regardless of the serialize_precision ini', function (mixed $value): void {
    $original = ini_get('serialize_precision');

    try {
        ini_set('serialize_precision', '17');
        $at17 = $this->serializer->serialize($value);

        ini_set('serialize_precision', '-1');
        $atMinus1 = $this->serializer->serialize($value);
    } finally {
        ini_set('serialize_precision', $original === false ? '-1' : $original);
    }

    expect($at17)->toBe($atMinus1);
    // Shortest round-trip form is used regardless of the ambient ini.
    expect($at17)->toContain('"a": 0.1')->toContain('"b": 1.5');
})->with([
    // Each writer writes its own floats, and a member named from NUL is one only the walk takes.
    'written natively' => [['a' => 0.1, 'b' => 1.5, 'c' => 1e-7, 'd' => 10.0, 'e' => 1.0 / 3.0]],
    'walked' => [(object) ["\0walked" => true, 'a' => 0.1, 'b' => 1.5, 'c' => 1e-7, 'd' => 10.0, 'e' => 1.0 / 3.0]],
]);

it('renders an integer-valued float as a bare integer (10.0 collapses to 10)', function (): void {
    // Documented consequence of shortest-round-trip float encoding: 10.0 is byte-identical to 10.
    expect($this->serializer->serialize(['x' => 10.0]))->toBe($this->serializer->serialize(['x' => 10]));
});

it('leaves serialize_precision unchanged after encoding a float', function (mixed $value): void {
    ini_set('serialize_precision', '9');

    try {
        $this->serializer->serialize($value);
        expect(ini_get('serialize_precision'))->toBe('9');
    } finally {
        ini_set('serialize_precision', '-1');
    }
})->with([
    'written natively' => [['x' => 0.1]],
    'walked' => [(object) ["\0walked" => true, 'x' => 0.1]],
]);

/*
 * The writer hands a value to `json_encode` where it can and walks it where it cannot, and the walk is what
 * the bytes have always been. So every row here is a value the two could read differently — a member order,
 * a numeric key, an escape, a float, the depth bound itself, a member `json_encode` would silently drop — and
 * names the writer that should answer it, so a guard that stops admitting a value fails here rather than
 * quietly costing the time.
 */
it('writes the bytes its own walk writes, through the writer each value is owed', function (mixed $value, bool $native): void {
    $walk = (new ReflectionMethod(CanonicalJsonSerializer::class, 'encode'))->invoke($this->serializer, $value, 0)."\n";
    $answered = (new ReflectionMethod(CanonicalJsonSerializer::class, 'native'))->invoke($this->serializer, $value);

    expect($this->serializer->serialize($value))->toBe($walk)
        ->and($answered)->toBe($native ? $walk : null);
})->with([
    'members and lists nested' => [['b' => [1, [2, ['c' => 3]]], 'a' => ['x' => []], 'e' => new stdClass], true],
    'a list of objects' => [[(object) ['k' => 'v'], new stdClass, []], true],
    'integer keys out of order' => [[3 => 'c', 1 => 'a'], true],
    'an object whose members are numbers' => [(object) ['0' => 'a', '1' => 'b'], true],
    'every escape' => [['s' => "quote \" backslash \\ tab \t newline \n return \r slash / ünïcödé \u{2028}\u{2029} nul \u{0}"], true],
    'numbers' => [['max' => PHP_INT_MAX, 'negative' => -1, 'sum' => 0.1 + 0.2, 'tiny' => 1e-7, 'negative zero' => -0.0, 'whole' => 10.0], true],
    'a scalar alone' => ['text', true],
    'null alone' => [null, true],
    'the deepest value it takes' => [(static function (): array {
        $deep = [];
        for ($i = 0; $i < 127; $i++) {
            $deep = [$deep];
        }

        return $deep;
    })(), true],
    'an object and a list each reached twice' => [(static function (): array {
        $object = (object) ['k' => 'v'];
        $list = [1, 2];

        return [$object, $list, $object, $list];
    })(), true],
    'a member named from NUL, which json_encode leaves out' => [(object) ["\0hidden" => 1, 'shown' => 2], false],
    'a key named from NUL, which json_encode keeps on an array' => [["\0kept" => 1, 'shown' => 2], true],
    'a member with an empty name' => [(object) ['' => 1], true],
    'a stdClass subclass, which json_encode writes through jsonSerialize()' => [new class extends stdClass implements JsonSerializable
    {
        public int $shown = 1;

        public function jsonSerialize(): string
        {
            return 'not what the walk writes';
        }
    }, false],
    'text that reads like a serialised value' => [['s' => ';O:3:"Foo":0:{}', 't' => ';E:9:"Foo:Bar";', 'u' => "\0 starts with NUL"], true],
    'a whole document' => [(new Canonicalizer)->canonicalize(workedExample()), true],
    'an empty collection at every position' => [(new Canonicalizer)->canonicalize(emptyCollectionPositions()), true],
]);

it('refuses what its walk refuses, in the walk\'s own words', function (Closure $make): void {
    $value = $make();
    $reason = $this->serializer->rejects($value);

    try {
        $this->serializer->serialize($value);
        $thrown = null;
    } catch (RuntimeException $refusal) {
        $thrown = rtrim($refusal->getMessage(), '.');
    }

    expect($reason)->not->toBeNull()
        ->and($thrown)->toBe($reason);
})->with([
    // Objects `json_encode` would write without complaint.
    'an object json_encode writes through jsonSerialize()' => [static fn (): EpochDate => new EpochDate('@0')],
    'an enum case' => [static fn (): array => ['severity' => Severity::Error]],
    // Declaring one is deprecated, and that notice is about the fixture rather than about this writer.
    'an object serialize() writes as null' => [static fn (): array => [@new LegacySerializedValue]],
    'an object whose __sleep() leaves serialize() writing null' => [static fn (): array => [new SleepingValue(keeps: null)]],
    'an object that remembers being a stdClass' => [static fn (): array => [unserialize(serialize((object) ['a' => 1]), ['allowed_classes' => false])]],
    'a closure' => [static fn (): array => [static fn (): int => 1]],
    'an object with no JSON form' => [static fn (): array => ['stack' => new SplStack]],
    // And what `json_encode` refuses too, in words other than the walk's.
    'a resource' => [static fn (): array => ['stream' => fopen('php://memory', 'r')]],
    'a non-finite float' => [static fn (): array => ['x' => NAN]],
    'a string that is not UTF-8' => [static fn (): array => ['s' => "\xB1\x31"]],
    'a value that holds itself' => [static function (): stdClass {
        $self = new stdClass;
        $self->self = $self;

        return $self;
    }],
    'a value past the depth bound' => [static function (): array {
        $deep = [];
        for ($i = 0; $i < 128; $i++) {
            $deep = [$deep];
        }

        return $deep;
    }],
]);

it('calls nothing on a value it refuses, as its walk never did', function (Closure $make): void {
    // A refusal is only as good as the value it leaves behind: a framework model flushes its caches in
    // `__sleep()`, and a large graph behind one object costs its whole size to read. PHP's own `serialize()`
    // calls into each of these, so it is no way to find out what a value holds.
    [$value, $watched] = $make();

    expect(fn () => $this->serializer->serialize($value))->toThrow(RuntimeException::class)
        ->and($watched->calls)->toBe([]);
})->with([
    'an object whose __sleep() changes it' => [static function (): array {
        $model = new SleepingValue;

        return [['example' => $model], $model];
    }],
    'an object serialize() would ask to write itself' => [static function (): array {
        $legacy = @new LegacySerializedValue;

        return [['example' => $legacy], $legacy];
    }],
    'an object held by another, which serialize() would reach through it' => [static function (): array {
        $model = new SleepingValue;

        return [['held' => new ArrayObject([$model])], $model];
    }],
]);

it('refuses an object not yet initialised without initialising it', function (): void {
    $class = new ReflectionClass(SleepingValue::class);
    $ghost = $class->newLazyGhost(static function (SleepingValue $model): void {});

    expect(fn () => $this->serializer->serialize(['example' => $ghost]))->toThrow(RuntimeException::class)
        ->and($class->isUninitializedLazyObject($ghost))->toBeTrue();
})->skip(PHP_VERSION_ID < 80400, 'Lazy objects arrived in PHP 8.4.');

it('leaves json_last_error() as it found it, as its walk always has', function (): void {
    json_decode('{');

    expect(fn () => $this->serializer->serialize(['x' => NAN]))->toThrow(RuntimeException::class);
    $this->serializer->serialize(['a' => [1, 2.5, 'three']]);

    expect(json_last_error_msg())->toBe('Syntax error');
});

it('halves the indent on LF alone, whatever newline PCRE was built to read', function (): void {
    // `json_encode` escapes every byte PCRE could take for a newline but one: 0x85, the second byte of `Å`,
    // which a PCRE built to take any newline reads as NEL. `(*ANY)` ahead of the pattern stands in for that build.
    $value = ['k' => ['s' => "\u{00C5}    four spaces after Å"]];
    $pattern = (new ReflectionClassConstant(CanonicalJsonSerializer::class, 'REINDENT'))->getValue();
    $pretty = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    expect(preg_replace('/(*ANY)'.substr($pattern, 1), '  ', $pretty)."\n")->toBe($this->serializer->serialize($value));
});
