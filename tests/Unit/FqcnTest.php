<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Schema\ComponentNames;
use Docuccino\Core\Support\Fqcn;

/**
 * The one "short class name" helper. Component names, tag names and operationIds are all derived
 * through it, so what it keeps of a name is what reaches the document.
 */
it('keeps the last namespace segment of a name', function (string $fqcn, string $expected): void {
    expect(Fqcn::short($fqcn))->toBe($expected);
})->with([
    'namespaced' => ['App\\Http\\Resources\\GadgetResource', 'GadgetResource'],
    'unqualified' => ['GadgetResource', 'GadgetResource'],
    'a leading separator' => ['\\GadgetResource', 'GadgetResource'],
    'one segment' => ['App\\Gadget', 'Gadget'],
    'empty' => ['', ''],
    // `::class` on an anonymous class continues past `@anonymous` with a NUL byte, the ABSOLUTE file it
    // was written in and a counter of the anonymous classes the PROCESS declared first. None of that is
    // a namespace separator, so shortening alone carried the whole tail into whatever this named.
    'an anonymous class' => ["class@anonymous\0/home/alice/checkout/app/Http/Inline.php:9\$1f", 'class@anonymous'],
    'an anonymous subclass' => ["App\\Models\\Gadget@anonymous\0/home/alice/checkout/app/Models/Inline.php:4\$0", 'Gadget@anonymous'],
]);

it('names an anonymous class without the machine or the order it was met', function (): void {
    // The real runtime spelling, not a written-out one: the counter is whatever this process had reached.
    $first = new class {};
    $second = new class {};

    foreach ([$first::class, $second::class] as $name) {
        $short = Fqcn::short($name);

        expect($short)->toBe('class@anonymous')
            // No NUL, no absolute prefix, and no process-order counter — and nothing survives sanitizing
            // into a component key either, which is what a `$ref` and a generated client's type read.
            ->and($short)->not->toContain("\0")
            ->and($short)->not->toContain(dirname(__DIR__, 3))
            ->and(ComponentNames::sanitize($short))->toBe('classanonymous');
    }
});
