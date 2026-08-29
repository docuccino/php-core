<?php

declare(strict_types=1);

use Docuccino\Core\Support\Glob;

/**
 * The product's one wildcard grammar. `routes.include` has always spoken it and now a safelist entry and
 * an `#[AppliesTo]` selector speak the same one — the point being that there is nothing here for a
 * second reader to get subtly different.
 */
it('matches a pattern against a subject', function (string $pattern, string $subject, bool $matches): void {
    expect(Glob::matches($pattern, $subject))->toBe($matches);
})->with([
    'exactly' => ['api/forms', 'api/forms', true],
    'not exactly' => ['api/forms', 'api/form', false],
    'a trailing star' => ['api/*', 'api/forms/1', true],
    'a leading star' => ['*/forms', 'api/v2/forms', true],
    'a star in the middle' => ['GET /api/*/forms', 'GET /api/v2/forms', true],
    // The one that separates this from fnmatch(): a `*` runs straight through a slash.
    'a star across slashes' => ['api/*', 'api/deeply/nested/thing', true],
    'a star matching nothing' => ['api/forms*', 'api/forms', true],
    'everything' => ['*', 'anything at all', true],
    'everything, including nothing' => ['*', '', true],
    // Regex metacharacters are literal, or a pattern with a dot in it would match anything.
    'a literal dot' => ['api.forms', 'apiXforms', false],
    'a literal plus' => ['a+', 'aaa', false],
    'a question mark is not a wildcard' => ['api/form?', 'api/forms', false],
    'a bracket class is not a class' => ['api/[fg]orms', 'api/forms', false],
]);

it('matches any of a list, and nothing against an empty one', function (): void {
    expect(Glob::matchesAny(['api/*', 'admin/*'], 'admin/users'))->toBeTrue()
        ->and(Glob::matchesAny(['api/*', 'admin/*'], 'internal/health'))->toBeFalse()
        ->and(Glob::matchesAny([], 'anything'))->toBeFalse();
});

/*
 * A subject the `u` modifier refuses is still a subject, and a pattern that plainly spells it must still
 * match it. Without the exact-spelling short circuit the expression would return false rather than an
 * answer, and an allow entry would silently stop silencing.
 */
it('answers for a subject a unicode expression could not read', function (): void {
    $invalid = "name\xB1";

    expect(Glob::matches($invalid, $invalid))->toBeTrue()
        ->and(Glob::matches('*', $invalid))->toBeTrue()
        ->and(Glob::matches('name*', $invalid))->toBeFalse();
});
