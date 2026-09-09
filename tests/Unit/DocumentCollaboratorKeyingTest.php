<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\TagMapperKeying;
use Docuccino\Core\Extensions\Contracts\TagMapper;

/*
 * A document's config bag holds strings, and two of its members hold OBJECTS the adapter resolved from
 * one of those strings. An object is where the fragment cache runs out of things to hash: the bag says
 * what was named, never what came back, so every such member owes an answer to "what in the key moves
 * when this collaborator's behaviour does" — and a member that owes nothing owes the reason.
 *
 * The domain is read off the constructor rather than listed, so a third collaborator arrives here as a
 * failure instead of as a member nobody asked the question of.
 */

/** Every constructor member of {@see DocumentConfig} whose type is a class or an interface. */
function documentCollaborators(): array
{
    $collaborators = [];

    foreach ((new ReflectionClass(DocumentConfig::class))->getConstructor()?->getParameters() ?? [] as $parameter) {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            $collaborators[] = $parameter->getName();
        }
    }

    sort($collaborators);

    return $collaborators;
}

it('asks every collaborator a document resolves what keys it, and takes a reason for an answer', function (): void {
    // `tagMapper` is read INSIDE an operation fragment, so it is keyed twice over: its declaration files
    // go in the route's manifest where the tag went through it, and what the instance was handed goes in
    // the document-level part of the key ({@see TagMapperKeying}).
    //
    // `routeFilter` is read before any fragment is looked up: it decides which route descriptors exist
    // at all. A route it starts excluding is never iterated, and one it starts admitting has no entry to
    // be served, so no fragment can hold its answer and there is nothing to go stale.
    $answers = ['routeFilter', 'tagMapper'];

    expect(documentCollaborators())->toBe($answers)
        // The denominator, so a constructor this stopped being able to read fails loudly.
        ->and($answers)->toHaveCount(2);
});

it('moves the document key when the tag mapper it resolved is a different mapper', function (): void {
    // The keyed member's half of the answer above, stated on the digest itself: same class, same file,
    // two constructions. Nothing else in a document's configuration can tell these two apart.
    $document = static fn (?TagMapper $mapper): DocumentConfig => new DocumentConfig(
        'default',
        [],
        tags: ['mapper' => 'tags.the-mapper'],
        tagMapper: $mapper,
    );

    $keying = new class('V1') implements TagMapper
    {
        public function __construct(private readonly string $prefix) {}

        public function map(string $tag): string
        {
            return $this->prefix.'-'.$tag;
        }
    };
    $other = new ($keying::class)('V2');

    expect(TagMapperKeying::stateDigest($document($other)))
        ->not->toBe(TagMapperKeying::stateDigest($document($keying)))
        ->and(TagMapperKeying::stateDigest($document(null)))->toBe('')
        ->and($document($keying)->hash())->toBe($document($other)->hash());
});
