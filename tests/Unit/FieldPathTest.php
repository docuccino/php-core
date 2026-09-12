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

/*
 * One path answering for another: the question a producer asks before it says a field's shape is
 * undecided, when a declaration elsewhere may already have decided it. Compared segment by segment,
 * because a string prefix cannot tell an escaped dot from a separator and would read `meta\.scoring` as
 * something inside `meta`.
 */
it('says whether one path names another or something inside it', function (string $path, string $ancestor, bool $covers): void {
    expect(FieldPath::isAtOrUnder($path, $ancestor))->toBe($covers);
})->with([
    'a path names itself' => ['meta', 'meta', true],
    'a child names its parent' => ['meta.scoring', 'meta', true],
    'a grandchild names its ancestor' => ['meta.scoring.scores', 'meta', true],
    'a wildcard element names its container' => ['items.*', 'items', true],
    'a deeper ancestor is matched too' => ['meta.scoring.scores', 'meta.scoring', true],
    'a sibling does not' => ['meta.other', 'meta.scoring', false],
    'a parent does not name its own child' => ['meta', 'meta.scoring', false],
    'an unrelated name does not' => ['other', 'meta', false],
    // The reason this is not `str_starts_with`: the two share every character.
    'a name holding a dot is not inside the name before it' => ['meta\.scoring', 'meta', false],
    'a name holding a dot answers for itself' => ['meta\.scoring', 'meta\.scoring', true],
    // A prefix of a SEGMENT is not a segment: `met` is not an ancestor of `meta`.
    'a shared segment prefix does not' => ['meta.scoring', 'met', false],
    // The pair a string prefix gets wrong: `a\` is a name ending in a backslash, and `a\.b` is one
    // field called `a.b` — so the second is not inside the first, however the characters line up.
    'a trailing backslash is part of a name, not the start of an escape' => ['a\.b', 'a\\', false],
]);

/*
 * The bracketed spelling, written and read back. `toQueryName()` mints the names every producer of a
 * deepObject member publishes and `fromQueryName()` is what a declaration is matched against, so a
 * name the write produces and the read does not recognise is a member an author cannot address — and a
 * name the read accepts that the write never produces is a declaration landing on a field nobody
 * asked about. Every segment shape with a character the two grammars argue over is a row.
 */
it('reads back exactly the bracketed name it wrote', function (array $segments, string $name): void {
    expect(FieldPath::toQueryName($segments))->toBe($name)
        ->and(FieldPath::fromQueryName($name))->toBe($segments);
})->with([
    'a top-level name is its own spelling' => [['filter'], 'filter'],
    'one member rides as brackets' => [['filter', 'status'], 'filter[status]'],
    'every depth rides as its own brackets' => [['filter', 'window', 'from'], 'filter[window][from]'],
    // The wire has no escape, and reads an inner `[` as part of the member's name:
    // `?filter[a[b]=x` sets the `a[b` key of `filter`. So this is one member, not two.
    'an opening bracket inside a member is the member’s own' => [['filter', 'a[b'], 'filter[a[b]'],
    // The path grammar would fold these; the wire spelling has no separator to confuse them with.
    'a dot inside a member needs no escaping here' => [['filter', 'a.b'], 'filter[a.b]'],
    'a backslash inside a member is a character' => [['filter', 'a\b'], 'filter[a\b]'],
    'a member ending in a backslash' => [['filter', 'a\\'], 'filter[a\\]'],
    'a wildcard is a member name like any other' => [['items', '*', 'id'], 'items[*][id]'],
]);

/*
 * The one asymmetry, stated rather than left to be discovered: the wire spelling carries no escape, so
 * a member whose name holds a `]` closes its own group early and there is no name that means it. The
 * write still produces something — the member is listed for a reader who wants to see it — and the
 * read refuses it, which is the honest end: a declaration naming it reaches nothing and is reported,
 * instead of removing whichever member the string happened to be re-parsed into.
 */
it('writes a member holding a closing bracket and refuses to read it back', function (): void {
    $name = FieldPath::toQueryName(['filter', 'a]b']);

    expect($name)->toBe('filter[a]b]')
        ->and(FieldPath::fromQueryName($name))->toBeNull();
});

it('refuses a bracketed name the write does not produce', function (string $name): void {
    expect(FieldPath::fromQueryName($name))->toBeNull()
        ->and(FieldPath::queryNameAsPath($name))->toBeNull();
})->with([
    // Unbalanced: on the wire `?filter[opaque=x` sets a top-level `filter_opaque`, so this names no
    // member of `filter` at all — reading it as one removes a field the author never mentioned.
    'an unterminated member' => ['filter[opaque'],
    'an unterminated member below one that closed' => ['filter[window][from'],
    'a closing bracket with nothing open' => ['filter]status]'],
    // Text after the last `]` is not a segment and has no spelling of its own.
    'text after the last bracket' => ['filter[a]b]'],
    // An empty member name is an array append on the wire, not a named member.
    'an empty member' => ['filter[]'],
    'an empty container name' => ['[status]'],
    'nothing at all' => [''],
]);

/*
 * The same reading spelled in the path grammar, which is what a declaration is compared with a
 * validation rule key through. It is narrower than the bracketed reading on purpose: a member ending
 * in a backslash has no spelling here, because the path reader would take that backslash as escaping
 * the separator after it and answer about a different field.
 */
it('spells a bracketed name as the path the rule keys are keyed by', function (string $name, ?string $path): void {
    expect(FieldPath::queryNameAsPath($name))->toBe($path);
})->with([
    'a top-level name' => ['filter', 'filter'],
    'one member' => ['filter[status]', 'filter.status'],
    'every depth' => ['filter[window][from]', 'filter.window.from'],
    // The dot is the member's own, so it comes back escaped — `filter.a\.b` is the field `a.b` inside
    // `filter`, which is a different rule key from `filter.a.b`.
    'a dot inside a member is escaped' => ['filter[a.b]', 'filter.a\.b'],
    'a bracket inside a member survives' => ['filter[a[b]', 'filter.a[b'],
    'a backslash inside a member survives' => ['filter[a\b]', 'filter.a\b'],
    'a trailing backslash on the LAST member is safe' => ['filter[a\\]', 'filter.a\\'],
    // `filter.a\.b` would read back as the single field `a.b` inside `filter`, not as `b` inside `a\`.
    'a member ending in a backslash before another has no spelling' => ['filter[a\][b]', null],
]);
