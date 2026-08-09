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
use Docuccino\Core\Extensions\Contracts\RouteBindingSchemaResolver;
use Docuccino\Core\Extensions\Contracts\RouteResolver;
use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionSorter;
use ReflectionClass;
use Throwable;

/**
 * The extension set for one build, partitioned by contract and pre-sorted within each partition by
 * {@see ExtensionSorter}. One instance may satisfy several contracts and then appears in every
 * matching partition.
 *
 * @internal
 */
final readonly class ResolvedExtensions
{
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
     * @param  list<EnvironmentDigestContributor>  $environmentDigestContributors  gated booted-app cache-digest segments
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
        public array $environmentDigestContributors = [],
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
     * Every resolved extension class, deduped and sorted — a fragment-cache key input, so changing
     * the extension set invalidates every fragment.
     *
     * @return list<string>
     */
    public function classSignature(): array
    {
        $classes = [];
        foreach ([$this->routeResolvers, $this->operationExtensions, $this->typeToSchema, $this->exceptionToResponse, $this->documentTransformers, $this->ruleTransformers, $this->responseAnalysisTargets, $this->responseStatusResolvers, $this->payloadMediaTypeResolvers, $this->routeBindingSchemaResolvers, $this->environmentDigestContributors] as $partition) {
            foreach ($partition as $extension) {
                $classes[$extension::class] = true;
            }
        }

        $names = array_keys($classes);
        sort($names);

        return $names;
    }

    /**
     * {@see classSignature()} with each class paired with its composer package's installed version,
     * so upgrading a package that changes an extension's behaviour invalidates every fragment even
     * though the class list didn't move. The lookup is tolerant: an unresolvable package contributes
     * an empty version rather than failing the build.
     *
     * @return list<string>
     */
    public function cacheSignature(): array
    {
        $signature = [];
        foreach ($this->classSignature() as $class) {
            $signature[] = $class.'@'.self::packageVersion($class);
        }

        return $signature;
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
