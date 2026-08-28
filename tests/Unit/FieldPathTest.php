<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Validation\FieldPath;

/*
 * The one grammar every reader of a body field path shares. Two readers already split one — the
 * validation builder assembling a body out of rule keys, and the `#[BodyParameter]` that patches a
 * property of the body it assembled — and a string that means one thing to the producer and another to
 * the patcher is how a declaration lands somewhere nobody asked for.
 */
it('splits a field path the way a validation rule key is read', function (string $path, array $segments): void {
    expect(FieldPath::segments($path))->toBe($segments);
})->with([
    'a plain name is one segment' => ['nickname', ['nickname']],
    'a dot descends' => ['meta.validation_overrides', ['meta', 'validation_overrides']],
    'every dot descends' => ['a.b.c', ['a', 'b', 'c']],
    'a wildcard is a segment of its own' => ['items.*.id', ['items', '*', 'id']],
    // Laravel's own escape, read the way Laravel reads it.
    'an escaped dot belongs to the name' => ['meta\.validation_overrides', ['meta.validation_overrides']],
    'escaped and unescaped dots mix' => ['a\.b.c', ['a.b', 'c']],
    // A lone backslash is not an escape: only the one in front of a dot disappears.
    'a backslash before anything else stays' => ['a\b.c', ['a\b', 'c']],
    'a backslash at the end stays' => ['a\\', ['a\\']],
    // The empty segments — kept rather than dropped, because they are the evidence the string names
    // no field, and a caller that silently dropped them would document a name nobody wrote.
    'an empty path is one empty segment' => ['', ['']],
    'a trailing dot leaves an empty segment' => ['meta.', ['meta', '']],
    'a leading dot leaves an empty segment' => ['.meta', ['', 'meta']],
    'a doubled dot leaves an empty segment' => ['a..b', ['a', '', 'b']],
]);

it('calls a path well formed exactly when every segment names something', function (string $path, bool $wellFormed): void {
    expect(FieldPath::isWellFormed($path))->toBe($wellFormed);
})->with([
    'a plain name' => ['nickname', true],
    'a dotted path' => ['meta.validation_overrides', true],
    'a wildcard path' => ['items.*.id', true],
    'an escaped dot' => ['meta\.validation_overrides', true],
    // An escaped dot at the end is a NAME ending in a dot, not a trailing separator — the escape is
    // read before the split, so the two spellings part company here.
    'a name ending in an escaped dot' => ['meta\.', true],
    'an empty path' => ['', false],
    'a trailing dot' => ['meta.', false],
    'a leading dot' => ['.meta', false],
    'a doubled dot' => ['a..b', false],
]);
