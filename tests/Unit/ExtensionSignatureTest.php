<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\ExtensionSorter;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Pipeline\FragmentCache;

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

/** A second class at a priority of its own, so a pair of them ties on nothing. */
#[ExtensionOrder(priority: 100)]
final class PrioritisedTransformer implements DocumentTransformer
{
    public function __construct(private readonly string $mode = 'a') {}

    public function transform(UirDocumentDraft $document, DocumentContext $context): void {}
}

/**
 * Declare a two-file extension hierarchy in a directory the test owns and return `[parent file, child
 * FQCN]`. One process may declare a class once, so only the FIRST call's marker reaches the loaded
 * body; later calls rewrite the FILES, which is the whole of what the key reads.
 *
 * The files are written on EVERY call, so the marker on disk is always the one asked for. Nothing
 * deletes the directory and its name is stable per process, so a call that wrote only when the file was
 * absent would inherit whatever a run in a reused pid had left there — and a row mutating the marker in
 * place would then find its own `str_replace` matching nothing.
 *
 * @return array{0: string, 1: class-string}
 */
function sourceKeyedExtension(string $marker): array
{
    $dir = sys_get_temp_dir().'/docuccino-sigsource-'.getmypid();
    @mkdir($dir, 0777, true);
    $parent = $dir.'/SourceKeyedParent.php';
    $child = $dir.'/SourceKeyedChild.php';

    file_put_contents($parent, sprintf(sourceKeyedParentTemplate(), $marker));
    file_put_contents($child, "<?php\nnamespace Docuccino\\Core\\Tests\\Temp;\nfinal class SourceKeyedChild extends SourceKeyedParent {}\n");

    if (! class_exists('Docuccino\Core\Tests\Temp\SourceKeyedChild', false)) {
        require $parent;
        require $child;
    }

    return [(string) realpath($parent), 'Docuccino\Core\Tests\Temp\SourceKeyedChild'];
}

/** The parent's source, with one `%s` where its marker goes — an ordinary class doing an ordinary thing. */
function sourceKeyedParentTemplate(): string
{
    return <<<'PHP'
        <?php

        namespace Docuccino\Core\Tests\Temp;

        use Docuccino\Core\Extensions\Context\DocumentContext;
        use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
        use Docuccino\Core\Extensions\Document\UirDocumentDraft;

        abstract class SourceKeyedParent implements DocumentTransformer
        {
            protected const MARKER = '%s';

            public function transform(UirDocumentDraft $document, DocumentContext $context): void {}
        }

        PHP;
}

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

/*
 * The ORDER half. A chain of extensions is first-match-wins — `RouteContext`'s six resolvers,
 * `SchemaConverter`'s mappers — or sequential mutation, in `OperationPipeline`. So the sequence the
 * instances arrive in is published, and a key that cannot tell two sequences apart hands a warm build
 * the other sequence's fragment.
 *
 * `ExtensionSorter` decides a sequence from priority, then FQCN, then the registration index. Only the
 * last of those is arrival, and it is reached exactly when two instances are of ONE class: the ordering
 * attribute is declared per class, so such a pair shares a priority, and `before`/`after` name classes,
 * so neither can name the other (ExtensionSorterTest holds that half). That is the run this signature
 * has to carry — and only that run, because a fix that keys every order costs every application a cold
 * rebuild for reorders that change nothing.
 */

it('separates the two registration orders of two instances of one class', function (): void {
    // Registration through to signature, the way a build reaches it: same set both times, and the sorter
    // has nothing but arrival to separate them by.
    $sorter = new ExtensionSorter;
    $a = new ConfiguredTransformer('a');
    $b = new ConfiguredTransformer('b');

    $aFirst = new ResolvedExtensions(documentTransformers: $sorter->sort([$a, $b]));
    $bFirst = new ResolvedExtensions(documentTransformers: $sorter->sort([$b, $a]));

    expect($sorter->sort([$a, $b]))->toBe([$a, $b])
        ->and($sorter->sort([$b, $a]))->toBe([$b, $a])
        ->and($aFirst->cacheSignature())->not->toBe($bFirst->cacheSignature());
});

it('gives the two orders two fragment keys, so a warm build cannot answer across them', function (): void {
    // What the entries are for. Everything else about the two builds is identical, so the signature is
    // the only field of the key that can carry the difference.
    $sorter = new ExtensionSorter;
    $a = new ConfiguredTransformer('a');
    $b = new ConfiguredTransformer('b');
    $cache = FragmentCache::disabled();

    $key = static fn (ResolvedExtensions $resolved): string => $cache->key('GET /a', 'doc:default', 'config', $resolved->cacheSignature());

    expect($key(new ResolvedExtensions(documentTransformers: $sorter->sort([$a, $b]))))
        ->not->toBe($key(new ResolvedExtensions(documentTransformers: $sorter->sort([$b, $a]))));
});

