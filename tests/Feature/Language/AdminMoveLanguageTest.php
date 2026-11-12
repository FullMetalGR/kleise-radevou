<?php

use App\Application\ChainUnavailable;
use App\Application\Clock;
use App\Http\ErrorEnvelope;
use App\Http\MoveRefusal;
use App\Models\User;
use App\Site\SiteLanguage;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\Support\LunaFixture;

/*
 * The api.php keys of the admin's move and of the blocked hold, walked in both languages by real
 * requests on the demo: the admin's move, POST /admin/bookings/{uuid}/move and its read GET
 * /admin/api/bookings/{uuid}/move, under a Greek user and an English one, and the hold of an
 * address the business blocked, POST /api/v1/holds under lang=el and lang=en. Each answer's
 * detail is the line of the language asked, read through the translator, the Greek differing from
 * the English with nothing left unfilled, the status and the code unchanged. ApiLanguageTest
 * walks the rest of the file and holds the two walks together to its keys.
 *
 * The move's write enters the transaction runner, so the file truncates rather than wrapping each
 * test in a transaction, and seeds the demo afresh; every request below is refused and writes
 * nothing, so asking each twice is no change. luna is in Europe/Athens, on a five-minute grid;
 * the clock stands on Monday 2026-11-02 at 08:00 Athens, and the booking moved is Elena's Haircut
 * on the Tuesday at 10:00 (she works 09:00 to 13:00 there).
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

/** luna's owner, the language given chosen as theirs. */
function amlOwner(SiteLanguage $language): User
{
    DB::table('users')->where('email', 'owner@luna.example')->update(['locale' => $language->value]);

    return User::query()->where('email', 'owner@luna.example')->sole();
}

/** An Athens wall clock as the wire spells an instant. */
function amlAt(string $athens): string
{
    return LunaFixture::at($athens)->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::RFC3339);
}

/**
 * An ask made while the catalog is changed for it, the change put back after it, so the twin
 * asks meet the same rows.
 *
 * @param  Closure(SiteLanguage): TestResponse<Response>  $ask
 * @return Closure(SiteLanguage): TestResponse<Response>
 */
function amlWhile(Closure $change, Closure $back, Closure $ask): Closure
{
    return function (SiteLanguage $language) use ($change, $back, $ask): TestResponse {
        $change();

        try {
            return $ask($language);
        } finally {
            $back();
        }
    };
}

