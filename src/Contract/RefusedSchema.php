<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

use Docuccino\Core\Provenance\ClassNames;
use Docuccino\Core\Provenance\MessagePaths;
use Docuccino\Core\Provenance\RootRelativeSourcePathResolver;
use Docuccino\Core\Support\PlainText;
use Throwable;

/**
 * Why a validator would not take a schema, in its own words.
 *
 * The validator parses each schema as it reaches it, so a schema it will not parse — a `$ref` at a name
 * nothing defines, a keyword it cannot read — throws rather than answering. Both readers of a schema in
 * this package have to survive that, and both quote the reason, so the quoting lives once here.
 *
 * A thrown message is somebody else's text, so it is relativised before it goes anywhere: a message
 * naming the build machine would make one machine's report differ from another's. An exception with
 * nothing to say is named by its class instead, through {@see ClassNames} — an anonymous one names a
 * file too.
 *
 * @internal
 */
final class RefusedSchema
{
    public static function reason(Throwable $refused): string
    {
        $message = trim($refused->getMessage());

        $paths = new RootRelativeSourcePathResolver('');

        return $message === ''
            ? (new ClassNames($paths))->of($refused)
            : rtrim(PlainText::of((new MessagePaths($paths))->relative($message)), '.');
    }
}