it('keys two instances of different classes alike whichever order it was handed them', function (DocumentTransformer $one, DocumentTransformer $other): void {
    // The sibling that must not regress, asserted on the signature DIRECTLY rather than through the
    // sorter: the sorter canonicalises these pairs by FQCN or by priority, so putting them through it
    // would pass whether the signature read order or not.
    expect((new ResolvedExtensions(documentTransformers: [$one, $other]))->cacheSignature())
        ->toBe((new ResolvedExtensions(documentTransformers: [$other, $one]))->cacheSignature());
})->with([
    'two classes at one priority' => [new ConfiguredTransformer('a'), new InheritingTransformer('b')],
    'two classes at two priorities' => [new ConfiguredTransformer('a'), new PrioritisedTransformer('b')],
    // There is no "one class at two priorities" row to write: the ordering attribute is TARGET_CLASS, so
    // two instances of one class read one priority however they were registered.
]);

it('keys two indistinguishable instances of one class alike whichever order it was handed them', function (): void {
    // Nothing separates these two, so nothing about them is observable and a reorder of them is not a
    // rebuild. It is also what refuses a position paired with the OBJECT — keying `spl_object_id`
    // alongside it looks safer and makes two runs of one configuration two cache entries.
    $one = new ConfiguredTransformer('a');
    $other = new ConfiguredTransformer('a');

    expect((new ResolvedExtensions(documentTransformers: [$one, $other]))->cacheSignature())
        ->toBe((new ResolvedExtensions(documentTransformers: [$other, $one]))->cacheSignature());
});

it('leaves an entry no other instance of its class contests exactly as it was', function (): void {
    // The cost half, pinned as bytes: an application registering one instance per class — every
    // application there has been — keys as it did before order was carried, so nobody pays a cold
    // rebuild for this. A position appended unconditionally would fail here.
    $signature = (new ResolvedExtensions(
        documentTransformers: [new ConfiguredTransformer('a')],
        typeToSchema: [],
    ))->cacheSignature();

    expect($signature)->toHaveCount(1)
        ->and($signature[0])->toMatch('/^ConfiguredTransformer@[^#*~]*#[0-9a-f]{16}~[0-9a-f]{16}$/');
});

/*
 * The source half. The version an entry is paired with is a proxy for a package author's release, and an
 * extension in the application's own tree has no such author: its "package" is the root, whose version
 * does not move when a file is saved. So the bytes it is WRITTEN in are keyed too.
 */

it('hands back files carrying the marker it was asked for, whatever the last call left on disk', function (): void {
    // The rows below edit the parent's file in place, so the helper has to be a writer rather than a
    // first-one-wins initialiser: its directory is named for the process and nothing deletes it, so a
    // run in a pid the OS reused starts with the previous run's edits already applied. A row asking for
    // 'P1' and being handed 'P2' then replaces nothing and reports the digest as stuck.
    [$parent] = sourceKeyedExtension('P1');
    file_put_contents($parent, str_replace("'P1'", "'P2'", (string) file_get_contents($parent)));

    [$again] = sourceKeyedExtension('P1');

    expect((string) file_get_contents($again))->toContain("'P1'")
        ->and((string) file_get_contents($again))->not->toContain("'P2'");
});

it('keys an extension on its whole hierarchy, so a parent it inherits from is part of the key', function (): void {
    // A parent writes as much of an extension's answer as the leaf does, and the leaf's own file says
    // nothing about it — the same reason DeclarationFiles exists. Editing the parent's file with the
    // class already loaded is exactly what the cache has to notice: the loaded body cannot change
    // mid-process, and the key is taken off disk.
    [$parent, $child] = sourceKeyedExtension('P1');
    $signature = static fn (): array => (new ResolvedExtensions(documentTransformers: [new $child]))->cacheSignature();

    $before = $signature();
    file_put_contents($parent, str_replace("'P1'", "'P2'", (string) file_get_contents($parent)));

    expect($signature())->not->toBe($before);
});

