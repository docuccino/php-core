<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader\Arazzo;

/** What kind of description a source description is, as its `type` states. */
enum SourceType: string
{
    case OpenApi = 'openapi';
    case Arazzo = 'arazzo';
    case AsyncApi = 'asyncapi';
}
