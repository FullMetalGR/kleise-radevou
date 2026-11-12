<?php

use App\Http\Controllers\Admin\BookingActionController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;

pest()->use(DatabaseTruncation::class);

/*
 * An action from the booking dialog over a customer's page: the press posts to the dialog's own
 * route, the redirect lands back on the customer's page, and what the page shows arrives re-read
 * from the server: the table's row on the redirect's page, and the dialog's detail, the actions
 * left among it, on the partial reload the page then makes for the booking it has open.
 *
 * The use case enters the transaction runner, so this file truncates rather than wrapping each
 * test in a transaction, and seeds the demo afresh. The fixture's booking starts on the fixture's
 * date, so the clock is pinned 26 hours before it and the cancel stands inside no policy window.
 */

beforeEach(function () {
    $this->withoutVite();
    dropDemoSchemas();
    seedDemo();
    FixtureDay::pinClock();
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

it('cancels a booking from the dialog over a customer\'s page and lands back on the page with the row and the dialog\'s detail re-read', function () {
    $uuid = LunaFixture::booking('confirmed', blocks: false);
    $owner = User::query()->where('email', 'owner@luna.example')->sole();
    $page = tenantUrl('luna', '/admin/customers/'.rawurlencode(LunaFixture::CUSTOMER_EMAIL));
    $version = (string) app(HandleInertiaRequests::class)->version(request());
    $detail = ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'Customer', 'X-Inertia-Partial-Data' => 'detail'];

    $this->actingAs($owner)->get($page.'?booking='.$uuid, $detail)
        ->assertJsonPath('props.detail.booking.status', 'confirmed')
        ->assertJsonPath('props.detail.actions', ['cancel']);

    $this->from($page)->post(tenantUrl('luna', '/admin/bookings/'.$uuid.'/cancel'))
        ->assertRedirect($page);

    $this->get($page)
        ->assertOk()
        ->assertInertia(fn (Assert $props) => $props->component('Customer')
            ->where('flash', ['kind' => 'success', 'message' => __(BookingActionController::CANCELLED)])
            ->where('customer.bookings', 1)
            ->where('bookings.rows.0.uuid', $uuid)
            ->where('bookings.rows.0.status', 'cancelled')
            ->where('detail', null));

    $this->get($page.'?booking='.$uuid, $detail)
        ->assertJsonPath('props.detail.booking.status', 'cancelled')
        ->assertJsonPath('props.detail.actions', [])
        ->assertJsonPath('props.detail.trail.0.action', 'cancelled');

    expect(LunaFixture::row($uuid)['status'])->toBe('cancelled');
});
