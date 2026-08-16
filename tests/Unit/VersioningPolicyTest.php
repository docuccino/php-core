<?php

declare(strict_types=1);

use Docuccino\Core\Diff\Change;
use Docuccino\Core\Diff\ChangeKind;
use Docuccino\Core\Diff\Changeset;
use Docuccino\Core\Diff\ChangeTarget;
use Docuccino\Core\Diff\Policy\DateVersionPolicy;
use Docuccino\Core\Diff\Policy\NoVersioningPolicy;
use Docuccino\Core\Diff\Policy\SemverPolicy;
use Docuccino\Core\Diff\Policy\VersioningPolicies;

function breakingSet(): Changeset
{
    return new Changeset([
        new Change(ChangeKind::Removed, ChangeTarget::Operation, 'op:v1:aaaaaaaaaaaaaaaa', 'GET /forms', true, 'operation.removed'),
    ]);
}

function additiveSet(): Changeset
{
    return new Changeset([
        new Change(ChangeKind::Added, ChangeTarget::Operation, 'op:v1:bbbbbbbbbbbbbbbb', 'GET /widgets', false, 'operation.added'),
    ]);
}

function emptySet(): Changeset
{
    return new Changeset;
}

describe('SemverPolicy', function (): void {
    it('passes a breaking change with a major bump', function (): void {
        $verdict = (new SemverPolicy)->evaluate(breakingSet(), '1.4.2', '2.0.0');

        expect($verdict->satisfied)->toBeTrue()
            ->and($verdict->policy)->toBe('semver');
    });

    it('fails a breaking change without a major bump and suggests the version', function (): void {
        $verdict = (new SemverPolicy)->evaluate(breakingSet(), '1.4.2', '1.5.0');

        expect($verdict->satisfied)->toBeFalse()
            ->and($verdict->code)->toBe('major-bump-required')
            ->and($verdict->requiredVersion)->toBe('2.0.0');
    });

    it('treats a 0.x minor bump as adequate for a breaking change', function (): void {
        expect((new SemverPolicy)->evaluate(breakingSet(), '0.4.0', '0.5.0')->satisfied)->toBeTrue();
        $violation = (new SemverPolicy)->evaluate(breakingSet(), '0.4.0', '0.4.1');
        expect($violation->satisfied)->toBeFalse()
            ->and($violation->code)->toBe('minor-bump-required')
            ->and($violation->requiredVersion)->toBe('0.5.0');
    });

    it('passes any non-breaking change regardless of version', function (): void {
        expect((new SemverPolicy)->evaluate(additiveSet(), '1.4.2', '1.4.2')->satisfied)->toBeTrue()
            ->and((new SemverPolicy)->evaluate(emptySet(), '1.4.2', '1.4.2')->satisfied)->toBeTrue();
    });

    it('rejects an unparseable version', function (): void {
        $verdict = (new SemverPolicy)->evaluate(breakingSet(), '1.4', '2026-01-01');

        expect($verdict->satisfied)->toBeFalse()
            ->and($verdict->code)->toBe('invalid-version');
    });

    it('escapes control characters in a version it quotes back', function (): void {
        // The version comes out of the artifact being diffed, and the violation is printed to a terminal.
        $verdicts = [
            (new SemverPolicy)->evaluate(breakingSet(), "1.4\x1B[31m", '2.0.0'),
            (new SemverPolicy)->evaluate(breakingSet(), "1.4.2-\x1B[31m", '1.4.3'),
            (new SemverPolicy)->evaluate(breakingSet(), "0.4.2-\x1B[31m", '0.4.2'),
            (new DateVersionPolicy)->evaluate(breakingSet(), "not-a-date\x1B[31m", '2026-08-01'),
            (new DateVersionPolicy)->evaluate(breakingSet(), "2026-08-01\x1B[31m", '2026-08-01'),
        ];

        foreach ($verdicts as $verdict) {
            expect($verdict->message)->not->toContain("\x1B")
                ->and($verdict->message)->toContain('\x1B[31m');
        }
    });
});

describe('DateVersionPolicy', function (): void {
    it('passes a breaking change with a newer date', function (): void {
        expect((new DateVersionPolicy)->evaluate(breakingSet(), '2026-07-01', '2026-08-01')->satisfied)->toBeTrue();
    });

    it('fails a breaking change without a newer date', function (): void {
        $verdict = (new DateVersionPolicy)->evaluate(breakingSet(), '2026-08-01', '2026-08-01');

        expect($verdict->satisfied)->toBeFalse()
            ->and($verdict->code)->toBe('new-date-required');
    });

    it('ignores a trailing suffix when comparing dates', function (): void {
        $verdict = (new DateVersionPolicy)->evaluate(breakingSet(), '2026-08-01', '2026-08-01-rc1');

        expect($verdict->satisfied)->toBeFalse();
    });

    it('passes a non-breaking change on the same date', function (): void {
        expect((new DateVersionPolicy)->evaluate(additiveSet(), '2026-08-01', '2026-08-01')->satisfied)->toBeTrue();
    });

    it('rejects a non-date version', function (): void {
        $verdict = (new DateVersionPolicy)->evaluate(breakingSet(), '1.0.0', '2.0.0');

        expect($verdict->satisfied)->toBeFalse()
            ->and($verdict->code)->toBe('invalid-date-version');
    });
});

describe('NoVersioningPolicy', function (): void {
    it('fails any breaking change outright', function (): void {
        $verdict = (new NoVersioningPolicy)->evaluate(breakingSet(), 'anything', 'anything');

        expect($verdict->satisfied)->toBeFalse()
            ->and($verdict->code)->toBe('breaking-forbidden');
    });

    it('passes non-breaking changes', function (): void {
        expect((new NoVersioningPolicy)->evaluate(additiveSet(), 'a', 'b')->satisfied)->toBeTrue();
    });
});

describe('VersioningPolicies factory', function (): void {
    it('resolves each built-in keyword', function (): void {
        expect(VersioningPolicies::for('semver'))->toBeInstanceOf(SemverPolicy::class)
            ->and(VersioningPolicies::for('date'))->toBeInstanceOf(DateVersionPolicy::class)
            ->and(VersioningPolicies::for('none'))->toBeInstanceOf(NoVersioningPolicy::class);
    });

    it('fails closed to the strictest policy on an unknown keyword', function (): void {
        expect(VersioningPolicies::for('bogus'))->toBeInstanceOf(NoVersioningPolicy::class);
    });
});
