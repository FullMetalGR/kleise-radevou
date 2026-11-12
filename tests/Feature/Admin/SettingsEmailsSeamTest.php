<?php

use App\Application\Clock;
use App\Http\Controllers\Admin\SettingsController;
use App\Models\User;
use App\Tenancy\Profile;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\FixedClock;

pest()->use(DatabaseTruncation::class);

/*
 * The Emails section's write as the seam feels it: a key switched off through PUT
 * /admin/settings is honoured by the very next send in the same process, because the seam reads
 * the settings row at send time and caches nothing. Not wrapped in a transaction: the hold and
 * the confirm enter the transaction runner, which refuses to open inside another. The seam
 * helpers live in tests/Pest.php.
 */

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00:00'));
    seamTransport()->flush();
    settingsOf('luna')->update(['reminder_lead_hours' => 0]);
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

it('honours a message switched off through the settings save on the next send, with no restart between', function () {
    [$url, $body] = seamHold('Haircut');
    $held = seamHeld($this->postJson($url, $body));
    seamConfirm($this, $held['uuid'], $held['token']);
    expect(seamAddresses())->toEqualCanonicalizing(['owner@luna.example', 'perpetua.vandersnoot@example.test']);
    seamTransport()->flush();

    $owner = User::query()->where('email', 'owner@luna.example')->sole();
    $patch = ['currency' => 'EUR', 'theme' => 'violet', 'checkout_ttl_min' => 15, 'approval_ttl_hours' => 48, 'payment_ttl_hours' => 24, 'reminder_lead_hours' => 0, 'start_interval_min' => 15, 'emails_off' => ['confirmed'], ...array_fill_keys(Profile::FIELDS, ''), 'country' => 'GR'];
    $this->actingAs($owner)->from(tenantUrl('luna', '/admin/settings'))->json('PUT', tenantUrl('luna', '/admin/settings'), $patch, ['X-Inertia' => 'true'])
        ->assertRedirect(tenantUrl('luna', '/admin/settings'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => __(SettingsController::SAVED)]);

    [$url, $body] = seamHold('Haircut', '2026-11-03 11:00');
    $held = seamHeld($this->postJson($url, $body));
    seamConfirm($this, $held['uuid'], $held['token']);
    expect(seamAddresses())->toBe(['owner@luna.example']);
    seamTransport()->flush();

    $this->actingAs($owner)->from(tenantUrl('luna', '/admin/settings'))->json('PUT', tenantUrl('luna', '/admin/settings'), [...$patch, 'emails_off' => []], ['X-Inertia' => 'true'])->assertRedirect();

    [$url, $body] = seamHold('Haircut', '2026-11-03 12:00');
    $held = seamHeld($this->postJson($url, $body));
    seamConfirm($this, $held['uuid'], $held['token']);
    expect(seamAddresses())->toEqualCanonicalizing(['owner@luna.example', 'perpetua.vandersnoot@example.test']);
});
