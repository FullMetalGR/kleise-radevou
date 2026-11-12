<?php

use App\Application\ChainUnavailable;
use App\Application\Clock;
use App\Application\Dto\MoveChoices;
use App\Application\Event\BookingRescheduled;
use App\Application\MoverRefused;
use App\Application\RescheduleBooking;
use App\Application\StaffNotEligible;
use App\Application\TransitionRefused;
use App\Application\UnacceptedWarning;
use App\Domain\Audit\Actor;
use App\Domain\Booking\MaskCrossing;
use App\Domain\Booking\Overridable;
use App\Domain\Booking\SegmentConflict;
use App\Domain\Booking\TransitionRefusal;
use App\Http\BookingTrail;
use App\Infrastructure\BookingTransitionFactory;
use App\Infrastructure\Cache\StaffDayMaskCache;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\FixedClock;
use Tests\Support\LunaFixture;

pest()->use(DatabaseTruncation::class);

/*
 * The business moves a booking: RescheduleBooking with a signed-in user or support as the actor
 * and MoveChoices beside the start, over luna's real rows through the factory.
 *
 * Not wrapped in a transaction: every write here enters the transaction runner, which refuses to
 * open inside another. The mask cache is the null store, so every availability read comes from
 * the rows alone.
 *
 * luna is in Europe/Athens, UTC+2 in November. 2026-11-03 is a Tuesday and 2026-11-04 a
 * Wednesday; Elena works both, 09:00 to 13:00 and 14:00 to 18:00, and Nikos Tuesday to Saturday
 * 10:00 to 19:00. Haircut is 45 minutes and both perform it, Colour an hour and both perform it,
 * Bridal trial Elena's alone. The clock stands on the Monday before at 08:00 Athens.
 */

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    app()->instance(StaffDayMaskCache::class, coldMaskCache());
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00:00'));
    settingsOf('luna')->update(['reminder_lead_hours' => 0]);
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

function businessMoves(): RescheduleBooking
{
    return app(BookingTransitionFactory::class)->reschedule(LunaFixture::tenant());
}

/**
 * A booking held the way the booking page takes one and confirmed, with whatever it sent
 * cleared away.
 *
 * @param  non-empty-list<string>  $services
 */
function businessConfirmed(array $services = ['Haircut'], string $start = '2026-11-03 10:00', string $staff = 'Elena Papadaki'): string
{
    $uuid = LunaFixture::hold($services, $start, $staff);
    app(BookingTransitionFactory::class)->confirm(LunaFixture::tenant())->confirm($uuid, Actor::customer());
    seamTransport()->flush();

    return $uuid;
}

/**
 * The booking's items in chain order, each as the member, the service and the Athens range.
 *
 * @return list<string>
 */
function businessItems(string $uuid): array
{
    $athens = new DateTimeZone('Europe/Athens');
    $utc = new DateTimeZone('UTC');
    $items = [];

    foreach (DB::select(
        'select m.name as staff, s.name as service, i.start_utc::text as start_utc, i.end_utc::text as end_utc'
        .' from tenant_luna.bookings b'
        .' join tenant_luna.booking_items i on i.booking_id = b.id'
        .' join tenant_luna.staff m on m.id = i.staff_id'
        .' join tenant_luna.services s on s.id = i.service_id'
        .' where b.uuid = ?::uuid order by i.sort',
        [$uuid],
    ) as $row) {
        assert($row instanceof stdClass && is_string($row->staff) && is_string($row->service) && is_string($row->start_utc) && is_string($row->end_utc));

        $wall = fn (string $text): string => (new DateTimeImmutable($text, $utc))->setTimezone($athens)->format('m-d H:i');
        $items[] = sprintf('%s %s %s-%s', $row->staff, $row->service, $wall($row->start_utc), $wall($row->end_utc));
    }

    return $items;
}

/**
 * The payload of the one rescheduled row of the trail.
 *
 * @return array<mixed>
 */
function businessMoveFacts(): array
{
    $moves = array_values(array_filter(LunaFixture::trail(), fn (array $row): bool => $row['action'] === 'rescheduled'));

    expect($moves)->toHaveCount(1);

    $payload = $moves[0]['payload'];
    assert(is_array($payload));

    return $payload;
}

/**
 * What the closure threw. Nothing, or something else than the class named, fails the test.
 *
 * @template T of Throwable
 *
 * @param  class-string<T>  $class
 * @return T
 */
function businessRefusal(string $class, Closure $run): Throwable
{
    try {
        $run();
    } catch (Throwable $thrown) {
        // Anything else is rethrown, so the test fails naming what was thrown instead.
        if (! $thrown instanceof $class) {
            throw $thrown;
        }

        return $thrown;
    }

    throw new LogicException(sprintf('Nothing was thrown where %s was expected.', $class));
}

