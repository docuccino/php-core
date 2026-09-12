<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use DateTimeInterface;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use JsonSerializable;
use ReflectionMethod;

/**
 * A date-time that is not an object on the wire, superseding {@see ClassTypeToSchema} by running
 * earlier: reflecting one publishes members no serializer sends, and a `@property`-documented date class
 * hoists a component of two hundred calendar fields for a value that is one string.
 *
 * `JsonSerializable` says a class states its own JSON form and never which one, so a form is PUBLISHED
 * only where its bytes have been read ({@see READ_JSON_FORM}). A class stating none encodes as PHP's own
 * `date`/`timezone_type`/`timezone` bag, so it is left to the class mapper; any other stated form, and
 * the interface any may stand behind, is widened — `format: date-time` over a class writing an epoch
 * integer costs a consumer a runtime failure where claiming nothing costs only type safety. A producer
 * that knows the wire format better pins it from `Priorities::FIRST`, since an EARLY tie breaks by FQCN
 * ascending and puts this mapper ahead of every adapter one.
 */
#[ExtensionOrder(priority: Priorities::EARLY)]
final class DateTimeTypeToSchema implements TypeToSchema
{
    /** The RFC 3339 string the forms below render, read off their bytes in test. */
    public const SCHEMA = ['type' => 'string', 'format' => 'date-time'];

    /**
     * The declarations whose bytes have been read — Carbon's, by string because core requires none of
     * them. Matched as the DECLARING class of `jsonSerialize()`, so an inheritor is covered and a
     * restater widened.
     *
     * @var list<string>
     */
    private const READ_JSON_FORM = [
        'Carbon\\Carbon',
        'Carbon\\CarbonImmutable',
        'Carbon\\CarbonInterface',
    ];

    public function supports(DType $type): bool
    {
        return $type instanceof ClassT
            && is_a($type->fqcn, DateTimeInterface::class, true)
            // An interface stands for every implementation at once, so its wire form is unknowable.
            && (interface_exists($type->fqcn) || is_a($type->fqcn, JsonSerializable::class, true));
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $type instanceof ClassT || ! $this->supports($type)) {
            return null;
        }

        return self::writesTheFormWeHaveRead($type->fqcn)
            ? new SchemaResult(self::SCHEMA, 0.9)
            : new SchemaResult([], 0.4);
    }

    /** Whether the class takes its JSON form, unrestated, from one of the declarations we have read. */
    private static function writesTheFormWeHaveRead(string $fqcn): bool
    {
        if (! is_a($fqcn, JsonSerializable::class, true)) {
            return false;
        }

        $declaring = (new ReflectionMethod($fqcn, 'jsonSerialize'))->getDeclaringClass()->getName();

        return in_array($declaring, self::READ_JSON_FORM, true);
    }
}
