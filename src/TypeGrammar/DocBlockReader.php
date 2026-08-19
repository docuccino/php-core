<?php

declare(strict_types=1);

namespace Docuccino\Core\TypeGrammar;

use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTextNode;

/**
 * The one docblock reader: prose, `@example`, `@summary`/`@description`, `@property`/`@param`/`@var`
 * tags — each with its `@phpstan-`/`@psalm-` prefixed forms — and the OAS summary/description split,
 * all through the shared {@see PhpDocParserStack} so there's a single grammar.
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

        return $node === null ? null : $this->prose($node);
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
        return $this->tag($this->stack->parseDocBlock($docComment), '@example');
    }

    /**
     * The OAS `summary` and `description` a docblock states.
     *
     * By convention that is the leading prose split in two — first paragraph, then the rest — which
     * makes one text serve both the maintainer reading the code and the consumer reading the document.
     * `@summary` and `@description` are the way out of that: declaring EITHER hands the consumer-facing
     * text over to the tags outright, and the free prose above them stops feeding both fields. So a
     * note about who may call this never reaches the document, and a field the author didn't state
     * comes out empty rather than half-quoting a note meant for somebody else.
     *
     * @return array{summary: ?string, description: ?string}
     */
    public function read(?string $docComment): array
    {
        $node = $this->stack->parseDocBlock($docComment);
        if ($node === null) {
            return ['summary' => null, 'description' => null];
        }

        $summary = $this->tag($node, '@summary');
        $description = $this->tag($node, '@description');
        if ($summary !== null || $description !== null) {
            return ['summary' => $summary, 'description' => $description];
        }

        $prose = $this->prose($node);
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

    /** The first non-empty value of a free-form tag, or null. */
    private function tag(?PhpDocNode $node, string $name): ?string
    {
        if ($node === null) {
            return null;
        }

        foreach ($node->getTagsByName($name) as $tag) {
            $value = trim((string) $tag->value);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** The leading prose of an already-parsed docblock. */
    private function prose(PhpDocNode $node): ?string
    {
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
}
