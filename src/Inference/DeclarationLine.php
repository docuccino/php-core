<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

use PhpParser\Node;
use PhpToken;

/**
 * The line native reflection reports for a parsed declaration: the line of its `function`/`fn` keyword.
 * php-parser starts the node at its first attribute or modifier instead, so a reader matching a reflected
 * line against a node's start line misses every declaration that carries either on a line of its own —
 * compare against this, never against `getStartLine()`.
 */
final class DeclarationLine
{
    /** Tokens that may stand between a declaration's attributes and its keyword. */
    private const LEADING = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_STATIC, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_ABSTRACT, T_FINAL];

    /**
     * Null where `$source` is not the text the node was parsed from, so nothing can be said about it.
     *
     * @param  string  $source  the whole file the node was parsed from
     */
    public static function of(Node\FunctionLike $node, string $source): ?int
    {
        $groups = $node->getAttrGroups();
        $last = $groups === [] ? null : $groups[array_key_last($groups)];
        $from = $last === null ? $node->getStartFilePos() : $last->getEndFilePos() + 1;
        $line = $last === null ? $node->getStartLine() : $last->getEndLine();
        $end = $node->getEndFilePos();
        if ($from < 0 || $line < 1 || $end < $from || $end >= strlen($source)) {
            return null;
        }

        foreach (PhpToken::tokenize('<?php '.substr($source, $from, $end - $from + 1)) as $index => $token) {
            if ($index === 0 || $token->is(self::LEADING)) {
                continue; // the open tag this prepends, or trivia and modifiers before the keyword
            }

            return $token->is([T_FUNCTION, T_FN]) ? $line + $token->line - 1 : null;
        }

        return null;
    }
}
