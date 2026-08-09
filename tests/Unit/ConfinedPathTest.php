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
