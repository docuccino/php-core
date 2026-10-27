<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

/**
 * The facts a document states about itself, read without validating anything.
 *
 * `format` is the id of the OpenAPI version it declares (`openapi-3.0`, `openapi-3.1`, `openapi-3.2`),
 * or null where it declares none this package carries a meta-schema for. `legacy` marks an artifact
 * written before UIR 2.0: a root `uir` or `$schema` member, or a generator spec version below 2.
 *
 * `uirVersion` is the UIR version a full document says it was written to, as the major.minor its schema
 * is published under (`2.1` for a `specVersion` of `2.1.0`): its generator's `specVersion`, or the root
 * `uir` an artifact written before UIR 2.0 carries. It is the version {@see ReadDocument::validate()}
 * picks the extension's schema by, and null where the document states none that reads as one.
 *
 * `info` is the title and version the document's `info` states ({@see DocumentInfo}), read with
 * everything else here when the document is, so nothing decodes it again to ask.
 */
final readonly class DocumentInspection
{
    /**
     * @param  list<ExtensionMember>  $extensions  in {@see ExtensionMember} declaration order
     */
    public function __construct(
        public Syntax $syntax,
        public ?string $openapi,
        public ?string $format,
        public Capability $capability,
        public array $extensions,
        public ?DocumentGenerator $generator,
        public bool $legacy,
        public ?string $uirVersion = null,
        public DocumentInfo $info = new DocumentInfo,
    ) {}

    public function carries(ExtensionMember $member): bool
    {
        return in_array($member, $this->extensions, true);
    }
}
