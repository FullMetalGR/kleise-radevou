<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Application\ApproveBooking;
use App\Application\CancelBooking;
use App\Application\Clock;
use App\Application\ConfirmBooking;
use App\Application\ConfirmPaidBooking;
use App\Application\GuardedWrite;
use App\Application\MarkBookingOutcome;
use App\Application\Payment\SettlesByProvider;
use App\Application\RejectBooking;
use App\Application\ReleaseHold;
use App\Application\RescheduleBooking;
use App\Infrastructure\Cache\StaffDayMaskCache;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\LockManager;
use App\Infrastructure\Db\Pool;
use App\Infrastructure\Db\PostgresBookingLookup;
use App\Infrastructure\Db\PostgresBookingRepository;
use App\Infrastructure\Db\PostgresSoldChain;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Db\TransactionRunner;
use App\Infrastructure\Events\TransitionPublishers;
use App\Tenancy\Tenant;

/**
 * The one place a guarded transition is built for a tenant: the pre-read and the booking
 * repository, both bound to the one tenant given, so a use case and a repository for different
 * tenants can never be paired. Resolved from the container for its shared parts, the reader,
 * the runner, the lock manager, the mask cache, the clock, the publisher every committed
 * transition goes out through; the payment factory, whose provider for the tenant is who
 * answers whether a booking may settle unpaid and whose seal the paid transition opens the
 * hop's sealed token with; the hold's factory, whose planner places a moved chain; and the
 * settings reader, which an approval asks for the payment hold's hours before its transaction
 * opens.
 *
 * One factory rather than one per use case, because the transitions built here differ in their
 * guard and their landing place and in nothing a factory decides. The move is here too
 * although it runs its own sequence: a caller asks for a transition of a booking, and which of
 * them share an implementation is not something a caller should have to know.
 */
final readonly class BookingTransitionFactory
{
    public function __construct(
        private BookingReader $bookings,
        private TransactionRunner $runner,
        private LockManager $locks,
        private StaffDayMaskCache $cache,
        private Clock $clock,
        private TransitionPublishers $events,
        private PaymentFactory $payments,
        private HoldBookingFactory $holds,
        private SettingsReader $settings,
    ) {}

    public function confirm(Tenant $tenant): ConfirmBooking
    {
        return new ConfirmBooking($this->guarded($tenant), new SettlesByProvider($this->payments->provider($tenant)));
    }

    /**
     * The paid transition: the payment notification's.
     */
    public function paid(Tenant $tenant): ConfirmPaidBooking
    {
        return new ConfirmPaidBooking($this->guarded($tenant), $this->payments->seal());
    }

    public function approve(Tenant $tenant): ApproveBooking
    {
        return new ApproveBooking(
            $this->guarded($tenant),
            new SettlesByProvider($this->payments->provider($tenant)),
            fn (): int => $this->settings->read($tenant)->paymentTtlHours,
        );
    }

    public function cancel(Tenant $tenant): CancelBooking
    {
        return new CancelBooking($this->guarded($tenant));
    }

    public function reject(Tenant $tenant): RejectBooking
    {
        return new RejectBooking($this->guarded($tenant));
    }

    public function markOutcome(Tenant $tenant): MarkBookingOutcome
    {
        return new MarkBookingOutcome($this->guarded($tenant));
    }

    public function release(Tenant $tenant): ReleaseHold
    {
        return new ReleaseHold($this->guarded($tenant));
    }

    /**
     * The one write here that is not a guarded transition, built here all the same: what a
     * caller asks for is a transition of a booking, and which of them share an implementation is
     * not the caller's business. Its planner comes from the hold's factory, so a chain is placed
     * the same way whether it is being taken or moved.
     */
    public function reschedule(Tenant $tenant): RescheduleBooking
    {
        return new RescheduleBooking(
            new PostgresSoldChain($this->bookings, $tenant),
            $this->holds->planner($tenant, Pool::Whole),
            new PostgresBookingRepository($this->runner, $this->locks, $tenant, $this->cache),
            $tenant,
            $this->clock,
            $this->events->for($tenant),
        );
    }

    private function guarded(Tenant $tenant): GuardedWrite
    {
        return new GuardedWrite(
            new PostgresBookingLookup($this->bookings, $tenant),
            new PostgresBookingRepository($this->runner, $this->locks, $tenant, $this->cache),
            $tenant,
            $this->clock,
            $this->events->for($tenant),
        );
    }
}
