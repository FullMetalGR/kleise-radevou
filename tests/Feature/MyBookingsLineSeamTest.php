<?php

use App\Application\Clock;
use App\Application\Notification\MessageKey;
use App\Infrastructure\Db\BookingReader;
use App\Jobs\SendAcknowledgement;
use App\Jobs\SendCancellation;
use App\Jobs\SendConfirmation;
use App\Jobs\SendMove;
use App\Jobs\SendNewBookingNotice;
use App\Jobs\SendReminder;
use App\Jobs\SendRequestApproved;
use App\Jobs\SendRequestExpired;
use App\Jobs\SendRequestRejected;
use App\Jobs\SendThankYou;
use App\Notifications\MyBookingsLine;
use App\Site\SiteLanguage;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Support\FixedClock;

pest()->use(DatabaseTruncation::class);

/*
 * The closing line through the seam: every customer job reads the bookings link's switch at the
 * send and closes its message on the tenant's My bookings page while it is on, and on nothing
 * while it is off. The business's messages carry none.
 *
 * luna is the demo seeder's; the booking is a row of casey's on 2026-11-03 at 08:00 UTC, the clock
 * the Monday before, so the reminder is still ahead of its booking. Each job is the one its
 * listener queues, run now. The thank-you's booking is over and marked completed, on the Sunday
 * before the clock, since its job sends for nothing else.
 */

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00:00'));
    seamTransport()->flush();
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * Every customer form, as the job that sends it, for a booking of luna's.
 *
 * @return array<string, Closure(int, string): ShouldQueue>
 */
function mybLineJobs(): array
{
    return [
        'the acknowledgement' => fn (int $tenant, string $uuid): ShouldQueue => new SendAcknowledgement($tenant, $uuid, str_repeat('ab', 32)),
        'the confirmation' => fn (int $tenant, string $uuid): ShouldQueue => new SendConfirmation($tenant, $uuid, str_repeat('ab', 32)),
        'the approval' => fn (int $tenant, string $uuid): ShouldQueue => new SendRequestApproved($tenant, $uuid),
        'the approval awaiting payment' => fn (int $tenant, string $uuid): ShouldQueue => new SendRequestApproved($tenant, $uuid, awaitingPayment: true),
        'the rejection' => fn (int $tenant, string $uuid): ShouldQueue => new SendRequestRejected($tenant, $uuid),
        'the cancellation' => fn (int $tenant, string $uuid): ShouldQueue => new SendCancellation($tenant, $uuid, false, true),
        'the move' => fn (int $tenant, string $uuid): ShouldQueue => new SendMove($tenant, $uuid, false, true, '2026-11-02 08:00:00.000000+00:00'),
        'the reminder' => function (int $tenant, string $uuid): ShouldQueue {
            $reader = app(BookingReader::class);
            $booking = $reader->snapshot(mybTenant(), $uuid) ?? throw new LogicException('No booking '.$uuid);

            return new SendReminder($tenant, $uuid, SendReminder::instant($booking->startsAt()), $reader->revision(mybTenant(), $uuid));
        },
        'the timeout' => fn (int $tenant, string $uuid): ShouldQueue => new SendRequestExpired($tenant, $uuid),
        'the unpaid lapse' => fn (int $tenant, string $uuid): ShouldQueue => new SendRequestExpired($tenant, $uuid, unpaid: true),
        'the thank-you' => fn (int $tenant, string $uuid): ShouldQueue => new SendThankYou($tenant, $uuid),
    ];
}

it('closes {0} on the My bookings page while the bookings link is on, and on nothing while it is off', function (string $form) {
    $uuid = $form === 'the thank-you' ? mybBooking(MYB_CASEY, '2026-11-01 08:00:00', 'completed') : mybBooking(MYB_CASEY, '2026-11-03 08:00:00');
    $tenant = mybTenant();
    // Under the prefix of the booking's language, English here, as the manage and payment
    // links are.
    $page = app(TenantUrls::class)->myBookings(Slug::of('luna'), SiteLanguage::English);
    $line = customerMailLine(MyBookingsLine::LINE, ['link' => $page]);

    Bus::dispatchSync(mybLineJobs()[$form]($tenant->id, $uuid));
    $on = (string) seamAt(MYB_CASEY)->getTextBody();

    seamTransport()->flush();
    seamSwitchOff([MessageKey::MyBookingsLink->value]);
    Bus::dispatchSync(mybLineJobs()[$form]($tenant->id, $uuid));
    $off = (string) seamAt(MYB_CASEY)->getTextBody();

    expect(rtrim($on))->toEndWith("\n\n".$line)
        ->and(substr_count($on, $line))->toBe(1)
        ->and($off)->not->toContain($line)
        ->and($off)->not->toContain($page);
})->with(fn () => array_keys(mybLineJobs()));

it('closes no business message on the page: the business has the admin', function () {
    $uuid = mybBooking(MYB_CASEY, '2026-11-03 08:00:00');

    Bus::dispatchSync(new SendNewBookingNotice(mybTenant()->id, $uuid));

    $text = (string) seamAt('owner@luna.example')->getTextBody();

    expect($text)->not->toContain(customerMailLine(MyBookingsLine::LINE, ['link' => app(TenantUrls::class)->myBookings(Slug::of('luna'), SiteLanguage::English)]))
        ->and($text)->not->toContain(customerMailLine(MyBookingsLine::LINE, ['link' => app(TenantUrls::class)->myBookings(Slug::of('luna'))]))
        ->and($text)->not->toContain('/my-bookings');
});
