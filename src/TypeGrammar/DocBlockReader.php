<?php

declare(strict_types=1);

namespace Docuccino\Core\TypeGrammar;

use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTextNode;

/**
 * The one docblock reader: prose, `@example`, `@property`/`@param`/`@var` tags, and the OAS
 * summary/description split, all through the shared {@see PhpDocParserStack} so there's a single grammar.
 */
final class DocBlockReader
{
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
     * ordered `name => {type, description}` map. Read forms count too — a serialized attribute is readable —
     * with `@property` first, and a duplicate name keeps its first declaration.
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
        foreach ([...$node->getPropertyTagValues(), ...$node->getPropertyReadTagValues()] as $tag) {
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

        return $out;
    }

    /**
     * The `@param` tags a docblock declares, as an ordered `name => {type, description}` map. A promoted
     * constructor property writes its precise type here rather than in a `@var`, so this is where a `list<T>`
     * behind a native `array` is found. A duplicate name keeps its first declaration.
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
        foreach ($node->getParamTagValues() as $tag) {
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

        return $out;
    }

    /** The first `@var` type a docblock states, or null. */
    public function varType(?string $docComment): ?string
    {
        $node = $this->stack->parseDocBlock($docComment);
        if ($node === null) {
            return null;
        }

        foreach ($node->getVarTagValues() as $tag) {
            $type = trim((string) $tag->type);
            if ($type !== '') {
                return $type;
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
