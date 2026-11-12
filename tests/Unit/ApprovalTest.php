<?php

use App\Domain\Audit\Actor;
use App\Domain\Booking\Approval;
use App\Domain\Booking\TransitionColumn;

/*
 * What an approval writes onto the booking row, as one description two callers share: the
 * owner's decision, and the sweeper, which grants a timed-out request in the owner's favour
 * without going through the guarded sequence at all.
 */

it('records who said yes and when: the id of the user who pressed it, and the instant itself rather than a spelling of one', function () {
    $approval = Approval::by(Actor::user(7), instantAt('2026-11-02 08:30:00'));

    expect($approval->userId)->toBe(7)
        ->and($approval->at)->toEqual(instantAt('2026-11-02 08:30:00'))
        ->and($approval->columns())->toBe([
            TransitionColumn::HoldClass->value => null,
            TransitionColumn::HoldExpiresAt->value => null,
            TransitionColumn::ApprovedAt->value => $approval->at,
            TransitionColumn::ApprovedBy->value => 7,
        ]);
});

it('names nobody when the decision was not a person\'s, which is the sweeper granting a request the owner never answered', function () {
    $columns = Approval::by(Actor::job('expire-holds'), instantAt('2026-11-02 08:30:00'))->columns();

    expect($columns[TransitionColumn::ApprovedBy->value])->toBeNull()
        ->and(Approval::by(Actor::system(), instantAt('2026-11-02 08:30:00'))->userId)->toBeNull()
        ->and(Approval::by(Actor::customer(), instantAt('2026-11-02 08:30:00'))->userId)->toBeNull();
});

it('ends the hold with the decision, because a booking that has been decided is no longer waiting on anybody', function () {
    $columns = Approval::by(Actor::user(7), instantAt('2026-11-02 08:30:00'))->columns();

    expect(array_key_exists(TransitionColumn::HoldClass->value, $columns))->toBeTrue()
        ->and($columns[TransitionColumn::HoldClass->value])->toBeNull()
        ->and(array_key_exists(TransitionColumn::HoldExpiresAt->value, $columns))->toBeTrue()
        ->and($columns[TransitionColumn::HoldExpiresAt->value])->toBeNull()
        ->and(array_keys($columns))->not->toContain(TransitionColumn::RejectionReason->value);
});

it('keeps the instant in whatever zone it was given, since where it is spelled is the store\'s business and not this one\'s', function () {
    $athens = instantAt('2026-11-02 10:30:00', 'Europe/Athens');
    $columns = Approval::by(Actor::user(7), $athens)->columns();

    expect($columns[TransitionColumn::ApprovedAt->value])->toBe($athens);
});
