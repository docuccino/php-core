<?php

declare(strict_types=1);

use Docuccino\Core\Provenance\MessagePaths;
use Docuccino\Core\Provenance\RootRelativeSourcePathResolver;

/**
 * The scrubber that lets a thrown message become a published diagnostic. The path ladder itself is
 * `RootRelativeSourcePathResolver`'s and is proved there; what these rows prove is which runs inside a
 * message are handed to it, and which are left alone.
 *
 * The two datasets below are the same dial read from both ends, and they belong together: every
 * relaxation that closes a leak is a chance to reduce something that was never a path, and every
 * exclusion that protects an author's text is a chance to publish a machine. So a change to the
 * matcher has to answer both lists at once — the first says nothing survives that names this machine,
 * the second says nothing is rewritten that the application actually wrote.
 *
 * Every shape a release note says is closed owes the first list a row, and the shapes left open owe the
 * second one, because a leak has already been counted closed on a reading of the code while the suite
 * had nothing that would have failed.
 */
it('reports one failure identically from two checkouts of the same code', function (): void {
    // The determinism promise, stated where it is easiest to break: two developers hit the same bug,
    // the thrown message names their own machine, and the diagnostic the document carries is the same
    // bytes for both. Windows on one side, so the drive letter is in the claim too.
    $thrown = static fn (string $root, string $separator): string => sprintf(
        'FormData::__construct(): Argument #1 ($name) must be of type string, int given, called in %s on line 10',
        $root.$separator.implode($separator, ['app', 'Http', 'Controllers', 'FormController.php']),
    );

    $alice = new MessagePaths(new RootRelativeSourcePathResolver('/home/alice/checkout'));
    $bob = new MessagePaths(new RootRelativeSourcePathResolver('C:\\Users\\bob\\dev\\checkout'));
    // A space in $HOME is ordinary on macOS and Windows, and a run that stopped at the space used to
    // leave the tail of the path standing while LOOKING scrubbed.
    $carol = new MessagePaths(new RootRelativeSourcePathResolver('/Users/ca rol/checkout'));

    expect($alice->relative($thrown('/home/alice/checkout', '/')))
        ->toBe($bob->relative($thrown('C:\\Users\\bob\\dev\\checkout', '\\')))
        ->toBe($carol->relative($thrown('/Users/ca rol/checkout', '/')))
        ->toBe('FormData::__construct(): Argument #1 ($name) must be of type string, int given, called in app/Http/Controllers/FormController.php on line 10');
});

it('scrubs every path in a message that names more than one', function (): void {
    $scrubbed = (new MessagePaths(new RootRelativeSourcePathResolver('/app/root')))
        ->relative('Could not copy /app/root/config/one.yaml to "/app/root/config/two.yaml"');

    expect($scrubbed)->toBe('Could not copy config/one.yaml to "config/two.yaml"');
});

