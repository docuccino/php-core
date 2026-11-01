<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Core\Tests\Fixtures\SamplePriority;
use Docuccino\Core\Tests\Fixtures\SampleShape;
use Docuccino\Core\Tests\Fixtures\SampleStatus;

it('gives one case the value values() lists for it', function (string $enum, string $case, string|int|null $value): void {
    expect(EnumReflection::valueOf($enum, $case))->toBe($value)
        // Asked again, the answer comes from the enum read once, and is the same.
        ->and(EnumReflection::valueOf($enum, $case))->toBe($value)
        ->and(EnumReflection::file($enum))->toBe(EnumReflection::file($enum));

    if ($value !== null) {
        // The same value, at the same position as the case's name.
        $position = array_search($case, EnumReflection::names($enum), true);
        expect(EnumReflection::values($enum)[$position])->toBe($value);
    }
})->with([
    'string-backed' => [SampleStatus::class, 'Published', 'published'],
    'int-backed' => [SamplePriority::class, 'High', 2],
    'pure, by name' => [SampleShape::class, 'Square', 'Square'],
    'a case the enum does not have' => [SampleStatus::class, 'Retired', null],
    'a name compared exactly' => [SampleStatus::class, 'published', null],
    'not an enum' => [stdClass::class, 'Draft', null],
]);
