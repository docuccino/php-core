<?php

declare(strict_types=1);

use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Provenance\Source;

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

it('keeps a file outside the project root verbatim', function (): void {
    $source = Source::fromLocation(
        new SourceLocation('/other/place/a.php'),
        '/app/root',
    );

    expect($source->file)->toBe('/other/place/a.php');
});

it('does not treat a sibling directory sharing a prefix as inside the root', function (): void {
    $source = Source::fromLocation(
        new SourceLocation('/app/root-extra/a.php'),
        '/app/root',
    );

    expect($source->file)->toBe('/app/root-extra/a.php');
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