/**
 * A closure of luna on a date: the business's where no member is named, the whole day where no
 * window is.
 */
function businessClosure(?string $staff, string $date, ?string $start = null, ?string $end = null): void
{
    DB::table('tenant_luna.blackouts')->insert([
        'staff_id' => $staff === null ? null : LunaFixture::id('staff', $staff),
        'date' => $date,
        'start_time' => $start,
        'end_time' => $end,
        'reason' => 'Closed',
    ]);
}

it('moves a confirmed booking to a new time for a signed-in user, keeping its member, its status and its price, and the trail names the user, both starts and that the customer was told', function () {
    $uuid = businessConfirmed();
    $user = tenantUserId();

    businessMoves()->reschedule($uuid, Actor::user($user), LunaFixture::at('2026-11-04 15:00'));

    expect(businessItems($uuid))->toBe(['Elena Papadaki Haircut 11-04 15:00-11-04 15:45'])
        ->and(LunaFixture::row($uuid))->toMatchArray(['status' => 'confirmed', 'total_minor' => 3500, 'blocks' => [true]])
        ->and(LunaFixture::trail()[2])->toMatchArray(['action' => 'rescheduled', 'actor' => 'user:'.$user])
        ->and(businessMoveFacts())->toBe([
            'customer_told' => true,
            'moved_from' => '2026-11-03T08:00:00.000000+00:00',
            'starts_at' => '2026-11-04T13:00:00.000000+00:00',
        ]);
});

it('gives a one-segment booking to another member at the same time, and the trail says from whom to whom by id', function () {
    $uuid = businessConfirmed();
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');

    businessMoves()->reschedule($uuid, Actor::user(tenantUserId()), LunaFixture::at('2026-11-03 10:00'), new MoveChoices(staff: [0 => $nikos]));

    expect(businessItems($uuid))->toBe(['Nikos Georgiou Haircut 11-03 10:00-11-03 10:45'])
        // The rows hold the payload as jsonb, which keeps an object's keys in an order of its own.
        ->and(businessMoveFacts())->toEqual([
            'customer_told' => true,
            'moved_from' => '2026-11-03T08:00:00.000000+00:00',
            'staff_changed' => [['segment' => 0, 'from' => $elena, 'to' => $nikos]],
            'starts_at' => '2026-11-03T08:00:00.000000+00:00',
        ]);
});

it('moves a two-service booking by one shift and gives its second segment alone to another member, keyed by its position in the chain', function () {
    $uuid = businessConfirmed(['Haircut', 'Colour']);
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');

    businessMoves()->reschedule($uuid, Actor::user(tenantUserId()), LunaFixture::at('2026-11-04 15:00'), new MoveChoices(staff: [1 => $nikos]));

    expect(businessItems($uuid))->toBe([
        'Elena Papadaki Haircut 11-04 15:00-11-04 15:45',
        'Nikos Georgiou Colour 11-04 15:45-11-04 16:45',
    ])
        ->and(LunaFixture::row($uuid))->toMatchArray(['total_minor' => 11500])
        ->and(businessMoveFacts()['staff_changed'])->toEqual([['segment' => 1, 'from' => LunaFixture::id('staff', 'Elena Papadaki'), 'to' => $nikos]]);
});

it('never moves a booking onto another booking of the member, not even with the warning accepted, and leaves both where they were', function () {
    $other = businessConfirmed(['Haircut'], '2026-11-03 10:00', 'Nikos Georgiou');
    $uuid = businessConfirmed(['Haircut'], '2026-11-03 12:00');
    $trail = LunaFixture::trail();

    $refused = businessRefusal(SegmentConflict::class, fn () => businessMoves()->reschedule(
        $uuid,
        Actor::user(tenantUserId()),
        LunaFixture::at('2026-11-03 10:15'),
        new MoveChoices(staff: [0 => LunaFixture::id('staff', 'Nikos Georgiou')], warningAccepted: true),
    ));

    expect($refused->segment)->toBe(0)
        ->and(businessItems($uuid))->toBe(['Elena Papadaki Haircut 11-03 12:00-11-03 12:45'])
        ->and(businessItems($other))->toBe(['Nikos Georgiou Haircut 11-03 10:00-11-03 10:45'])
        ->and(LunaFixture::trail())->toBe($trail);
});

