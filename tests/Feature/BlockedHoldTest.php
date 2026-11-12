<?php

use App\Application\Clock;
use App\Http\ErrorEnvelope;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\FixedClock;
use Tests\Support\LunaFixture;

/*
 * An address the business blocked from online booking is refused at the hold, POST /api/v1/holds,
 * with one sentence that says only that booking online is not available, the code blocked and the
 * status 403, and nothing of the block, its reason or the profile in the answer; nothing is
 * written. The address is matched as citext compares it, so a capital typed on the booking page
 * changes nothing. An address with no block, or one unblocked, holds as before, and the admin's
 * wizard, POST /admin/bookings, still books a blocked address.
 *
 * The holds enter the transaction runner, so this file truncates rather than wrapping each test
 * in a transaction, and seeds the demo afresh. luna is in Europe/Athens; 2026-11-03 is a Tuesday,
 * when Elena works from 09:00, and the clock stands on the Monday before.
 */

pest()->use(DatabaseTruncation::class);

beforeEach(function () {
    $this->withoutVite();
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00:00'));
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * A hold's or the wizard's body on luna: one Haircut on Elena on the Tuesday, at 10:00 or the time
 * given, under the address given.
 *
 * @return array<string, mixed>
 */
function blockedHoldBody(string $email, string $at = '10:00'): array
{
    return [
        'customer_name' => LunaFixture::CUSTOMER_NAME,
        'customer_email' => $email,
        'items' => [['service_id' => LunaFixture::id('services', 'Haircut'), 'staff_id' => LunaFixture::id('staff', 'Elena Papadaki')]],
        'start' => LunaFixture::at('2026-11-03 '.$at)->format(DateTimeImmutable::RFC3339),
    ];
}

function blockedHoldBlock(string $email, ?string $reason = 'Three no-shows, never paid'): void
{
    DB::table('tenant_luna.customer_profiles')->insert([
        'email' => $email, 'tags' => '{VIP}', 'blocked_at' => '2026-11-01 09:00:00+00:00', 'blocked_reason' => $reason,
        'created_at' => '2026-11-01 09:00:00+00:00', 'updated_at' => '2026-11-01 09:00:00+00:00',
    ]);
}

it('refuses the hold of a blocked address with the one sentence and nothing else, writing nothing, whatever the case typed', function () {
    blockedHoldBlock('casey@example.com');

    foreach (['casey@example.com', 'Casey@EXAMPLE.com'] as $typed) {
        $this->postJson(tenantUrl('luna', '/api/v1/holds'), blockedHoldBody($typed))->assertStatus(403)->assertExactJson([
            'code' => 'blocked',
            'message' => 'blocked',
            'data' => ['status' => 403, 'detail' => apiSentence(ErrorEnvelope::BLOCKED_DETAIL)],
        ]);
    }

    expect(DB::table('tenant_luna.bookings')->count())->toBe(0)
        ->and(apiSentence(ErrorEnvelope::BLOCKED_DETAIL))->not->toContain('Three no-shows');
});

it('holds an address with no block, one whose profile carries none, and one unblocked, as before', function () {
    DB::table('tenant_luna.customer_profiles')->insert([
        'email' => 'ada@example.test', 'tags' => '{VIP}', 'created_at' => '2026-11-01 09:00:00+00:00', 'updated_at' => '2026-11-01 09:00:00+00:00',
    ]);
    blockedHoldBlock('casey@example.com');
    DB::table('tenant_luna.customer_profiles')->where('email', 'casey@example.com')->update(['blocked_at' => null, 'blocked_reason' => null]);

    $this->postJson(tenantUrl('luna', '/api/v1/holds'), blockedHoldBody('ada@example.test'))->assertCreated();
    $this->postJson(tenantUrl('luna', '/api/v1/holds'), blockedHoldBody('casey@example.com', '11:00'))->assertCreated();
    $this->postJson(tenantUrl('luna', '/api/v1/holds'), blockedHoldBody('nobody@example.test', '12:00'))->assertCreated();

    expect(DB::table('tenant_luna.bookings')->count())->toBe(3);
});

it('books a blocked address from the admin\'s wizard, the manual booking landing confirmed', function () {
    blockedHoldBlock('casey@example.com');
    $owner = User::query()->where('email', 'owner@luna.example')->sole();

    $this->actingAs($owner)->from(tenantUrl('luna', '/admin/bookings'))
        ->postJson(tenantUrl('luna', '/admin/bookings'), blockedHoldBody('casey@example.com'))
        ->assertRedirect(tenantUrl('luna', '/admin/bookings'));

    expect(DB::table('tenant_luna.bookings')->where('customer_email', 'casey@example.com')->value('status'))->toBe('confirmed');
});