it('walks the admin\'s move and the blocked hold in Greek under a Greek user and lang=el, each detail its Greek line, the code and status unchanged, and in English under an English one', function () {
    $uuid = LunaFixture::booking('confirmed', start: '2026-11-03 10:00');
    $bridal = LunaFixture::booking('confirmed', start: '2026-11-04 10:00', service: 'Bridal trial');
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $haircut = LunaFixture::id('services', 'Haircut');
    DB::table('tenant_luna.customer_profiles')->insert([
        'email' => 'casey@example.com', 'blocked_at' => '2026-11-01 09:00:00+00:00', 'blocked_reason' => 'Three no-shows',
        'created_at' => '2026-11-01 09:00:00+00:00', 'updated_at' => '2026-11-01 09:00:00+00:00',
    ]);

    $press = fn (string $booking, array $body): Closure => fn (SiteLanguage $language): TestResponse => $this->actingAs(amlOwner($language))
        ->from(tenantUrl('luna', '/admin/bookings/'.$booking))
        ->post(tenantUrl('luna', '/admin/bookings/'.$booking.'/move'), $body);
    $read = fn (array $query): Closure => fn (SiteLanguage $language): TestResponse => $this->actingAs(amlOwner($language))
        ->getJson(tenantUrl('luna', '/admin/api/bookings/'.$uuid.'/move?'.http_build_query($query)));
    $at = fn (string $athens, array $more = []): array => ['start' => amlAt($athens), ...$more];
    $assignment = ['service_id' => $haircut, 'staff_id' => $elena];
    $asked = [
        'envelope.unaccepted_warning' => $press($uuid, $at('2026-11-03 13:00')),
        'envelope.blocked' => fn (SiteLanguage $language): TestResponse => $this->postJson(tenantUrl('luna', '/api/v1/holds?lang='.$language->value), [
            'customer_name' => LunaFixture::CUSTOMER_NAME,
            'customer_email' => 'casey@example.com',
            'items' => [['service_id' => $haircut, 'staff_id' => $elena]],
            'start' => amlAt('2026-11-03 11:00'),
        ]),
        'move.past' => $press($uuid, $at('2026-11-02 07:00')),
        'move.off_grid' => $press($uuid, $at('2026-11-03 11:02')),
        'move.service_gone' => amlWhile(
            fn () => DB::table('tenant_luna.services')->where('id', $haircut)->update(['status' => 'inactive']),
            fn () => DB::table('tenant_luna.services')->where('id', $haircut)->update(['status' => 'active']),
            $press($uuid, $at('2026-11-03 11:00')),
        ),
        'move.staff_gone' => amlWhile(
            fn () => DB::table('tenant_luna.service_staff')->where($assignment)->delete(),
            fn () => DB::table('tenant_luna.service_staff')->insert($assignment),
            $press($uuid, $at('2026-11-03 11:00')),
        ),
        'move.staff_not_eligible' => $press($bridal, $at('2026-11-04 10:00', ['staff' => [0 => $nikos]])),
        'move.no_such_segment' => $press($uuid, $at('2026-11-03 11:00', ['staff' => [3 => $nikos]])),
        'validation.move.staff' => $press($uuid, $at('2026-11-03 11:00', ['staff' => ['x' => $nikos]])),
        'validation.move.warning_accepted' => $press($uuid, $at('2026-11-03 11:00', ['warning_accepted' => 'maybe'])),
        'validation.move.tell_customer' => $press($uuid, $at('2026-11-03 11:00', ['tell_customer' => 'maybe'])),
        'validation.move.date' => $read(['date' => '3 Nov']),
        'validation.move.one' => $read([]),
    ];
    expect(array_keys($asked))->toEqualCanonicalizing(apiKeysWalkedOnTheDemo());
    $rows = DB::table('tenant_luna.booking_items')->orderBy('id')->get(['id', 'staff_id', 'start_utc'])->all();
    $bookings = DB::table('tenant_luna.bookings')->count();

    foreach ($asked as $key => $ask) {
        Cache::flush();
        $greek = $ask(SiteLanguage::Greek);
        $english = $ask(SiteLanguage::English);

        expect($greek->json('data.detail'))->toBe(apiSentence('api.'.$key, [], SiteLanguage::Greek), $key)
            ->and($english->json('data.detail'))->toBe(apiSentence('api.'.$key), $key)
            ->and($greek->getStatusCode())->toBe($english->getStatusCode(), $key)
            ->and($greek->getStatusCode())->toBeGreaterThanOrEqual(400, $key)
            ->and($greek->json('code'))->toBe($english->json('code'), $key)
            ->and($greek->json('code'))->toBeString();
    }

    foreach (array_keys($asked) as $key) {
        $line = apiSentence('api.'.$key, [], SiteLanguage::Greek);

        expect($line)->not->toBe(apiSentence('api.'.$key), $key)
            ->and(unfilledIn($line))->toBe([], $key)
            ->and(linesShownIn($line, 'api', SiteLanguage::English))->toBe([], $key);
    }

    // The read's verdict on a start the write refuses carries the write's sentence, in the user's
    // language as well.
    $verdict = fn (SiteLanguage $language): mixed => $read(['start' => amlAt('2026-11-02 07:00')])($language)->assertOk()->json('verdict.detail');

    expect($verdict(SiteLanguage::Greek))->toBe(apiSentence('api.move.past', [], SiteLanguage::Greek))
        ->and($verdict(SiteLanguage::English))->toBe(apiSentence('api.move.past'));

    // Nothing moved and nothing was held.
    expect(DB::table('tenant_luna.booking_items')->orderBy('id')->get(['id', 'staff_id', 'start_utc'])->all())->toEqual($rows)
        ->and(DB::table('tenant_luna.bookings')->count())->toBe($bookings);
});

test('a refusal by the lead time or the horizon reads the general sentence in both languages', function () {
    // The business's move is bound by neither rule (MoveBookingTest), so no request reaches either;
    // the refusals are built where the planner would build them. The past keeps its own sentence,
    // so the case cannot pass by reading nothing.
    $refusals = [
        ChainUnavailable::LEAD_TIME => ChainUnavailable::insideLeadTime(new DateTimeImmutable('2026-11-02 08:00:00', new DateTimeZone('UTC'))),
        ChainUnavailable::HORIZON => ChainUnavailable::pastHorizon('2027-11-02'),
        ChainUnavailable::PAST => ChainUnavailable::inThePast(new DateTimeImmutable('2026-11-02 06:00:00', new DateTimeZone('UTC'))),
    ];
    $read = 0;

    foreach ([SiteLanguage::Greek, SiteLanguage::English] as $language) {
        app()->setLocale($language->value);

        foreach ($refusals as $rule => $refusal) {
            $envelope = MoveRefusal::for($refusal);
            $key = $rule === ChainUnavailable::PAST ? 'api.move.'.ChainUnavailable::PAST : ErrorEnvelope::UNAVAILABLE_DETAIL;

            expect($envelope->detail)->toBe(apiSentence($key, [], $language), $rule)
                ->and($envelope->data['rule'])->toBe($rule)
                ->and($envelope->status)->toBe(409);
            $read++;
        }
    }

    expect($read)->toBe(6)
        ->and(apiSentence(ErrorEnvelope::UNAVAILABLE_DETAIL, [], SiteLanguage::Greek))->not->toBe(apiSentence(ErrorEnvelope::UNAVAILABLE_DETAIL));
});
