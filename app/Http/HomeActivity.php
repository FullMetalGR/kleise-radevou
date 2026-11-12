<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\ConfirmPaidBooking;
use App\Application\Dto\BookingRecord;
use App\Domain\Audit\Actor;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditKey;
use App\Domain\Enum\BookingStatus;

/**
 * Home's Recent activity, one row per trail entry: which whole sentence of admin/home.php's
 * `activity` group the entry is spelled by, one key per action and per shape of actor, never a
 * phrase spliced into another, and the names the sentence fills, the customer's as :customer and
 * the acting user's or operator's as :staff.
 *
 * The shapes, as BookingTrail resolves the actor: the customer; a user named, or one the tenant no
 * longer has (removed); the platform's support with the operator's name, or with none where the
 * operator is gone (support_removed); and the engine or a job (system), but for the payment's
 * notification, whose confirmation is the customer paying online. A creation, a hold taken or a
 * booking confirmed with no status it left, is a booking made: online or a request by the
 * customer, added by a user or support. An expiry names the hold that ran out, whoever reaped it.
 * An erasure and an export name nobody: the sentence says a customer's data, not whose, so their
 * rows carry no customer's name. Any case the table holds no sentence for reads as the booking
 * having changed, so nothing is mis-said.
 *
 * Only the action, the kind, the name and the whitelisted facts are read, and no fact is
 * carried onto a row, so the amount a payment took never reaches one. The customer's name is
 * the booking's, null once erased or where the booking is gone, and such a row is spelled by
 * the same sentence's twin under erased, a whole sentence that names no customer, so nothing
 * stands in the customer's place; the erasure and the export name nobody either way.
 */
final readonly class HomeActivity
{
    /** The rows Home lists, newest first. */
    public const int ROWS = 5;

    /** The group of admin/home.php the sentences sit in. */
    private const string GROUP = 'activity.';

    /** The group within it of an erased customer's sentences, the same keys naming no customer. */
    private const string ERASED = 'erased.';

    /** The two sentences that name no customer whoever the customer was. */
    private const array NAMELESS = [AuditAction::CustomerErased->value, AuditAction::CustomerExported->value];

    /** The shapes a user or the platform's support acts in. */
    private const array STAFF = ['user', 'removed', 'support', 'support_removed'];

    /**
     * The shapes each action is spelled for, beyond the creations, the expiry and the two that
     * name nobody, which are read apart.
     *
     * @var array<string, list<string>>
     */
    private const array SHAPES = [
        'confirmed' => ['customer', 'paid', 'system'],
        'approved' => [...self::STAFF, 'system'],
        'rejected' => [...self::STAFF, 'system'],
        'cancelled' => ['customer', ...self::STAFF, 'system'],
        'released' => ['customer', 'system'],
        'rescheduled' => ['customer', ...self::STAFF, 'system'],
        'completed' => self::STAFF,
        'no_show' => self::STAFF,
    ];

    /** The hold classes an expiry is spelled by; any other reads as a booking expired. */
    private const array EXPIRED = ['approval', 'checkout', 'payment'];

    /**
     * The row, and beneath its sentence the booking's own line: the booking's status, its services
     * and its named members as HomePayload names them, read off the record given, the entry's
     * booking as it stands now; no record, nothing beneath.
     *
     * @param  array{action: string, actor: string, kind: string, name: string|null, at: string, facts: array<mixed>, booking: array{uuid: string, customer: string|null, starts_at: string|null}|null}  $entry
     * @return array{sentence: string, customer: string|null, staff: string|null, at: string, booking: string|null, starts_at: string|null, status: string|null, services: list<string>, members: list<string>}
     */
    public static function row(array $entry, ?BookingRecord $record): array
    {
        [$services, $members] = $record === null ? [[], []] : HomePayload::names($record);
        $sentence = self::sentence($entry['action'], self::shapeOf($entry), $entry['facts']);
        $nameless = in_array($sentence, self::NAMELESS, true);
        $customer = $nameless ? null : $entry['booking']['customer'] ?? null;

        return [
            'sentence' => self::GROUP.($customer === null && ! $nameless ? self::ERASED : '').$sentence,
            'customer' => $customer,
            'staff' => in_array($entry['kind'], ['user', 'support'], true) ? $entry['name'] : null,
            'at' => $entry['at'],
            'booking' => $entry['booking']['uuid'] ?? null,
            'starts_at' => $entry['booking']['starts_at'] ?? null,
            'status' => $record?->snapshot->status->value,
            'services' => $services,
            'members' => $members,
        ];
    }

    /**
     * @param  array{actor: string, kind: string, name: string|null}  $entry
     */
    private static function shapeOf(array $entry): ?string
    {
        return match ($entry['kind']) {
            Actor::CUSTOMER => 'customer',
            'user' => $entry['name'] === null ? 'removed' : 'user',
            'support' => $entry['name'] === null ? 'support_removed' : 'support',
            Actor::SYSTEM => 'system',
            'job' => $entry['actor'] === Actor::job(ConfirmPaidBooking::ACTOR)->value ? 'paid' : 'system',
            default => null,
        };
    }

    /**
     * The key within the group for an action, its actor's shape and its facts.
     *
     * @param  array<mixed>  $facts
     */
    private static function sentence(string $action, ?string $shape, array $facts): string
    {
        $creation = $action === AuditAction::Held->value
            || ($action === AuditAction::Confirmed->value && ! array_key_exists(AuditKey::StatusFrom->value, $facts));

        if ($creation) {
            return match (true) {
                $shape === 'customer' => ($facts[AuditKey::StatusTo->value] ?? null) === BookingStatus::AwaitingApproval->value ? 'held.request' : 'held.online',
                in_array($shape, self::STAFF, true) => 'added.'.$shape,
                default => 'unknown',
            };
        }

        if ($action === AuditAction::Expired->value) {
            $class = $facts[AuditKey::HoldClass->value] ?? null;

            return 'expired.'.(in_array($class, self::EXPIRED, true) ? $class : 'other');
        }

        if ($action === AuditAction::CustomerErased->value || $action === AuditAction::CustomerExported->value) {
            return $action;
        }

        $shapes = self::SHAPES[$action] ?? [];

        if ($shape === 'paid' && $action !== AuditAction::Confirmed->value) {
            $shape = 'system';
        }

        return $shape !== null && in_array($shape, $shapes, true) ? $action.'.'.$shape : 'unknown';
    }

    /**
     * Every key within the group a row can be spelled by, for the test that holds the file to
     * this table.
     *
     * @return list<string>
     */
    public static function sentences(): array
    {
        $keys = ['held.online', 'held.request', 'unknown', AuditAction::CustomerErased->value, AuditAction::CustomerExported->value];

        foreach (self::STAFF as $shape) {
            $keys[] = 'added.'.$shape;
        }

        foreach ([...self::EXPIRED, 'other'] as $class) {
            $keys[] = 'expired.'.$class;
        }

        foreach (self::SHAPES as $action => $shapes) {
            foreach ($shapes as $shape) {
                $keys[] = $action.'.'.$shape;
            }
        }

        $named = array_values(array_diff($keys, self::NAMELESS));

        return array_map(fn (string $key): string => self::GROUP.$key, [...$keys, ...array_map(fn (string $key): string => self::ERASED.$key, $named)]);
    }
}
