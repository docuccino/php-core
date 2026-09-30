<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\DiagnosticCollector;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Lint\DocumentLint;
use Docuccino\Core\Lint\LintRuleOptions;

/*
 * A build runs a lint beside the rest of its work, over a copy of the draft ({@see DocumentLint}), so whatever
 * a lint wrote would reach no document, and would be lost without a word. Every one therefore writes nothing,
 * held to it over one document that gives each of them something to say, since a lint that finds nothing to
 * read has not been shown to leave the draft alone. The lints are every one a shipped package declares.
 */

dataset('document lints', function (): array {
    $transformers = array_filter(
        shippedDeclaredClasses(),
        static fn (string $class): bool => is_subclass_of($class, DocumentTransformer::class),
        ARRAY_FILTER_USE_KEY,
    );

    // The scan reaches document transformers outside the directory the lints sit in, and outside core: report-
    // only transformers already live there, so that is where the next lint may be written.
    expect(array_filter($transformers, static fn (string $file): bool => ! str_contains($file, '/php/core/src/Lint/')))->not->toBeEmpty()
        ->and(array_filter($transformers, static fn (string $file): bool => str_contains($file, '/php/laravel/src/')))->not->toBeEmpty();

    $lints = [];
    foreach (array_keys($transformers) as $class) {
        $reflection = new ReflectionClass($class);
        if ($reflection->isInstantiable() && $reflection->implementsInterface(DocumentLint::class)) {
            $lints[$class] = [$class];
        }
    }

    // Seven today; finding none would mean the scan or the interface moved, not that the lints went.
    expect(count($lints))->toBeGreaterThanOrEqual(7);

    return $lints;
});

it('reports on the draft and leaves it exactly as it found it', function (string $class): void {
    $document = lintDocument(
        [
            // An operation nobody described, under an operationId no generator can name a method after and
            // a tag the document never declares, answering with a union one empty branch makes vacuous, a
            // redirect that never says which, and an example its own schema refuses.
            'GET /api/accounts' => [
                'operationId' => 'accounts/index',
                'tags' => ['Accounts'],
                'responses' => [
                    '200' => ['description' => 'OK', 'content' => ['application/json' => [
                        'schema' => ['anyOf' => [[], ['$ref' => '#/components/schemas/Account']]],
                    ]]],
                    '3XX' => ['description' => 'Redirect'],
                ],
            ],
        ],
        [['name' => 'Billing', 'description' => 'Invoices.']],
    );
    $document['components']['schemas']['Account'] = [
        'type' => 'object',
        'properties' => ['id' => ['type' => 'integer'], 'password' => ['type' => 'string']],
        'example' => ['id' => 'not a number'],
    ];

    $constructor = (new ReflectionClass($class))->getConstructor();
    $first = $constructor?->getParameters()[0] ?? null;
    $options = $first?->getType() instanceof ReflectionNamedType && $first->getType()->getName() === LintRuleOptions::class
        ? [new LintRuleOptions(enabled: true)]
        : [];
    $lint = new $class(...$options);

    $draft = new UirDocumentDraft($document);
    $collector = new DiagnosticCollector;
    $lint->transform($draft, new DocumentContext(new DocumentConfig(key: 'd', info: ['title' => 'T', 'version' => '1']), 'doc:d', $collector));

    expect($collector->all())->not->toBeEmpty()
        ->and($draft->toArray())->toBe($document);
})->with('document lints');
