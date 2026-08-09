<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;

it('round-trips the worked example, preserving all members', function (): void {
    $input = workedExample();

    $output = UirDocument::fromArray($input)->toArray();

    expect($output)->toEqual($input);
});

it('preserves unknown x-* members byte-for-byte through the model', function (): void {
    $input = [
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'x-vendor' => ['nested' => ['deep' => [1, 2, 3]], 'flag' => true],
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => [
            '/things' => [
                'x-path-meta' => ['owner' => 'team-a'],
                'get' => [
                    'x-readme' => ['explorer-enabled' => false, 'samples-languages' => ['php', 'curl']],
                    'operationId' => 'things.index',
                    'responses' => [
                        '200' => [
                            'description' => 'ok',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'x-custom' => ['weird' => ['z' => 1, 'a' => 2]],
                                        'properties' => ['id' => ['type' => 'integer', 'x-faker' => 'randomNumber']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $output = UirDocument::fromArray($input)->toArray();

    expect($output)->toEqual($input);

    expect($output['x-vendor'])->toBe($input['x-vendor']);
    expect($output['paths']['/things']['x-path-meta'])->toBe($input['paths']['/things']['x-path-meta']);
    expect($output['paths']['/things']['get']['x-readme'])->toBe($input['paths']['/things']['get']['x-readme']);

    $schema = $output['paths']['/things']['get']['responses']['200']['content']['application/json']['schema'];
    expect($schema['x-custom'])->toBe(['weird' => ['z' => 1, 'a' => 2]]);
    expect($schema['properties']['id']['x-faker'])->toBe('randomNumber');
});
