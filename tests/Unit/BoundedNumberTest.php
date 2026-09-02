<?php

declare(strict_types=1);

use Docuccino\Core\Support\BoundedNumber;
use Opis\JsonSchema\Validator as OpisValidator;

/**
 * The one ladder from a set of numeric bounds to a value they admit, which three producers of a
 * representative value read: the validated field's synthesized example, the collection exporter's request
 * body, and the fill an error example puts where a member went unread.
 *
 * Every row states its answer from the KEYWORDS' own meaning, and then hands that answer to a JSON Schema
 * validator which knows the keywords and nothing about this ladder ({@see boundedNumberRejection()}). That
 * second assertion is the one that cannot agree with a wrong implementation: an expected value written
 * beside the code that produces it ratifies whatever the code does, and the whole reason this class exists
 * is that a value contradicting the schema beside it reached a published document twice.
 *
 * `null` is the answer where the bounds admit NO number, and it is never "the value is null" — so those
 * rows assert the opposite way round: every value the ladder could have reached is put to the validator
 * and refused.
 */
function boundedNumberRejection(array $keywords, mixed $value, bool $integer): ?string
{
    $schema = json_decode((string) json_encode(['type' => $integer ? 'integer' : 'number'] + $keywords));

    if (! is_object($schema)) {
        return 'the keywords could not be expressed as a schema';
    }

    $result = (new OpisValidator)->validate($value, $schema);

    return $result->isValid() ? null : ($result->error()?->keyword() ?? 'refused');
}

