<?php

declare(strict_types=1);

use Docuccino\Core\Support\ConfiguredFlag;

/**
 * The one reading of a configured on/off switch.
 *
 * The rule, stated here rather than read back off the code: a switch is `true` or `false`. A key
 * holding either answers itself. A key nobody wrote answers the caller's default, silently. A key
 * holding ANYTHING ELSE answers the caller's default and is reported — never coerced, because
 * `(bool) 'no'` is `true` and coercion therefore reads a switch its author turned off as turned on.
 *
 * `null` counts as written: it is what a key with nothing after the colon holds, so it is a refusal
 * and not an absence.
 */
it('reads only true and false as switches, and refuses everything else', function (mixed $value, ?bool $answer): void {
    // Both defaults, because a refusal's answer IS the default and a single default would hide that.
    foreach ([true, false] as $default) {
        $flag = ConfiguredFlag::read(['s' => $value], 's', $default);

        expect($flag->on)->toBe($answer ?? $default)
            ->and($flag->refused())->toBe($answer === null);

        if ($answer === null) {
            expect($flag->refusal('a.b.s'))
                ->toContain('a.b.s')
                ->toContain(get_debug_type($value))
                ->toContain($default ? 'read as true' : 'read as false');
        } else {
            expect($flag->refusal('a.b.s'))->toBeNull();
        }
    }
})->with([
    // The two that are switches.
    'true' => [true, true],
    'false' => [false, false],
    // The words a YAML author reaches for, every one of which a YAML parser hands back as a string.
    "'no'" => ['no', null],
    "'off'" => ['off', null],
    "'yes'" => ['yes', null],
    "'on'" => ['on', null],
    // The shapes a PHP author or an env() reaches for.
    "'1'" => ['1', null],
    "'0'" => ['0', null],
    '1' => [1, null],
    '0' => [0, null],
    "''" => ['', null],
    'null' => [null, null],
    '[]' => [[], null],
]);

it('reads an absent key as the default, silently', function (): void {
    expect(ConfiguredFlag::read([], 's', true)->on)->toBeTrue()
        ->and(ConfiguredFlag::read([], 's', false)->on)->toBeFalse()
        ->and(ConfiguredFlag::read([], 's', true)->refused())->toBeFalse()
        ->and(ConfiguredFlag::read([], 's', true)->refusal('a.b.s'))->toBeNull();
});

/**
 * A key holding `null` must stay distinguishable from one nobody wrote: a key an author typed and left
 * empty is a mistake worth naming, and the same config surface already tells the two apart for
 * `error_responses`. Same answer, different report — which is the whole distinction.
 */
it('tells a key holding null from a key nobody wrote', function (): void {
    $written = ConfiguredFlag::read(['s' => null], 's', true);
    $absent = ConfiguredFlag::read([], 's', true);

    expect($written->on)->toBe($absent->on)
        ->and($written->refused())->toBeTrue()
        ->and($absent->refused())->toBeFalse()
        ->and($written->refusal('cache.enabled'))->toContain('null');
});

/** The sentence names the key, what it holds, and what was read instead. */
it('names the key, the type it holds and the value used instead', function (): void {
    expect(ConfiguredFlag::read(['enabled' => 'no'], 'enabled', false)->refusal('lint.descriptions.enabled'))
        ->toBe('lint.descriptions.enabled is string rather than true or false, so it names no switch — it is read as false, its default.');

    expect(ConfiguredFlag::read(['enabled' => ['no']], 'enabled', true)->refusal('cache.enabled'))
        ->toBe('cache.enabled is array rather than true or false, so it names no switch — it is read as true, its default.');
});

/**
 * The refusal is the point, so it is executed rather than asserted: this is the value that would come
 * back from a cast, next to what the reading actually answers.
 */
it('never answers what a cast would answer for a switch spelled no', function (): void {
    expect((bool) 'no')->toBeTrue()
        ->and(ConfiguredFlag::read(['enabled' => 'no'], 'enabled', false)->on)->toBeFalse();
});
