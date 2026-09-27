<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\TypeGrammar\DocBlockReader;
use Docuccino\Core\TypeGrammar\ImportContext;
use Docuccino\Core\TypeGrammar\TypeStringParser;
use ReflectionClass;

/**
 * The subtypes a `@phpstan-sealed` (or `@psalm-inheritors`) tag closes an interface or abstract class
 * over. That is a closed set its author declared and the analyser enforces, so a value typed by the
 * parent is exactly one of them — which no scan for implementors could claim, since nothing stops the
 * next class from joining an open hierarchy.
 *
 * @internal
 */
final readonly class SealedHierarchy
{
    /**
     * @param  list<ClassT|EnumT>  $members  the permitted subtypes, as the tag orders them
     * @param  list<string>  $unreadable  what the tag names that is not a subtype of the parent
     */
    private function __construct(
        public array $members,
        public array $unreadable,
    ) {}

    /** The hierarchy `$fqcn` seals, or null when it is not an interface or abstract class carrying the tag. */
    public static function of(string $fqcn): ?self
    {
        if (! class_exists($fqcn) && ! interface_exists($fqcn)) {
            return null;
        }

        $class = new ReflectionClass($fqcn);
        if (! $class->isInterface() && ! ($class->isAbstract() && ! $class->isTrait())) {
            // A concrete class is one of its own values, which the tag does not list.
            return null;
        }

        $doc = $class->getDocComment();
        $written = (new DocBlockReader)->sealed($doc === false ? null : $doc);
        if ($written === null) {
            return null;
        }

        $file = $class->getFileName();
        $type = (new TypeStringParser)->parse($written, ImportContext::forFile($file === false ? null : $file));

        $members = [];
        $unreadable = [];
        foreach ($type instanceof UnionT ? $type->members : [$type] as $member) {
            if (self::permits($fqcn, $member)) {
                /** @var ClassT|EnumT $member */
                $members[] = $member;
            } else {
                $unreadable[] = $member instanceof ClassT || $member instanceof EnumT ? $member->fqcn : $written;
            }
        }

        return new self($members, array_values(array_unique($unreadable)));
    }

    private static function permits(string $parent, DType $member): bool
    {
        return ($member instanceof ClassT || $member instanceof EnumT) && is_subclass_of($member->fqcn, $parent);
    }
}