it('answers the nearest value the bounds admit, and one they accept', function (array $keywords, int|float $seed, bool $integer, string $expected): void {
    $answer = BoundedNumber::nearest($keywords, $seed, $integer);

    expect(json_encode($answer === null ? null : $answer[0]))->toBe($expected)
        ->and($answer === null ? null : boundedNumberRejection($keywords, $answer[0], $integer))->toBeNull();
})->with([
    // Nothing stated: the seed is the answer, which is what makes the seed the caller's decision rather
    // than this table's. `type: integer` already tells a reader everything the value would.
    'no bound at all, integer' => [[], 0, true, '0'],
    'no bound at all, number' => [[], 1, false, '1'],

    // A floor names a legal value — the whole reason a bound is read where a `pattern` is not.
    'a floor above the seed' => [['minimum' => 18], 0, true, '18'],
    'a floor the seed already clears' => [['minimum' => -3], 0, true, '0'],
    // An integer's inclusive bounds round INWARD, so a fractional one names the integer it really
    // admits: 0.5 is not an integer, and the smallest integer at or above it is 1.
    'a fractional floor on an integer' => [['minimum' => 0.5], 0, true, '1'],
    // The same floor on a `number`, which admits it exactly.
    'a fractional floor on a number' => [['minimum' => 0.5], 0, false, '0.5'],

    // A ceiling below the seed is satisfied by itself: the same rule read downward.
    'a ceiling below the seed' => [['maximum' => -5], 0, true, '-5'],
    'a ceiling the seed already clears' => [['maximum' => 12], 0, true, '0'],
    'a fractional ceiling on an integer' => [['maximum' => -0.5], 0, true, '-1'],

    // An exclusive bound names the nearest value it ADMITS, and that is where the two types part.
    'an exclusive floor on an integer' => [['exclusiveMinimum' => 0], 0, true, '1'],
    'a fractional exclusive floor on an integer' => [['exclusiveMinimum' => 5.5], 0, true, '6'],
    // On a `number` the tightest answer is 0 plus an epsilon, which no deterministic table can name — so
    // one whole unit up, legal and the same bytes on every machine.
    'an exclusive floor on a number' => [['exclusiveMinimum' => 0], 0, false, '1'],
    'an exclusive ceiling on an integer' => [['exclusiveMaximum' => 0], 0, true, '-1'],
    'an exclusive ceiling on a number' => [['exclusiveMaximum' => 0], 0, false, '-1'],

    // A whole unit is not always available, and an open range is INHABITED whether or not it is: a
    // probability is any value strictly between 0 and 1, and 0.5 is one. So the step off an exclusive
    // bound is half the room to the opposite bound wherever a whole unit would leave the range — still
    // arithmetic on the keywords, so still the same bytes everywhere, where an epsilon would be neither.
    'an open unit interval on a number' => [['exclusiveMinimum' => 0, 'exclusiveMaximum' => 1], 0, false, '0.5'],
    'an open range narrower than a unit' => [['exclusiveMinimum' => 0, 'maximum' => 0.5], 0, false, '0.25'],
    'exclusive bounds a unit apart on a number' => [['exclusiveMinimum' => 3, 'exclusiveMaximum' => 4], 0, false, '3.5'],
    // The whole unit is kept wherever there is room for it, because it is the answer nearer the seed and
    // the one a reader recognises: half of a range up to 1000000 illustrates nothing about the bound.
    'a whole unit the room allows' => [['exclusiveMinimum' => 0, 'maximum' => 1], 0, false, '1'],
    // The other half of the same fact: an inclusive floor is admitted BY ITSELF, so a ceiling less than a
    // unit above it takes nothing away — reading the floor as "floor plus one" is what made this empty.
    'an inclusive floor under an exclusive ceiling' => [['minimum' => 4.5, 'exclusiveMaximum' => 5], 0, false, '4.5'],

    // Both spellings of a floor at once: the conjunction is the HIGHER of the two, whichever keyword
    // states it — a schema may carry both, and honouring only one publishes a value the other rejects.
    'an inclusive floor above an exclusive one' => [['minimum' => 5, 'exclusiveMinimum' => 0], 0, true, '5'],
    'an exclusive floor above an inclusive one' => [['minimum' => 0, 'exclusiveMinimum' => 4], 0, true, '5'],
    'an inclusive ceiling below an exclusive one' => [['maximum' => -9, 'exclusiveMaximum' => 0], 0, true, '-9'],
    'an exclusive ceiling below an inclusive one' => [['maximum' => 0, 'exclusiveMaximum' => -4], 0, true, '-5'],

    // A step is applied LAST and then re-checked, because it is the one keyword that can move a value
    // back out of the range the bounds put it in.
    'a step above a floor' => [['minimum' => 1, 'multipleOf' => 5], 0, true, '5'],
    'a step the seed already satisfies' => [['multipleOf' => 5], 0, true, '0'],
    // The step is taken AWAY from whichever bound the value was pushed against, which on a range below
    // the seed is downward: -6 is the multiple of 3 this range holds, and rounding toward +INF unmoored
    // from the pressure reaches -3, which the ceiling rejects.
    'a step below a ceiling' => [['minimum' => -10, 'maximum' => -5, 'multipleOf' => 3], 0, true, '-6'],
    // A step whose multiples ARE exactly representable, which is the one the corpus states: a currency
    // amount in hundredths, illustrated by the neutral value it already satisfies.
    'a fractional step a whole value satisfies' => [['multipleOf' => 0.01], 0, false, '0'],

    // A whole float publishes as an integer literal, which `type: number` accepts just the same and is
    // the shortest true form — so the bytes do not turn on which keyword the value came out of.
    'a whole float floor on a number' => [['minimum' => 7.0], 0, false, '7'],

    // Nothing validates, so nothing is published: bounds that cross, a step with no multiple between
    // them, a step whose multiples miss the integers, and a fractional step with no exactly
    // representable multiple at all — 0.1 three times over is 0.30000000000000004 in every IEEE double,
    // and `multipleOf` is checked by exact division.
    'bounds that cross' => [['minimum' => 5, 'maximum' => 3], 0, true, 'null'],
    'exclusive bounds that meet' => [['exclusiveMinimum' => 3, 'exclusiveMaximum' => 4], 0, true, 'null'],
    // The same emptiness on a `number`, where it takes bounds that COINCIDE rather than merely meet: an
    // open interval with the same value at both ends holds nothing, whatever step is taken off it.
    'exclusive bounds that coincide on a number' => [['exclusiveMinimum' => 1, 'exclusiveMaximum' => 1], 0, false, 'null'],
    'a step with no multiple between the bounds' => [['minimum' => 1, 'maximum' => 4, 'multipleOf' => 5], 0, true, 'null'],
    'a step that misses the integers' => [['minimum' => 1, 'multipleOf' => 0.3], 0, true, 'null'],
    'a fractional step with no representable multiple' => [['minimum' => 0.3, 'multipleOf' => 0.1], 0, false, 'null'],

    // A bound PHP can hold and an `int` cannot. The integers above 1e308 exist and one of them satisfies
    // this schema, but none of them is an `int`, and truncating to `PHP_INT_MAX` would publish a value
    // the floor rejects. `type: number` carries the same bound as a double and answers with it.
    'a floor past PHP_INT_MAX on an integer' => [['minimum' => 1e308], 0, true, 'null'],
    'a floor past PHP_INT_MAX on a number' => [['minimum' => 1e308], 0, false, '1.0e+308'],
    // Two FINITE bounds whose step overflows between them: 2 × 9e307 is INF, which is not a number JSON
    // can carry at all — and the answer below it, 9e307, is under the floor. So the whole answer is
    // re-checked for finiteness rather than only its inputs.
    'a step whose next multiple overflows' => [['minimum' => 1e308, 'multipleOf' => 9e307], 0, false, 'null'],
]);