it('leaves nothing in a published message that names the machine it was built on', function (string $case, string $message, string $expected): void {
    // Direction one. Every row is a shape a real build has produced, and every one of them used to
    // put an absolute path, a per-process counter or an install prefix into the document — which is a
    // determinism defect, since the same code on the next machine emits different bytes.
    $scrubbed = (new MessagePaths(new RootRelativeSourcePathResolver('/app/root')))->relative($message);

    expect($scrubbed)->toBe($expected)
        ->and($scrubbed)->not->toContain('/app/root/');
})->with([
    // The analyser is phar-resident, so its own internal errors name a path in ITS spelling. The
    // interior of an archive is the same wherever the archive sits, so only the archive relativises.
    [
        'the analyser\'s own phar',
        'Internal error in phar:///opt/brew/Cellar/phpstan/2.1.0/libexec/phpstan.phar/src/Analyser/NodeScopeResolver.php',
        'Internal error in phar://phpstan.phar/src/Analyser/NodeScopeResolver.php',
    ],
    [
        'a phar inside the project',
        'Internal error in phar:///app/root/vendor/phpstan/phpstan/phpstan.phar/src/Analyser/X.php',
        'Internal error in phar://vendor/phpstan/phpstan/phpstan.phar/src/Analyser/X.php',
    ],
    ['another local stream wrapper', 'Could not open file:///app/root/app/X.php', 'Could not open file://app/X.php'],
    // `::class` on an anonymous class is a base name, a NUL byte, the absolute file, the line, and a
    // counter of the anonymous classes the PROCESS declared first. Two runs need not agree on the last.
    [
        'an anonymous class inside a sentence',
        "Expected FormData, got class@anonymous\0/app/root/app/Support/Inline.php:18\$1f",
        'Expected FormData, got class@anonymous declared in app/Support/Inline.php:18',
    ],
    // A UNC path and a drive glued to a word character are shapes nothing but a path has, so both are
    // reduced whatever precedes them.
    ['a UNC share', 'Failed to read \\\\SERVER\\share\\app\\Http\\X.php', 'Failed to read X.php'],
    ['a drive glued to a word', 'read atC:\\Users\\dev\\app\\X.php ok', 'read atX.php ok'],
    // A path outside the project and outside any package: no root to strip, so the name is all that
    // may survive. This is the ladder's documented degradation, reached through a message.
    [
        'a path under no root at all',
        'file_get_contents(/elsewhere/cache/acme/Reader.php): Failed to open stream',
        'file_get_contents(Reader.php): Failed to open stream',
    ],
    // A directory one segment under the root relativises to exactly its own basename, which is also what
    // the ladder answers when it recognised nothing — so a predicate reading the ANSWER cannot tell the
    // two apart, and published these whole. They are the framework's own directories, so the failure
    // reaching a route diagnostic is an ordinary one: a storage permission, an unreadable vendor tree.
    ['a directory one segment under the root', 'mkdir(/app/root/storage): Permission denied', 'mkdir(storage): Permission denied'],
    ['a package tree the build could not read', 'scandir(/app/root/vendor): Permission denied', 'scandir(vendor): Permission denied'],
    ['a file with no extension under the root', 'require(/app/root/artisan) failed', 'require(artisan) failed'],
    // The root itself has nothing left after the strip, and the one thing `relative()` can answer with
    // there — the name of the directory the checkout sits in — is a different string on every machine.
    ['the project root itself', 'mkdir(/app/root): Permission denied', 'mkdir(): Permission denied'],
]);

it('tells a root it recognised from a root it never found', function (): void {
    // The predicate reason 3 turns on, stated as the one pair that isolates it: the two paths differ only
    // in whether a root accounts for them, and the ladder answers BOTH with a bare `storage`. So an
    // answer longer than the basename is not proof a prefix was stripped — asking with segments the
    // ladder cannot have invented is (MessagePaths::PROBE).
    $paths = new MessagePaths(new RootRelativeSourcePathResolver('/app/root'));

    expect($paths->relative('mkdir(/app/root/storage) failed'))->toBe('mkdir(storage) failed')
        ->and($paths->relative('mkdir(/elsewhere/storage) failed'))->toBe('mkdir(/elsewhere/storage) failed');
});

it('crosses a space inside a directory name, and stops at one that starts a sentence', function (string $case, string $message, string $expected): void {
    // Both ends of the space tolerance. A `$HOME` with a space in it is the ordinary macOS and Windows
    // case and its segments have text against both separators; a sentence carrying on after a path has no
    // separator left, and a second path in the same sentence puts a space right against the next one.
    expect((new MessagePaths(new RootRelativeSourcePathResolver('/app/root')))->relative($message))
        ->toBe($expected);
})->with([
    ['a space inside a directory name', 'read /Users/ca rol/Library/pkg/Reader.php failed', 'read Reader.php failed'],
    ['a sentence carrying on after a path', 'See /docs/reference/configuration and open app.php', 'See /docs/reference/configuration and open app.php'],
    ['a second path in the same sentence', 'See /docs/reference/configuration and /docs/other/x.php', 'See /docs/reference/configuration and x.php'],
]);