it('refuses a move outside the member\'s working hours naming the segment, the member and hours, and lands it once the warning is accepted, the trail saying it overrode them', function () {
    $uuid = businessConfirmed();
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $user = Actor::user(tenantUserId());

    // 13:00 is Elena's lunch: no booking there, she simply does not work it.
    $refused = businessRefusal(UnacceptedWarning::class, fn () => businessMoves()->reschedule($uuid, $user, LunaFixture::at('2026-11-03 13:00')));

    expect($refused->crossings)->toEqual([new MaskCrossing(0, $elena, Overridable::Hours)])
        ->and(businessItems($uuid))->toBe(['Elena Papadaki Haircut 11-03 10:00-11-03 10:45']);

    businessMoves()->reschedule($uuid, $user, LunaFixture::at('2026-11-03 13:00'), new MoveChoices(warningAccepted: true));

    expect(businessItems($uuid))->toBe(['Elena Papadaki Haircut 11-03 13:00-11-03 13:45'])
        ->and(businessMoveFacts()['overridden'])->toEqual([['segment' => 0, 'kind' => 'hours']]);
});

it('refuses a move onto a closure naming it a closure, the business\'s closed day and a member\'s own window alike, and lands it once the warning is accepted', function () {
    $uuid = businessConfirmed(['Haircut', 'Colour']);
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');
    $user = Actor::user(tenantUserId());
    businessClosure(null, '2026-11-04');

    $closedDay = businessRefusal(UnacceptedWarning::class, fn () => businessMoves()->reschedule($uuid, $user, LunaFixture::at('2026-11-04 15:00')));

    // Nikos's own window closes the second segment alone; the first is on Elena's open hours.
    businessClosure('Nikos Georgiou', '2026-11-05', '15:30', '17:00');
    $ownWindow = businessRefusal(UnacceptedWarning::class, fn () => businessMoves()->reschedule($uuid, $user, LunaFixture::at('2026-11-05 15:00'), new MoveChoices(staff: [1 => $nikos])));

    expect($closedDay->crossings)->toEqual([new MaskCrossing(0, $elena, Overridable::Closure), new MaskCrossing(1, $elena, Overridable::Closure)])
        ->and($ownWindow->crossings)->toEqual([new MaskCrossing(1, $nikos, Overridable::Closure)])
        ->and(businessItems($uuid))->toBe(['Elena Papadaki Haircut 11-03 10:00-11-03 10:45', 'Elena Papadaki Colour 11-03 10:45-11-03 11:45']);

    businessMoves()->reschedule($uuid, $user, LunaFixture::at('2026-11-05 15:00'), new MoveChoices(staff: [1 => $nikos], warningAccepted: true));

    expect(businessItems($uuid))->toBe(['Elena Papadaki Haircut 11-05 15:00-11-05 15:45', 'Nikos Georgiou Colour 11-05 15:45-11-05 16:45'])
        ->and(businessMoveFacts()['overridden'])->toEqual([['segment' => 1, 'kind' => 'closure']]);
});

it('names both where a segment lands on a closure on a day its member does not work', function () {
    $uuid = businessConfirmed();
    businessClosure('Elena Papadaki', '2026-11-07');

    $refused = businessRefusal(UnacceptedWarning::class, fn () => businessMoves()->reschedule($uuid, Actor::user(tenantUserId()), LunaFixture::at('2026-11-07 10:00')));

    expect(array_map(fn (MaskCrossing $crossing): string => $crossing->kind->value, $refused->crossings))->toBe(['hours', 'closure']);
});

it('refuses a closed booking whoever moves it, with nothing written', function (string $status) {
    $uuid = LunaFixture::booking($status, blocks: false);

    $refused = businessRefusal(TransitionRefused::class, fn () => businessMoves()->reschedule($uuid, Actor::user(tenantUserId()), LunaFixture::at('2026-11-04 15:00'), new MoveChoices(warningAccepted: true)));

    expect($refused->reason)->toBe(TransitionRefusal::NotReschedulable)
        ->and(LunaFixture::trail())->toBe([]);
})->with(['completed', 'no_show', 'cancelled', 'rejected', 'expired']);

it('holds the customer to the reschedule window and not the business, for the user and for support alike', function () {
    DB::table('tenant_luna.services')->where('name', 'Haircut')->update(['reschedule_window_hours' => 48]);
    $uuid = businessConfirmed();

    $customer = businessRefusal(TransitionRefused::class, fn () => businessMoves()->reschedule($uuid, Actor::customer(), LunaFixture::at('2026-11-04 15:00')));
    businessMoves()->reschedule($uuid, Actor::user(tenantUserId()), LunaFixture::at('2026-11-04 15:00'));
    businessMoves()->reschedule($uuid, Actor::support(3), LunaFixture::at('2026-11-04 16:00'));

    expect($customer->reason)->toBe(TransitionRefusal::WindowClosed)
        ->and(businessItems($uuid))->toBe(['Elena Papadaki Haircut 11-04 16:00-11-04 16:45'])
        ->and(array_column(LunaFixture::trail(), 'actor'))->toBe(['customer', 'customer', 'user:'.tenantUserId(), 'support:3']);
});

