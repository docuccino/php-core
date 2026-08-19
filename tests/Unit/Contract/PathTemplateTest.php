<?php

declare(strict_types=1);

use Docuccino\Core\Contract\PathTemplate;

it('binds a concrete path to its placeholders', function (string $template, string $path, ?array $bound): void {
    expect(PathTemplate::parse($template)->bind($path))->toBe($bound);
})->with([
    'no placeholders' => ['/api/invoices', '/api/invoices', []],
    'a leading slash is not part of the path' => ['/api/invoices', 'api/invoices', []],
    'a trailing slash is not either' => ['/api/invoices', '/api/invoices/', []],
    'one placeholder' => ['/api/invoices/{invoice}', '/api/invoices/42', ['invoice' => '42']],
    'two placeholders' => ['/a/{x}/b/{y}', '/a/1/b/2', ['x' => '1', 'y' => '2']],
    'a percent-encoded value is decoded' => ['/api/invoices/{invoice}', '/api/invoices/INV%2F1', ['invoice' => 'INV/1']],
    'the query string is not part of the path' => ['/api/invoices', '/api/invoices?page=2', []],
    'a fragment is not either' => ['/api/invoices', '/api/invoices#top', []],
    'a placeholder never spans a slash' => ['/api/invoices/{invoice}', '/api/invoices/4/2', null],
    'too few segments' => ['/api/invoices/{invoice}', '/api/invoices', null],
    'a literal that differs' => ['/api/invoices', '/api/credits', null],
    'an empty segment is not a value' => ['/api/invoices/{invoice}', '/api/invoices//', null],
    'a lone brace is a literal, not a placeholder' => ['/api/{', '/api/{', []],
    'an empty placeholder name is a literal' => ['/api/{}', '/api/x', null],
]);

it('scores a literal segment above a placeholder, position by position', function (): void {
    expect(PathTemplate::parse('/api/invoices/recent')->literalMask())->toBe('111')
        ->and(PathTemplate::parse('/api/invoices/{invoice}')->literalMask())->toBe('110')
        ->and(PathTemplate::parse('/api/invoices/recent')->literalMask())
        ->toBeGreaterThan(PathTemplate::parse('/api/invoices/{invoice}')->literalMask());
});

it('scores the root as the empty mask', function (): void {
    expect(PathTemplate::parse('/')->literalMask())->toBe('')
        ->and(PathTemplate::parse('/')->bind(''))->toBe([]);
});
