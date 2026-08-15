<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Extensions\ResolvedExtensions;

enum SignatureMode: string
{
    case Strict = 'strict';
    case Loose = 'loose';
}

enum SignatureFlavour
{
    case Sweet;
    case Sour;
}

/**
 * An extension registered as an OBJECT, the way `Docuccino::extend(new MyExtension(mode: 'a'))` does —
 * so what it was configured with is instance state, not a class name.
 */
class ConfiguredTransformer implements DocumentTransformer
{
    /** @param  list<string>  $only */
    public function __construct(
        private readonly string $mode = 'a',
        private readonly array $only = [],
        private readonly ?SignatureMode $backed = null,
        private readonly ?SignatureFlavour $pure = null,
    ) {}

    public function transform(UirDocumentDraft $document, DocumentContext $context): void {}
}

/** The same shape with the setting held as a closure, which no digest can read. */
final class ClosureConfiguredTransformer implements DocumentTransformer
{
    public function __construct(private readonly Closure $decide) {}

    public function transform(UirDocumentDraft $document, DocumentContext $context): void {}
}

/** A subclass, so the digest has to reach a PRIVATE property declared on the parent. */
final class InheritingTransformer extends ConfiguredTransformer {}

/*
 * The fragment-cache key's view of the extension set. Extensions are registrable as instances on every
 * surface there is, so a key that saw only class names told two differently-configured instances apart
 * from each other not at all — and a warm cache answered the second configuration with the first one's
 * output.
 */

it('separates two instances of one class configured differently', function (ConfiguredTransformer $a, ConfiguredTransformer $b): void {
    expect((new ResolvedExtensions(documentTransformers: [$a]))->cacheSignature())
        ->not->toBe((new ResolvedExtensions(documentTransformers: [$b]))->cacheSignature());
})->with([
    'a scalar setting' => [new ConfiguredTransformer('a'), new ConfiguredTransformer('b')],
    'an array setting' => [new ConfiguredTransformer(only: ['x']), new ConfiguredTransformer(only: ['y'])],
    // Json::stable() collapses any object to its class-string, so both cases of one enum looked alike.
    'a backed enum case' => [new ConfiguredTransformer(backed: SignatureMode::Strict), new ConfiguredTransformer(backed: SignatureMode::Loose)],
    'a pure enum case' => [new ConfiguredTransformer(pure: SignatureFlavour::Sweet), new ConfiguredTransformer(pure: SignatureFlavour::Sour)],
]);

it('gives two instances configured alike the same signature', function (): void {
    // The other half: over-keying costs a cold build on every run, so equal configuration has to be
    // equal — including one built by a different route through the same constructor.
    $one = new ResolvedExtensions(documentTransformers: [new ConfiguredTransformer('a', ['x'], SignatureMode::Strict)]);
    $two = new ResolvedExtensions(documentTransformers: [new ConfiguredTransformer(mode: 'a', only: ['x'], backed: SignatureMode::Strict)]);

    expect($two->cacheSignature())->toBe($one->cacheSignature());
});

it('counts an instance once however many contracts it satisfies, and twice when there are two of it', function (): void {
    // An extension satisfying several contracts appears in several partitions and is still one thing;
    // two of them is a different extension set from one, and the count has to say so.
    $extension = new ConfiguredTransformer('a');
    $shared = new ResolvedExtensions(documentTransformers: [$extension], typeToSchema: [$extension]);
    $single = new ResolvedExtensions(documentTransformers: [$extension]);
    $pair = new ResolvedExtensions(documentTransformers: [$extension, new ConfiguredTransformer('a')]);

    expect($shared->cacheSignature())->toBe($single->cacheSignature())
        ->and($shared->cacheSignature())->toHaveCount(1)
        ->and($pair->cacheSignature())->toHaveCount(2);
});

