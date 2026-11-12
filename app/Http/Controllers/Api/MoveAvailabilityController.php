<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\Clock;
use App\Application\QueryMoveAvailability;
use App\Http\AvailabilityPayload;
use App\Http\Controllers\Controller;
use App\Http\ManagedBooking;
use App\Http\Requests\MoveAvailabilityRequest;
use App\Infrastructure\Cache\StaffDayMaskCache;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\Pool;
use App\Infrastructure\Db\PostgresAvailabilityRepository;
use App\Infrastructure\Db\PostgresServiceRepository;
use App\Infrastructure\Db\PostgresSoldChain;
use App\Infrastructure\Db\SettingsReader;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/bookings/{uuid}/availability: the reschedule dialog's read, the starts the booking
 * a manage token admitted could move to on each day asked for. The answer is GET /availability's
 * shape, keyed by the tenant's dates with RFC 3339 instants in UTC, and like it advisory and
 * never cacheable, no-store: the move is the only authority.
 *
 * The guard ran in the middleware and bound the booking it read; the identifier is the only
 * thing taken from that read. The chain is the move's own pre-read, placed on the catalog the
 * way the move places it, and the booking is left out of what counts as busy by the
 * availability repository rebuilding the staff-days it stands on. Opens no transaction and sets
 * nothing on the connection.
 *
 * What the use case answers is thinned to the starts on the tenant's start interval, read on the
 * tenant's wall clock, as the public read thins its own: the dialog draws the booking app's list,
 * so it offers what the booking page offers. The move itself still takes a free start off the
 * interval: the interval shapes the offer, not the write.
 *
 * Behind the manage token like every route that takes it, and rate limited on its own: a budget
 * beside the public read's rather than a share of it, so a dialog open beside the tenant's own
 * booking page spends nothing the website is using, and in place of the general limit rather than
 * beside it.
 */
final class MoveAvailabilityController extends Controller
{
    public function __invoke(MoveAvailabilityRequest $request, ManagedBooking $managed, CurrentTenant $current, DatabaseManager $db, SettingsReader $settings, StaffDayMaskCache $cache, BookingReader $reader, Clock $clock): JsonResponse
    {
        $tenant = $current->get();

        $useCase = new QueryMoveAvailability(
            new PostgresSoldChain($reader, $tenant),
            new PostgresServiceRepository($db, $tenant, Pool::Whole),
            new PostgresAvailabilityRepository($db, $settings, $tenant, $cache),
            $tenant,
            $clock,
        );

        $starts = $useCase->query($managed->get()->uuid, $request->window())->every($settings->read($tenant)->startIntervalMin, $tenant->zone());

        $response = new JsonResponse(AvailabilityPayload::of($starts));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
