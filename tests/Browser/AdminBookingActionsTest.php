<?php

use App\Http\Controllers\Admin\BookingActionController;
use App\Http\ErrorEnvelope;
use App\Site\SiteLanguage;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\Look;
use Tests\Browser\Support\PhoneScripts;

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

/**
 * Escape closes the layer on top and no other, because the library that draws the dialogs keeps
 * one listener for the key on the document and hands it to the top layer. The hand-over comes a
 * moment after a layer opens over another, later than anything the page shows of the new layer,
 * and a press in that moment goes to the layer under it, which closes with all that is on it.
 *
 * The library settles in one pass which layer takes the pointer and which holds the key: the
 * first it writes on the layer's own element at once, the second it acts on afterwards. So this
 * notes, each time the document is given such a listener, the layer whose element reads as
 * taking the pointer just then, which is the layer the listener belongs to.
 */
const ESCAPE_WATCH = <<<'JS'
    (() => {
        const listen = document.addEventListener;
        document.addEventListener = function (type, listener, options) {
            listen.call(this, type, listener, options);
            if (type === 'keydown' && (options === true || options?.capture === true)) {
                window.escapeHolder = Array.from(document.querySelectorAll('[role="dialog"]')).filter((layer) => layer.style.pointerEvents === 'auto').pop() ?? null;
            }
        };
        return true;
    })()
    JS;

/**
 * Resolves true once the layer around the element given holds Escape, by the note the watch
 * above keeps; false after five seconds, which is a failure worth reading.
 */
function escapeHeldAround(string $selector): string
{
    $selector = json_encode($selector, JSON_THROW_ON_ERROR);

    return <<<JS
        new Promise((resolve) => {
            const started = Date.now();
            const check = () => {
                const layer = document.querySelector({$selector})?.closest('[role="dialog"]') ?? null;
                if (layer !== null && window.escapeHolder === layer) {
                    resolve(true);
                } else if (Date.now() - started > 5000) {
                    resolve(false);
                } else {
                    setTimeout(check, 25);
                }
            };
            check();
        })
        JS;
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
        ->assertSeeIn('.admin-booking-status .admin-chip', adminLine('admin/shell.kit.statuses.awaiting_approval'))
        ->assertSeeIn('.admin-booking-actions', adminLine('admin/bookings.actions.approve'))
        ->assertSeeIn('.admin-booking-actions', adminLine('admin/bookings.actions.reject'))
        ->assertCount('.admin-booking-trail__entry', 0)
        ->click('.admin-booking-action--approve')
        ->waitForText(adminLine(BookingActionController::APPROVED))
        ->assertSeeIn('.admin-booking-status .admin-chip', adminLine('admin/shell.kit.statuses.confirmed'))
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
        ->assertSeeIn('.admin-booking-status .admin-chip', adminLine('admin/shell.kit.statuses.rejected'))
        ->assertSeeIn('.admin-booking-facts__reason', 'Fully booked that week')
        ->assertCount('.admin-rail-pending', 0);

    $page->click('.admin-booking-close')->waitForText(adminLine('admin/bookings.table.count', ['count' => '4', 'total' => '4']));

    $page->click('Cora Cancelled')
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->click('.admin-booking-action--cancel')
        ->waitForText(adminLine('admin/bookings.cancel.warning'))
        ->click('.admin-cancel-prompt__confirm')
        ->waitForText(adminLine(BookingActionController::CANCELLED))
        ->assertSeeIn('.admin-booking-status .admin-chip', adminLine('admin/shell.kit.statuses.cancelled'))
        ->assertCount('.admin-booking-actions', 0);

    $page->click('.admin-booking-close')->waitForText(adminLine('admin/bookings.table.count', ['count' => '4', 'total' => '4']));

    $page->click('Paul Past')
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->assertSeeIn('.admin-booking-actions', adminLine('admin/bookings.actions.complete'))
        ->assertSeeIn('.admin-booking-actions', adminLine('admin/bookings.actions.no_show'))
        ->click('.admin-booking-action--complete')
        ->waitForText(adminLine(BookingActionController::COMPLETED))
        ->assertSeeIn('.admin-booking-status .admin-chip', adminLine('admin/shell.kit.statuses.completed'))
        ->assertCount('.admin-booking-actions', 0)
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenant_luna.bookings')->orderBy('id')->pluck('status')->all())
        ->toBe(['confirmed', 'rejected', 'cancelled', 'completed']);
});

