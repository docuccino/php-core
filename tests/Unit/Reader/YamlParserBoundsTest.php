<?php

declare(strict_types=1);

use Composer\Semver\Intervals;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Docuccino\Core\Reader\DocumentReader;
use Docuccino\Core\Reader\ReadFailure;
use Docuccino\Core\Reader\UnreadableDocumentException;

/*
 * Two walks happen inside symfony/yaml, before any budget of the reader's sees a value, so only the parser
 * can bound them: how deep it nests, and how deep it recurses lexing a flow collection written over several
 * lines. A release that bounds neither builds whatever depth it is handed, and PHP segfaults freeing it once
 * the reader has refused it as too deep: 400 KB of `[`, or 250 KB of `{a: `. A release that bounds the first
 * and not the second spends 583 MB and 6 s lexing 1 MB of `[`, so 200 KB ends PHP at its default memory
 * limit. symfony/yaml bounds both from 7.4.18 and 8.1.6; the 8.0 line never bounded the second.
 *
 * Three more walks cost more than the text, and nothing outside the parser sees them happen: a map inside flow
 * lists was read again for every scalar after it, so 149 bytes nested 11 deep took 6 s and each level
 * about triples it; blank lines under 100 nested blocks were copied once per level, so 139 KB took 4 s
 * and 239 MB; and a run of spaces before a key's colon was scanned once per space, so 128 KB took 2.6 s.
 * symfony/yaml bounds all three from 7.4.23 and 8.1.11.
 *
 * Nothing can catch any of these, so no release missing a bound may be installable. Composer's default
 * advisory block (CVE-2026-45133, CVE-2026-109413, CVE-2026-123792) refuses the same releases, so the floor
 * is what `--prefer-lowest` runs.
 */

/** The releases that leave one of the walks unbounded, as one constraint. */
const UNBOUNDED_YAML_RELEASES = '<7.4.23 || >=8.0.0-dev <8.1.11';

it('admits no symfony/yaml release that leaves the parser unbounded, in any manifest that requires it', function (): void {
    $root = dirname(__DIR__, 5);
    $parser = new VersionParser;
    $unbounded = $parser->parseConstraints(UNBOUNDED_YAML_RELEASES);

    $requiring = [];
    foreach ([$root.'/composer.json', ...(array) glob($root.'/php/*/composer.json')] as $path) {
        $manifest = json_decode((string) file_get_contents((string) $path), true, 512, JSON_THROW_ON_ERROR);
        $constraint = is_array($manifest) && is_array($manifest['require'] ?? null) ? ($manifest['require']['symfony/yaml'] ?? null) : null;
        if (! is_string($constraint)) {
            continue;
        }

        $requiring[] = basename(dirname((string) $path));

        expect(Intervals::haveIntersections($parser->parseConstraints($constraint), $unbounded))->toBeFalse((string) $path)
            // And no tighter than the bounds need: the first release of each line that has both is admitted.
            ->and(Semver::satisfies('7.4.23', $constraint))->toBeTrue((string) $path)
            ->and(Semver::satisfies('8.1.11', $constraint))->toBeTrue((string) $path);
    }

    // A glob that found nothing would admit everything.
    expect($requiring)->toContain('core');
});

// Whatever the parser built is freed as the refusal leaves the reader, which is where a release that built
// all of it segfaults, taking this file's worker with it. What a bounded release holds on the way is linear in
// the text, at most about 20 bytes a byte here, where the lexing walk it bounds holds hundreds: 583 MB for
// 1 MB of `[`.
it('refuses a document nested far past the limit cheaply, and survives freeing it', function (string $bytes, ReadFailure $failure, string $reason): void {
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $started = hrtime(true);

    try {
        (new DocumentReader)->read($bytes);
        $this->fail('A document nested hundreds of thousands deep was read.');
    } catch (UnreadableDocumentException $exception) {
        expect($exception->failure)->toBe($failure)
            ->and($exception->reason)->toBe($reason)
            ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(0.5)
            ->and(memory_get_peak_usage() - $before)->toBeLessThan(32 * strlen($bytes));
    }
})->with([
    // Text that opens like JSON is read as JSON first, so its refusal is the JSON parser's.
    'a flow sequence opened 200,000 times (400 KB)' => [str_repeat('[', 200_000).str_repeat(']', 200_000), ReadFailure::TooDeep, 'The document nests more than 128 levels deep, past what can be written back out.'],
    'a flow mapping opened 200,000 times (1 MB)' => ["openapi: 3.1.0\nx: ".str_repeat('{a: ', 200_000).'1'.str_repeat('}', 200_000)."\n", ReadFailure::Syntax, 'The document is not valid YAML: Maximum nesting depth of 128 exceeded.'],
    // Lexed before it is parsed, which is the walk 7.4.18 bounded.
    'a flow sequence opened 300,000 times, one bracket a line (900 KB)' => ["openapi: 3.1.0\nx: ".str_repeat("[\n", 300_000).str_repeat(']', 300_000)."\n", ReadFailure::Syntax, 'The document is not valid YAML: Maximum nesting depth of 128 exceeded.'],
]);

it('reads a map nested in flow lists in time that grows with the text, not with the depth', function (): void {
    // The first of the three walks above, at the depth that took an unbounded release 6 s.
    $bytes = "openapi: 3.1.0\ninfo: {title: Orders, version: '1'}\npaths: {}\nx: ".str_repeat('[a: ', 11).'1'.str_repeat(', 1]', 11)."\n";
    $started = hrtime(true);

    $read = (new DocumentReader)->read($bytes);

    expect((hrtime(true) - $started) / 1e9)->toBeLessThan(0.5)
        ->and(substr_count($read->json(), '"a"'))->toBe(11);
});
