<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

use Docuccino\Core\Extensions\Contracts\RouteResolver;

/**
 * A framework-agnostic description of one discovered route, produced by a
 * {@see RouteResolver}. The action reference is opaque
 * (a `Class@method` string, a `Class` invokable, or the sentinel `Closure` for closure
 * routes) — the adapter's {@see RouteContext} carries the
 * resolved reflection.
 */
final readonly class RouteDescriptor
{
    /**
     * @param  list<string>  $methods  upper-case HTTP methods (a route may answer several)
     * @param  string  $uri  the route template, always leading-slashed (`/api/forms/{form}`)
     * @param  string|null  $action  the resolved action target (`Class@method`, an invokable class,
     *                               or the `Closure` sentinel) — folded into {@see cacheSignature()}
     * @param  list<string>  $middleware  the route's fully-gathered middleware, in effect order
     * @param  list<string>  $cacheInputs  extra scalar cache-key inputs a resolver folds in (design
     *                                     §10): anything not knowable from method/URI/action/middleware
     *                                     that must still bust the fragment cache when it changes
     */
    public function __construct(
        public array $methods,
        public string $uri,
        public ?string $name = null,
        public ?string $action = null,
        public array $middleware = [],
        public array $cacheInputs = [],
    ) {}

    /** The primary documentable HTTP method (lower-case) — the first non-HEAD method. */
    public function primaryMethod(): string
    {
        foreach ($this->methods as $method) {
            if (strtoupper($method) !== 'HEAD') {
                return strtolower($method);
            }
        }

        return strtolower($this->methods[0] ?? 'get');
    }

    /**
     * Every documentable HTTP method this route answers (lower-case, deduped, `HEAD` dropped): a
     * route registered for several verbs (`Route::match(['put','patch'], …)`) documents ONE
     * operation per method (arch F8), so `PUT|PATCH` yields two operations under the same path and
     * `GET|POST` correctly documents a query operation and a body operation. Falls back to the
     * primary method when nothing documentable remains.
     *
     * @return list<string>
     */
    public function documentableMethods(): array
    {
        $methods = [];
        foreach ($this->methods as $method) {
            if (strtoupper($method) === 'HEAD') {
                continue;
            }
            $lower = strtolower($method);
            if (! in_array($lower, $methods, true)) {
                $methods[] = $lower;
            }
        }

        return $methods === [] ? [$this->primaryMethod()] : $methods;
    }

    /** A stable, human-readable signature for diagnostics: `GET /api/forms`. */
    public function signature(): string
    {
        return strtoupper($this->primaryMethod()).' '.$this->uri;
    }

    /**
     * The fragment-cache key input for this route (design §10) — NOT for humans (that is
     * {@see signature()}). Beyond method + URI it folds in the resolved action target and the
     * normalised middleware list, so re-pointing a route at a different controller, or adding /
     * removing middleware (e.g. an auth guard that changes the documented security), invalidates the
     * cached fragment even though the human signature is unchanged.
     */
    public function cacheSignature(): string
    {
        $middleware = array_values(array_filter(
            array_map('trim', $this->middleware),
            static fn (string $entry): bool => $entry !== '',
        ));

        return implode("\0", [
            strtoupper($this->primaryMethod()),
            $this->uri,
            $this->action ?? '',
            implode(',', $middleware),
            implode(',', $this->cacheInputs),
        ]);
    }
}
