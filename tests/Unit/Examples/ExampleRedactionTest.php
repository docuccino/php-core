<?php

declare(strict_types=1);

use Docuccino\Core\Examples\ExampleRedaction;
use Docuccino\Core\Lint\SensitiveFieldLintOptions;

/**
 * A recorded body is captured from a live request and published in a document, so this is the part of
 * the feature that must not have a hole in it. Dataset coverage over EVERY sensitive-name heuristic
 * and EVERY credential shape, the unknown-entry degradation for both, and the two limits the
 * replacement deliberately keeps (types survive; a name taints what sits under it) — plus the numbers
 * it reports instead of replacing, which is how a secret that is not a string still refuses to publish.
 */
it('replaces a value under every sensitive member name', function (string $name): void {
    [$body, $pointers] = (new ExampleRedaction)->apply([$name => 'a-real-value']);

    expect($body)->toBe([$name => ExampleRedaction::PLACEHOLDER])
        ->and($pointers)->toBe(['/'.$name]);
})->with(array_map(
    static fn (string $token): array => [$token],
    array_keys(SensitiveFieldLintOptions::DEFAULT_PATTERNS),
));

it('replaces a value that IS a credential whatever it is called', function (string $value): void {
    [$body, $pointers] = (new ExampleRedaction)->apply(['detail' => $value]);

    expect($body)->toBe(['detail' => ExampleRedaction::PLACEHOLDER])
        ->and($pointers)->toBe(['/detail']);
})->with([
    'a PEM private key' => ["-----BEGIN RSA PRIVATE KEY-----\nMIIB\n-----END RSA PRIVATE KEY-----"],
    'an AWS access key id' => ['AKIAIOSFODNN7EXAMPLE'],
    'a temporary AWS key id' => ['ASIAIOSFODNN7EXAMPLE'],
    'a GitHub token' => ['ghp_1234567890abcdefghijklmnopqrstuvwx'],
    'a GitHub fine-grained token' => ['github_pat_1234567890abcdefghijklmnop'],
    'a live Stripe secret key' => ['sk_live_abcdefghij1234567890'],
    'a live Stripe restricted key' => ['rk_live_abcdefghij1234567890'],
    'a Slack token' => ['xoxb-1234567890-abcdefghij'],
    'a JWT' => ['eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.dBjftJeZ4CVPmB92K27uhbUJU1p1r_wW1gFWFOEjXk'],
    'a URL with credentials' => ['postgres://user:hunter2@db.internal/app'],
]);

it('leaves an ordinary value alone', function (mixed $value): void {
    [$body, $pointers] = (new ExampleRedaction)->apply(['detail' => $value]);

    expect($body)->toBe(['detail' => $value])
        ->and($pointers)->toBe([]);
})->with([
    'prose' => ['The invoice could not be voided.'],
    'a uuid' => ['0193a1f0-0000-7000-8000-000000000000'],
    'a hash' => ['5e884898da28047151d0e56f8dc6292773603d0d6aabbdd62a11ef721d1542d8'],
    'a base64 payload' => ['SGVsbG8sIHdvcmxkLiBUaGlzIGlzIGEgc2FtcGxlLg=='],
    'a number' => [42],
    'a bool' => [true],
    'null' => [null],
    'an empty string' => [''],
]);

it('keeps the type of everything it does not replace, so the example still fits its schema', function (): void {
    [$body] = (new ExampleRedaction)->apply(['token_count' => 5, 'token_expires' => true, 'token' => 'abc']);

    expect($body)->toBe(['token_count' => 5, 'token_expires' => true, 'token' => ExampleRedaction::PLACEHOLDER]);
});

it('reports a number under a credential name without replacing it', function (string $token): void {
    [$body, $pointers] = (new ExampleRedaction)->apply([$token => 123456]);

    expect($body)->toBe([$token => 123456])
        ->and($pointers)->toBe(['/'.$token]);
})->with(array_map(
    static fn (string $token): array => [$token],
    array_keys(SensitiveFieldLintOptions::DEFAULT_PATTERNS),
));

it('reads a number only from the name it IS, never from one it merely contains', function (mixed $value): void {
    [$body, $pointers] = (new ExampleRedaction)->apply(['token_count' => $value]);

    expect($body)->toBe(['token_count' => $value])
        ->and($pointers)->toBe([]);
})->with([
    'a count' => [5],
    'a timestamp' => [1712345678],
]);

it('leaves a non-number under a credential name alone, because it carries no secret', function (mixed $value): void {
    [$body, $pointers] = (new ExampleRedaction)->apply(['token' => $value]);

    expect($body)->toBe(['token' => $value])
        ->and($pointers)->toBe([]);
})->with([
    'true' => [true],
    'false' => [false],
    'null' => [null],
    'an empty string' => [''],
]);

it('judges a number by its own name and never by an inherited one', function (): void {
    [$body, $pointers] = (new ExampleRedaction)->apply(['token' => ['expires_in' => 3600, 'value' => 'abc']]);

    expect($body)->toBe(['token' => ['expires_in' => 3600, 'value' => ExampleRedaction::PLACEHOLDER]])
        ->and($pointers)->toBe(['/token/value']);
});

it('honours the pointer safelist for a number as it does for a string', function (): void {
    $options = new SensitiveFieldLintOptions(allow: ['/cvv']);

    [$body, $pointers] = (new ExampleRedaction($options))->apply(['cvv' => 123]);

    expect($body)->toBe(['cvv' => 123])
        ->and($pointers)->toBe([]);
});