it('refuses a start in the past, named as passed, to the business as to everyone, with nothing written', function () {
    $uuid = businessConfirmed();

    $past = businessRefusal(ChainUnavailable::class, fn () => businessMoves()->reschedule($uuid, Actor::user(tenantUserId()), LunaFixture::at('2026-11-02 07:00'), new MoveChoices(warningAccepted: true)));

    expect($past->segment)->toBe(0)
        ->and($past->rule)->toBe(ChainUnavailable::PAST)
        ->and(businessItems($uuid))->toBe(['Elena Papadaki Haircut 11-03 10:00-11-03 10:45']);
});

it('moves inside the lead time and past the horizon, which bind customers alone, for a signed-in user and for support, while the customer is refused both', function () {
    $uuid = businessConfirmed();
    // 08:00 in Athens on the Monday: five hours of lead time reach 13:00, and three days of
    // horizon end on the Thursday.
    DB::table('tenant_luna.services')->where('name', 'Haircut')->update(['lead_time_min' => 300, 'horizon_days' => 3]);

    $lead = businessRefusal(ChainUnavailable::class, fn () => businessMoves()->reschedule($uuid, Actor::customer(), LunaFixture::at('2026-11-02 10:00')));
    $horizon = businessRefusal(ChainUnavailable::class, fn () => businessMoves()->reschedule($uuid, Actor::customer(), LunaFixture::at('2026-11-10 10:00')));

    businessMoves()->reschedule($uuid, Actor::user(tenantUserId()), LunaFixture::at('2026-11-02 10:00'));
    $atLead = businessItems($uuid);
    businessMoves()->reschedule($uuid, Actor::support(3), LunaFixture::at('2026-11-10 11:00'));

    expect([$lead->rule, $horizon->rule])->toBe([ChainUnavailable::LEAD_TIME, ChainUnavailable::HORIZON])
        ->and($atLead)->toBe(['Elena Papadaki Haircut 11-02 10:00-11-02 10:45'])
        ->and(businessItems($uuid))->toBe(['Elena Papadaki Haircut 11-10 11:00-11-10 11:45'])
        ->and(array_column(array_values(array_filter(LunaFixture::trail(), fn (array $row): bool => $row['action'] === 'rescheduled')), 'actor'))->toBe(['user:'.tenantUserId(), 'support:3']);
});

it('refuses a segment given to a member who does not give its service, or who is no longer active, as the manual booking refuses that pick', function () {
    $uuid = businessConfirmed(['Beard trim'], '2026-11-03 10:00', 'Nikos Georgiou');
    $haircut = businessConfirmed(['Haircut'], '2026-11-04 10:00');
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');
    $user = Actor::user(tenantUserId());

    $unassigned = businessRefusal(StaffNotEligible::class, fn () => businessMoves()->reschedule($uuid, $user, LunaFixture::at('2026-11-03 11:00'), new MoveChoices(staff: [0 => $elena])));
    DB::table('tenant_luna.staff')->where('id', $nikos)->update(['status' => 'inactive']);
    $inactive = businessRefusal(StaffNotEligible::class, fn () => businessMoves()->reschedule($haircut, $user, LunaFixture::at('2026-11-04 10:00'), new MoveChoices(staff: [0 => $nikos])));

    expect([$unassigned->segment, $unassigned->staffId])->toBe([0, $elena])
        ->and([$inactive->segment, $inactive->staffId])->toBe([0, $nikos])
        ->and(businessItems($uuid))->toBe(['Nikos Georgiou Beard trim 11-03 10:00-11-03 10:20']);
});

it('refuses the engine, a job and a customer carrying a choice only the business makes, before anything is read', function () {
    $uuid = businessConfirmed();

    foreach ([
        [Actor::system(), new MoveChoices],
        [Actor::job('expire-holds'), new MoveChoices],
        [Actor::customer(), new MoveChoices(staff: [0 => LunaFixture::id('staff', 'Nikos Georgiou')])],
        [Actor::customer(), new MoveChoices(warningAccepted: true)],
        [Actor::customer(), new MoveChoices(tellCustomer: false)],
    ] as [$actor, $choices]) {
        businessRefusal(MoverRefused::class, fn () => businessMoves()->reschedule($uuid, $actor, LunaFixture::at('2026-11-04 15:00'), $choices));
    }

    expect(businessItems($uuid))->toBe(['Elena Papadaki Haircut 11-03 10:00-11-03 10:45']);
});

