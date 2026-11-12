<?php

use App\Http\Controllers\Admin\BookingActionController;
use App\Notifications\ThankYou;
use App\Site\SiteLanguage;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\FlowScripts;

/*
 * The thank-you in a real browser: the owner completes a booking that has begun from the booking
 * dialog, and the customer's thank-you, read from the test process, which serves the application
 * and holds the array transport phpunit.xml names, carries the tenant's Google reviews link under
 * "Leave us a review" and Book again; Book again opens the hosted page on the booking's service and
 * member, so the flow starts at the time step.
 *
 * The booking is a row of luna's, a Haircut on Elena two days behind today on the system clock, as
 * every walk dates its rows. The bundle is the one npm run build wrote, which is why the build gate
 * precedes this one.
 */

const THANKS_WALK_CUSTOMER = 'tessa.thanked@example.test';

const THANKS_WALK_REVIEWS = 'https://g.page/r/luna-hair-studio/review';

beforeEach(function () {
    expect(is_file(public_path('build/embed.js')))->toBeTrue('public/build/embed.js is missing; run npm run build before the browser gate.');

    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
    seamTransport()->flush();
});

afterEach(fn () => dropDemoSchemas());

/**
 * A confirmed Haircut of Tessa's on Elena's row, forty-five minutes from the instant given.
 */
function thanksWalkBooking(DateTimeImmutable $start): void
{
    $end = $start->add(new DateInterval('PT45M'));
    $utc = fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'hosted',
        'uuid' => (string) Str::uuid(), 'status' => 'confirmed', 'customer_name' => 'Tessa Thanked',
        'customer_email' => THANKS_WALK_CUSTOMER, 'total_minor' => 3500, 'currency' => 'EUR',
        'payment_mode' => 'onsite', 'requires_approval' => false, 'on_approval_timeout' => 'expire',
    ]);
    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $id, 'sort' => 0,
        'service_id' => seamId('services', 'Haircut'),
        'staff_id' => seamId('staff', 'Elena Papadaki'),
        'start_utc' => $utc($start), 'end_utc' => $utc($end),
        'block_start_utc' => $utc($start), 'block_end_utc' => $utc($end),
        'price_minor' => 3500, 'blocks' => true,
    ]);
}

it('completes a begun booking from the dialog and thanks the customer with the reviews link and Book again, which opens the flow at the time step on that service and member', function () {
    settingsOf('luna')->update(['google_reviews_url' => THANKS_WALK_REVIEWS]);
    thanksWalkBooking(athensDaysOn(-2));

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->click(navLabel('bookings'))->waitForText(adminLine('admin/bookings.table.count', ['count' => '1', 'total' => '1']));

    $page->click('Tessa Thanked')
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->assertSeeIn('.admin-booking-actions', adminLine('admin/bookings.actions.complete'))
        ->click('.admin-booking-action--complete')
        ->waitForText(adminLine(BookingActionController::COMPLETED))
        ->assertSeeIn('.admin-booking-facts .admin-chip', adminLine('admin/shell.kit.statuses.completed'))
        ->assertCount('.admin-booking-actions', 0)
        ->assertNoJavaScriptErrors();

    $urls = app(TenantUrls::class);
    // Book again and My bookings under the prefix of the booking's language, English here.
    $again = $urls->bookingLink(Slug::of('luna'), ['service' => seamId('services', 'Haircut'), 'staff' => seamId('staff', 'Elena Papadaki')], SiteLanguage::English);
    $message = seamAt(THANKS_WALK_CUSTOMER);
    $text = (string) $message->getTextBody();

    expect(DB::table('tenant_luna.bookings')->pluck('status')->all())->toBe(['completed'])
        ->and(seamAddresses())->toBe([THANKS_WALK_CUSTOMER])
        ->and($message->getSubject())->toBe(customerMailLine(ThankYou::SUBJECT, ['business' => 'Luna Hair Studio']))
        ->and($text)->toContain(customerMailLine('customer-mail.greeting', ['customer' => 'Tessa Thanked']))
        ->and($text)->toContain('    '.decisionLine('decision.facts.item', ['service' => 'Haircut', 'staff' => 'Elena Papadaki']))
        ->and($text)->toContain(customerMailLine(ThankYou::REVIEW_LINE, ['link' => THANKS_WALK_REVIEWS]))
        ->and($text)->toContain(customerMailLine('customer-mail.book_again', ['link' => $again]))
        ->and(seamLinksIn($text))->toBe([THANKS_WALK_REVIEWS, $again, $urls->myBookings(Slug::of('luna'), SiteLanguage::English)]);

    // The link the customer follows: the hosted page with the service and the member written onto
    // the mount, so the flow opens past both pickers on the time step.
    $page->navigate($again)
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript(FlowScripts::STEP, 'when')
        ->assertNoJavaScriptErrors();
});