it('peels the reject overlay with one Escape and leaves the dialog under it', function () {
    actionBooking('Rita Request', 'awaiting_approval', athensDaysOn(61));

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->click(navLabel('bookings'))->waitForText(adminLine('admin/bookings.table.count', ['count' => '1', 'total' => '1']));

    $page->click('Rita Request')->waitForText(adminLine('admin/bookings.dialog.items.title'));
    $page->script(ESCAPE_WATCH);

    // Each press waits for the key to be the layer's it is meant for: the overlay's once it is
    // up, the dialog's again once the overlay is gone.
    $page->click('.admin-booking-action--reject')
        ->waitForText(adminLine('admin/bookings.reject.title'))
        ->assertScript(escapeHeldAround('#reject-reason'), true)
        ->keys('#reject-reason', 'Escape')
        ->assertCount('.admin-reject-prompt', 0)
        ->assertCount('.admin-booking-dialog', 1)
        ->assertScript(escapeHeldAround('.admin-booking-close'), true)
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
        ->assertSeeIn('.admin-booking-status .admin-chip', adminLine('admin/shell.kit.statuses.awaiting_approval'))
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

/**
 * The dialog's when panel as the browser draws it: whether its time is struck through, and
 * whether the panel sits on a fill of its own rather than the dialog's.
 */
const WHEN_LOOK = <<<'JS'
    document.fonts.ready.then(() => {
        const panel = document.querySelector('.admin-booking-dialog .admin-booking-facts');
        const time = panel?.querySelector('.admin-booking-facts__time');
        if (!panel || !time) {
            return null;
        }
        const frame = getComputedStyle(document.querySelector('.admin-booking-dialog')).backgroundColor;
        return [getComputedStyle(time).textDecorationLine.includes('line-through'), getComputedStyle(panel).backgroundColor !== frame];
    })
    JS;

/**
 * True when the dialog's footer holds its buttons on one line, the state's actions starting at
 * the footer's start edge and Move and Close ending at its end edge.
 */
const FOOTER_SPLIT = <<<'JS'
    document.fonts.ready.then(() => {
        const footer = document.querySelector('.admin-booking-dialog > .admin-modal__footer');
        const neutral = footer?.querySelector('.admin-booking-neutral');
        if (!footer || !neutral) {
            return false;
        }
        const style = getComputedStyle(footer);
        const box = footer.getBoundingClientRect();
        const start = box.left + parseFloat(style.paddingLeft);
        const end = box.right - parseFloat(style.paddingRight);
        const actions = footer.querySelector('.admin-booking-actions');
        const tops = new Set(Array.from(footer.querySelectorAll('button')).map((button) => Math.round(button.getBoundingClientRect().top)));
        return tops.size === 1 && Math.abs(neutral.getBoundingClientRect().right - end) <= 1
            && (actions === null || Math.abs(actions.getBoundingClientRect().left - start) <= 1);
    })
    JS;

/**
 * True when every button of the dialog's footer is at least 44 pixels tall and holds its label
 * inside itself, no line holds more than two, at least one line holds two, and each button takes
 * at least half the footer's row less half the gap between two.
 */
const FOOTER_TWO_TO_A_LINE = <<<'JS'
    document.fonts.ready.then(() => {
        const footer = document.querySelector('.admin-booking-dialog > .admin-modal__footer');
        const buttons = Array.from(footer?.querySelectorAll('button') ?? []);
        if (!footer || buttons.length < 2) {
            return false;
        }
        const style = getComputedStyle(footer);
        const room = footer.getBoundingClientRect().width - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
        const lines = new Map();
        buttons.forEach((button) => {
            const top = Math.round(button.getBoundingClientRect().top);
            lines.set(top, [...(lines.get(top) ?? []), button.getBoundingClientRect()]);
        });
        return buttons.every((button) => button.getBoundingClientRect().height >= 43.5 && button.scrollWidth <= button.clientWidth + 1)
            && [...lines.values()].every((line) => line.length <= 2 && line.every((own) => own.width >= (room - 8) / 2 - 1))
            && [...lines.values()].some((line) => line.length === 2);
    })
    JS;

/** How many columns a grid of the dialog lays out, by the selector of the grid. */
function dialogColumns(string $selector): string
{
    $selector = json_encode('.admin-booking-dialog '.$selector, JSON_THROW_ON_ERROR);

    return "document.fonts.ready.then(() => getComputedStyle(document.querySelector({$selector})).gridTemplateColumns.split(' ').length)";
}

it('draws a booking of each state with its status beside the name, the time struck where the visit did not happen, a request on the attention fill, and the state\'s actions apart from Move and Close', function () {
    $states = [
        'Ava Asking' => ['awaiting_approval', 61, [false, true]],
        'Cleo Confirmed' => ['confirmed', 62, [false, false]],
        'Cass Cancelled' => ['cancelled', 63, [true, false]],
        'Rhea Rejected' => ['rejected', 64, [true, false]],
        'Coco Completed' => ['completed', -30, [false, false]],
        'Nora Noshow' => ['no_show', -31, [true, false]],
    ];
    foreach ($states as $customer => [$status, $days]) {
        actionBooking($customer, $status, athensDaysOn($days));
    }
    $count = adminLine('admin/bookings.table.count', ['count' => '6', 'total' => '6']);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->resize(1440, 900);
    $page->click(navLabel('bookings'))->waitForText($count);

    foreach ($states as $customer => [$status, , $look]) {
        $page->click($customer)
            ->waitForText(adminLine('admin/bookings.dialog.items.title'))
            ->assertSeeIn('.admin-modal__title', $customer)
            ->assertAttribute('.admin-modal__head .admin-booking-status .admin-chip', 'data-status', $status)
            ->assertScript(WHEN_LOOK, $look)
            ->assertScript(dialogColumns('.admin-booking-facts'), 3)
            ->assertScript(dialogColumns('.admin-booking-contact'), 3)
            ->assertScript(FOOTER_SPLIT, true)
            ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
        Look::shot($page, 'booking-dialog-'.$status.'-1440');
        $page->click('.admin-booking-close')->waitForText($count);
    }

    // The cancel confirm over a confirmed booking, kept, then the Move form in its place.
    $page->click('Cleo Confirmed')
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->click('.admin-booking-action--cancel')
        ->waitForText(adminLine('admin/bookings.cancel.warning'));
    Look::shot($page, 'booking-dialog-cancel-prompt-1440');

    $page->click('.admin-cancel-prompt__keep')
        ->assertMissing('.admin-cancel-prompt')
        ->assertScript(Admin::focusIsOn('.admin-booking-action--cancel'), true)
        ->click('.admin-booking-move')
        ->waitForText(adminLine('admin/bookings.move.save_why'))
        ->assertMissing('.admin-booking-facts')
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'booking-dialog-move-1440');

    $page->click('.admin-move__back')
        ->assertVisible('.admin-booking-facts')
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenant_luna.bookings')->orderBy('id')->pluck('status')->all())
        ->toBe(['awaiting_approval', 'confirmed', 'cancelled', 'rejected', 'completed', 'no_show']);
});

it('fits the dialog to a phone in Greek, its footer\'s buttons 44 pixels tall and two to a line with every label inside its button, and keeps it whole with the focus where it was as the window crosses the breakpoint and back', function () {
    actionBooking('Paula Past', 'confirmed', athensDaysOn(-30));
    // The labels are longer in Greek. The language is the owner's before the sign-in.
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => SiteLanguage::Greek->value]);
    $count = adminLine('admin/bookings.table.count', ['count' => '1', 'total' => '1'], SiteLanguage::Greek);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->resize(1440, 900);
    $page->navigate(Admin::url('luna', '/bookings'))
        ->waitForText($count)
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, SiteLanguage::Greek->value);

    $page->click('Paula Past')
        ->waitForText(adminLine('admin/bookings.dialog.items.title', [], SiteLanguage::Greek))
        ->assertSeeIn('.admin-booking-actions', adminLine('admin/bookings.actions.no_show', [], SiteLanguage::Greek))
        ->assertScript(FOOTER_SPLIT, true);
    Look::shot($page, 'booking-dialog-el-1440');

    $page->script("document.querySelector('.admin-booking-close').focus()");
    $page->resize(390, 844)
        ->assertScript(PhoneScripts::DIALOG_FILLS_SCREEN, true)
        ->assertScript(PhoneScripts::ITEMS_AS_ROWS, true)
        ->assertScript(PhoneScripts::DIALOG_NO_SIDEWAYS, true)
        ->assertScript(PhoneScripts::DIALOG_FOOTER_BUTTONS_INSIDE, true)
        ->assertScript(FOOTER_TWO_TO_A_LINE, true)
        ->assertScript(dialogColumns('.admin-booking-facts'), 2)
        ->assertScript(dialogColumns('.admin-booking-contact'), 1)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertScript(Admin::focusIsOn('.admin-booking-close'), true);
    Look::shot($page, 'booking-dialog-el-390');

    $page->resize(1440, 900)
        ->assertScript(FOOTER_SPLIT, true)
        ->assertScript(dialogColumns('.admin-booking-facts'), 3)
        ->assertScript(Admin::focusIsOn('.admin-booking-close'), true)
        ->keys('.admin-booking-close', 'Enter')
        ->waitForText($count)
        ->assertCount('.admin-booking-dialog', 0)
        ->assertNoJavaScriptErrors();
});

