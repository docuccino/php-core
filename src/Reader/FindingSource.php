<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

/**
 * Which specification a finding is held to: the OpenAPI meta-schema for the version the document
 * declares, the Docuccino extension's schema for the version its generator wrote, or — for a description
 * {@see ArazzoReader} read — the Arazzo specification for the version it declares.
 */
enum FindingSource: string
{
    case OpenApi = 'openapi';
    case Extension = 'extension';
    case Arazzo = 'arazzo';
}
