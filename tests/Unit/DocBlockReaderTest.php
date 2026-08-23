<?php

declare(strict_types=1);

use Docuccino\Core\TypeGrammar\DocBlockReader;

/**
 * The docblock prose reader: the summary/description split and the `@summary`/`@description` tags that
 * override it, @example, `@property` tags, and the degradation branches.
 */
it('splits leading prose into summary (first paragraph) and description (remainder)', function (): void {
    $read = (new DocBlockReader)->read("/**\n * First summary line.\n *\n * Second paragraph with detail.\n */");

    expect($read['summary'])->toBe('First summary line.')
        ->and($read['description'])->toBe('Second paragraph with detail.');
});

it('joins paragraphs beyond the first into the description', function (): void {
    $read = (new DocBlockReader)->read("/**\n * Summary.\n *\n * Second para.\n *\n * Third para.\n */");

    expect($read['summary'])->toBe('Summary.')
        ->and($read['description'])->toBe("Second para.\n\nThird para.");
});

it('leaves the description null for a single-paragraph docblock', function (): void {
    $read = (new DocBlockReader)->read("/**\n * Only one paragraph here.\n */");

    expect($read['summary'])->toBe('Only one paragraph here.')
        ->and($read['description'])->toBeNull();
});

it('returns both null for an empty or absent docblock', function (?string $doc): void {
    expect((new DocBlockReader)->read($doc))->toBe(['summary' => null, 'description' => null, 'deprecated' => false]);
})->with([
    'empty docblock' => ['/** */'],
    'null' => [null],
]);

// The tag rung and the prose rung are the same precedence layer, so which of them a field takes has
// to be decided here rather than by a patch. An explicit tag wins, and it wins for BOTH fields at
// once — the prose above it was written for whoever maintains the action.
it('takes summary and description from @summary and @description over the prose', function (): void {
    $read = (new DocBlockReader)->read(
        "/**\n * Internal — dispatched by the worker.\n *\n * Retries three times.\n *\n * @summary Void an invoice\n *\n * @description Marks an invoice void. This cannot be undone.\n */"
    );

    expect($read['summary'])->toBe('Void an invoice')
        ->and($read['description'])->toBe('Marks an invoice void. This cannot be undone.');
});

it('drops the free prose from both fields once either tag is declared', function (string $doc, ?string $summary, ?string $description): void {
    expect((new DocBlockReader)->read($doc))->toBe(['summary' => $summary, 'description' => $description, 'deprecated' => false]);
})->with([
    '@summary alone' => ["/**\n * Internal note.\n *\n * More of it.\n *\n * @summary Send an invoice\n */", 'Send an invoice', null],
    '@description alone' => ["/**\n * Internal note.\n *\n * More of it.\n *\n * @description The long version.\n */", null, 'The long version.'],
]);

it('ignores an empty tag and falls back to the prose convention', function (): void {
    // A tag with nothing after it states nothing, so it cannot be what the author meant to publish.
    $read = (new DocBlockReader)->read("/**\n * Summary.\n *\n * Detail.\n *\n * @summary\n */");

    expect($read['summary'])->toBe('Summary.')
        ->and($read['description'])->toBe('Detail.');
});

it('keeps the first of a repeated @summary or @description', function (): void {
    $read = (new DocBlockReader)->read("/**\n * @summary First\n *\n * @summary Second\n *\n * @description One\n *\n * @description Two\n */");

    expect($read['summary'])->toBe('First')
        ->and($read['description'])->toBe('One');
});

it('reads a @description that runs over several lines', function (): void {
    $read = (new DocBlockReader)->read("/**\n * @description Marks an invoice void.\n * Voiding is permanent.\n */");

    expect($read['description'])->toBe("Marks an invoice void.\nVoiding is permanent.");
});

it('reads the first non-empty @example and the leading prose via summary()', function (): void {
    $reader = new DocBlockReader;
    $doc = "/**\n * A summary.\n *\n * @example {\"id\": 1}\n */";

    expect($reader->summary($doc))->toBe('A summary.')
        ->and($reader->example($doc))->toBe('{"id": 1}');
});

it('enumerates @property / @property-read tags as an ordered name => {type, description} map', function (): void {
    $doc = "/**\n * A model.\n *\n * @property int \$id The identifier.\n * @property ?string \$name\n * @property-read string \$slug\n */";

    $properties = (new DocBlockReader)->properties($doc);

    expect(array_keys($properties))->toBe(['id', 'name', 'slug'])
        ->and($properties['id'])->toBe(['type' => 'int', 'description' => 'The identifier.'])
        ->and($properties['name'])->toBe(['type' => '?string', 'description' => null])
        ->and($properties['slug'])->toBe(['type' => 'string', 'description' => null]);
});