it('goes on reporting a numeric credential the recorder could not replace', function (): void {
    [$clean] = (new ExampleRedaction)->apply(['card_number' => 4111111111111111, 'name' => 'Acme']);

    expect((new ExampleRedaction)->findings($clean))->toBe(['/card_number']);
});

it('takes a sensitive member name to mean everything beneath it', function (): void {
    [$body, $pointers] = (new ExampleRedaction)->apply([
        'api_secret' => ['id' => 'ck_1', 'value' => 'wxyz', 'rotations' => 3],
        'name' => 'Acme',
    ]);

    expect($body)->toBe([
        'api_secret' => ['id' => ExampleRedaction::PLACEHOLDER, 'value' => ExampleRedaction::PLACEHOLDER, 'rotations' => 3],
        'name' => 'Acme',
    ])->and($pointers)->toBe(['/api_secret/id', '/api_secret/value']);
});

it('reads a list index as a position and never as a member name', function (): void {
    [$body, $pointers] = (new ExampleRedaction)->apply(['data' => [['secret' => 's'], ['name' => 'n']]]);

    expect($body)->toBe(['data' => [['secret' => ExampleRedaction::PLACEHOLDER], ['name' => 'n']]])
        ->and($pointers)->toBe(['/data/0/secret']);
});

it('escapes a member name a JSON pointer would otherwise read as two', function (): void {
    [, $pointers] = (new ExampleRedaction)->apply(['a/b~c' => ['token' => 'x']]);

    expect($pointers)->toBe(['/a~1b~0c/token']);
});

it('honours the leakage safelist by pointer, and never by member name', function (SensitiveFieldLintOptions $options, array $expected): void {
    [$body] = (new ExampleRedaction($options))->apply(['reset_token' => 'public-value']);

    expect($body)->toBe($expected);
})->with([
    'by pointer' => [new SensitiveFieldLintOptions(allow: ['/reset_token']), ['reset_token' => 'public-value']],
    'by name' => [new SensitiveFieldLintOptions(allow: ['reset_token']), ['reset_token' => ExampleRedaction::PLACEHOLDER]],
    'neither' => [new SensitiveFieldLintOptions, ['reset_token' => ExampleRedaction::PLACEHOLDER]],
]);

it('goes on reporting a name-safelisted credential in a committed body', function (): void {
    $redaction = new ExampleRedaction(new SensitiveFieldLintOptions(allow: ['access_token']));

    expect($redaction->findings(['access_token' => '1|hR4kQzVb8Nn2tYpLxWc7Jd5']))->toBe(['/access_token']);
});

it('takes a pointer safelist as the one way to publish a value under a sensitive name', function (): void {
    $options = new SensitiveFieldLintOptions(allow: ['/meta/next_page_token']);
    $body = ['meta' => ['next_page_token' => 'cursor:42'], 'api_key' => 'live-value'];

    [$redacted, $pointers] = (new ExampleRedaction($options))->apply($body);

    expect($redacted)->toBe(['meta' => ['next_page_token' => 'cursor:42'], 'api_key' => ExampleRedaction::PLACEHOLDER])
        ->and($pointers)->toBe(['/api_key']);
});

it('applies an application\'s own heuristics too', function (): void {
    $options = (new SensitiveFieldLintOptions)->withPatterns(['sortcode' => 'a bank sort code']);

    [$body] = (new ExampleRedaction($options))->apply(['sort_code' => '20-00-00']);

    expect($body)->toBe(['sort_code' => ExampleRedaction::PLACEHOLDER]);
});

it('reports what a committed body still holds without changing it', function (): void {
    $body = ['api_key' => 'live-value', 'name' => 'Acme', 'note' => 'ghp_1234567890abcdefghijklmnopqrstuvwx'];

    expect((new ExampleRedaction)->findings($body))->toBe(['/api_key', '/note']);
});

it('finds nothing in a body the recorder already cleaned', function (): void {
    [$clean] = (new ExampleRedaction)->apply(['api_key' => 'live-value', 'name' => 'Acme']);

    expect((new ExampleRedaction)->findings($clean))->toBe([]);
});

it('leaves an empty object an empty object', function (): void {
    [$body, $pointers] = (new ExampleRedaction)->apply(['meta' => (object) []]);

    // A walk that rebuilds every map must hand back a `{}` and not the `[]` a PHP array shares with it.
    expect($body['meta'])->toBeInstanceOf(stdClass::class)
        ->and(json_encode($body))->toBe('{"meta":{}}')
        ->and($pointers)->toBe([]);
});

it('redacts inside an object whose member names look like list indexes', function (): void {
    [$body, $pointers] = (new ExampleRedaction)->apply(['by_id' => (object) ['0' => (object) ['token' => 'abc']]]);

    expect($pointers)->toBe(['/by_id/0/token'])
        ->and($body)->toEqual(['by_id' => (object) ['0' => (object) ['token' => ExampleRedaction::PLACEHOLDER]]]);
});

it('leaves a body deeper than it walks exactly as it found it', function (): void {
    $deep = 'ghp_1234567890abcdefghijklmnopqrstuvwx';
    for ($i = 0; $i < 200; $i++) {
        $deep = ['next' => $deep];
    }

    [$body, $pointers] = (new ExampleRedaction)->apply($deep);

    expect($pointers)->toBe([])
        ->and($body)->toBe($deep);
});
