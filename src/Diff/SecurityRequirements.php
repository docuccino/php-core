<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Support\Hydrate;

/**
 * Which `components.securitySchemes` a document actually makes a client satisfy. A requirement names a
 * scheme by KEY rather than through a `$ref`, so this reads every `security` member wherever one sits —
 * the document root, an operation, a webhook, a callback, a path item under `components` — rather than
 * the modelled operations only.
 *
 * Like {@see SchemaReachability} it prefers to over-collect: a name it reads where no requirement really
 * stated one leaves that scheme's changes breaking, while a name it misses stands a real break down to
 * silence.
 *
 * @internal
 */
final readonly class SecurityRequirements
{
    /**
     * @param  array<string, true>  $names
     */
    private function __construct(private array $names) {}

    public static function of(UirDocument $document): self
    {
        $names = [];
        self::collect($document->toArray(), $names);

        return new self($names);
    }

    public function requires(string $name): bool
    {
        return isset($this->names[$name]);
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  array<string, true>  $out
     */
    private static function collect(array $node, array &$out): void
    {
        foreach ($node as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            if ($key === 'security') {
                self::namesIn($value, $out);
            }

            self::collect($value, $out);
        }
    }

    /**
     * A requirement is a map of scheme name → scopes, and `security` a list of them. A string key at either
     * level names a scheme, because an artifact that wrote the map without the list around it is malformed
     * and still means one. Where the model owns the member that recovery has happened already
     * ({@see Hydrate::securityRequirements()}), so the key read here is the one under a `security` the
     * model keeps verbatim — a callback, a path item under `components`.
     *
     * @param  array<array-key, mixed>  $requirements
     * @param  array<string, true>  $out
     */
    private static function namesIn(array $requirements, array &$out): void
    {
        foreach ($requirements as $key => $requirement) {
            if (is_string($key) && $key !== '') {
                $out[$key] = true;
            }

            if (! is_array($requirement)) {
                continue;
            }

            foreach (array_keys($requirement) as $name) {
                if (is_string($name) && $name !== '') {
                    $out[$name] = true;
                }
            }
        }
    }
}
