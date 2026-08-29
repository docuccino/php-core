<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use Docuccino\Attributes\BodyParameter;

/**
 * A request source class whose own declaration carries the value a consumer should send for the field —
 * the one part of a declaration that is a sample rather than a constraint. Only ever reflected.
 */
#[BodyParameter(name: 'nickname', type: 'string', example: 'Ada')]
final class ExampledRequestClass {}
