<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\TypeGrammar\DocBlockReader;
use Docuccino\Core\TypeGrammar\ImportContext;
use Docuccino\Core\TypeGrammar\TypeStringParser;
use ReflectionMethod;

/**
 * The type a method's docblock says it returns, read through core's one docblock reader and one type
 * grammar, with class names resolved against the imports of the file the method is written in — a
 * trait's, for a method a trait supplies. Null where no `@return` states one.
 */
final class DeclaredReturnType
{
    private static ?DocBlockReader $reader = null;

    private static ?TypeStringParser $parser = null;

    public static function of(ReflectionMethod $method): ?DType
    {
        $doc = $method->getDocComment();
        if ($doc === false) {
            return null;
        }

        $type = (self::$reader ??= new DocBlockReader)->returnType($doc);
        if ($type === null) {
            return null;
        }

        $file = $method->getFileName();

        return (self::$parser ??= new TypeStringParser)->parse($type, ImportContext::forFile($file === false ? null : $file));
    }
}
