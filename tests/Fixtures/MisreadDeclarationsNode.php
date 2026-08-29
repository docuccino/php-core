<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use Docuccino\Attributes\Abilities;
use Docuccino\Attributes\CookieParameter;
use Docuccino\Attributes\DeprecatedOperation;
use Docuccino\Attributes\Description;
use Docuccino\Attributes\ErrorComponent;
use Docuccino\Attributes\ExcludeFromDocs;
use Docuccino\Attributes\Group;
use Docuccino\Attributes\HeaderParameter;
use Docuccino\Attributes\IgnoreParam;
use Docuccino\Attributes\IgnoreResponse;
use Docuccino\Attributes\InDocs;
use Docuccino\Attributes\Internal;
use Docuccino\Attributes\OptionallyAuthenticated;
use Docuccino\Attributes\PathParameter;
use Docuccino\Attributes\QueryParameter;
use Docuccino\Attributes\Response;
use Docuccino\Attributes\ResponseHeader;
use Docuccino\Attributes\RuleSchema;
use Docuccino\Attributes\Security;
use Docuccino\Attributes\Summary;
use Docuccino\Attributes\Unauthenticated;
use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\AppliesTo;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Docuccino\Attributes\Webhook;

/**
 * Every class-target attribute a TYPE is NOT read for, on one class — the whole
 * `SchemaClassAttributes::ELSEWHERE` table exercised through the real reflection path, plus one
 * honoured `#[Description]` to prove the honoured half is not swept up with it.
 *
 * Nothing here is instantiated, which is why the arguments are absent: `SchemaClassAttributes` reads
 * NAMES, and a published diagnostic must not depend on a constructor running.
 */
#[Description(text: 'A node whose author put an operation\'s declarations on the type.')]
#[Abilities]
#[ApiVersionChange]
#[AppliesTo]
#[CookieParameter]
#[DeprecatedOperation]
#[ErrorComponent]
#[ExcludeFromDocs]
#[Group]
#[HeaderParameter]
#[IgnoreParam]
#[IgnoreResponse]
#[InDocs]
#[Internal]
#[OptionallyAuthenticated]
#[PathParameter]
#[QueryParameter]
#[QueryParameter]
#[RenamedResponseField]
#[Response]
#[ResponseHeader]
#[RuleSchema]
#[Security]
#[Summary]
#[Unauthenticated]
#[Webhook]
final class MisreadDeclarationsNode
{
    public string $name = '';
}
