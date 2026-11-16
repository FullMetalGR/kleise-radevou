<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Dto\BookingRecord;
use App\Http\Requests\BlackoutRequest;
use App\Infrastructure\Db\BookingsInside;
use App\Infrastructure\Db\BudgetExceeded;
use App\Infrastructure\Db\Busy;
use App\Infrastructure\Db\LockedClosures;
use App\Infrastructure\Db\RetriesExhausted;
use App\Infrastructure\Db\SettingsReader;
use App\Notifications\TenantClock;
use App\Notifications\TenantTime;
use App\Site\SiteLanguage;
use App\Tenancy\Permission;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Adding a closure over time that may hold bookings, for the business or for one person.
 *
 * An add with no choice over no bookings is written as it always was. Over bookings, it writes
 * nothing and answers 409 bookings_inside with the bookings and the choices this principal is
 * offered; the admin shows them and posts again with its choice and the bookings it showed. That
 * add is written only when the bookings inside are still exactly those shown, and otherwise
 * answers the same refusal with the bookings as they now stand, changed set to true.
 *
 * An add that waited too long for a lock, lost to another transaction or ran past its time wrote
 * nothing, and is answered 503 busy with a Retry-After, whichever of those it was.
 *
 * A choice the principal is not offered for the bookings shown is a 403, asked before anything is
 * written. Keep and move write the closure and leave the bookings; cancel then cancels or
 * rejects each one in its own write, and the flash says how many went and names any left as they
 * were because they changed meanwhile.
 *
 * A booking on the list is its uuid and its status for every principal, and the rest of it, the
 * customer's name, the times and the services, only for one who holds viewBookings.
 */
final readonly class ClosureOverBookings
{
    public const string CODE = 'bookings_inside';

    /** The refusal's sentence, by the count, and the one for a list that changed. */
    public const string INSIDE = 'admin/staff.refusals.bookings_inside';

    public const string CHANGED = 'admin/staff.refusals.bookings_changed';

    /** The flashes: the closure added, the bookings cancelled, and those left as they were. */
    public const string ADDED = 'admin/staff.flashes.closure_added';

    public const string CANCELLED = 'admin/staff.flashes.closure_cancelled';

    public const string SKIPPED = 'admin/staff.flashes.closure_skipped';

    public function __construct(
        private LockedClosures $closures,
        private ClosureChoices $choices,
        private ClosureCancellations $cancellations,
        private SettingsReader $settings,
    ) {}

    /**
     * Adds the closure and answers the flash for the redirect back, or the refusal to answer in
     * its place.
     *
     * @return array{kind: string, message: string}|JsonResponse
     */
    public function add(Tenant $tenant, Principal $principal, BlackoutRequest $request, ?int $staffId): array|JsonResponse
    {
        $choice = $request->choice();
        $seen = $choice === null ? null : $request->seen();

        if ($choice !== null && $seen !== null && ! in_array($choice, $this->choices->offered($tenant, $principal, $seen), true)) {
            throw new AccessDeniedHttpException;
        }

        [$start, $end] = $request->window();
        $reason = $request->reason();

        try {
            $inside = $this->closures->add($tenant, $staffId, $request->day(), $request->until(), $start, $end, $reason, $seen);
        } catch (BookingsInside $refused) {
            return $this->refusal($tenant, $principal, $refused);
        } catch (Busy|RetriesExhausted|BudgetExceeded) {
            // The add lost to another write, or ran out of time, and wrote nothing: press again.
            return ErrorEnvelope::busy()->toResponse();
        }

        if ($choice !== ClosureChoice::Cancel || $inside === []) {
            return ['kind' => 'success', 'message' => AdminLines::line(self::ADDED)];
        }

        $skipped = $this->cancellations->run($tenant, $principal, $inside, $reason);
        $cancelled = count($inside) - count($skipped);
        $message = $cancelled === 0 ? AdminLines::line(self::ADDED) : trans_choice(self::CANCELLED, $cancelled);

        if ($skipped === []) {
            return ['kind' => 'success', 'message' => $message];
        }

        // Kept until dismissed, since somebody has to look at what was left.
        return ['kind' => 'error', 'message' => $message.' '.trans_choice(self::SKIPPED, count($skipped), ['bookings' => $this->named($tenant, $skipped)])];
    }

    private function refusal(Tenant $tenant, Principal $principal, BookingsInside $refused): JsonResponse
    {
        $count = count($refused->bookings);
        $sees = $principal->holds(Permission::ViewBookings);
        $seen = array_map(self::seen(...), $refused->bookings);

        return (new ErrorEnvelope(self::CODE, 409, $refused->changed ? AdminLines::line(self::CHANGED) : trans_choice(self::INSIDE, $count), [
            'count' => $count,
            'changed' => $refused->changed,
            'bookings' => array_map(fn (BookingRecord $record): array => $sees ? self::row($record) : self::seen($record), $refused->bookings),
            'choices' => array_map(fn (ClosureChoice $choice): string => $choice->value, $this->choices->offered($tenant, $principal, $seen)),
        ]))->toResponse();
    }

    /**
     * A booking as the add's next post names it back.
     *
     * @return array{uuid: string, status: string}
     */
    private static function seen(BookingRecord $record): array
    {
        return ['uuid' => $record->snapshot->uuid, 'status' => $record->snapshot->status->value];
    }

    /**
     * A booking as the list shows it: its first start and its last end, the customer's name, null
     * once the customer is erased, and its services in chain order, each with its member and its
     * times, every instant RFC 3339 in UTC.
     *
     * @return array<string, mixed>
     */
    private static function row(BookingRecord $record): array
    {
        $booking = BookingPayload::guest($record->snapshot);

        return [
            'uuid' => $booking['uuid'],
            'status' => $booking['status'],
            'start' => BookingPayload::instant($record->snapshot->startsAt()),
            'end' => BookingPayload::instant($record->snapshot->endsAt()),
            'customer_name' => $booking['customer_name'],
            'items' => array_map(fn (array $item): array => [
                'sort' => $item['sort'],
                'service_id' => $item['service_id'],
                'service_name' => $item['service_name'],
                'staff_id' => $item['staff_id'],
                'staff_name' => $item['staff_name'],
                'start' => $item['start'],
                'end' => $item['end'],
            ], $booking['items']),
        ];
    }

    /**
     * The bookings left as they were, each its customer and its first start in the tenant's
     * clock, in the language of the request, the start alone for a customer erased.
     *
     * @param  list<BookingRecord>  $bookings
     */
    private function named(Tenant $tenant, array $bookings): string
    {
        $clock = TenantClock::of($tenant, $this->settings->read($tenant));
        $language = SiteLanguage::tryFrom(app()->getLocale()) ?? SiteLanguage::English;

        return implode('; ', array_map(function (BookingRecord $record) use ($clock, $language): string {
            $when = TenantTime::instant($record->snapshot->startsAt(), $clock, $language);
            $name = $record->snapshot->customer?->name;

            return $name === null ? $when : $name.', '.$when;
        }, $bookings));
    }
}