it('keys an extension on the content of its files and not on when they were written', function (): void {
    // The cost direction, and the whole reason a `composer install` costs nobody a rebuild: the same
    // bytes written again are the same extension, whatever the filesystem says about the timestamp.
    [$parent, $child] = sourceKeyedExtension('P1');
    $signature = static fn (): array => (new ResolvedExtensions(documentTransformers: [new $child]))->cacheSignature();

    $before = $signature();
    file_put_contents($parent, (string) file_get_contents($parent));
    touch($parent, time() + 60);

    expect($signature())->toBe($before);
});

it('names an extension whose declaration no file holds, and no other', function (): void {
    // eval()'d code reports a path no is_file() matches, and a dependency manifest records a file that
    // isn't there as ABSENT — which reads FRESH for as long as it stays absent. So there is nothing to
    // key such an entry on, and a caller holding a cache is owed the name rather than a silent nothing.
    if (! class_exists('Docuccino\Core\Tests\Temp\EvaldSignatureTransformer', false)) {
        eval('namespace Docuccino\Core\Tests\Temp; class EvaldSignatureTransformer implements \Docuccino\Core\Extensions\Contracts\DocumentTransformer { public function transform(\Docuccino\Core\Extensions\Document\UirDocumentDraft $document, \Docuccino\Core\Extensions\Context\DocumentContext $context): void {} }');
    }

    $evald = 'Docuccino\Core\Tests\Temp\EvaldSignatureTransformer';
    $mixed = new ResolvedExtensions(documentTransformers: [new ConfiguredTransformer('a'), new $evald]);

    expect($mixed->unhashableExtensions())->toBe([$evald])
        ->and((new ResolvedExtensions(documentTransformers: [new ConfiguredTransformer('a')]))->unhashableExtensions())->toBe([]);
});

/*
 * Which of the resolved extensions did not ship with this product. A caller sharing one stored
 * fragment between two documents refuses to when any of them is resolved: the claim that nothing the
 * build reads differs between those documents is checked for the extensions shipped here and cannot
 * be checked for anyone else's.
 */
it('separates the extensions that shipped here from the ones an application wrote', function (): void {
    $ours = new ConfiguredTransformer('a');
    $theirs = applicationOwnedExtension();

    $mixed = new ResolvedExtensions(operationExtensions: [$theirs], documentTransformers: [$ours]);

    expect($mixed->foreignExtensions())->toBe([$theirs::class])
        // Both directions: a set of only our own reports none, so this is not a method that answers
        // "everything" whatever it is handed.
        ->and((new ResolvedExtensions(documentTransformers: [$ours]))->foreignExtensions())->toBe([]);
});

it('reads ownership off the declaring file rather than the namespace', function (): void {
    // PHP names an anonymous class after the interface it implements, so one an application wrote is
    // called `Docuccino\…\DocumentTransformer@anonymous`. Both of these are, and only one of them is
    // ours — a namespace test would call them both ours and share a fragment it may not.
    $ours = new class implements DocumentTransformer
    {
        public function transform(UirDocumentDraft $document, DocumentContext $context): void {}
    };
    $theirs = applicationOwnedAnonymousTransformer();

    expect($ours::class)->toStartWith('Docuccino\\')
        ->and($theirs::class)->toStartWith('Docuccino\\')
        ->and((new ResolvedExtensions(documentTransformers: [$ours]))->foreignExtensions())->toBe([])
        ->and((new ResolvedExtensions(documentTransformers: [$theirs]))->foreignExtensions())->toBe([$theirs::class]);
});

it('trusts a package this product ships and no other name under the same vendor', function (string $package, bool $ours): void {
    // Nothing reserves the `docuccino/` vendor segment: a fork, a path repository, a private registry
    // or a first-party package living outside this monorepo can all publish under it. A prefix test
    // hands every one of them the sharing only a reviewed extension has earned, with no byte-identity
    // check behind it — so the gate NAMES the packages ({@see ResolvedExtensions::SHIPPED_PACKAGES},
    // held to the monorepo's own directories by the tools suite).
    $extension = packageOwnedExtension($package);
    $foreign = (new ResolvedExtensions(documentTransformers: [$extension]))->foreignExtensions();

    expect($foreign)->toBe($ours ? [] : [$extension::class]);
})->with([
    'core' => ['docuccino/core', true],
    'attributes' => ['docuccino/attributes', true],
    'laravel' => ['docuccino/laravel', true],
    'inference-phpstan' => ['docuccino/inference-phpstan', true],
    'another package under our vendor' => ['docuccino/community-extras', false],
    'a name one of ours is a prefix of' => ['docuccino/core-extras', false],
    'somebody else entirely' => ['acme/api-docs', false],
]);
