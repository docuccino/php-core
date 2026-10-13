<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

/**
 * How much of Docuccino's extension a document carries, read off the document alone.
 *
 * - `full` — a root `x-docuccino` object: a full artifact, with whatever identities, provenance, pages
 *   and workflows it wrote;
 * - `identities` — no root extension, but a flat `x-docuccino-id` somewhere: an OpenAPI export written
 *   with its ids kept, so nodes pair by identity and carry nothing else;
 * - `plain` — neither: an OpenAPI document as any tool might write it.
 */
enum Capability: string
{
    case Plain = 'plain';
    case Identities = 'identities';
    case Full = 'full';
}