it('keeps the first declaration of a duplicated @property name and ignores a nameless tag', function (): void {
    // @property before @property-read; a duplicate keeps the first (more-authoritative) type.
    $doc = "/**\n * @property int \$id\n * @property-read string \$id\n */";

    expect((new DocBlockReader)->properties($doc))->toBe(['id' => ['type' => 'int', 'description' => null]]);
});

it('returns no properties for a docblock without @property tags', function (?string $doc): void {
    expect((new DocBlockReader)->properties($doc))->toBe([]);
})->with([
    'prose only' => ["/**\n * Just prose.\n */"],
    'empty' => ['/** */'],
    'null' => [null],
]);

it('enumerates @param tags as an ordered name => {type, description} map', function (): void {
    // A promoted constructor property states its precise type here and nowhere else, so this is how a
    // `list<T>` behind a native `array` is found.
    $doc = "/**\n * Builds one.\n *\n * @param  list<ErrorDetailData>|Optional  \$errors  The failures.\n * @param  string  \$title\n */";

    $params = (new DocBlockReader)->params($doc);

    expect(array_keys($params))->toBe(['errors', 'title'])
        // A union comes back parenthesised and spaced, which is the parser's own rendering of the node it
        // built — TypeStringParser reads it straight back, so it is normalised rather than mangled.
        ->and($params['errors'])->toBe(['type' => '(list<ErrorDetailData> | Optional)', 'description' => 'The failures.'])
        ->and($params['title'])->toBe(['type' => 'string', 'description' => null]);
});

it('keeps the first declaration of a duplicated @param name', function (): void {
    expect((new DocBlockReader)->params("/**\n * @param int \$id\n * @param string \$id\n */"))
        ->toBe(['id' => ['type' => 'int', 'description' => null]]);
});

it('returns no params for a docblock without @param tags', function (?string $doc): void {
    expect((new DocBlockReader)->params($doc))->toBe([]);
})->with([
    'prose only' => ["/**\n * Just prose.\n */"],
    'empty' => ['/** */'],
    'null' => [null],
]);

it('reads the first @var type, and null when there is none', function (): void {
    $reader = new DocBlockReader;

    expect($reader->varType("/**\n * @var list<ErrorDetailData>\n */"))->toBe('list<ErrorDetailData>')
        ->and($reader->varType("/**\n * @var int\n * @var string\n */"))->toBe('int')
        ->and($reader->varType("/**\n * Just prose.\n */"))->toBeNull()
        ->and($reader->varType(null))->toBeNull();
});

it('reads the type out of an analyser-prefixed @var tag', function (string $tag): void {
    // Standardising on the prefixed form is a real and common convention — it is how you state a type
    // the runtime doesn't have — so each accepted spelling reads the same as the plain one.
    expect((new DocBlockReader)->varType("/**\n * ".$tag." list<ErrorDetailData>\n */"))
        ->toBe('list<ErrorDetailData>');
})->with([
    '@var' => ['@var'],
    '@phpstan-var' => ['@phpstan-var'],
    '@psalm-var' => ['@psalm-var'],
]);

it('ignores a @var spelling it does not accept', function (string $tag): void {
    // The unknown-entry contract: only the two conventional analyser prefixes are read. `@phan-var` and
    // an invented prefix parse fine but are not this reader's vocabulary, so they answer null rather
    // than silently widening it.
    expect((new DocBlockReader)->varType("/**\n * ".$tag." list<ErrorDetailData>\n */"))->toBeNull();
})->with([
    '@phan-var' => ['@phan-var'],
    '@rector-var' => ['@rector-var'],
]);

it('prefers @phpstan-var, then @psalm-var, then @var, whatever order they are written in', function (): void {
    // Determinism: the prefixed tag exists precisely to say what the generic one couldn't, so it wins,
    // and @phpstan- wins over @psalm- because PHPStan is the engine behind this project. Source order
    // never decides it.
    $reader = new DocBlockReader;

    expect($reader->varType("/**\n * @var array\n * @psalm-var list<int>\n * @phpstan-var list<string>\n */"))
        ->toBe('list<string>')
        ->and($reader->varType("/**\n * @phpstan-var list<string>\n * @psalm-var list<int>\n * @var array\n */"))
        ->toBe('list<string>')
        ->and($reader->varType("/**\n * @var array\n * @psalm-var list<int>\n */"))->toBe('list<int>');
});

it('applies the same tag precedence to @param', function (): void {
    $doc = "/**\n * @param  array  \$rows  Generic prose.\n * @psalm-param  list<int>  \$rows\n * @phpstan-param  list<string>  \$rows  Precise prose.\n */";

    expect((new DocBlockReader)->params($doc))
        ->toBe(['rows' => ['type' => 'list<string>', 'description' => 'Precise prose.']]);
});

