<?php

declare(strict_types=1);

use Docuccino\Core\Canonical\CanonicalJsonSerializer;

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

it('encodes floats identically regardless of the serialize_precision ini', function (): void {
    $value = ['a' => 0.1, 'b' => 1.5, 'c' => 1e-7, 'd' => 10.0, 'e' => 1.0 / 3.0];

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
});

it('renders an integer-valued float as a bare integer (10.0 collapses to 10)', function (): void {
    // Documented consequence of shortest-round-trip float encoding: 10.0 is byte-identical to 10.
    expect($this->serializer->serialize(['x' => 10.0]))->toBe($this->serializer->serialize(['x' => 10]));
});

it('leaves serialize_precision unchanged after encoding a float', function (): void {
    ini_set('serialize_precision', '9');

    try {
        $this->serializer->serialize(['x' => 0.1]);
        expect(ini_get('serialize_precision'))->toBe('9');
    } finally {
        ini_set('serialize_precision', '-1');
    }
});
