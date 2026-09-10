<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions;

use Composer\InstalledVersions;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Contracts\EnvironmentDigestContributor;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Contracts\PayloadMediaTypeResolver;
use Docuccino\Core\Extensions\Contracts\ResponseAnalysisTarget;
use Docuccino\Core\Extensions\Contracts\ResponseStatusResolver;
use Docuccino\Core\Extensions\Contracts\RouteBindingFieldSchemaResolver;
use Docuccino\Core\Extensions\Contracts\RouteBindingSchemaResolver;
use Docuccino\Core\Extensions\Contracts\RouteNoteCollector;
use Docuccino\Core\Extensions\Contracts\RouteResolver;
use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionSorter;
use Docuccino\Core\Extensions\Schema\ConfigurationDigest;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Pipeline\FragmentCache;
use ReflectionClass;
use Throwable;

/**
 * The extension set for one build, partitioned by contract and pre-sorted within each partition by
 * {@see ExtensionSorter}. One instance may satisfy several contracts and then appears in every
 * matching partition.
 *
 * It travels whole — into the pipeline, and on into every RouteContext — rather than being
 * unpacked chain by chain at each seam. That is the point: a new contract adds a partition HERE and a
 * reader in whatever consumes it, and no signature in between has to grow a parameter for it.
 *
 * @internal
 */
