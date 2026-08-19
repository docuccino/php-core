<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use Docuccino\Attributes\Mock;

/**
 * A request source class whose fields are named by validation rules rather than by properties — a
 * FormRequest in every way that matters here — so the class-level `#[Mock]` form is the only one that
 * can reach one.
 */
#[Mock(faker: 'safeEmail', property: 'email')]
#[Mock(faker: 'numberBetween:1,100', seedGroup: 'listing', property: 'per_page')]
final class MockedRequestClass {}
