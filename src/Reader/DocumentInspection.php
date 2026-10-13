<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

/**
 * The facts a document states about itself, read without validating anything.
 *
 * `format` is the id of the OpenAPI version it declares (`openapi-3.0`, `openapi-3.1`, `openapi-3.2`),
 * or null where it declares none this package carries a meta-schema for. `legacy` marks an artifact
 * written before UIR 2.0: a root `uir` or `$schema` member, or a generator spec version below 2.
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
    ) {}

    public function carries(ExtensionMember $member): bool
    {
        return in_array($member, $this->extensions, true);
    }
}
