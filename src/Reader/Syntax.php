<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

/** The serialisation a document was read from. */
enum Syntax: string
{
    case Json = 'json';
    case Yaml = 'yaml';
}
