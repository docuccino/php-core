<?php

declare(strict_types=1);

use Docuccino\Core\Support\GeneratedDirectory;

/**
 * A directory Docuccino generates for its own machine output ignores itself, the way Laravel's
 * generated `storage/` directories do — so an enabled cache never turns up in `git status`. The
 * writer is quiet: it never rewrites a user's `.gitignore` and never raises on a path it cannot
 * write.
 */
it('creates the directory with a self-ignoring .gitignore', function (): void {
    $base = sys_get_temp_dir().'/docuccino-generated-dir-'.bin2hex(random_bytes(6));

    GeneratedDirectory::ensure($base.'/nested');

    expect(is_dir($base.'/nested'))->toBeTrue()
        ->and(file_get_contents($base.'/nested/.gitignore'))->toBe("*\n!.gitignore\n");

    @unlink($base.'/nested/.gitignore');
    @rmdir($base.'/nested');
    @rmdir($base);
});

it('drops the .gitignore into a directory that already exists without one', function (): void {
    $base = sys_get_temp_dir().'/docuccino-generated-dir-'.bin2hex(random_bytes(6));
    mkdir($base, 0755, true);

    GeneratedDirectory::ensure($base);

    expect(file_get_contents($base.'/.gitignore'))->toBe("*\n!.gitignore\n");

    @unlink($base.'/.gitignore');
    @rmdir($base);
});

it('never rewrites a .gitignore the user has customised', function (): void {
    $base = sys_get_temp_dir().'/docuccino-generated-dir-'.bin2hex(random_bytes(6));
    mkdir($base, 0755, true);
    file_put_contents($base.'/.gitignore', "# mine\n!keep-me.json\n");

    GeneratedDirectory::ensure($base);
    GeneratedDirectory::ensure($base);

    expect(file_get_contents($base.'/.gitignore'))->toBe("# mine\n!keep-me.json\n");

    @unlink($base.'/.gitignore');
    @rmdir($base);
});

it('tolerates a path it cannot create (a file sits where the parent should be)', function (): void {
    $base = sys_get_temp_dir().'/docuccino-generated-dir-'.bin2hex(random_bytes(6));
    mkdir($base, 0755, true);
    file_put_contents($base.'/file', 'not a directory');

    GeneratedDirectory::ensure($base.'/file/nested');

    expect(is_dir($base.'/file/nested'))->toBeFalse()
        ->and(file_get_contents($base.'/file'))->toBe('not a directory');

    @unlink($base.'/file');
    @rmdir($base);
});

it('tolerates an existing directory it cannot write to', function (): void {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        test()->markTestSkipped('running as root — mode bits deny nothing');
    }

    $base = sys_get_temp_dir().'/docuccino-generated-dir-'.bin2hex(random_bytes(6));
    mkdir($base, 0555, true);

    GeneratedDirectory::ensure($base);

    expect(is_file($base.'/.gitignore'))->toBeFalse();

    @chmod($base, 0755);
    @rmdir($base);
});