it('carries the choice to tell the customer on the record, true by default and on every move by the customer', function () {
    $seen = [];
    Event::listen(BookingRescheduled::class, function (BookingRescheduled $moved) use (&$seen): void {
        $seen[] = [$moved->actor->value, $moved->tellCustomer];
    });
    $uuid = businessConfirmed();
    $user = Actor::user(tenantUserId());

    businessMoves()->reschedule($uuid, Actor::customer(), LunaFixture::at('2026-11-04 15:00'));
    businessMoves()->reschedule($uuid, $user, LunaFixture::at('2026-11-04 16:00'));
    businessMoves()->reschedule($uuid, $user, LunaFixture::at('2026-11-04 17:00'), new MoveChoices(tellCustomer: false));

    expect($seen)->toBe([['customer', true], ['user:'.tenantUserId(), true], ['user:'.tenantUserId(), false]])
        ->and(array_map(fn (array $row): mixed => is_array($row['payload']) ? $row['payload']['customer_told'] ?? null : null, array_slice(LunaFixture::trail(), 2)))->toBe([null, true, false]);
});

it('tells the customer in the business\'s voice naming the new member, and everybody at the business naming the user, the one who moved it included', function () {
    addTenantUser('marina@luna.example', 'manager');
    $uuid = businessConfirmed();

    businessMoves()->reschedule($uuid, Actor::user(tenantUserId()), LunaFixture::at('2026-11-04 15:00'), new MoveChoices(staff: [0 => LunaFixture::id('staff', 'Nikos Georgiou')]));

    $addresses = seamAddresses();
    sort($addresses);
    $mine = seamTextAt(LunaFixture::CUSTOMER_EMAIL);
    $words = ['business' => 'Luna Hair Studio', 'from' => lunaInstant(LunaFixture::at('2026-11-03 10:00')), 'to' => lunaInstant(LunaFixture::at('2026-11-04 15:00'))];

    expect($addresses)->toBe(['marina@luna.example', 'owner@luna.example', LunaFixture::CUSTOMER_EMAIL])
        ->and($mine)->toContain(customerMailLine('customer-mail.moved.booking.by_business', $words))
        ->and($mine)->toContain(customerMailLine('customer-mail.moved.unexpected', $words))
        ->and($mine)->toContain('Nikos Georgiou')
        ->and($mine)->not->toContain('Elena Papadaki')
        ->and(seamTextAt('marina@luna.example'))->toContain(businessMailLine('business-mail.move_notice.by.user', ['staff' => 'Demo Owner']))
        ->and(seamTextAt('owner@luna.example'))->toContain(businessMailLine('business-mail.move_notice.by.user', ['staff' => 'Demo Owner']));
});

it('sends the customer nothing when the business leaves them untold, and still tells the business', function () {
    $uuid = businessConfirmed();

    businessMoves()->reschedule($uuid, Actor::user(tenantUserId()), LunaFixture::at('2026-11-04 15:00'), new MoveChoices(tellCustomer: false));

    expect(seamAddresses())->toBe(['owner@luna.example'])
        ->and(businessItems($uuid))->toBe(['Elena Papadaki Haircut 11-04 15:00-11-04 15:45']);
});

it('shows the dialog\'s trail the members of a staff change by name beside their ids, and the business move\'s other facts as they were written', function () {
    $uuid = businessConfirmed(['Haircut', 'Colour']);
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');

    businessMoves()->reschedule($uuid, Actor::user(tenantUserId()), LunaFixture::at('2026-11-03 13:00'), new MoveChoices(staff: [1 => $nikos], warningAccepted: true, tellCustomer: false));

    $moved = array_values(array_filter(app(BookingTrail::class)->of(LunaFixture::tenant(), $uuid), fn (array $entry): bool => $entry['action'] === 'rescheduled'));

    expect($moved)->toHaveCount(1)
        ->and($moved[0]['name'])->toBe('Demo Owner')
        ->and($moved[0]['facts']['staff_changed'])->toEqual([['segment' => 1, 'from' => $elena, 'to' => $nikos, 'from_name' => 'Elena Papadaki', 'to_name' => 'Nikos Georgiou']])
        ->and($moved[0]['facts']['overridden'])->toEqual([['segment' => 0, 'kind' => 'hours']])
        ->and($moved[0]['facts']['customer_told'])->toBeFalse();
});
