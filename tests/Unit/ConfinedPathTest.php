<?php

declare(strict_types=1);

use Docuccino\Core\Support\ConfinedPath;

/**
 * Path confinement (security L2): a user/config/attribute-supplied relative path must resolve
 * inside the base directory, rejecting `..` traversal (lexically, even for non-existent targets)
 * and symlink escapes (via realpath when the target exists).
 */
it('resolves a plain relative path under the base', function (): void {
    expect(ConfinedPath::resolve('/app', 'resources/docs/api.md'))->toBe('/app/resources/docs/api.md');
});

it('collapses harmless . and .. that stay within the base', function (): void {
    expect(ConfinedPath::resolve('/app', 'resources/../resources/api.md'))->toBe('/app/resources/api.md');
});

it('rejects a relative traversal that escapes the base', function (): void {
    expect(ConfinedPath::resolve('/app', '../../etc/passwd'))->toBeNull()
        ->and(ConfinedPath::resolve('/app', 'resources/../../secret'))->toBeNull();
});

it('treats a leading slash as base-relative, never absolute (so it cannot read /etc/passwd)', function (): void {
    // The user value is always joined under the base, so an absolute-looking path is confined,
    // not honoured as-is — /etc/passwd resolves harmlessly inside the app tree.
    expect(ConfinedPath::resolve('/app', '/etc/passwd'))->toBe('/app/etc/passwd')
        ->and(ConfinedPath::resolve('/app', '/resources/api.md'))->toBe('/app/resources/api.md');
});

it('rejects a symlink that tunnels out of the base', function (): void {
    $base = sys_get_temp_dir().'/docuccino-confine-'.uniqid();
    mkdir($base, 0755, true);
    $outside = sys_get_temp_dir().'/docuccino-outside-'.uniqid().'.md';
    file_put_contents($outside, 'secret');
    symlink($outside, $base.'/link.md');

    expect(ConfinedPath::resolve($base, 'link.md'))->toBeNull();

    @unlink($base.'/link.md');
    @unlink($outside);
    @rmdir($base);
});

it('refuses a path no filesystem can hold rather than raising on it', function (string $case, ?string $resolved): void {
    // PHP lets an author write a NUL byte by accident — a stray escape in a double-quoted attribute
    // argument — and every function that would answer for one (realpath, file_get_contents, is_dir)
    // raises a ValueError instead. A security control whose failure mode is a throw costs its caller
    // more than the thing it guarded: the nearest catch above the readers is per-route, so one stray
    // escape took the whole route and reported a PHP-internals string naming no attribute and no
    // remedy. It is refused here, as the same outcome a traversal gets, and nothing is read either way.
    expect($resolved)->toBeNull();
})->with([
    ['a NUL in the relative path', ConfinedPath::resolve('/app', "docs/a.json\0.txt")],
    ['a NUL alone', ConfinedPath::resolve('/app', "\0")],
    ['a NUL in the base', ConfinedPath::resolve("/app\0", 'docs/a.json')],
    ['a NUL in a relative configured dir', ConfinedPath::configuredDir('/app', "content\0/x")],
    // The absolute branch takes a configured directory verbatim, so it never reached resolve() at all.
    ['a NUL in an absolute configured dir', ConfinedPath::configuredDir('/app', "/srv/content\0/x")],
]);