it('reduces a path under a $HOME that has a space in it, wherever under it the path sits', function (string $case, string $message, string $expected): void {
    // The shape was closed for exactly one position — a path under the base path itself — and left
    // standing everywhere else under the same home, which is where a global cache and an installed phar
    // sit. So the message LOOKED scrubbed while naming the machine's own user.
    $scrubbed = (new MessagePaths(new RootRelativeSourcePathResolver('/Users/ca rol/checkout')))->relative($message);

    expect($scrubbed)->toBe($expected)
        ->and($scrubbed)->not->toContain('ca rol');
})->with([
    ['under the base path', 'read /Users/ca rol/checkout/app/X.php failed', 'read app/X.php failed'],
    ['under the home, outside every root', 'read /Users/ca rol/Library/Caches/pkg/Reader.php failed', 'read Reader.php failed'],
    ['a phar installed under the home', 'Internal error in phar:///Users/ca rol/bin/phpstan.phar/src/X.php', 'Internal error in phar://phpstan.phar/src/X.php'],
]);

it('leaves alone every run that a machine did not put there', function (string $case, string $message): void {
    // Direction two, and the one that must be impossible to get wrong: an over-scrub does not leak a
    // machine, it makes the product state something the application never said. Each row here is text
    // an author wrote or a tool quoted back, and each was reduced to its last segment before.
    expect((new MessagePaths(new RootRelativeSourcePathResolver('/app/root')))->relative($message))
        ->toBe($message);
})->with([
    // Reachable on EVERY build: the validator throws `Unresolved reference: {$ref}` and the pointer is
    // the only thing naming the component that could not be resolved.
    ['a JSON pointer', 'Unresolved reference: #/components/schemas/User/properties/password'],
    ['a $ref in quotes', 'Points at $ref "#/components/schemas/Order/properties/total".'],
    // A malformed overlay makes the YAML parser quote the offending line back. Reduce it and the
    // diagnostic sends the author hunting for text nobody wrote.
    ['a route signature a parser quoted', 'Malformed inline YAML at line 3 (near "GET /api/forms/{form}").'],
    ['a route signature with no parameter', 'Unknown route GET /api/forms'],
    ['a route signature with a format suffix', 'Unknown route GET /api/users.json'],
    // Reducing a rule states a DIFFERENT, valid-looking rule — the worst of the four, because nothing
    // downstream can tell it was rewritten.
    ['a rule whose regex holds a separator', 'Rule "regex:/^\\d+\\/\\d+$/" could not be read'],
    ['a rule whose regex holds none', 'Rule "regex:/^[a-z]+$/" could not be read'],
    ['a root-relative documentation link', 'See /docs/reference/configuration for the key.'],
    // The price of reason 4, and the shape that stays open because of it: a directory under no root
    // names no file, and nothing tells it from the link above. Reducing it would state a path the
    // application never wrote, which is the direction that must be impossible — so it stands, spaced
    // `$HOME` and all.
    ['a directory under no root at all', 'scandir(/Users/ca rol/Library/Caches) failed'],
    ['a home-relative path', 'Reading ~/Projects/app/config.php failed'],
    ['a URL', 'GET https://api.example.com/v1/forms returned 500'],
    ['a URL naming a file', 'Fetching https://cdn.example.com/assets/app.js failed'],
    ['a namespaced class', 'Class App\\Http\\Controllers\\FormController does not exist'],
    ['a media type', 'Body is application/vnd.api+json'],
    ['a date', 'Expected 2026/08/25 style'],
    ['a single-segment absolute path', 'The directory /tmp is not writable'],
    ['no path at all', 'Undefined array key "form"'],
]);

