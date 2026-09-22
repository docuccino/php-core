<?php

declare(strict_types=1);

namespace Docuccino\Core\Spec;

/**
 * Which UIR version this build writes, stated once.
 *
 * It was six sites before, and three of them had already drifted — including the adapter's
 * fragment-cache `specVersion`, which is what makes a spec change retire the fragments built under the
 * old one. A stale copy there is under-keying, which is a correctness bug rather than a cost.
 *
 * The `$id` carries major.minor and a document's `uir` member carries the patch too, so the URL is
 * derived from the version rather than written beside it.
 */
final class UirSpec
{
    /** The precise format version an emitted document declares. */
    public const string VERSION = '1.1.0';

    /** The major.minor the schema is published and bundled under. */
    public static function minor(): string
    {
        [$major, $minor] = explode('.', self::VERSION);

        return $major.'.'.$minor;
    }

    /** The `$schema` an emitted document declares, which is also where the schema is served. */
    public static function schemaUrl(): string
    {
        return 'https://spec.docuccino.app/uir/'.self::minor().'/schema.json';
    }
}