final readonly class ResolvedExtensions
{
    /**
     * The composer packages this product ships — the whole of what {@see shippedHere()} trusts. Named
     * one by one rather than matched on the `docuccino/` vendor segment, because nothing reserves that
     * segment: a fork, a path repository, a private registry or a first-party package living outside
     * this monorepo can all publish under it, and a prefix would hand any of them the sharing an
     * unreviewed extension is not entitled to.
     *
     * @var list<string>
     */
    public const array SHIPPED_PACKAGES = [
        'docuccino/attributes',
        'docuccino/core',
        'docuccino/inference-phpstan',
        'docuccino/laravel',
    ];

    /**
     * What separates a {@see cacheSignature()} entry from its position in its run. Neither a class name
     * nor a hex digest can hold it, so an entry carrying one is unambiguous.
     */
    private const POSITION = '*';

    /** What separates a {@see cacheSignature()} entry from its source digest, on the same reasoning. */
    private const SOURCE = '~';

    /**
     * Grouped by phase once, up front, so a build iterating phases per route doesn't re-filter the
     * whole list every time.
     *
     * @var array<int, list<OperationExtension>> phase value → its extensions, sorted order preserved
     */
    private array $operationExtensionsByPhase;

    /**
     * @param  list<RouteResolver>  $routeResolvers
     * @param  list<OperationExtension>  $operationExtensions  globally sorted; group by phase downstream
     * @param  list<TypeToSchema>  $typeToSchema
     * @param  list<ExceptionToResponse>  $exceptionToResponse
     * @param  list<DocumentTransformer>  $documentTransformers
     * @param  list<RuleTransformer>  $ruleTransformers  the validation rule vocabulary chain
     * @param  list<ResponseAnalysisTarget>  $responseAnalysisTargets  gated success-body analysis redirects
     * @param  list<ResponseStatusResolver>  $responseStatusResolvers  gated success-status overrides
     * @param  list<PayloadMediaTypeResolver>  $payloadMediaTypeResolvers  gated response media-type matchers
     * @param  list<RouteBindingSchemaResolver>  $routeBindingSchemaResolvers  gated route-key schema typers
     * @param  list<RouteBindingFieldSchemaResolver>  $routeBindingFieldSchemaResolvers  gated `{post:slug}` column typers
     * @param  list<EnvironmentDigestContributor>  $environmentDigestContributors  gated booted-app cache-digest segments
     * @param  list<RouteNoteCollector>  $routeNoteCollectors  gated aggregators of per-route notes a document transformer reports
     */
    public function __construct(
        public array $routeResolvers = [],
        public array $operationExtensions = [],
        public array $typeToSchema = [],
        public array $exceptionToResponse = [],
        public array $documentTransformers = [],
        public array $ruleTransformers = [],
        public array $responseAnalysisTargets = [],
        public array $responseStatusResolvers = [],
        public array $payloadMediaTypeResolvers = [],
        public array $routeBindingSchemaResolvers = [],
        public array $routeBindingFieldSchemaResolvers = [],
        public array $environmentDigestContributors = [],
        public array $routeNoteCollectors = [],
    ) {
        $byPhase = [];
        foreach ($operationExtensions as $extension) {
            $byPhase[$extension->phase()->value][] = $extension;
        }
        $this->operationExtensionsByPhase = $byPhase;
    }

    /**
     * The operation extensions declaring the given phase, in sorted order.
     *
     * @return list<OperationExtension>
     */
    public function operationExtensionsFor(OperationPhase $phase): array
    {
        return $this->operationExtensionsByPhase[$phase->value] ?? [];
    }

    /**
     * The fragment-cache's view of the extension set: one entry per resolved INSTANCE, each naming its
     * class, its composer package's installed version, a digest of its own configuration and a digest of
     * the bytes it is WRITTEN in. The version pairing means upgrading a package that changes an
     * extension's behaviour invalidates every fragment even though the class list didn't move; the
     * lookup is tolerant, and an unresolvable package contributes an empty version rather than failing
     * the build.
     *
     * The version cannot stand in for the body, which is why {@see sourceDigest()} is paired with it: a
     * package's version moves when its author releases, and an extension in the APPLICATION's own tree
     * has no such author — its "package" is the root, whose version does not move when a file is saved.
     * So an author edited their own extension, rebuilt, and was served the output the old body produced.
     * The two components are complementary rather than redundant, and neither is a heuristic about where
     * a class lives: the source digest is inert exactly where the version is informative, since a release
     * nobody edited reinstalls byte-identically, and informative exactly where the version is inert. A
     * `composer update` that leaves an extension's own bytes alone therefore moves no digest, and one
     * that changes another file of its package is what the version is still there for.
     *
     * Per INSTANCE rather than per class because an extension is registered as an object as often as a
     * class-string (`Docuccino::extend(new MyExtension(mode: 'a'))`), and two instances of one class
     * configured differently are two different builds. Keyed by the class alone they are one entry, and
     * a warm cache answers the second configuration with the first one's output.
     *
     * Identity and multiplicity are not the whole set: ORDER is published too, because every chain
     * reading these instances is first-match-wins (`RouteContext`'s six resolvers, `SchemaConverter`) or
     * sequential (`OperationPipeline`). {@see ExtensionSorter} settles order from the registration index
     * exactly where nothing intrinsic separates two instances, which is when they are of ONE class —
     * `priority` is a class-level attribute and `before`/`after` name classes. So each member of a
     * same-class run carries its position in that run, and every other entry stays order-free: two
     * DIFFERENT classes cannot trade places in the sorted output at all, and keying that would buy every
     * application a cold rebuild for a change nothing can observe.
     *
     * The position sees no more than the digest does — two instances differing only inside a collaborator
     * object key alike, and so key alike in either order.
     *
     * Every entry is document-wide, this one included: an extension shapes whatever operations it is run
     * over, and nothing here can say which of them its answer reached. So an edited extension retires
     * every fragment — the same blast radius the package version has always had, rather than a new one.
     *
     * @return list<string>
     */
    public function cacheSignature(): array
    {
        $instances = $this->instances();

        /** @var array<class-string, int> $occurrences */
        $occurrences = [];
        foreach ($instances as $extension) {
            $occurrences[$extension::class] = ($occurrences[$extension::class] ?? 0) + 1;
        }

        /** @var array<class-string, int> $reached */
        $reached = [];
        $signature = [];
        foreach ($instances as $extension) {
            $class = $extension::class;
            $entry = $class.'@'.self::packageVersion($class).'#'.ConfigurationDigest::of($extension)
                .self::SOURCE.(self::sourceDigest($extension) ?? '');

            if ($occurrences[$class] > 1) {
                $reached[$class] = ($reached[$class] ?? -1) + 1;
                $entry .= self::POSITION.$reached[$class];
            }

            $signature[] = $entry;
        }

        // Sorting is safe now every entry says where in its run it ran: two instances configured alike
        // contribute the same entry twice, which is what running an extension twice is — the count is
        // part of the set — and two configured differently no longer trade places silently.
        sort($signature);

        return $signature;
    }

    /**
     * The classes of the resolved extensions that did not ship with this product, sorted — every one
     * an application or a third-party package contributes. Read from the composer package the class's
     * FILE belongs to and not from its namespace, because a namespace is a string anyone can write and
     * an anonymous class is named after the interface it implements, which is one of ours.
     *
     * Why a caller sharing one stored fragment between documents owes these a refusal:
     * {@see FragmentCache::documentScope()}.
     *
     * @return list<class-string>
     */
    public function foreignExtensions(): array
    {
        $classes = [];
        foreach ($this->instances() as $extension) {
            if (! self::shippedHere($extension)) {
                $classes[$extension::class] = true;
            }
        }

        $classes = array_keys($classes);
        sort($classes);

        return $classes;
    }

    /**
     * Whether an extension's declaring file belongs to one of this product's own packages
     * ({@see SHIPPED_PACKAGES}).
     */
    private static function shippedHere(object $extension): bool
    {
        try {
            $file = (new ReflectionClass($extension))->getFileName();
        } catch (Throwable) {
            return false;
        }

        if ($file === false) {
            return false;
        }

        return in_array(self::composerNameFor($file), self::SHIPPED_PACKAGES, true);
    }

    /**
     * The classes of the resolved extensions whose declaration no file can be hashed back from, sorted.
     * A caller holding the fragment cache owes them a refusal: an entry keyed on an empty source digest
     * is keyed on nothing, and there is nothing else in the signature that moves when such a class's
     * body does.
     *
     * The whole resolved set is read, which is the set {@see cacheSignature()} publishes — the refusal
     * and the key have to answer over the same instances, or a fragment keyed on an entry the refusal
     * did not look at is keyed on nothing again.
     *
     * @return list<class-string>
     */
    public function unhashableExtensions(): array
    {
        $classes = [];
        foreach ($this->instances() as $extension) {
            if (self::sourceDigest($extension) === null) {
                $classes[$extension::class] = true;
            }
        }

        $classes = array_keys($classes);
        sort($classes);

        return $classes;
    }

    /**
     * A digest of the bytes one extension instance is written in — its own file, its parents' and its
     * traits' ({@see DeclarationFiles}) — or null when its declaration is nothing that can be hashed.
     *
     * The hierarchy is read whole because a parent or a trait writes as much of an extension's answer as
     * the leaf does, and in hierarchy order because two classes swapping which of them declares a method
     * is a different extension. Only CONTENT goes in, never the paths: what the extension answers is a
     * function of its bytes, and a file moved with its bytes intact answers the same.
     *
     * Null is the eval()'d case and the internal-class case. A file that is there and cannot be read is
     * null too: it is a body this build cannot see, which is the same position as one it cannot find.
     */
    private static function sourceDigest(object $extension): ?string
    {
        $files = DeclarationFiles::keyableFor($extension);

        if ($files === null) {
            return null;
        }

        $digests = [];
        foreach ($files as $file) {
            $digest = @hash_file('sha256', $file);
            if ($digest === false) {
                return null;
            }

            $digests[] = $digest;
        }

        return substr(hash('sha256', implode("\0", $digests)), 0, 16);
    }

    /**
     * Every resolved extension, once each however many contracts it satisfies.
     *
     * @return list<object>
     */
    private function instances(): array
    {
        $instances = [];
        foreach ($this->partitions() as $partition) {
            foreach ($partition as $extension) {
                $instances[spl_object_id($extension)] = $extension;
            }
        }

        return array_values($instances);
    }

    /**
     * @return list<list<object>>
     */
    private function partitions(): array
    {
        return [$this->routeResolvers, $this->operationExtensions, $this->typeToSchema, $this->exceptionToResponse, $this->documentTransformers, $this->ruleTransformers, $this->responseAnalysisTargets, $this->responseStatusResolvers, $this->payloadMediaTypeResolvers, $this->routeBindingSchemaResolvers, $this->routeBindingFieldSchemaResolvers, $this->environmentDigestContributors, $this->routeNoteCollectors];
    }

    /**
     * The installed version of the composer package owning $class, or `''` when it can't be worked
     * out — no file, no composer.json above it, or the package isn't tracked.
     */
    private static function packageVersion(string $class): string
    {
        try {
            if (! class_exists(InstalledVersions::class) || ! class_exists($class)) {
                return '';
            }

            $reflection = new ReflectionClass($class);
            $file = $reflection->getFileName();
            if ($file === false) {
                return '';
            }

            $name = self::composerNameFor($file);
            if ($name === null) {
                return '';
            }

            return InstalledVersions::getPrettyVersion($name) ?? '';
        } catch (Throwable) {
            return '';
        }
    }

    /** Walk up from a class file to its nearest composer.json and read its package `name`. */
    private static function composerNameFor(string $file): ?string
    {
        $directory = dirname($file);

        while (true) {
            $manifest = $directory.'/composer.json';
            if (is_file($manifest)) {
                $decoded = json_decode((string) @file_get_contents($manifest), true);
                $name = is_array($decoded) ? ($decoded['name'] ?? null) : null;

                return is_string($name) ? $name : null;
            }

            $parent = dirname($directory);
            if ($parent === $directory) {
                return null;
            }

            $directory = $parent;
        }
    }
}
