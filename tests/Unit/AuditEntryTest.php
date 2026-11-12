<?php

use App\Domain\Audit\Actor;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditKey;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;

/*
 * One row of the trail before it is a row: what happened, who did it, and a payload of scalars
 * drawn from the one declared key list. The list is the whole vocabulary, so a key outside it
 * is refused here rather than discovered in a jsonb column later, and nothing personal is a key
 * on it at all.
 */

/**
 * @param  array<string, mixed>  $payload
 */
function auditEntry(array $payload): AuditEntry
{
    return new AuditEntry('11111111-2222-3333-4444-555555555555', AuditAction::Held, Actor::customer(), $payload);
}

it('carries the booking by its public identifier, the action and the actor', function () {
    $entry = auditEntry([]);

    expect($entry->bookingUuid)->toBe('11111111-2222-3333-4444-555555555555')
        ->and($entry->action)->toBe(AuditAction::Held)
        ->and($entry->actor->value)->toBe('customer')
        ->and($entry->payload)->toBe([]);
});

it('reduces an enum to its backing value and an instant to UTC text carrying its offset', function () {
    $entry = auditEntry([
        AuditKey::StatusTo->value => BookingStatus::Pending,
        AuditKey::HoldClass->value => HoldClass::Checkout,
        AuditKey::HoldExpiresAt->value => new DateTimeImmutable('2026-11-03 12:15:00.123456', new DateTimeZone('Europe/Athens')),
        AuditKey::ItemCount->value => 2,
    ]);

    expect($entry->payload)->toBe([
        'status_to' => 'pending',
        'hold_class' => 'checkout',
        'hold_expires_at' => '2026-11-03T10:15:00.123456+00:00',
        'item_count' => 2,
    ]);
});

it('keeps a bare scalar and a null as they came', function () {
    expect(auditEntry([
        AuditKey::StatusFrom->value => null,
        AuditKey::ItemCount->value => 1,
        AuditKey::StartsAt->value => '2026-11-03T08:00:00.000000+00:00',
    ])->payload)->toBe(['status_from' => null, 'item_count' => 1, 'starts_at' => '2026-11-03T08:00:00.000000+00:00']);
});

it('refuses a key outside the declared list, naming it and the vocabulary', function () {
    expect(fn () => auditEntry(['customer_email' => 'casey@example.com']))
        ->toThrow(InvalidArgumentException::class, 'customer_email');
});

it('refuses a payload value that is not a scalar', function () {
    expect(fn () => auditEntry([AuditKey::ItemCount->value => [1, 2]]))->toThrow(InvalidArgumentException::class);
});

it('declares exactly seven keys, the paid transition\'s three, the move\'s one and the business move\'s three beside them, and nothing about the customer', function () {
    expect(array_map(fn (AuditKey $key): string => $key->value, AuditKey::cases()))
        ->toBe(['status_from', 'status_to', 'hold_class', 'hold_expires_at', 'item_count', 'starts_at', 'moved_from', 'payment_named', 'paid_minor', 'paid_currency', 'session_cleared', 'staff_changed', 'overridden', 'customer_told']);
});

it('keeps a list of facts per segment, each a map of fields reduced as a value is, and refuses a list holding anything else', function () {
    expect(auditEntry([
        AuditKey::StaffChanged->value => [['segment' => 1, 'from' => 2, 'to' => 3]],
        AuditKey::Overridden->value => [['segment' => 0, 'kind' => HoldClass::Checkout]],
    ])->payload)->toBe([
        'staff_changed' => [['segment' => 1, 'from' => 2, 'to' => 3]],
        'overridden' => [['segment' => 0, 'kind' => 'checkout']],
    ])
        ->and(fn () => auditEntry([AuditKey::StaffChanged->value => ['first' => ['segment' => 0]]]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => auditEntry([AuditKey::StaffChanged->value => [['segment' => ['nested']]]]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => auditEntry([AuditKey::StaffChanged->value => [[]]]))->toThrow(InvalidArgumentException::class);
});

it('names the actions the trail writes, one per transition that leaves a trail and the erasure and the export of the customer beside them', function () {
    expect(array_map(fn (AuditAction $action): string => $action->value, AuditAction::cases()))
        ->toBe(['held', 'expired', 'confirmed', 'cancelled', 'released', 'approved', 'rejected', 'completed', 'no_show', 'rescheduled', 'customer_erased', 'customer_exported']);
});
