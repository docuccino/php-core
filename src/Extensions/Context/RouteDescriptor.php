<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

use Docuccino\Core\Extensions\Contracts\RouteResolver;

/**
 * A framework-agnostic description of one discovered route, produced by a {@see RouteResolver}. The
 * action reference is opaque — a `Class@method` string, an invokable `Class`, or the `Closure`
 * sentinel; {@see RouteContext} carries the resolved reflection.
 */
final readonly class RouteDescriptor
{
    /**
     * @param  list<string>  $methods  upper-case HTTP methods (a route may answer several)
     * @param  string  $uri  the route template, always leading-slashed (`/api/forms/{form}`)
     * @param  string|null  $name  the route's name — the default `operationId`, so it is folded into
     *                             {@see cacheSignature()} too
     * @param  string|null  $action  the resolved action target (`Class@method`, an invokable class,
     *                               or the `Closure` sentinel) — folded into {@see cacheSignature()}
     * @param  list<string>  $middleware  the route's fully-gathered middleware, in effect order
     * @param  list<string>  $cacheInputs  extra scalar cache-key inputs a resolver folds in (design
     *                                     §10): anything not knowable from method/URI/action/middleware
     *                                     that must still bust the fragment cache when it changes
     * @param  string|null  $domain  the host this route answers on (`admin.example.com`, or a templated
     *                               `{tenant}.example.com`), scheme-less and lower-cased, or null when it
     *                               answers on every host. Part of the route's IDENTITY, not just its
     *                               cache key: one method and URI on two hosts is two operations, and
     *                               keying either without it serves one sibling's answer for the other.
     * @param  bool  $fallback  whether this is a catch-all route answering any path no other route
     *                          matched (Laravel's `Route::fallback()`). Its URI is a placeholder rather
     *                          than an endpoint, so it is reported and omitted rather than published.
     *                          Deliberately absent from {@see cacheSignature()}: a route that never
     *                          reaches a fragment has nothing to key.
     */
    public function __construct(
        public array $methods,
        public string $uri,
        public ?string $name = null,
        public ?string $action = null,
        public array $middleware = [],
        public array $cacheInputs = [],
        public ?string $domain = null,
        public bool $fallback = false,
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
     * Every documentable method this route answers (lower-case, deduped, `HEAD` dropped). A route
     * registered for several verbs gets one operation per method, so `PUT|PATCH` yields two
     * operations under the same path and `GET|POST` documents a query operation and a body operation.
     * Falls back to the primary method when nothing documentable remains.
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

    /**
     * A stable, human-readable signature for diagnostics: `GET /api/forms`, or
     * `GET admin.example.com/api/forms` when the route is bound to a host. Pass a method to name one
     * verb of a multi-method route rather than its primary one.
     */
    public function signature(?string $method = null): string
    {
        return strtoupper($method ?? $this->primaryMethod()).' '.($this->domain ?? '').$this->uri;
    }

    /**
     * The fragment-cache key input for this route — not for humans, that's {@see signature()}. Beyond
     * method and URI it folds in the name, the action target and normalised middleware, so renaming a
     * route (the name is the default `operationId`), re-pointing it at another controller or changing
     * middleware (an auth guard shifts the documented security) invalidates the fragment even though
     * the human signature didn't move. The name is folded unconditionally: no route file changes when
     * `routes/api.php` renames one, and a rename only ever invalidates the route it renamed. The host
     * is in here because it is what tells two same-method, same-URI siblings apart at all — without it
     * they share a key and a warm build hands one of them the other's fragment.
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
            $this->name ?? '',
            $this->action ?? '',
            implode(',', $middleware),
            implode(',', $this->cacheInputs),
            $this->domain ?? '',
        ]);
    }
}
