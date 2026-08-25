<?php

declare(strict_types=1);

use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\Examples\ExampleAudit;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Patch\Layer;
use Docuccino\Core\Patch\PatchGuard;
use Docuccino\Core\Patch\PatchResult;
use Docuccino\Core\Support\Json;
use Docuccino\Core\Support\JsonValue;

/**
 * `{}` as a VALUE. A draft assembled as PHP arrays has no other way to say "an object with nothing in
 * it" than a `stdClass`, and `===` on one is instance identity — so every reader that asks "did two
 * producers write the same thing?" has to ask by value, or an equal `{}` beside another reads as a
 * disagreement.
 *
 * The alternative was one shared instance, answering `===` directly. It is not available: a `stdClass`
 * subclass cannot be made immutable in PHP, so a shared `{}` is a shared MUTABLE object handed to
 * `DocumentTransformer`, which is third-party code. The write-through paths are pinned below, because
 * they are the reason the design is what it is.
 */
it('is a stdClass, so every reader that matches on one keeps matching', function (): void {
    // The reason a value type of its own was never on the table: every site that classifies a draft
    // value does it with `instanceof stdClass`.
    expect(JsonValue::decode('{}'))->toBeInstanceOf(stdClass::class)
        ->and((array) JsonValue::decode('{}'))->toBe([])
        ->and(JsonValue::decode('{"0":"a","1":"b"}'))->toBeInstanceOf(stdClass::class);
});

it('writes as an empty object through every writer that emits one', function (): void {
    $empty = JsonValue::decode('{}');

    expect(json_encode($empty))->toBe('{}')
        ->and((new CanonicalJsonSerializer)->serialize($empty))->toBe("{}\n")
        ->and((new CanonicalJsonSerializer)->serialize(['example' => $empty]))
        ->toBe("{\n  \"example\": {}\n}\n")
        // The fingerprinter too, and — the half that matters — differently from an empty list, or a
        // component deduped by content would merge `{}` with `[]`.
        ->and(Json::stable($empty))->toBe('{}')
        ->and(Json::stable($empty))->not->toBe(Json::stable([]));
});

it('is minted fresh, so no writer can reach another position through it', function (): void {
    // Why sharing one instance is not on offer: `__set` catches a direct assignment and NOTHING else.
    // PHP resolves a by-reference property acquisition through `get_property_ptr_ptr`, which creates
    // the property on a stdClass subclass without consulting `__set` at all — so a shared `{}` would
    // carry any of these into every `{}` in the document, and on through `FragmentCache` to disk.
    $writes = [
        'by-ref acquisition' => static function (object $o): void {
            /** @phpstan-ignore-next-line property.notFound (that it succeeds is the point) */
            $r = &$o->x;
            $r = 1;
        },
        'array append' => static function (object $o): void {
            /** @phpstan-ignore-next-line property.notFound */
            $o->list[] = 1;
        },
        'preg_match out-param' => static function (object $o): void {
            /** @phpstan-ignore-next-line property.notFound */
            preg_match('/a/', 'a', $o->m);
        },
        'parse_str out-param' => static function (object $o): void {
            /** @phpstan-ignore-next-line property.notFound */
            parse_str('a=1', $o->p);
        },
    ];

    foreach ($writes as $label => $write) {
        $written = JsonValue::decode('{}');
        $write($written);

        expect(get_object_vars($written))->not->toBe([], $label)
            // …and the one that matters: the write reached that object and no other.
            ->and(get_object_vars(JsonValue::decode('{}')))->toBe([], $label);
    }
});

it('stops the patch guard recording a shadow nobody lost', function (string $json): void {
    // Two producers writing the same object agree, and an `overrode` trail is for values that were
    // actually displaced. Under `!==` each fresh instance said they differed, and `--provenance=full`
    // grew an entry claiming a producer lost an example to an identical one. Decoded twice on purpose:
    // one decode reused would be `===` and would prove nothing.
    $guard = new PatchGuard;

    expect($guard->apply('example', JsonValue::decode($json), new Contribution(Layer::Attribute, 'a')))
        ->toBe(PatchResult::Accepted)
        ->and($guard->apply('example', JsonValue::decode($json), new Contribution(Layer::Inference, 'b')))
        ->toBe(PatchResult::Shadowed)
        ->and($guard->provenance()->records)->toHaveCount(1)
        ->and($guard->provenance()->records[0]->overrode)->toBe([]);
})->with([
    'empty object' => ['{}'],
    // The half a shared empty instance never covered: an index-keyed object is a stdClass too, and
    // there is no interning it — every distinct one would need its own singleton.
    'index-keyed object' => ['{"0":"a","1":"b"}'],
    'object nested in an array' => ['{"meta":{},"tags":[]}'],
    'object nested in a list' => ['{"rows":[{},{"0":"a","1":"b"}]}'],
]);

it('still records a shadow that really did lose something', function (): void {
    // The negative path: comparing by value must not collapse two values that differ, and `{}` against
    // `[]` is the pair a looser comparison loses first.
    $guard = new PatchGuard;

    $guard->apply('example', JsonValue::decode('{}'), new Contribution(Layer::Attribute, 'a'));
    $guard->apply('example', [], new Contribution(Layer::Inference, 'b'));

    expect($guard->provenance()->records[0]->overrode)->toHaveCount(1)
        ->and($guard->provenance()->records[0]->overrode[0]->value)->toBe([]);
});

it('keeps an integer-valued float apart from the int', function (): void {
    // `==` would call these one value. Nothing in the comparison may relax that: the emitted bytes
    // differ, and warm-versus-cold reads the document in this shape precisely to see it.
    expect(JsonValue::same(1.0, 1))->toBeFalse()
        ->and(JsonValue::same(['n' => 1.0], ['n' => 1]))->toBeFalse()
        ->and(JsonValue::same((object) ['n' => 1.0], (object) ['n' => 1]))->toBeFalse()
        // Which is why the fingerprinter is not the comparator: JSON has no spelling for the float.
        ->and(Json::stable(['n' => 1.0]))->toBe(Json::stable(['n' => 1]))
        ->and(JsonValue::same(JsonValue::decode('{}'), []))->toBeFalse()
        ->and(JsonValue::same(JsonValue::decode('{}'), JsonValue::decode('{}')))->toBeTrue();
});

it('satisfies type: object where an empty array does not', function (): void {
    // Why an array could never have been rescued downstream: this is the validator the example audit
    // runs, on the two values a PHP array cannot tell apart.
    $document = static fn (mixed $example): array => [
        'openapi' => '3.2.0',
        'info' => ['title' => 'T', 'version' => '1'],
        'paths' => [],
        'components' => ['schemas' => ['Free' => ['type' => 'object', 'example' => $example]]],
    ];

    $audit = static fn (mixed $example): int => count(
        (new ExampleAudit(ContractIndex::fromArray($document($example))))->run()->findings,
    );

    expect($audit(JsonValue::decode('{}')))->toBe(0)
        ->and($audit([]))->toBe(1);
});
