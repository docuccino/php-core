<?php

declare(strict_types=1);

namespace Docuccino\Core\TypeGrammar;

use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTextNode;

/**
 * The one docblock reader: prose, `@example`, `@property`/`@param`/`@var` tags — each with its
 * `@phpstan-`/`@psalm-` prefixed forms — and the OAS summary/description split, all through the shared
 * {@see PhpDocParserStack} so there's a single grammar.
 */
final class DocBlockReader
{
    /**
     * Tag precedence, most authoritative first, and the same order for every family below — an
     * analyser-prefixed tag exists to state what the generic one couldn't, and `@phpstan-` wins over
     * `@psalm-` because PHPStan is the engine behind this project.
     */
    private const VAR_TAGS = ['@phpstan-var', '@psalm-var', '@var'];

    private const PARAM_TAGS = ['@phpstan-param', '@psalm-param', '@param'];

    private const PROPERTY_TAGS = [
        '@phpstan-property', '@phpstan-property-read',
        '@psalm-property', '@psalm-property-read',
        '@property', '@property-read',
    ];

    public function __construct(
        private readonly PhpDocParserStack $stack = new PhpDocParserStack,
    ) {}

    /** The leading prose, summary and description together. */
    public function summary(?string $docComment): ?string
    {
        $node = $this->stack->parseDocBlock($docComment);
        if ($node === null) {
            return null;
        }

        foreach ($node->children as $child) {
            if ($child instanceof PhpDocTextNode) {
                $text = trim($child->text);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return null;
    }

    /**
     * The `@property`/`@property-read` tags a class declares (the ide-helper model-column convention), as an
     * ordered `name => {type, description}` map. Write-only forms are left out — not readable, so they don't
     * document a response — and a duplicate name keeps its first declaration.
     *
     * @return array<string, array{type: string, description: ?string}>
     */
    public function properties(?string $docComment): array
    {
        $node = $this->stack->parseDocBlock($docComment);
        if ($node === null) {
            return [];
        }

        $out = [];
        foreach (self::PROPERTY_TAGS as $tagName) {
            foreach ($node->getPropertyTagValues($tagName) as $tag) {
                $name = ltrim($tag->propertyName, '$');
                if ($name === '' || isset($out[$name])) {
                    continue;
                }

                $description = trim($tag->description);
                $out[$name] = [
                    'type' => (string) $tag->type,
                    'description' => $description === '' ? null : $description,
                ];
            }
        }

        return $out;
    }

    /**
     * The `@param` tags a docblock declares, as an ordered `name => {type, description}` map. A promoted
     * constructor property writes its precise type here rather than in a `@var`, so this is where a `list<T>`
     * behind a native `array` is found; a duplicate name keeps its first declaration.
     *
     * @return array<string, array{type: string, description: ?string}>
     */
    public function params(?string $docComment): array
    {
        $node = $this->stack->parseDocBlock($docComment);
        if ($node === null) {
            return [];
        }

        $out = [];
        foreach (self::PARAM_TAGS as $tagName) {
            foreach ($node->getParamTagValues($tagName) as $tag) {
                $name = ltrim($tag->parameterName, '$');
                if ($name === '' || isset($out[$name])) {
                    continue;
                }

                $description = trim($tag->description);
                $out[$name] = [
                    'type' => (string) $tag->type,
                    'description' => $description === '' ? null : $description,
                ];
            }
        }

        return $out;
    }

    /** The first type a `@var` tag states, in {@see self::VAR_TAGS} precedence, or null. */
    public function varType(?string $docComment): ?string
    {
        $node = $this->stack->parseDocBlock($docComment);
        if ($node === null) {
            return null;
        }

        foreach (self::VAR_TAGS as $tagName) {
            foreach ($node->getVarTagValues($tagName) as $tag) {
                $type = trim((string) $tag->type);
                if ($type !== '') {
                    return $type;
                }
            }
        }

        return null;
    }

    /** The first `@example` value, or null. */
    public function example(?string $docComment): ?string
    {
        $node = $this->stack->parseDocBlock($docComment);
        if ($node === null) {
            return null;
        }

        foreach ($node->getTagsByName('@example') as $tag) {
            $value = trim((string) $tag->value);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * The leading prose split into an OAS `summary` (first paragraph) and `description` (the rest).
     *
     * @return array{summary: ?string, description: ?string}
     */
    public function read(?string $docComment): array
    {
        $prose = $this->summary($docComment);
        if ($prose === null) {
            return ['summary' => null, 'description' => null];
        }

        $parts = preg_split('/\R{2,}/', $prose, 2);
        $summary = trim($parts[0] ?? $prose);
        $description = isset($parts[1]) ? trim($parts[1]) : null;

        return [
            'summary' => $summary === '' ? null : $summary,
            'description' => ($description === null || $description === '') ? null : $description,
        ];
    }
}
