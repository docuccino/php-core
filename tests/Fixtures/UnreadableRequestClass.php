<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use Docuccino\Attributes\BodyParameter;

/**
 * A request source class carrying a declaration PHP cannot construct — an `int` where the attribute
 * takes a `string` — beside a healthy one. The malformed half says nothing and the healthy half is
 * still read. Only ever reflected.
 */
/* @phpstan-ignore-next-line argument.type — the wrong argument type IS the fixture */
#[BodyParameter(name: 123)]
#[BodyParameter(name: 'note', type: 'string')]
final class UnreadableRequestClass {}