it('keeps the Move form\'s Back and Save two to a line in the dialog on a phone in Greek, each half the footer\'s row under the line saying why Save waits', function () {
    actionBooking('Mona Movable', 'confirmed', athensDaysOn(66));
    $el = SiteLanguage::Greek;
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => $el->value]);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->resize(390, 844);
    $page->navigate(Admin::url('luna', '/bookings'))
        ->waitForText(adminLine('admin/bookings.table.count', ['count' => '1', 'total' => '1'], $el))
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, $el->value);

    // Save is the longer word in Greek, and the two still halve the row here: the dialog's footer
    // gives their group its whole width.
    $page->click('Mona Movable')
        ->waitForText(adminLine('admin/bookings.dialog.items.title', [], $el))
        ->click('.admin-booking-move')
        ->waitForText(adminLine('admin/bookings.move.time_help', [], $el))
        ->assertSeeIn('.admin-move__why', adminLine('admin/bookings.move.save_why', [], $el))
        ->assertSeeIn('.admin-move__back', adminLine('admin/bookings.move.back', [], $el))
        ->assertSeeIn('.admin-move__save', adminLine('admin/bookings.move.save', [], $el))
        ->assertScript(PhoneScripts::DIALOG_FILLS_SCREEN, true)
        ->assertScript(FOOTER_TWO_TO_A_LINE, true)
        ->assertScript(PhoneScripts::DIALOG_FOOTER_BUTTONS_INSIDE, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->click('.admin-move__back')
        ->assertVisible('.admin-booking-facts')
        ->assertNoJavaScriptErrors();
});
