<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use Docuccino\Attributes\Example;
use Docuccino\Attributes\Hidden;
use Docuccino\Core\Extensions\BuiltIn\ClassTypeToSchema;

/**
 * A plain PHP DTO documenting its properties the way a docblock can — one `@example` per JSON type an
 * author writes, one that has no reading at all, one contested by an attribute, and one on a member the
 * schema hides — so the fallback {@see ClassTypeToSchema} is the only mapper that can read them.
 *
 * The tags here are what the real engine reads off these docblocks; a stub engine mirrors them as the
 * strings the docblock reader hands over, which is the whole point — text is all a tag can hold. Only
 * ever reflected.
 */
final readonly class DocumentedNode
{
    public function __construct(
        /**
         * Who owns the invoice.
         *
         * @example acme-corp
         */
        public string $tenant,
        /** @example false */
        public bool $settled,
        /** @example 7 */
        public int $seats,
        /** @example ["listing.view", "listing.create"] */
        public array $permissions,
        /**
         * The one literal with no reading: `n/a` is not an integer, and publishing it would hand a
         * consumer an example their own API rejects.
         *
         * @example n/a
         */
        public int $renewals,
        /**
         * Contested on one property: the docblock is precedence 30 and the attribute 40, so the
         * attribute is what a consumer sees.
         *
         * @example from-the-docblock
         */
        #[Example(value: 'from-the-attribute')]
        public string $pinned,
        /**
         * Never published, so the tag is never read and nothing is reported — there is no member to
         * carry it and nothing the author could do about it.
         *
         * @example never-read
         */
        #[Hidden]
        public string $hushed,
    ) {}
}
