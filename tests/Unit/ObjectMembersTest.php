<?php

declare(strict_types=1);

use Docuccino\Core\SpecValidation\ObjectMembers;

it('reads an object\'s members, and none off anything that is not one', function (mixed $value, array $members, array $names): void {
    expect(ObjectMembers::of($value))->toEqual($members)
        ->and(array_keys(ObjectMembers::of($value)))->toBe(array_keys($members))
        ->and(ObjectMembers::names($value))->toBe($names);
})->with([
    'an object' => [(object) ['get' => 1, 'x-a' => null], ['get' => 1, 'x-a' => null], ['get', 'x-a']],
    // A `responses` map is keyed by status code: PHP hands the key back as an int, and the name is the
    // string the document wrote.
    'numeric member names' => [json_decode('{"200": {}, "default": {}}'), [200 => new stdClass, 'default' => new stdClass], ['200', 'default']],
    'an empty object' => [new stdClass, [], []],
    'a list' => [[1, 2], [], []],
    'an associative array' => [['a' => 1], [], []],
    'a scalar' => ['a', [], []],
    'null' => [null, [], []],
]);
