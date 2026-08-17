<?php

declare(strict_types=1);

use Docuccino\Core\Provenance\MessagePaths;
use Docuccino\Core\Provenance\RootRelativeSourcePathResolver;

/**
 * The scrubber that lets a thrown message become a published diagnostic. The path ladder itself is
 * `RootRelativeSourcePathResolver`'s and is proved there; what these rows prove is which runs inside a
 * message are handed to it, and which are left alone.
 */
it('reports one failure identically from two checkouts of the same code', function (): void {
    // The determinism promise, stated where it is easiest to break: two developers hit the same bug,
    // the thrown message names their own machine, and the diagnostic the document carries is the same
    // bytes for both. Windows on one side, so the drive letter is in the claim too.
    $thrown = static fn (string $root): string => sprintf(
        'FormData::__construct(): Argument #1 ($name) must be of type string, int given, called in %s on line 10',
        $root.'/app/Http/Controllers/FormController.php',
    );

    $alice = new MessagePaths(new RootRelativeSourcePathResolver('/home/alice/checkout'));
    $bob = new MessagePaths(new RootRelativeSourcePathResolver('C:\\Users\\bob\\dev\\checkout'));

    expect($alice->relative($thrown('/home/alice/checkout')))
        ->toBe($bob->relative($thrown('C:\\Users\\bob\\dev\\checkout')))
        ->toBe('FormData::__construct(): Argument #1 ($name) must be of type string, int given, called in app/Http/Controllers/FormController.php on line 10');
});

it('degrades a path from outside the project and outside any package to its name', function (): void {
    // The run the base-path strip cannot help with — a global composer cache, an include directory, a
    // path repo that is not one. The ladder ends in a name, so what survives is still true and the
    // machine is no longer legible.
    $elsewhere = '/'.uniqid('docuccino-elsewhere-', true).'/vendor/acme/src/Reader.php';

    $scrubbed = (new MessagePaths(new RootRelativeSourcePathResolver('/app/root')))
        ->relative(sprintf('file_get_contents(%s): Failed to open stream: No such file or directory', $elsewhere));

    expect($scrubbed)->toBe('file_get_contents(Reader.php): Failed to open stream: No such file or directory')
        ->and($scrubbed)->not->toContain($elsewhere);
});

it('scrubs every path in a message that names more than one', function (): void {
    $scrubbed = (new MessagePaths(new RootRelativeSourcePathResolver('/app/root')))
        ->relative('Could not copy /app/root/config/one.yaml to "/app/root/config/two.yaml"');

    expect($scrubbed)->toBe('Could not copy config/one.yaml to "config/two.yaml"');
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

it('leaves alone the runs that only look absolute', function (string $case, string $message): void {
    // A URL's slashes follow a colon or another slash, a namespace separator follows a word character,
    // and a single-segment word is prose — none of them is a path, and mistaking one for a path would
    // cost the reader the part of the message that names what to go and change.
    expect((new MessagePaths(new RootRelativeSourcePathResolver('/app/root')))->relative($message))
        ->toBe($message);
})->with([
    ['a URL', 'GET https://api.example.com/v1/forms returned 500'],
    ['a namespaced class', 'Class App\\Http\\Controllers\\FormController does not exist'],
    ['a single-segment word', 'The directory /tmp is not writable'],
    ['no path at all', 'Undefined array key "form"'],
]);
