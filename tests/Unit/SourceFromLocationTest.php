<?php

declare(strict_types=1);

use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Provenance\Source;

/**
 * `fromLocation()` is the engine-finding → provenance-record crossing. The path rule it applies is
 * `RootRelativeSourcePathResolver`'s and is proved there; what these rows prove is that it applies that
 * one rather than a second copy of it.
 */
it('relativizes an absolute file inside the project root', function (): void {
    $source = Source::fromLocation(
        new SourceLocation('/app/root/modules/Form/FormController.php', 38),
        '/app/root',
    );

    expect($source->file)->toBe('modules/Form/FormController.php');
    expect($source->line)->toBe(38);
    expect($source->symbol)->toBeNull();
});

it('tolerates a trailing slash on the project root', function (): void {
    $source = Source::fromLocation(
        new SourceLocation('/app/root/a.php'),
        '/app/root/',
    );

    expect($source->file)->toBe('a.php');
});

it('degrades a file outside the project root to its name rather than an absolute path', function (): void {
    // The same rule the resolver states: a machine path in a provenance record would make two machines
    // building the same code emit different bytes, so the part that is still true survives and the rest
    // does not. The directory exists nowhere, so there is no package root to relativise against either.
    $source = Source::fromLocation(
        new SourceLocation('/'.uniqid('docuccino-outside-', true).'/place/a.php'),
        '/app/root',
    );

    expect($source->file)->toBe('a.php');
});

it('keeps an already-relative file unchanged', function (): void {
    $source = Source::fromLocation(
        new SourceLocation('modules/Form/FormController.php', 5),
        '/app/root',
    );

    expect($source->file)->toBe('modules/Form/FormController.php');
    expect($source->line)->toBe(5);
});

it('carries a supplied symbol and drops a normalised (-1) line', function (): void {
    $source = Source::fromLocation(
        new SourceLocation('/app/root/a.php', -1),
        '/app/root',
        'FormController::index',
    );

    expect($source->line)->toBeNull();
    expect($source->symbol)->toBe('FormController::index');
});
