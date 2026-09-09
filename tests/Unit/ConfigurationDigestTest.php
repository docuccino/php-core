<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Schema\ConfigurationDigest;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;

/** A collaborator whose answer is a constructor argument rather than anything in its own file. */
final class DigestedCollaborator
{
    public function __construct(private readonly string $prefix = 'a') {}
}

/** The same with nothing in it at all, so there is no state to read. */
final class StatelessCollaborator {}

/*
 * The state half of what a fragment can be keyed on. `ExtensionSignatureTest` covers it as the extension
 * signature reads it; these rows state it as the digest's own contract, and state the one claim its
 * docblock makes about its sibling — that files and state do not answer for each other.
 */

it('answers nothing for an instance with no state to read', function (): void {
    // A collaborator whose behaviour is entirely in its file contributes nothing here, which is what
    // keeps its key the same across every build ({@see DeclarationFiles} is the half that moves).
    expect(ConfigurationDigest::of(new StatelessCollaborator))->toBe('');
});

it('separates two instances of one class by what each was handed, and keys two alike alike', function (): void {
    expect(ConfigurationDigest::of(new DigestedCollaborator('a')))
        ->not->toBe(ConfigurationDigest::of(new DigestedCollaborator('b')))
        ->and(ConfigurationDigest::of(new DigestedCollaborator('a')))
        ->toBe(ConfigurationDigest::of(new DigestedCollaborator('a')));
});

it('reads what the file half cannot: one class, one file, two configurations', function (): void {
    // The claim the class docblock makes, executed. Two instances of one class share every file there
    // is, so a key built on files alone answers the second configuration with the first one's output —
    // which is the defect this digest exists to close, and the reason both halves are owed.
    $one = new DigestedCollaborator('a');
    $other = new DigestedCollaborator('b');

    expect(DeclarationFiles::keyableFor($other))->toBe(DeclarationFiles::keyableFor($one))
        ->and(ConfigurationDigest::of($other))->not->toBe(ConfigurationDigest::of($one));
});
