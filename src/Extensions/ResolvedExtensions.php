<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions;

use BackedEnum;
use Closure;
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
use Docuccino\Core\Support\Json;
use ReflectionClass;
use ReflectionFunction;
use Throwable;
use UnitEnum;

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
    /** How deep {@see readable()} descends into a property before it stops. */
    private const MAX_DEPTH = 64;

    /** What stands in for a value below {@see MAX_DEPTH}. */
    private const TRUNCATED = '@docuccino:depth';

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
     * class, its composer package's installed version and a digest of its own configuration. The
     * version pairing means upgrading a package that changes an extension's behaviour invalidates every
     * fragment even though the class list didn't move; the lookup is tolerant, and an unresolvable
     * package contributes an empty version rather than failing the build.
     *
     * Per INSTANCE rather than per class because an extension is registered as an object as often as a
     * class-string (`Docuccino::extend(new MyExtension(mode: 'a'))`), and two instances of one class
     * configured differently are two different builds. Keyed by the class alone they are one entry, and
     * a warm cache answers the second configuration with the first one's output.
     *
     * @return list<string>
     */
    public function cacheSignature(): array
    {
        $signature = [];
        foreach ($this->instances() as $extension) {
            $signature[] = $extension::class.'@'.self::packageVersion($extension::class).'#'.self::configurationDigest($extension);
        }

        // Two instances configured alike contribute the same entry twice, which is what running an
        // extension twice is — the count is part of the set.
        sort($signature);

        return $signature;
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
     * A digest of one extension instance's own configuration: its properties, private and inherited
     * ones included, keyed by where they were declared.
     *
     * What it sees is what configuration is made of: scalars, arrays of them, enum cases, and a closure
     * by where it was written plus what it captured. What it does NOT see is a collaborator object's own
     * fields — this deliberately does not descend into one, since an injected container would be an
     * unbounded walk and a collaborator is a dependency rather than a setting. Two instances differing
     * only inside such an object therefore still key alike; holding the setting itself is the fix.
     *
     * The digest leans on {@see Json::stable()} being TOTAL over what a property can hold: a value
     * `json_encode` refuses — a binary blob, a resource, an INF — fingerprints as itself, because one
     * shared digest for all of them would reopen the very cache collision this method closes.
     */
    private static function configurationDigest(object $extension): string
    {
        $state = [];
        foreach (self::properties($extension) as $key => $value) {
            $state[$key] = self::readable($value);
        }

        return $state === [] ? '' : substr(hash('sha256', Json::stable($state)), 0, 16);
    }

    /**
     * An instance's readable property values, keyed `Declaring\Class::name`. Uninitialised typed
     * properties have no value to read and static ones belong to the class, not the configuration.
     *
     * @return array<string, mixed>
     */
    private static function properties(object $extension): array
    {
        $values = [];
        for ($class = new ReflectionClass($extension); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || ! $property->isInitialized($extension)) {
                    continue;
                }

                $values[$property->getDeclaringClass()->getName().'::'.$property->getName()] = $property->getValue($extension);
            }
        }

        return $values;
    }

    /**
     * The two configuration values {@see Json::stable()} would flatten to a bare class name — an enum
     * case, so `Mode::Strict` and `Mode::Loose` are not the same setting, and a closure, read as where
     * it was written plus what it captured. Everything else is left to `Json::stable()`, which is why a
     * collaborator object still collapses to its class.
     *
     * A closure's source position is an absolute path, which never leaves this method: the signature is
     * a fragment-cache key and nothing else, so it is local to the machine that built the cache.
     *
     * The descent is bounded because a property may hold anything at all, `$a['self'] = &$a` included —
     * and that is a stack overflow, which is SIGSEGV with no message. `Json::stable()` bounds its own
     * walk for the same reason, but this one reaches the value first.
     */
    private static function readable(mixed $value, int $depth = 0): mixed
    {
        if (is_array($value)) {
            return $depth >= self::MAX_DEPTH
                ? self::TRUNCATED
                : array_map(static fn (mixed $item): mixed => self::readable($item, $depth + 1), $value);
        }

        if ($value instanceof Closure) {
            $function = new ReflectionFunction($value);

            return [
                'closure' => $function->getFileName().':'.$function->getStartLine().'-'.$function->getEndLine(),
                'bound' => $function->getClosureScopeClass()?->getName(),
                'captured' => self::readable($function->getStaticVariables(), $depth + 1),
            ];
        }

        if ($value instanceof BackedEnum) {
            return $value::class.'::'.$value->value;
        }

        return $value instanceof UnitEnum ? $value::class.'::'.$value->name : $value;
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