it('reads a bound value no schema could carry as a bound not stated', function (array $keywords, array $absent, string $expected): void {
    // The degradation contract, and it is stated as an EQUIVALENCE rather than against the oracle above:
    // none of these keyword values makes a valid 2020-12 schema, so a validator has no verdict to give
    // about them. What is owed instead is that the ladder answers exactly as it would have with the
    // keyword absent — nothing read, and nothing invented either.
    //
    // The one that matters in practice is draft-04's BOOLEAN `exclusiveMinimum`, a flag modifying
    // `minimum` rather than a bound of its own: read as a bound it would put the answer at 1 under a
    // schema that admits 0. A step of zero divides nothing and a negative one has no "next multiple up",
    // so both would be a division by zero or an answer below the floor.
    expect(json_encode(BoundedNumber::nearest($keywords, 0, true)))
        ->toBe(json_encode(BoundedNumber::nearest($absent, 0, true)))
        ->and(json_encode(BoundedNumber::nearest($absent, 0, true)[0] ?? null))->toBe($expected);
})->with([
    'a draft-04 exclusive flag' => [['minimum' => 0, 'exclusiveMinimum' => true], ['minimum' => 0], '0'],
    'a bound stated as text' => [['minimum' => '18'], [], '0'],
    'a bound stated as null' => [['minimum' => null], [], '0'],
    'a step of zero' => [['minimum' => 3, 'multipleOf' => 0], ['minimum' => 3], '3'],
    'a negative step' => [['minimum' => 3, 'multipleOf' => -5], ['minimum' => 3], '3'],
]);

it('publishes nothing for a bound no double could hold', function (array $keywords, bool $integer): void {
    // `1e400` is an ordinary number in JSON's grammar and `json_decode` saturates it to INF, so a bound
    // that arrives as INF or NAN leaves what the author wrote unknown in BOTH directions — there is no
    // value to prove admitted and no emptiness to report either. Nothing is published, which is the one
    // answer that is true whichever number the author meant.
    //
    // This is where the oracle above has to be asked the other question. Neither INF nor NAN can be
    // written into a JSON schema at all, so it cannot judge the answer; what it can say is that it
    // cannot, and that is the fact that makes "publish nothing" the only honest option. The other
    // half is what a value like this does downstream: a non-finite float is what
    // `CanonicalJsonSerializer` refuses outright, so a ladder answering INF would take the whole
    // document down rather than degrade one example.
    expect(BoundedNumber::nearest($keywords, 0, $integer))->toBeNull()
        ->and(boundedNumberRejection($keywords, 0, $integer))->toBe('the keywords could not be expressed as a schema');

    // The bound is STATED, and unreadable — the two questions answer independently, so a caller that
    // owes an example only where a rule pinned something still learns that one did.
    expect(BoundedNumber::stated($keywords))->toBeTrue();
})->with([
    'an unreadable floor on an integer' => [['minimum' => INF], true],
    'an unreadable floor on a number' => [['minimum' => INF], false],
    'an unreadable ceiling' => [['maximum' => -INF], true],
    'an unreadable exclusive floor' => [['exclusiveMinimum' => INF], false],
    'an unreadable exclusive ceiling' => [['exclusiveMaximum' => INF], false],
    // NAN compares false against everything, so a range around it is neither inhabited nor empty.
    'a floor that is not a number' => [['minimum' => NAN], false],
    // A step, for the same reason: `ceil($value / INF) * INF` is NAN, and 0 divided by it proves nothing.
    'an unreadable step' => [['minimum' => 1, 'multipleOf' => INF], true],
]);