it('reads analyser-prefixed @param and @property tags at all', function (): void {
    $reader = new DocBlockReader;

    expect($reader->params("/**\n * @phpstan-param  list<int>  \$ids\n */"))
        ->toBe(['ids' => ['type' => 'list<int>', 'description' => null]])
        ->and($reader->params("/**\n * @psalm-param  list<int>  \$ids\n */"))
        ->toBe(['ids' => ['type' => 'list<int>', 'description' => null]])
        ->and($reader->properties("/**\n * @phpstan-property  list<int>  \$ids\n */"))
        ->toBe(['ids' => ['type' => 'list<int>', 'description' => null]])
        ->and($reader->properties("/**\n * @psalm-property-read  list<int>  \$ids\n */"))
        ->toBe(['ids' => ['type' => 'list<int>', 'description' => null]]);
});

it('applies the same tag precedence to @property, read forms last within each analyser', function (): void {
    $doc = "/**\n * @property int \$id\n * @property-read string \$id\n * @psalm-property float \$id\n * @phpstan-property-read bool \$id\n */";

    expect((new DocBlockReader)->properties($doc))->toBe(['id' => ['type' => 'bool', 'description' => null]]);
});

it('still leaves a write-only @property out', function (): void {
    expect((new DocBlockReader)->properties("/**\n * @property-write int \$secret\n */"))->toBe([]);
});

/**
 * Inline tags name code the reader of an emitted document cannot see, so they never travel with the
 * prose. They are dropped rather than unwrapped — an unwrapped `{@see Foo}` would leave a bare FQCN in
 * consumer-facing text — and the bracket an author wrapped one in goes with it.
 */
it('drops inline tags out of the prose it publishes', function (string $prose, ?string $summary): void {
    expect((new DocBlockReader)->summary("/**\n * ".$prose."\n */"))->toBe($summary);
})->with([
    'a see tag mid-sentence' => ['The body {@see \\App\\Internal\\Thing} names.', 'The body names.'],
    'a bracketed see tag takes its brackets' => ['A subclass ({@see ListQueryBuilder}) filtered here.', 'A subclass filtered here.'],
    'a member reference' => ['Answers exactly what {@see Model::getTable()} answers.', 'Answers exactly what answers.'],
    'a link tag' => ['Rated by {@link https://example.test/spec} alone.', 'Rated by alone.'],
    'inheritdoc alone leaves no prose' => ['{@inheritdoc}', null],
    'inheritDoc in its other spelling' => ['{@inheritDoc}', null],
    'a trailing tag keeps the sentence intact' => ['The yearly entries {@see Entry}.', 'The yearly entries.'],
    'a bracketed tag alone' => ['Sorted ({@see Sorter}).', 'Sorted.'],
    'two tags in a row' => ['Built by {@see A} and {@see B} together.', 'Built by and together.'],
    'a paren that is not a tag survives' => ['Whatever `toArray()` returns.', 'Whatever `toArray()` returns.'],
    'a brace that is not a tag survives' => ['Shaped like array{id: int}.', 'Shaped like array{id: int}.'],
]);

it('drops inline tags out of @property and @param descriptions too', function (): void {
    $reader = new DocBlockReader;

    expect($reader->properties("/**\n * @property string \$title The title, per {@see Almanac}.\n */"))
        ->toBe(['title' => ['type' => 'string', 'description' => 'The title, per.']])
        ->and($reader->params("/**\n * @param  int  \$id  {@inheritdoc}\n */"))
        ->toBe(['id' => ['type' => 'int', 'description' => null]]);
});

it('drops inline tags out of an explicit @summary or @description', function (): void {
    $read = (new DocBlockReader)->read("/**\n * @summary Listed by {@see Lister}.\n * @description Detail from {@see Detail}.\n */");

    expect($read)->toBe(['summary' => 'Listed by.', 'description' => 'Detail from.', 'deprecated' => false]);
});

it('reads the @deprecated tag as a fact, on every prose branch', function (string $doc, bool $deprecated, ?string $summary): void {
    $read = (new DocBlockReader)->read($doc);

    expect($read['deprecated'])->toBe($deprecated)
        ->and($read['summary'])->toBe($summary);
})->with([
    'bare tag under prose' => ["/**\n * Lists widgets.\n *\n * @deprecated\n */", true, 'Lists widgets.'],
    'tag with a reason' => ["/**\n * Lists widgets.\n *\n * @deprecated Superseded by v2.\n */", true, 'Lists widgets.'],
    'tag beside @summary' => ["/**\n * Internal note.\n *\n * @summary Send an invoice\n * @deprecated\n */", true, 'Send an invoice'],
    'tag with no prose at all' => ["/**\n * @deprecated\n */", true, null],
    'no tag' => ["/**\n * Lists widgets.\n */", false, 'Lists widgets.'],
]);
