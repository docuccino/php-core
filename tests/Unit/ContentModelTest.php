<?php

declare(strict_types=1);

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Document\Content\ContentExtension;
use Docuccino\Core\Document\Content\NavNode;
use Docuccino\Core\Document\Content\Page;
use Docuccino\Core\Document\DocumentExtension;
use Docuccino\Core\Validation\Validator;

/**
 * The content value objects (round-trip fidelity), the canonical member order for the content
 * layer, and schema validation of the nav tree.
 */
it('round-trips a page through fromArray/toArray', function (): void {
    $data = [
        'id' => 'page:v1:aaaaaaaaaaaaaaaa',
        'slug' => 'guides/intro',
        'title' => 'Intro',
        'summary' => 'A short summary.',
        'order' => 3,
        'tags' => ['a', 'b'],
        'content' => 'Body.',
        'provenance' => [['producer' => 'config', 'layer' => 'config', 'source' => ['file' => 'x.md']]],
    ];

    expect(Page::fromArray($data)->toArray())->toBe($data);
});

it('omits absent optional page members from toArray', function (): void {
    expect((new Page(id: 'page:v1:bbbbbbbbbbbbbbbb', slug: 's'))->toArray())
        ->toBe(['id' => 'page:v1:bbbbbbbbbbbbbbbb', 'slug' => 's']);
});

it('round-trips a nested nav tree through fromArray/toArray', function (): void {
    $data = [
        'type' => 'group',
        'title' => 'Reference',
        'children' => [
            ['type' => 'operation', 'ref' => 'op:v1:cccccccccccccccc', 'title' => 'List'],
            ['type' => 'tag', 'ref' => 'Forms'],
        ],
    ];

    expect(NavNode::fromArray($data)->toArray())->toBe($data);
});

it('round-trips the content extension and drops empty sections', function (): void {
    $extension = new ContentExtension(
        pages: [new Page(id: 'page:v1:dddddddddddddddd', slug: 'a')],
        nav: [new NavNode(type: 'page', ref: 'page:v1:dddddddddddddddd')],
    );

    $round = ContentExtension::fromArray($extension->toArray());
    expect($round->toArray())->toBe($extension->toArray());

    expect((new ContentExtension)->isEmpty())->toBeTrue()
        ->and((new ContentExtension)->toArray())->toBe([]);
});

it('flows the typed content extension through the document extension', function (): void {
    $extension = new DocumentExtension(content: new ContentExtension(pages: [new Page(id: 'page:v1:eeeeeeeeeeeeeeee', slug: 'a')]));

    $round = DocumentExtension::fromArray($extension->toArray());

    expect($round->content)->toBeInstanceOf(ContentExtension::class)
        ->and($round->content?->pages[0]->slug)->toBe('a')
        ->and($round->toArray())->toBe($extension->toArray());
});

it('canonicalises the content layer into fixed member order', function (): void {
    $scrambled = [
        'x-docuccino' => [
            'content' => [
                'nav' => [
                    ['children' => [['ref' => 'page:v1:1111111111111111', 'type' => 'page', 'title' => 'P']], 'title' => 'G', 'type' => 'group'],
                ],
                'pages' => [
                    ['provenance' => [], 'content' => 'b', 'tags' => ['t'], 'order' => 1, 'summary' => 's', 'title' => 'T', 'slug' => 'sl', 'id' => 'page:v1:ffffffffffffffff'],
                ],
            ],
        ],
    ];

    $canonical = (new Canonicalizer)->canonicalize($scrambled);

    expect(array_keys($canonical['x-docuccino']['content']))->toBe(['pages', 'nav']);
    expect(array_keys($canonical['x-docuccino']['content']['pages'][0]))->toBe(['id', 'slug', 'title', 'summary', 'order', 'tags', 'content', 'provenance']);
    // A group nav node orders its members and recurses into children.
    expect(array_keys($canonical['x-docuccino']['content']['nav'][0]))->toBe(['type', 'title', 'children']);
    expect(array_keys($canonical['x-docuccino']['content']['nav'][0]['children'][0]))->toBe(['type', 'ref', 'title']);
});

it('validates a content nav tree and rejects an unknown node type', function (): void {
    $validator = new Validator;

    $base = workedExample();
    $base['x-docuccino']['content'] = [
        'pages' => [['id' => 'page:v1:aaaaaaaaaaaaaaaa', 'slug' => 'intro', 'title' => 'Intro']],
        'nav' => [['type' => 'group', 'title' => 'G', 'children' => [['type' => 'page', 'ref' => 'page:v1:aaaaaaaaaaaaaaaa']]]],
    ];
    expect($validator->validate($base)->isValid())->toBeTrue();

    $bad = $base;
    $bad['x-docuccino']['content']['nav'][0]['type'] = 'widget';
    expect($validator->validate($bad)->isValid())->toBeFalse();
});