it('reads a private property declared on a parent class', function (): void {
    // getProperties() stops at inherited privates, so a subclass would otherwise fingerprint as empty
    // and every configuration of it would look the same.
    expect((new ResolvedExtensions(documentTransformers: [new InheritingTransformer('a')]))->cacheSignature())
        ->not->toBe((new ResolvedExtensions(documentTransformers: [new InheritingTransformer('b')]))->cacheSignature());
});

it('is the same signature across two builds of one configuration', function (): void {
    // It is a cache key, so it has to be a function of the configuration and nothing about this run.
    $signature = static fn (): array => (new ResolvedExtensions(documentTransformers: [new ConfiguredTransformer('a', ['x'])]))->cacheSignature();

    expect($signature())->toBe($signature());
});

it('reads a closure by where it was written and what it captured', function (): void {
    // Json::stable() sees every closure as `Closure` and nothing else, so a setting held as one needs
    // reading here. Two closures written in two places are two settings; one written once is one setting
    // however many builds construct it, or a cache enabled beside such an extension is never warm.
    $signature = static fn (Closure $decide): array => (new ResolvedExtensions(
        documentTransformers: [new ClosureConfiguredTransformer($decide)],
    ))->cacheSignature();

    // One source position, built twice — the shape a provider registering an extension every build has.
    $same = static fn (): Closure => static fn (): bool => true;
    $other = static fn (): bool => false;

    expect($signature($same()))->not->toBe($signature($other))
        ->and($signature($same()))->toBe($signature($same()));
});

/**
 * A configuration value is whatever an extension author put in a property, and one that `json_encode`
 * refuses must not take the whole instance's digest down with it: a shared `''` answer keys every
 * configuration holding such a value alike, which is the exact cache collision this signature exists
 * to close, reopened by a binary blob.
 */
it('still separates two configurations when one of them holds a value json_encode refuses', function (Closure $make): void {
    $signature = static fn (string $mode): array => (new ResolvedExtensions(
        documentTransformers: [new ConfiguredTransformer($mode, $make())],
    ))->cacheSignature();

    expect($signature('a'))->not->toBe($signature('b'));
})->with([
    'a binary blob' => [fn (): array => ["\xB1\x31"]],
    'INF' => [fn (): array => [INF]],
    'a resource' => [fn (): array => [fopen('php://memory', 'r')]],
]);

it('separates two configurations that differ only in a value json_encode refuses', function (): void {
    $signature = static fn (array $only): array => (new ResolvedExtensions(
        documentTransformers: [new ConfiguredTransformer('a', $only)],
    ))->cacheSignature();

    expect($signature(["\xB1\x31"]))->not->toBe($signature(["\xB1\x32"]))
        ->and($signature(["\xB1\x31"]))->toBe($signature(["\xB1\x31"]));
});

it('answers a self-referential array property instead of crashing the build', function (): void {
    // A property may hold anything, `$a['self'] = &$a` included, and an unbounded walk over that is a
    // stack overflow: SIGSEGV, exit 139, no message and no diagnostic.
    $cycle = ['x' => 1];
    $cycle['self'] = &$cycle;

    $signature = (new ResolvedExtensions(documentTransformers: [new ConfiguredTransformer('a', $cycle)]))->cacheSignature();

    expect($signature)->toHaveCount(1)
        ->and($signature[0])->not->toBe(
            (new ResolvedExtensions(documentTransformers: [new ConfiguredTransformer('b', $cycle)]))->cacheSignature()[0],
        );
});

it('reads what a closure captured, when two of them were written in one place', function (): void {
    // The same source position twice, differing only in what each closed over.
    $signature = static function (string $mode): array {
        $decide = static fn (): string => $mode;

        return (new ResolvedExtensions(documentTransformers: [new ClosureConfiguredTransformer($decide)]))->cacheSignature();
    };

    expect($signature('a'))->not->toBe($signature('b'))
        ->and($signature('a'))->toBe($signature('a'));
});