it('refuses a set of bounds no value the ladder could reach satisfies', function (array $keywords, bool $integer): void {
    // The other direction for the `null` rows above, and the reason they are not simply "the arithmetic
    // gave up": every number this ladder could have arrived at — the seed, each bound it states, and the
    // midpoint between the two bounds — is put to the validator and refused. So `null` is a fact about
    // the bounds rather than about the walk.
    expect(BoundedNumber::nearest($keywords, 0, $integer))->toBeNull();

    $bounds = array_values(array_filter($keywords, static fn (mixed $v): bool => is_int($v) || is_float($v)));
    $candidates = [0, ...$bounds, ($bounds[0] + $bounds[1]) / 2];

    foreach ($candidates as $candidate) {
        expect(boundedNumberRejection($keywords, $candidate, $integer))->not->toBeNull();
    }

    // Anti-vacuity: the loop really did put several values to the validator.
    expect($candidates)->toHaveCount(4);
})->with([
    'bounds that cross' => [['minimum' => 5, 'maximum' => 3], true],
    'exclusive bounds that meet' => [['exclusiveMinimum' => 3, 'exclusiveMaximum' => 4], true],
    // The `number` row, where the midpoint is the value the ladder WOULD have reached had the interval
    // held anything: bounds that coincide leave it on the bound, which the bound itself excludes.
    'exclusive bounds that coincide' => [['exclusiveMinimum' => 1, 'exclusiveMaximum' => 1], false],
]);

it('reports a value the keywords reject, so the oracle above can fail', function (): void {
    // The oracle is a claimed guard, so it is executed rather than asserted: handed a value the keywords
    // refuse it names the keyword that refused, and handed one they accept it says nothing.
    expect(boundedNumberRejection(['minimum' => 5], 0, true))->toBe('minimum')
        ->and(boundedNumberRejection(['multipleOf' => 5], 3, true))->toBe('multipleOf')
        ->and(boundedNumberRejection(['minimum' => 5], 0.5, false))->toBe('minimum')
        ->and(boundedNumberRejection(['minimum' => 5], 5, true))->toBeNull();
});

it('says whether the keywords bound the number at all', function (array $keywords, bool $stated): void {
    // Asked separately from the value, because one caller owes an example only where a rule pinned
    // something: a bare `type: integer` earns no example, and the seed would be bytes without a fact.
    expect(BoundedNumber::stated($keywords))->toBe($stated);
})->with([
    'minimum' => [['minimum' => 1], true],
    'maximum' => [['maximum' => 1], true],
    'exclusiveMinimum' => [['exclusiveMinimum' => 1], true],
    'exclusiveMaximum' => [['exclusiveMaximum' => 1], true],
    'multipleOf' => [['multipleOf' => 1], true],
    // Zero is a stated bound like any other; only the value ladder reads it as no step.
    'a step of zero' => [['multipleOf' => 0], true],
    'nothing' => [[], false],
    'the type alone' => [['type' => 'integer'], false],
    // The degradation contract: a keyword that is not a number bounds nothing, so a schema carrying only
    // draft-04's boolean flag is unbounded and earns no example.
    'a draft-04 exclusive flag alone' => [['exclusiveMinimum' => true], false],
    'a bound stated as text' => [['minimum' => '1'], false],
    // A neighbour of a bound is not a bound: a length constraint and a pattern belong to strings.
    'a string constraint' => [['minLength' => 3, 'pattern' => '^a'], false],
]);
