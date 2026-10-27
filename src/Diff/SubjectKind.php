<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

/** What a {@see ChangeSubject} is. */
enum SubjectKind: string
{
    case Operation = 'operation';
    case PathItem = 'pathItem';
    case Schema = 'schema';
    case SecurityScheme = 'securityScheme';
    case Page = 'page';
}
