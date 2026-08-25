<?php

declare(strict_types=1);

namespace Docuccino\Core\Pipeline;

use Docuccino\Core\Extensions\Context\RouteDependencies;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Support\AtomicFile;
use Docuccino\Core\Support\ConfinedPath;
use Docuccino\Core\Support\GeneratedDirectory;
use Docuccino\Core\Support\Hydrate;
use Docuccino\Core\Support\JsonValue;
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
 * A dependency that ISN'T THERE is a state the manifest records, not a hash it fails to take: a route
 * may legitimately depend on a file nobody has written yet — an example file a `#[Example(file:)]`
 * names, a description a `#[DescriptionFromFile]` points at — and recording those as "no hash" would
 * mean absent-then never matches absent-now, so the route rebuilds on every single build. Absent is
 * therefore stamped {@see ABSENT} and compares fresh while it stays absent; a file appearing, changing
 * or vanishing is stale either way.
 *
 * Storage is a flat directory of `{key}.json` files written atomically (temp file + rename), each
 * stamped with {@see FORMAT} — the shape of what a fragment carries, and how it is written. Bump it
 * whenever the fragment gains something a warm build now needs, because an entry written before that
 * member existed reads back as "this route had none", which is a warm build quietly saying less than a
 * cold one; bump it for a change to the encoding too, since an entry written by a lossier one restores
 * different values. A miss costs one rebuild; a wrong warm answer costs the document.
 *
 * What comes back out has to be the same PHP value that went in, and neither direction of PHP's JSON
 * manages that on its own: floats are pinned to shortest-round-trip on the way out ({@see put()}), and
 * the way in goes through {@see JsonValue} so an empty or index-keyed object is not restored as a list.
 *
 * Dependency hashing goes through {@see FileDigests}, so one build hashes each file once — one cache
 * instance is one build's worth of memo, and callers get a fresh one per build.
 *
 * @internal
 */
final readonly class FragmentCache
{
    /** The entry format {@see get()} will read. An entry stamped anything else is a miss. */
    public const FORMAT = 6;

    /**
     * The manifest's stand-ins for the two things a digest cannot be. Neither can be mistaken for one:
     * a sha256 is 64 hex characters and these are not.
     */
    private const ABSENT = '@absent';

    private const UNREADABLE = '@unreadable';

    private bool $enabled;

    public function __construct(
        bool $enabled,
        private string $path,
        private string $toolVersion,
        private string $specVersion,
        private string $identityVersion,
        private FileDigests $digests = new FileDigests,
    ) {
        // A directory no filesystem call can accept is a cache that is OFF, not a build that dies:
        // every read and write below would raise on it ({@see ConfinedPath::holdable()}), and `@` does
        // not suppress a throw. A permanently cold cache costs one rebuild per route and still answers
        // exactly what a warm one would; the adapter reports the configured path that got it turned off.
        $this->enabled = $enabled && ConfinedPath::holdable($path) !== null;
    }

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
     * The cached fragment, if the entry is in the format this build reads and every recorded dependency
     * file still hashes to its stored value. Null otherwise — a miss, an entry from an older format and a
     * stale entry are all indistinguishable to callers, and all three simply rebuild.
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
            // Through the shared reader, not `json_decode(…, true)`: an associative decode reads `{}`
            // back as `[]`, and a fragment carries examples verbatim. That took `{}` out of a warm
            // build's bytes, changed the hashes computed off them, and had the example lint report a
            // mismatch on the warm build that the cold one never saw ({@see JsonValue}).
            $decoded = Hydrate::map(JsonValue::decode($raw));
        } catch (JsonException) {
            return null;
        }

        if (($decoded['format'] ?? null) !== self::FORMAT) {
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
            $dependencies[] = ['file' => $file, 'hash' => $this->recorded($file)];
        }

        // What goes in has to come back out as the same PHP value, and `json_encode`'s defaults do not
        // manage that for a float: a whole one writes as `1` and restores as an int, and the rest follow
        // the ambient `serialize_precision`, which is the host deciding what a warm build says.
        $precision = ini_set('serialize_precision', '-1');

        try {
            $payload = json_encode(
                ['format' => self::FORMAT, 'fragment' => $fragment->toArray(), 'dependencies' => $dependencies],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException) {
            return;
        } finally {
            if (is_string($precision)) {
                ini_set('serialize_precision', $precision);
            }
        }

        $file = $this->file($key);
        GeneratedDirectory::ensure(dirname($file));
        AtomicFile::write($file, $payload);
    }

    /**
     * What a dependency file hashed to when the entry was written: its digest, {@see ABSENT} when
     * there was no such file, or {@see UNREADABLE} when there was one and it could not be read. The
     * last is deliberately a value nothing can match, so an entry depending on a file we cannot see
     * the contents of never reads back as fresh.
     */
    private function recorded(string $file): string
    {
        $hash = $this->digests->of($file);
        if ($hash !== false) {
            return $hash;
        }

        return $this->digests->exists($file) ? self::UNREADABLE : self::ABSENT;
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

            // Absent then is fresh only while it is absent now: a file that has since appeared changes
            // what the route says, exactly as an edited one does.
            if ($expected === self::ABSENT) {
                if ($this->digests->exists($file)) {
                    return false;
                }

                continue;
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
}