it('takes every local stream wrapper as proof, and no other scheme', function (string $prefix, bool $reduced): void {
    // The whole table, plus the schemes that name a host or nothing on disk. A wrapper is the one
    // positive proof a run is a path, so a scheme missing from the table leaks and a scheme wrongly in
    // it reduces something that was never a file.
    $message = 'Could not open '.$prefix.'/app/root/app/Support/Inline.php';

    expect((new MessagePaths(new RootRelativeSourcePathResolver('/app/root')))->relative($message))
        ->when($reduced, fn ($it) => $it->toBe('Could not open '.$prefix.'app/Support/Inline.php'))
        ->when(! $reduced, fn ($it) => $it->toBe($message));
})->with([
    ['file://', true],
    ['phar://', true],
    ['zip://', true],
    ['compress.zlib://', true],
    ['compress.bzip2://', true],
    // Not in the table: a URL names a host, and neither of these names a file this machine holds.
    ['https://', false],
    ['data://', false],
]);

it('reads a route signature after every method a route can carry', function (string $method): void {
    // The whole table. A signature is quoted back by a YAML parser verbatim, so the method in front of
    // it is what says "this is a route" — and the format suffix is what would otherwise make it look
    // like a file.
    $message = sprintf('Unknown route %s /api/users.json', $method);

    expect((new MessagePaths(new RootRelativeSourcePathResolver('/app/root')))->relative($message))
        ->toBe($message);
})->with(['GET', 'PUT', 'HEAD', 'POST', 'PATCH', 'TRACE', 'QUERY', 'DELETE', 'OPTIONS']);

it('redacts the install prefix PHP appends to a failed include', function (): void {
    // The tail PHP puts on every failed include is a colon-separated list of directories, which no
    // path grammar can be relaxed enough to read without reading a `regex:/…/` the same way. They are
    // prefixes this process can name for itself, so they go literally instead.
    $entries = array_values(array_filter(
        explode(PATH_SEPARATOR, (string) ini_get('include_path')),
        static fn (string $entry): bool => str_starts_with($entry, '/') && substr_count($entry, '/') >= 2,
    ));

    // Anti-vacuity: nothing is proved on a machine whose include_path holds no absolute directory.
    expect($entries)->not->toBe([]);

    $message = sprintf("Failed opening required 'x.php' (include_path='%s')", implode(PATH_SEPARATOR, $entries));
    $scrubbed = (new MessagePaths(new RootRelativeSourcePathResolver('/app/root')))->relative($message);

    foreach ($entries as $entry) {
        expect($scrubbed)->not->toContain($entry);
    }
});

it('scrubs the file a callable label names and leaves the rest of the label alone', function (string $case, string $label, string $expected): void {
    // The other kind of fragment that reaches it: not a thrown message but a locator for something
    // anonymous, where the file IS the name. Whichever side of the file the locator sits on, the run
    // ends at the `:` before the line, so what the author needs — a file they can open and a line to
    // open it at — survives the scrub.
    expect((new MessagePaths(new RootRelativeSourcePathResolver('/app/root')))->relative($label))
        ->toBe($expected);
})->with([
    ['a closure named after its file', '/app/root/bootstrap/app.php::closure@42', 'bootstrap/app.php::closure@42'],
    ['a closure named before its file', 'closure@/app/root/bootstrap/app.php:42', 'closure@bootstrap/app.php:42'],
    ['a closure in a package', '/app/root/vendor/acme/src/Handlers.php::closure@7', 'vendor/acme/src/Handlers.php::closure@7'],
    ['a class and a method', 'App\\Exceptions\\Renderer::__invoke', 'App\\Exceptions\\Renderer::__invoke'],
    ['a label already relative', 'bootstrap/app.php::closure@42', 'bootstrap/app.php::closure@42'],
    ['a label naming one segment', 'app.php::closure@42', 'app.php::closure@42'],
]);
