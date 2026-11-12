<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Application\Clock;
use App\Application\MyBookings\RequestBookingsLink;
use App\Application\Notification\MessageKey;
use App\Infrastructure\Db\CustomerAccess;
use App\Infrastructure\Db\PostgresCustomerLinks;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Events\DispatchedLinkRequests;
use App\Site\Demo;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;

/**
 * The one place the bookings link's request is built for a tenant: the rows, the announcer and
 * the switch, all bound to the one tenant given.
 *
 * The switch is the tenant's emails_off read when the request asks, through the one reading the
 * seam asks when it sends (Settings::sends), so a link is never minted for a message the seam
 * would drop.
 */
final readonly class MyBookingsFactory
{
    public function __construct(
        private CustomerAccess $access,
        private Clock $clock,
        private SettingsReader $settings,
        private Dispatcher $events,
        private LoggerInterface $log,
        private CurrentTenant $current,
        private Demo $demo,
    ) {}

    public function requestLink(Tenant $tenant): RequestBookingsLink
    {
        return new RequestBookingsLink(
            new PostgresCustomerLinks($this->access, $tenant),
            $this->clock,
            new DispatchedLinkRequests($this->events, $this->log, $this->current, $this->demo, $tenant),
            fn (): bool => $this->settings->read($tenant)->sends(MessageKey::MyBookingsLink),
        );
    }
}
