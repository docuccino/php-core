<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

use Docuccino\Core\Extensions\Contracts\TagMapper;
use Docuccino\Core\Extensions\Schema\ConfigurationDigest;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;

/**
 * Fragment-cache soundness for a document's {@see TagMapper}: a configured mapper's answer lands inside
 * the operation fragment, and the config bag it was named in holds only a string — so the fragment is
 * keyed on the files that mapper answers FROM ({@see record()}) AND on what the resolved instance was
 * handed ({@see stateDigest()}), or an edit to either leaves the old tags warm.
 *
 * The resolved instance is what gets reflected, never the configured string: `tags.mapper` may name a
 * container binding rather than a class, and the class it hands back may be anonymous. Its own file is
 * only where the question was asked, so {@see DeclarationFiles} answers the rest.
 *
 * {@see record()} is called from wherever a tag actually went THROUGH the mapper, which is what keeps
 * the file half local: a route the mapper never answered for — a closure with no `#[Group]`, any route
 * under the `none` tag strategy — records nothing and stays warm across an edit to it.
 *
 * @internal
 */
final class TagMapperKeying
{
    /** Key `$dependencies` on the mapper that shaped a tag, or refuse the fragment the cache if it cannot be. */
    public static function record(RouteDependencies $dependencies, DocumentConfig $document): void
    {
        $mapper = $document->tagMapper;

        if ($mapper === null) {
            return;
        }

        $files = DeclarationFiles::keyableFor($mapper);

        if ($files === null) {
            $dependencies->refuseCaching();

            return;
        }

        $dependencies->addFiles($files);
    }

    /**
     * The mapper's identity and its own state, for the document-level part of every fragment key.
     *
     * A file says what the mapper's code is, never what this instance of it was constructed with — so a
     * mapper reading its prefix out of the application's config, the shape a container binding takes, is
     * a different mapper on every value with the same files behind it. That is a VALUE, and a dependency
     * manifest holds nothing but files: it is validated by re-hashing what it names, and there is no
     * file here to re-hash.
     *
     * So it goes in the key rather than the manifest, which makes it document-level — the mapper is
     * resolved once per document, and nothing before the lookup knows which routes it will answer for.
     * Over-keying is a rebuild; under-keying publishes the old prefix.
     *
     * The class is carried beside the digest because `tags.mapper` may name a container BINDING, whose
     * bound class can be swapped without a byte of config moving. An anonymous class names its file
     * here, and a closure held as a setting names where it was written — absolute paths both, which is
     * why this is a cache key and never an emitted byte.
     */
    public static function stateDigest(DocumentConfig $document): string
    {
        $mapper = $document->tagMapper;

        return $mapper === null ? '' : $mapper::class.'#'.ConfigurationDigest::of($mapper);
    }

    /**
     * The class of this document's tag mapper when nothing about it can be keyed, else null. What the
     * build reports, and the same question {@see record()} refuses on — asked once per document rather
     * than once per route, since one line saying so is the whole of what an author can act on.
     */
    public static function unhashableMapper(DocumentConfig $document): ?string
    {
        $mapper = $document->tagMapper;

        return $mapper !== null && DeclarationFiles::keyableFor($mapper) === null ? $mapper::class : null;
    }
}
