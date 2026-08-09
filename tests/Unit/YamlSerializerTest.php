<?php

declare(strict_types=1);

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Emit\YamlSerializer;
use Symfony\Component\Yaml\Yaml;

beforeEach(function (): void {
    $this->yaml = new YamlSerializer;
    $this->canonicalizer = new Canonicalizer;
});

it('serialises identical canonical input to byte-identical YAML across runs', function (): void {
    $canonical = $this->canonicalizer->canonicalize(workedExample());

    expect($this->yaml->serialize($canonical))->toBe($this->yaml->serialize($canonical));
});

it('preserves canonical member order rather than re-sorting', function (): void {
    // Canonicalisation fixes order (uir before openapi before info); YAML must not disturb it.
    $canonical = $this->canonicalizer->canonicalize(workedExample());

    $yaml = $this->yaml->serialize($canonical);

    $uirPos = strpos($yaml, 'uir:');
    $openapiPos = strpos($yaml, 'openapi:');
    $infoPos = strpos($yaml, 'info:');

    expect($uirPos)->toBeLessThan($openapiPos);
    expect($openapiPos)->toBeLessThan($infoPos);
});

it('uses block style and renders empty objects as maps', function (): void {
    $value = $this->canonicalizer->canonicalize([
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'paths' => [],
        'security' => [],
    ]);

    $yaml = $this->yaml->serialize($value);

    // Block style: nested keys on their own indented lines, not inline braces.
    expect($yaml)->toContain("info:\n");
    expect($yaml)->toContain('  title: API');

    // Round-trips back to the same structure.
    expect(Yaml::parse($yaml))->toEqual(json_decode(json_encode($value), true));
});

it('renders multi-line strings as literal blocks deterministically', function (): void {
    $value = ['description' => "line one\nline two\nline three"];

    $first = $this->yaml->serialize($value);
    $second = $this->yaml->serialize($value);

    expect($first)->toBe($second);
    expect(Yaml::parse($first)['description'])->toBe("line one\nline two\nline three");
});
