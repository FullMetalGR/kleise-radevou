<?php

use App\Http\Controllers\Admin\BookingActionController;
use App\Http\ErrorEnvelope;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Browser\Support\Admin;

/*
 * The booking dialog's actions in a real browser: an owner approves one request and rejects
 * another with a reason, cancels a confirmed booking and completes one that has begun, each
 * from the dialog, each answer arriving through the server's redirect and its flash, and the
 * rail's pending count following the requests the owner can still decide. A press the server
 * refuses answers an error toast in the server's sentence over the dialog, which stays open on
 * the booking as it was.
 *
 * The bookings are written straight to luna's rows. The requests hold their windows by the
 * database's clock, two days ahead. Every start is whole days from today, the upcoming ones
 * two months on and the past one thirty days behind, because the browser and the application
 * both read the real clock here.
 */

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

/**
 * One booking of luna on Elena's row: a request on an open approval window, or a confirmed
 * booking, starting at the instant given.
 */
function actionBooking(string $customer, string $status, DateTimeImmutable $start): void
{
    $end = $start->add(new DateInterval('PT45M'));
    $utc = fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    $request = $status === 'awaiting_approval';
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed',
        'uuid' => (string) Str::uuid(), 'status' => $status, 'customer_name' => $customer,
        'customer_email' => Str::slug($customer).'@example.test', 'total_minor' => 3500, 'currency' => 'EUR',
        'hold_class' => $request ? 'approval' : null,
        'hold_expires_at' => $request ? DB::raw("now() + interval '2 days'") : null,
        'payment_mode' => 'onsite', 'requires_approval' => $request, 'on_approval_timeout' => 'expire',
    ]);
    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $id, 'sort' => 0,
        'service_id' => DB::table('tenant_luna.services')->where('name', $request ? 'Bridal trial' : 'Haircut')->value('id'),
        'staff_id' => DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->value('id'),
        'start_utc' => $utc($start), 'end_utc' => $utc($end),
        'block_start_utc' => $utc($start), 'block_end_utc' => $utc($end),
        'price_minor' => 3500, 'blocks' => true,
    ]);
}

it('approves a request, rejects another with a reason, cancels a confirmed booking and completes a past one, all from the dialog', function () {
    actionBooking('Rita Request', 'awaiting_approval', athensDaysOn(61));
    actionBooking('Rhea Refused', 'awaiting_approval', athensDaysOn(62));
    actionBooking('Cora Cancelled', 'confirmed', athensDaysOn(63));
    actionBooking('Paul Past', 'confirmed', athensDaysOn(-30));

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->assertSeeIn('.admin-rail-pending', '2')
        ->click(navLabel('bookings'))
        ->waitForText(adminLine('admin/bookings.table.count', ['count' => '4', 'total' => '4']));

    $page->click('Rita Request')
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->assertSeeIn('.admin-booking-facts .admin-chip', adminLine('admin/shell.kit.statuses.awaiting_approval'))
        ->assertSeeIn('.admin-booking-actions', adminLine('admin/bookings.actions.approve'))
        ->assertSeeIn('.admin-booking-actions', adminLine('admin/bookings.actions.reject'))
        ->assertCount('.admin-booking-trail__entry', 0)
        ->click('.admin-booking-action--approve')
        ->waitForText(adminLine(BookingActionController::APPROVED))
        ->assertSeeIn('.admin-booking-facts .admin-chip', adminLine('admin/shell.kit.statuses.confirmed'))
        ->assertCount('.admin-booking-trail__entry', 1)
        ->assertDontSeeIn('.admin-booking-actions', adminLine('admin/bookings.actions.approve'))
        ->assertSeeIn('.admin-rail-pending', '1');

    $page->click('.admin-booking-close')->waitForText(adminLine('admin/bookings.table.count', ['count' => '4', 'total' => '4']));

    $page->click('Rhea Refused')
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->click('.admin-booking-action--reject')
        ->waitForText(adminLine('admin/bookings.reject.title'))
        ->type('#reject-reason', 'Fully booked that week')
        ->click('.admin-reject-prompt__confirm')
        ->waitForText(adminLine(BookingActionController::REJECTED))
        ->assertCount('.admin-reject-prompt', 0)
        ->assertSeeIn('.admin-booking-facts .admin-chip', adminLine('admin/shell.kit.statuses.rejected'))
        ->assertSeeIn('.admin-booking-facts__reason', 'Fully booked that week')
        ->assertCount('.admin-rail-pending', 0);

    $page->click('.admin-booking-close')->waitForText(adminLine('admin/bookings.table.count', ['count' => '4', 'total' => '4']));

    $page->click('Cora Cancelled')
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->click('.admin-booking-action--cancel')
        ->waitForText(adminLine('admin/bookings.cancel.warning'))
        ->click('.admin-cancel-prompt__confirm')
        ->waitForText(adminLine(BookingActionController::CANCELLED))
        ->assertSeeIn('.admin-booking-facts .admin-chip', adminLine('admin/shell.kit.statuses.cancelled'))
        ->assertCount('.admin-booking-actions', 0);

    $page->click('.admin-booking-close')->waitForText(adminLine('admin/bookings.table.count', ['count' => '4', 'total' => '4']));

    $page->click('Paul Past')
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->assertSeeIn('.admin-booking-actions', adminLine('admin/bookings.actions.complete'))
        ->assertSeeIn('.admin-booking-actions', adminLine('admin/bookings.actions.no_show'))
        ->click('.admin-booking-action--complete')
        ->waitForText(adminLine(BookingActionController::COMPLETED))
        ->assertSeeIn('.admin-booking-facts .admin-chip', adminLine('admin/shell.kit.statuses.completed'))
        ->assertCount('.admin-booking-actions', 0)
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenant_luna.bookings')->orderBy('id')->pluck('status')->all())
        ->toBe(['confirmed', 'rejected', 'cancelled', 'completed']);
});

