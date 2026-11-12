<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\BookingUnknown;
use App\Application\ChainUnavailable;
use App\Application\Dto\BookingRecord;
use App\Application\Dto\MoveCandidate;
use App\Application\NoSuchSegment;
use App\Application\QueryBusinessMove;
use App\Application\StaffNotEligible;
use App\Application\TransitionRefused;
use App\Domain\Booking\Actor;
use App\Domain\Booking\CancellationPolicy;
use App\Domain\Booking\SegmentConflict;
use App\Http\BookingPayload;
use App\Http\Controllers\Controller;
use App\Http\ErrorEnvelope;
use App\Http\MoveRefusal;
use App\Http\Requests\MoveReadRequest;
use App\Infrastructure\Cache\StaffDayMaskCache;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\BookingRecords;
use App\Infrastructure\Db\CalendarRoster;
use App\Infrastructure\Db\Pool;
use App\Infrastructure\Db\PostgresAvailabilityRepository;
use App\Infrastructure\Db\PostgresServiceRepository;
use App\Infrastructure\Db\PostgresSoldChain;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\HoldBookingFactory;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;

/**
 * GET /admin/api/bookings/{uuid}/move: what the Move form and the calendar's drag ask before they
 * write, under manageBookings as the move itself is. Advisory, like every availability read, and
 * no-store: it takes no lock, and the move under its locks stays the judge, so a start offered or
 * a placeable verdict here may still be refused by the write a moment later, with the write's own
 * code.
 *
 * Two shapes, by the parameter named. With `date`, the day's candidate starts on the tenant's
 * grid, each with its crossings, none where the chain fits the members' hours and no closure: a
 * candidate is a start the planner would take that no other booking of the members concerned is
 * in the way of, the booking's own items left out. With `start`, the verdict on that one start:
 * placeable with its crossings, or the refusal the write would answer for the same request at
 * that moment, its code, status and sentence, the write's own envelope (MoveRefusal), asked in
 * the write's order: the booking, the plan, the status rule, another booking, the crossings. A
 * placeable verdict with crossings is one the write refuses as unaccepted_warning until the
 * warning is accepted. Both read QueryBusinessMove, the write's planner and findings.
 *
 * Beside either, what the form offers per segment: the service, the member it is with now, and
 * the members it may be given to, the active members who perform the service, the implicit row
 * never among them. A day the chain cannot be planned for at all, a member override refused or
 * the catalog no longer placing the chain, is that refusal's envelope, as the write would answer
 * it; a booking nobody has is the 404.
 */
final class MoveReadController extends Controller
{
    public function __invoke(
        MoveReadRequest $request,
        string $uuid,
        CurrentTenant $current,
        DatabaseManager $db,
        SettingsReader $settings,
        StaffDayMaskCache $cache,
        BookingReader $reader,
        BookingRecords $records,
        CalendarRoster $roster,
        HoldBookingFactory $holds,
    ): JsonResponse {
        $tenant = $current->get();
        $record = $records->one($tenant, $uuid);

        if ($record === null) {
            return self::noStore(ErrorEnvelope::for(BookingUnknown::named($uuid))->toResponse());
        }

        $query = new QueryBusinessMove(
            new PostgresSoldChain($reader, $tenant),
            $holds->planner($tenant, Pool::Whole),
            new PostgresAvailabilityRepository($db, $settings, $tenant, $cache),
            $tenant,
        );
        $staff = $request->staff();
        $segments = self::segments($tenant, $record, $roster, new PostgresServiceRepository($db, $tenant, Pool::Whole));
        $day = $request->day();

        if ($day !== null) {
            try {
                $candidates = $query->candidates($uuid, $day, $staff);
            } catch (BookingUnknown|ChainUnavailable|StaffNotEligible|NoSuchSegment $refused) {
                return self::noStore(MoveRefusal::for($refused)->toResponse());
            }

            return self::noStore(new JsonResponse([
                'date' => $day,
                'segments' => $segments,
                'starts' => array_map(fn (MoveCandidate $candidate): array => [
                    'start' => BookingPayload::instant($candidate->start),
                    'crossings' => ErrorEnvelope::crossingsOf($candidate->crossings),
                ], $candidates),
            ]));
        }

        $start = $request->start();
        $guard = function () use ($uuid, $record): void {
            $refusal = CancellationPolicy::rescheduleRefusalByStatus($record->snapshot->status, $record->holdIsLive, Actor::Admin);

            if ($refusal !== null) {
                throw TransitionRefused::because($uuid, $refusal, $record->snapshot->status);
            }
        };

        try {
            $verdict = ['placeable' => true, 'crossings' => ErrorEnvelope::crossingsOf($query->verdict($uuid, $start, $staff, $guard))];
        } catch (BookingUnknown|ChainUnavailable|StaffNotEligible|NoSuchSegment|TransitionRefused|SegmentConflict $refused) {
            $envelope = MoveRefusal::for($refused);
            $verdict = ['placeable' => false, 'code' => $envelope->code, 'status' => $envelope->status, 'detail' => $envelope->detail, ...$envelope->data];
        }

        return self::noStore(new JsonResponse(['start' => BookingPayload::instant($start), 'segments' => $segments, 'verdict' => $verdict]));
    }

    /**
     * Per segment of the booking, in chain order: its service, the member it is with now, and
     * the members it may be given to, by id and name in roster order.
     *
     * @return list<array{segment: int, service_id: int, service_name: string, staff_id: int, staff_name: string, members: list<array{id: int, name: string}>}>
     */
    private static function segments(Tenant $tenant, BookingRecord $record, CalendarRoster $roster, PostgresServiceRepository $services): array
    {
        $items = $record->snapshot->items;
        $bookable = $services->bookable(array_values(array_unique(array_map(fn ($item): int => $item->serviceId, $items))));
        $active = $roster->active($tenant);
        $segments = [];

        foreach ($items as $item) {
            $service = $bookable[$item->serviceId] ?? null;
            $segments[] = [
                'segment' => $item->sort,
                'service_id' => $item->serviceId,
                'service_name' => $item->serviceName,
                'staff_id' => $item->staffId,
                'staff_name' => $item->staffName,
                'members' => $service === null ? [] : array_values(array_filter($active, fn (array $member): bool => $service->performedBy($member['id']))),
            ];
        }

        return $segments;
    }

    private static function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
