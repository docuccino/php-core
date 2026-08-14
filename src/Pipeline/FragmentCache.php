<?php

declare(strict_types=1);

namespace Docuccino\Core\Pipeline;

use Docuccino\Core\Extensions\Context\RouteDependencies;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Support\GeneratedDirectory;
use JsonException;

/**
 * The OperationFragment cache: keys a route's built fragment on everything that could change its
 * output *except* the dependency files, then checks freshness by re-hashing the stored dependency
 * list. A hit reconstructs the fragment without ever invoking the type engine.
 *
 * key = sha256(tool ver ‖ spec ver ‖ identity-algo ver ‖ doc configHash ‖ resolved extension
 * signature (FQCNs + owning package versions) ‖ route cache-signature (method + URI + name +
 * resolved action + normalised middleware)). The entry also stores `sha256(each ActionAnalysis +
 * out-of-band dependency file)`, so any changed or removed dependency invalidates it. TraceReport
 * and {@see RouteDependencies} files merge into that one list — {@see put()} is the seam.
 *
 * Storage is a flat directory of `{key}.json` files written atomically (temp file + rename).
 *
 * Dependency hashing goes through {@see FileDigests}, so one build hashes each file once — one cache
 * instance is one build's worth of memo, and callers get a fresh one per build.
 *
 * @internal
 */
final readonly class FragmentCache
{
    public function __construct(
        private bool $enabled,
        private string $path,
        private string $toolVersion,
        private string $specVersion,
        private string $identityVersion,
        private FileDigests $digests = new FileDigests,
    ) {}

    /** A no-op cache — every lookup misses, nothing is stored. This is the default. */
    public static function disabled(): self
    {
        return new self(false, '', '', '', '');
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @param  string  $routeSignature  the route cache-signature ({@see RouteDescriptor::cacheSignature()})
     * @param  list<string>  $extensionSignature  resolved extension class-strings paired with owning package versions
     */
    public function key(string $routeSignature, string $configHash, array $extensionSignature): string
    {
        return hash('sha256', implode("\0", [
            $this->toolVersion,
            $this->specVersion,
            $this->identityVersion,
            $configHash,
            implode(',', $extensionSignature),
            $routeSignature,
        ]));
    }

    /**
     * The cached fragment, if the entry exists and every recorded dependency file still hashes to
     * its stored value. Null otherwise — a miss and a stale entry are indistinguishable to callers.
     */
    public function get(string $key): ?OperationFragment
    {
        if (! $this->enabled) {
            return null;
        }

        $raw = @file_get_contents($this->file($key));
        if ($raw === false) {
            return null;
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $dependencies = is_array($decoded['dependencies'] ?? null) ? $decoded['dependencies'] : [];
        if (! $this->dependenciesFresh($dependencies)) {
            return null;
        }

        $fragment = $decoded['fragment'] ?? null;
        if (! is_array($fragment)) {
            return null;
        }

        /** @var array<string, mixed> $fragment */
        return OperationFragment::fromArray($fragment);
    }

    /**
     * @param  list<string>  $dependencyFiles  the ActionAnalysis dependency files for this route
     */
    public function put(string $key, OperationFragment $fragment, array $dependencyFiles): void
    {
        if (! $this->enabled) {
            return;
        }

        $dependencies = [];
        foreach (array_values(array_unique($dependencyFiles)) as $file) {
            $hash = $this->digests->of($file);
            $dependencies[] = ['file' => $file, 'hash' => $hash === false ? '' : $hash];
        }

        try {
            $payload = json_encode(
                ['fragment' => $fragment->toArray(), 'dependencies' => $dependencies],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException) {
            return;
        }

        $this->writeAtomically($this->file($key), $payload);
    }

    /**
     * @param  array<array-key, mixed>  $dependencies
     */
    private function dependenciesFresh(array $dependencies): bool
    {
        foreach ($dependencies as $dependency) {
            if (! is_array($dependency)) {
                return false;
            }

            $file = $dependency['file'] ?? null;
            $expected = $dependency['hash'] ?? null;
            if (! is_string($file) || ! is_string($expected)) {
                return false;
            }

            $current = $this->digests->of($file);
            if ($current === false || $current !== $expected) {
                return false;
            }
        }

        return true;
    }

    private function file(string $key): string
    {
        return rtrim($this->path, '/').'/'.$key.'.json';
    }

    private function writeAtomically(string $file, string $contents): void
    {
        GeneratedDirectory::ensure(dirname($file));

        // random_int over bin2hex(random_bytes(…)): unambiguous int return type for every analyser
        // version we support, and 63 bits of entropy instead of 32.
        $temp = $file.'.'.getmypid().'.'.dechex(random_int(0, PHP_INT_MAX)).'.tmp';
        if (@file_put_contents($temp, $contents) === false) {
            return;
        }

        if (! @rename($temp, $file)) {
            @unlink($temp);
        }
    }
}