it('peels the reject overlay with one Escape and leaves the dialog under it', function () {
    actionBooking('Rita Request', 'awaiting_approval', athensDaysOn(61));

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->click(navLabel('bookings'))->waitForText(adminLine('admin/bookings.table.count', ['count' => '1', 'total' => '1']));

    $page->click('Rita Request')
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->click('.admin-booking-action--reject')
        ->waitForText(adminLine('admin/bookings.reject.title'))
        ->keys('#reject-reason', 'Escape')
        ->assertCount('.admin-reject-prompt', 0)
        ->assertCount('.admin-booking-dialog', 1)
        ->keys('.admin-booking-close', 'Escape')
        ->wait(1)
        ->assertPathIs('/admin/bookings')
        ->assertCount('.admin-booking-dialog', 0);

    expect(DB::table('tenant_luna.bookings')->value('status'))->toBe('awaiting_approval');
});

it('answers a press on a request whose window closed after the dialog rendered with the server\'s sentence as an error toast, the dialog still open on the request as it was', function () {
    actionBooking('Rita Request', 'awaiting_approval', athensDaysOn(61));
    $uuid = DB::table('tenant_luna.bookings')->value('uuid');
    assert(is_string($uuid));

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->click(navLabel('bookings'))->waitForText(adminLine('admin/bookings.table.count', ['count' => '1', 'total' => '1']));

    $page->click('Rita Request')
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->assertSeeIn('.admin-booking-actions', adminLine('admin/bookings.actions.approve'));

    // The window closes between the render and the press, and the sweeper has not run: the row
    // still reads awaiting_approval, so only the guard under the lock can refuse the approval.
    DB::table('tenant_luna.bookings')->where('uuid', $uuid)->update(['hold_expires_at' => DB::raw("now() - interval '1 minute'")]);

    $page->click('.admin-booking-action--approve')
        ->waitForText(apiSentence(ErrorEnvelope::NOT_APPROVABLE_DETAIL))
        ->assertSeeIn('.admin-toast', apiSentence(ErrorEnvelope::NOT_APPROVABLE_DETAIL))
        ->assertAttribute('.admin-toast', 'role', 'alert')
        ->assertAttribute('.admin-toast', 'data-kind', 'error')
        ->assertCount('.admin-booking-dialog', 1)
        ->assertPathIs('/admin/bookings/'.$uuid)
        ->assertSeeIn('.admin-booking-facts .admin-chip', adminLine('admin/shell.kit.statuses.awaiting_approval'))
        ->assertCount('.admin-booking-trail__entry', 0);

    // The toast sits over the dialog's backdrop, not under it: the operator reads it and
    // dismisses it with the dialog still open, the error toast staying until then.
    $page->click('.admin-toast-dismiss')
        ->assertCount('.admin-toast', 0)
        ->assertCount('.admin-booking-dialog', 1)
        ->assertPathIs('/admin/bookings/'.$uuid)
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('status'))->toBe('awaiting_approval');
});
