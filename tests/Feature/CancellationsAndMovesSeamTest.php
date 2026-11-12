<?php

use App\Application\Clock;
use App\Application\Event\BookingCancelled;
use App\Application\Event\BookingReleased;
use App\Application\Event\BookingRescheduled;
use App\Application\Event\TransitionEvent;
use App\Application\Notification\MessageKey;
use App\Domain\Audit\Actor;
use App\Domain\Enum\BookingStatus;
use App\Infrastructure\BookingTransitionFactory;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Events\TransitionPublishers;
use App\Jobs\SendMove;
use App\Notifications\MyBookingsLine;
use App\Site\SiteLanguage;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Part\DataPart;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;
use Tests\Support\HaltedClock;
use Tests\Support\LunaFixture;
use Tests\Support\RefusingTransport;
use Tests\Support\SeamLog;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * The two changes to a booking somebody was expecting, told to everyone who was expecting it,
 * over luna's real rows: a cancellation and a move, each to the customer and to the business,
 * reached through the manage routes the way a customer reaches them and through the use case
 * the way the admin will.
 *
 * Not wrapped in a transaction: the hold, the confirm and the transitions all enter the
 * transaction runner, which refuses to open inside another.
 *
 * The seam helpers, seamHold, seamHeld, seamConfirm, seamSent, seamOnly, seamAt, seamAddresses,
 * seamSwitchOff and seamEveryKeyBut, live in tests/Pest.php, and SeamLog, RefusingTransport and
 * HaltedClock under tests/Support. Shared on purpose rather than copied, so two notification
 * tests cannot acquire two ideas of what a sent message is, and this file runs alone.
 *
 * luna is in Europe/Athens, UTC+2 in November. 2026-11-03 is a Tuesday and 2026-11-04 a
 * Wednesday; Elena works both, 09:00 to 13:00 and 14:00 to 18:00. Haircut is the ordinary
 * pay-on-site service and Bridal trial the two-hour one that requires approval. The clock stands
 * on the Monday before, outside every window. luna's demo rows carry one user, the owner, named
 * Demo Owner, and its named staff rows have no addresses of their own, so the business recipient
 * rule resolves to the owner alone unless a test seeds otherwise.
 *
 * The customer's words are customer-mail.php's, read through the translator in the booking's
 * language, English here, with the business and a move's two starts filled in; the business's
 * are their own.
 */

const EXPECTING_CUSTOMER = 'perpetua.vandersnoot@example.test';

const EXPECTING_OWNER = 'owner@luna.example';

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00:00'));
    seamTransport()->flush();

    // A confirmed booking, and a move of one, schedule the reminder as well, which under the
    // suite's sync queue runs inside the transition and would be counted here as one more
    // message, and under the database queue as one more row. It is switched off at the tenant
    // rather than filtered out of every assertion: a lead of zero is how a tenant sends no
    // reminders, and the reminder has its own file, ReminderSeamTest.
    settingsOf('luna')->update(['reminder_lead_hours' => 0]);
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * One line of the customer's messages in English, the business filled in, and for a move's
 * opening the start it left and the start it has as ICU spells them in luna's clock.
 */
function expectingLine(string $key, string $from = FixtureDay::START, string $to = '2026-11-04 15:00'): string
{
    return customerMailLine('customer-mail.'.$key, [
        'business' => 'Luna Hair Studio',
        'from' => lunaInstant(LunaFixture::at($from)),
        'to' => lunaInstant(LunaFixture::at($to)),
    ]);
}

/**
 * One line of the business's notices in English, the business and the customer filled in, the
 * name of a user or the public name where the line names one, and for a move's opening the start
 * it left and the start it has as ICU spells them in luna's clock.
 *
 * @param  array<string, string>  $replace
 */
function expectingNotice(string $key, array $replace = [], string $from = FixtureDay::START, string $to = '2026-11-04 15:00'): string
{
    return businessMailLine('business-mail.'.$key, [
        'business' => 'Luna Hair Studio',
        'customer' => LunaFixture::CUSTOMER_NAME,
        'from' => lunaInstant(LunaFixture::at($from)),
        'to' => lunaInstant(LunaFixture::at($to)),
        ...$replace,
    ]);
}

/**
 * A confirmed booking taken the way the booking page takes one, with the confirmation and the
 * new-booking notice cleared away: they are not this file's subject.
 *
 * @return array{uuid: string, token: string}
 */
function expectingConfirmed(TestCase $test, string $service = 'Haircut', string $start = FixtureDay::START): array
{
    [$url, $body] = seamHold($service, $start);
    $held = seamHeld($test->postJson($url, $body));
    seamConfirm($test, $held['uuid'], $held['token']);

    seamTransport()->flush();

    return $held;
}

/**
 * A request lodged with the owner, with the acknowledgement and the approval request cleared
 * away.
 *
 * @return array{uuid: string, token: string}
 */
function expectingRequested(TestCase $test): array
{
    [$url, $body] = seamHold('Bridal trial');
    $held = seamHeld($test->postJson($url, $body));

    seamTransport()->flush();

    return $held;
}

/**
 * The cancel as the manage view sends it: the token in the body.
 */
function expectingCancel(TestCase $test, string $uuid, string $token): void
{
    $test->postJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'/cancel'), ['token' => $token])->assertOk();
}

/**
 * The move as the reschedule dialog sends it: the token and the new start in the body, the
 * start spelled the way the wire spells one.
 */
function expectingMove(TestCase $test, string $uuid, string $token, string $start = '2026-11-04 15:00'): void
{
    $test->postJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'/reschedule'), [
        'token' => $token,
        'start' => LunaFixture::at($start)->format(DateTimeImmutable::RFC3339),
    ])->assertOk();
}

/**
 * A move whose two messages go out with the schema-wide highest item id strictly above the
 * reader's answer for the moved booking at both moments SendMove could read it. Before the
 * move, a booking is planted with its item at an explicit id a million above the highest item
 * id there is, and an explicit id does not advance the sequence, so that ceiling is there when
 * the move queues the job and every item the move re-inserts draws a number below it. Then the
 * queue takes the jobs instead of running them inside the move, a second booking is planted
 * the way LunaFixture plants one, and the queue is worked, so the sequence's last value is
 * above the moved booking's items as well by the time the job runs. Answers the reader's
 * number for the moved booking, pinned below the ceiling at both moments, below the sequence's
 * last value and apart from the trail's count, so that a file carrying any of those fails the
 * equality a caller asks against the answer. Without the plants the moved booking's items are
 * the newest rows in the schema and the numbers agree by accident.
 */
function expectingMoveBehindANewerBooking(TestCase $test, string $uuid, string $token, string $start): int
{
    config(['queue.default' => 'database']);

    $highest = DB::scalar('select max(id) from tenant_luna.booking_items');

    assert(is_int($highest));

    $ceiling = 1_000_000 + $highest;
    LunaFixture::booking('confirmed', start: '2026-11-03 14:00', itemId: $ceiling);

    expectingMove($test, $uuid, $token, $start);

    $later = LunaFixture::booking('confirmed', start: '2026-11-03 12:00');

    // The move of a confirmed booking queues its two messages and, with luna's lead at zero,
    // no reminder; both wait on the queue while the booking above is planted.
    expect(DB::table('jobs')->count())->toBe(2);

    // No memory ceiling for the worker: its check reads the memory of the whole test process,
    // which a full run grows past the default 128 MB by the time this file runs, and a worker
    // over it stops after the first job and leaves the other due ones queued.
    Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--memory' => 0, '--no-interaction' => true]);

    $revision = app(BookingReader::class)->revision(LunaFixture::tenant(), $uuid);
    $lastDrawn = DB::scalar('select max(i.id) from tenant_luna.booking_items i join tenant_luna.bookings b on b.id = i.booking_id where b.uuid = ?::uuid', [$later]);

    expect(DB::scalar('select max(id) from tenant_luna.booking_items'))->toBeGreaterThan($revision)
        ->and(DB::scalar('select max(id) from tenant_luna.booking_items'))->toBe($ceiling)
        ->and($ceiling)->toBeGreaterThan($revision)
        ->and($lastDrawn)->toBeGreaterThan($revision)
        ->and(count(LunaFixture::trail()))->not->toBe($revision);

    return $revision;
}

/**
 * A transition announced for luna the way a use case announces one, for the cases the wire
 * cannot reach: a record for a booking that has since gone, and a listener whose job fails.
 */
function expectingPublish(TransitionEvent $event): void
{
    app(TransitionPublishers::class)->for(LunaFixture::tenant())->publish($event);
}

/**
 * The one calendar file a message carries, as text.
 */
function expectingFile(string $address): string
{
    $attachments = seamAt($address)->getAttachments();

    expect(array_map(fn (DataPart $part): ?string => $part->getFilename(), $attachments))->toBe(['booking.ics']);

    return $attachments[0]->getBody();
}

/**
 * The value of one property of a calendar file, unfolded.
 */
function expectingIcs(string $file, string $property): string
{
    $unfolded = str_replace("\r\n ", '', $file);

    if (preg_match('/^'.preg_quote($property, '/').':(.*)$/m', $unfolded, $found) !== 1) {
        throw new RuntimeException(sprintf('The file carries no %s.', $property));
    }

    return rtrim($found[1], "\r");
}

it('confirms the customer\'s own cancellation of a confirmed booking back to them, and tells the business naming the customer, both in the tenant clock', function () {
    $held = expectingConfirmed($this);

    expectingCancel($this, $held['uuid'], $held['token']);

    $customer = seamAt(EXPECTING_CUSTOMER);
    $business = seamAt(EXPECTING_OWNER);
    $mine = (string) $customer->getTextBody();
    $theirs = (string) $business->getTextBody();

    expect(seamAddresses())->toBe([EXPECTING_CUSTOMER, EXPECTING_OWNER])
        ->and(LunaFixture::row($held['uuid']))->toMatchArray(['status' => 'cancelled', 'hold_class' => null, 'blocks' => [false]])
        ->and(array_map(fn (array $row): mixed => $row['action'], LunaFixture::trail()))->toBe(['held', 'confirmed', 'cancelled'])
        // The customer reads what they did, as their own act, about the hour the salon means:
        // 10:00 in Athens is 08:00 in the row.
        ->and($customer->getSubject())->toBe(expectingLine('cancelled.booking.by_customer.subject'))
        ->and($mine)->toContain(expectingLine('cancelled.booking.by_customer.cancelled'))
        ->and($mine)->toContain(expectingLine('cancelled.booking.by_customer.means'))
        ->and($mine)->toContain('Haircut')
        ->and($mine)->toContain('3 Nov 2026, 10:00')
        ->and($mine)->not->toContain(', 08:00')
        ->and(substr_count($mine, 'Europe/Athens'))->toBe(1)
        ->and($mine)->not->toContain(expectingLine('cancelled.request.by_customer.cancelled'))
        ->and($mine)->not->toContain($held['token'])
        ->and($mine)->not->toContain('/manage/')
        ->and($customer->getAttachments())->toBe([])
        // The business reads who did it, by name, and the hour in their own clock.
        ->and($business->getSubject())->toBe(expectingNotice('cancellation_notice.booking.subject'))
        ->and($theirs)->toContain(expectingNotice('cancellation_notice.by.customer').' '.expectingNotice('cancellation_notice.booking.means'))
        ->and($theirs)->toContain('3 Nov 2026, 10:00')
        ->and($theirs)->not->toContain(', 08:00')
        ->and(substr_count($theirs, 'Europe/Athens'))->toBe(1)
        // The owner manages bookings and is told how to reach the customer.
        ->and($theirs)->toContain(EXPECTING_CUSTOMER)
        ->and($theirs)->toContain('+30 210 1234567')
        ->and($theirs)->not->toContain($held['token'])
        ->and($business->getAttachments())->toBe([]);
});

it('tells the customer the business cancelled their booking, and tells everybody at the business naming the user who did it, the one who clicked included', function () {
    addTenantUser('marina@luna.example', 'manager');
    $held = expectingConfirmed($this);

    app(BookingTransitionFactory::class)->cancel(LunaFixture::tenant())->cancel($held['uuid'], Actor::user(tenantUserId(EXPECTING_OWNER)));

    $addresses = seamAddresses();
    sort($addresses);

    $mine = (string) seamAt(EXPECTING_CUSTOMER)->getTextBody();
    $owner = (string) seamAt(EXPECTING_OWNER)->getTextBody();
    $manager = (string) seamAt('marina@luna.example')->getTextBody();

    expect($addresses)->toBe(['marina@luna.example', EXPECTING_OWNER, EXPECTING_CUSTOMER])
        ->and(LunaFixture::row($held['uuid'])['status'])->toBe('cancelled')
        // The customer is told it was the business's act, and who to ask.
        ->and(seamAt(EXPECTING_CUSTOMER)->getSubject())->toBe(expectingLine('cancelled.booking.by_business.subject'))
        ->and($mine)->toContain(expectingLine('cancelled.booking.by_business.cancelled'))
        ->and($mine)->toContain(expectingLine('cancelled.booking.by_business.means'))
        ->and($mine)->not->toContain(expectingLine('cancelled.booking.by_customer.cancelled'))
        // A tenant has several people, and the one who clicked is not the only one who was
        // expecting the customer: the manager hears, and so does the owner who did it, and both
        // read the name on the owner's own row.
        ->and($owner)->toContain(expectingNotice('cancellation_notice.by.user', ['staff' => 'Demo Owner']))
        ->and($manager)->toContain(expectingNotice('cancellation_notice.by.user', ['staff' => 'Demo Owner']))
        ->and($manager)->not->toContain(expectingNotice('cancellation_notice.by.customer'))
        ->and($manager)->toContain('3 Nov 2026, 10:00');
});

it('tells everybody at the business that the platform\'s support cancelled it, in the public name the configuration holds and by no operator\'s name', function () {
    config(['site.name' => 'Example Bookings']);
    $held = expectingConfirmed($this);

    app(BookingTransitionFactory::class)->cancel(LunaFixture::tenant())->cancel($held['uuid'], Actor::support(7));

    $owner = (string) seamAt(EXPECTING_OWNER)->getTextBody();

    expect(LunaFixture::row($held['uuid'])['status'])->toBe('cancelled')
        ->and($owner)->toContain(expectingNotice('cancellation_notice.by.support', ['name' => 'Example Bookings']));
});

it('tells both about a live request the customer gave up, in the words of a request withdrawn rather than an appointment cancelled', function () {
    $held = expectingRequested($this);

    expectingCancel($this, $held['uuid'], $held['token']);

    $customer = seamAt(EXPECTING_CUSTOMER);
    $business = seamAt(EXPECTING_OWNER);
    $mine = (string) $customer->getTextBody();
    $theirs = (string) $business->getTextBody();

    expect(seamAddresses())->toBe([EXPECTING_CUSTOMER, EXPECTING_OWNER])
        ->and(LunaFixture::row($held['uuid']))->toMatchArray(['status' => 'cancelled', 'hold_class' => null, 'blocks' => [false]])
        ->and($customer->getSubject())->toBe(expectingLine('cancelled.request.by_customer.subject'))
        ->and($mine)->toContain(expectingLine('cancelled.request.by_customer.cancelled'))
        ->and($mine)->toContain(expectingLine('cancelled.request.by_customer.means'))
        ->and($mine)->toContain('Bridal trial')
        ->and($mine)->toContain('3 Nov 2026, 10:00')
        ->and($mine)->not->toContain(expectingLine('cancelled.booking.by_customer.cancelled'))
        ->and($mine)->not->toContain(expectingLine('cancelled.booking.by_customer.means'))
        ->and($business->getSubject())->toBe(expectingNotice('cancellation_notice.request.subject'))
        ->and($theirs)->toContain(expectingNotice('cancellation_notice.by.customer').' '.expectingNotice('cancellation_notice.request.means'))
        ->and($theirs)->not->toContain(expectingNotice('cancellation_notice.booking.means'));
});

it('says nothing at all when a checkout hold is handed back through DELETE /holds/{uuid}, with every listener registered', function () {
    [$url, $body] = seamHold('Haircut');
    $held = seamHeld($this->postJson($url, $body));

    // The list this runs against is the production one: the cancel has its two listeners, and
    // the release, which leaves the same cancelled row, has none. That is what the two records
    // are two records for, and a listener that had to tell them apart by reading the booking
    // could not. Read by the record's own name: the payment listener subscribes to the marker
    // every record carries, sends no message, and is not counted here.
    expect(Event::getRawListeners()[BookingCancelled::class] ?? [])->toHaveCount(2)
        ->and(Event::getRawListeners()[BookingReleased::class] ?? [])->toBe([]);

    $this->deleteJson(tenantUrl('luna', '/api/v1/holds/'.$held['uuid'].'?token='.$held['token']))->assertNoContent();

    expect(seamSent())->toBe([])
        ->and(LunaFixture::row($held['uuid'])['status'])->toBe('cancelled')
        ->and(array_map(fn (array $row): mixed => $row['action'], LunaFixture::trail()))->toBe(['held', 'released']);
});

it('says nothing either when a checkout hold is cancelled or moved through the manage routes before it was confirmed, because a checkout hold gets no mail on any event', function () {
    [$url, $body] = seamHold('Haircut');
    $moved = seamHeld($this->postJson($url, $body));
    [$url, $body] = seamHold('Haircut', '2026-11-03 11:00');
    $cancelled = seamHeld($this->postJson($url, $body));

    // That customer is still inside the booking flow, and nobody at the business has heard of
    // the hold: the new-booking notice goes on the confirm. The record carries the class the row
    // is about to lose, and both listeners read it there.
    expectingMove($this, $moved['uuid'], $moved['token']);
    expectingCancel($this, $cancelled['uuid'], $cancelled['token']);

    expect(seamSent())->toBe([])
        ->and(LunaFixture::row($moved['uuid']))->toMatchArray(['status' => 'pending', 'hold_class' => 'checkout'])
        ->and(LunaFixture::row($cancelled['uuid'])['status'])->toBe('cancelled')
        ->and(array_map(fn (array $row): mixed => $row['action'], LunaFixture::trail()))->toBe(['held', 'held', 'rescheduled', 'cancelled']);
});

it('tells the customer and the business a confirmed booking moved, with both instants in the tenant clock, and re-sends the calendar file at a revision that supersedes the confirmation\'s', function () {
    [$url, $body] = seamHold('Haircut');
    $held = seamHeld($this->postJson($url, $body));
    seamConfirm($this, $held['uuid'], $held['token']);
    $first = expectingFile(EXPECTING_CUSTOMER);
    seamTransport()->flush();

    // Five minutes pass between the confirmation and the move. Under a clock that stood still
    // the two files would carry one stamp, and the assertion below that the second is stamped
    // later could not fail; it is the send-time clock the stamp is asserted to be.
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:05:00'));

    $revision = expectingMoveBehindANewerBooking($this, $held['uuid'], $held['token'], '2026-11-04 15:00');

    $customer = seamAt(EXPECTING_CUSTOMER);
    $business = seamAt(EXPECTING_OWNER);
    $mine = (string) $customer->getTextBody();
    $theirs = (string) $business->getTextBody();
    $second = expectingFile(EXPECTING_CUSTOMER);

    expect(seamAddresses())->toBe([EXPECTING_CUSTOMER, EXPECTING_OWNER])
        ->and(LunaFixture::row($held['uuid']))->toMatchArray(['status' => 'confirmed', 'blocks' => [true]])
        // 10:00 and 15:00 in Athens are 08:00 and 13:00 in the rows. Neither row hour is an
        // hour anybody is expected at.
        ->and($customer->getSubject())->toBe(expectingLine('moved.booking.subject'))
        ->and($mine)->toContain(expectingLine('moved.booking.by_customer'))
        ->and($mine)->toContain(expectingLine('moved.calendar'))
        ->and($mine)->not->toContain(', 08:00')
        ->and($mine)->not->toContain(', 13:00')
        ->and(substr_count($mine, 'Europe/Athens'))->toBe(1)
        ->and($mine)->not->toContain($held['token'])
        ->and($mine)->not->toContain('/manage/')
        ->and($business->getSubject())->toBe(expectingNotice('move_notice.booking.subject'))
        ->and($theirs)->toContain(expectingNotice('move_notice.booking.opening'))
        ->and($theirs)->toContain('3 Nov 2026, 10:00')
        ->and($theirs)->toContain('4 Nov 2026, 15:00')
        ->and($theirs)->toContain(expectingNotice('move_notice.by.customer'))
        ->and($theirs)->not->toContain(', 08:00')
        ->and($theirs)->not->toContain(', 13:00')
        ->and(substr_count($theirs, 'Europe/Athens'))->toBe(1)
        ->and($business->getAttachments())->toBe([]);

    // The same entry, at a higher revision, stamped later: the three fields a calendar client
    // compares when two files claim one identifier, and the reason the second import updates the
    // entry the first one made rather than adding a second beside it. The number is the booking's
    // and not a count of anything: above the zero the confirmation carried, and exactly the
    // reader's answer for this booking asked after the move. The equality is the one check that
    // SendMove hands the reader's number to the file, and what it catches is a file carrying any
    // other number, with the substitutes a job could read held apart from the answer by
    // expectingMoveBehindANewerBooking rather than trusted to differ: the schema-wide highest
    // item id, pinned above it both when the move queues the job and when the job runs; the
    // sequence's last value, pinned above it; the trail's count, pinned unequal to it; and the
    // answer plus one, unequal by construction. A clock is not pinned: its seconds are ten
    // digits, and the answer here is one digit. Every one of those passes the strictly-greater
    // line and the reader's own contract test below, which asks the reader and never the file.
    expect(expectingIcs($second, 'UID'))->toBe($held['uuid'])
        ->and(expectingIcs($first, 'UID'))->toBe($held['uuid'])
        ->and(expectingIcs($first, 'SEQUENCE'))->toBe('0')
        ->and((int) expectingIcs($second, 'SEQUENCE'))->toBeGreaterThan(0)
        ->and((int) expectingIcs($second, 'SEQUENCE'))->toBe($revision)
        ->and(expectingIcs($first, 'DTSTAMP'))->toBe('20261102T060000Z')
        ->and(expectingIcs($second, 'DTSTAMP'))->toBe('20261102T060500Z')
        ->and(expectingIcs($first, 'DTSTART'))->toBe('20261103T080000Z')
        ->and(expectingIcs($second, 'DTSTART'))->toBe('20261104T130000Z')
        ->and($second)->not->toContain($held['token']);
});

it('numbers a later move above an earlier one whatever happened to the trail between them, because a client keeps the entry with the higher number', function () {
    $held = expectingConfirmed($this);

    expectingMove($this, $held['uuid'], $held['token'], '2026-11-04 15:00');
    $first = expectingFile(EXPECTING_CUSTOMER);
    seamTransport()->flush();

    // Retention deletes audit_log rows outright at 24 months, while a booking and its items live 24
    // months past the last segment: a booking taken far enough ahead loses its whole trail and can
    // still be moved. The trail is cleared here the way that sweep clears it, and the file the next
    // move sends has to carry a higher number all the same, because the customer's calendar holds
    // the first one and ignores anything lower.
    DB::table('tenant_luna.audit_log')->delete();

    $revision = expectingMoveBehindANewerBooking($this, $held['uuid'], $held['token'], '2026-11-04 16:00');
    $second = expectingFile(EXPECTING_CUSTOMER);

    $earlier = (int) expectingIcs($first, 'SEQUENCE');
    $later = (int) expectingIcs($second, 'SEQUENCE');

    // The later number is the reader's, asked behind a newer booking for the reason the move
    // test above gives.
    expect($earlier)->toBeGreaterThan(0)
        ->and($later)->toBeGreaterThan($earlier)
        ->and($later)->toBe($revision)
        ->and(expectingIcs($second, 'DTSTART'))->toBe('20261104T140000Z')
        ->and(array_map(fn (array $row): mixed => $row['action'], LunaFixture::trail()))->toBe(['rescheduled']);
});

it('numbers a booking by the highest id among its own items and not the schema\'s, raises it on a move of that booking alone, and answers zero for a uuid no booking has or that is no uuid at all', function () {
    $reader = app(BookingReader::class);
    $tenant = LunaFixture::tenant();
    $first = LunaFixture::booking('confirmed', start: '2026-11-03 10:00');
    $second = LunaFixture::booking('confirmed', start: '2026-11-03 12:00');

    // The number as the rows themselves give it: the highest id among the items of one booking.
    $highestOwnItem = function (string $uuid): int {
        $highest = DB::scalar('select max(i.id) from tenant_luna.booking_items i join tenant_luna.bookings b on b.id = i.booking_id where b.uuid = ?::uuid', [$uuid]);

        assert(is_int($highest));

        return $highest;
    };

    expect($reader->revision($tenant, $first))->toBe($highestOwnItem($first))
        ->and($reader->revision($tenant, $second))->toBe($highestOwnItem($second))
        // The second booking's items were inserted after the first's, so the schema-wide
        // maximum is the second's, and the first's number is its own and not that.
        ->and($reader->revision($tenant, $first))->toBeLessThan($reader->revision($tenant, $second))
        ->and($reader->revision($tenant, (string) Str::uuid()))->toBe(0)
        ->and($reader->revision($tenant, 'not-a-uuid'))->toBe(0);

    $before = $reader->revision($tenant, $second);

    app(BookingTransitionFactory::class)->reschedule($tenant)->reschedule($second, Actor::customer(), LunaFixture::at('2026-11-03 15:00'));

    expect($reader->revision($tenant, $second))->toBeGreaterThan($before)
        ->and($reader->revision($tenant, $second))->toBe($highestOwnItem($second))
        ->and($reader->revision($tenant, $first))->toBe($highestOwnItem($first));
});

it('tells both about a live request that moved, in the words of a request, with no calendar file and no second approval request', function () {
    $held = expectingRequested($this);

    expectingMove($this, $held['uuid'], $held['token'], '2026-11-03 15:00');

    $customer = seamAt(EXPECTING_CUSTOMER);
    $business = seamAt(EXPECTING_OWNER);
    $mine = (string) $customer->getTextBody();
    $theirs = (string) $business->getTextBody();
    // The message's own words, less the closing line every customer message carries to My bookings
    // while that key is on, whose page is the customer's bookings and not this request.
    $closing = MyBookingsLine::closing(app(TenantUrls::class)->myBookings(Slug::of('luna'), SiteLanguage::English), SiteLanguage::English);
    $words = str_replace($closing, '', $mine);

    // Exactly the two: the request the owner already holds is not sent again, and its links
    // are signed over the booking and the user rather than the time, so they still decide it.
    expect(seamAddresses())->toBe([EXPECTING_CUSTOMER, EXPECTING_OWNER])
        ->and(LunaFixture::row($held['uuid']))->toMatchArray(['status' => 'awaiting_approval', 'hold_class' => 'approval'])
        ->and($customer->getSubject())->toBe(expectingLine('moved.request.subject'))
        ->and($mine)->toContain(expectingLine('moved.request.by_customer', to: '2026-11-03 15:00'))
        ->and($mine)->toContain(expectingLine('moved.unchanged'))
        ->and($mine)->toContain('Bridal trial')
        ->and($mine)->toContain($closing)
        // Worded as a request throughout: neither a booking's opening nor what a booking's
        // move says after the times.
        ->and($words)->not->toContain(expectingLine('moved.booking.by_customer', to: '2026-11-03 15:00'))
        ->and($words)->not->toContain(expectingLine('moved.calendar'))
        ->and($words)->not->toContain(expectingLine('moved.awaiting_payment'))
        // No file: the acknowledgement carried none and the approval's will be the first, so a
        // file here would put a later revision into the customer's calendar before the first.
        ->and($customer->getAttachments())->toBe([])
        ->and($business->getSubject())->toBe(expectingNotice('move_notice.request.subject'))
        ->and($theirs)->toContain(expectingNotice('move_notice.request.opening', to: '2026-11-03 15:00'))
        ->and($theirs)->toContain('3 Nov 2026, 15:00')
        ->and($theirs)->toContain(expectingNotice('move_notice.by.customer'))
        ->and($theirs)->not->toContain('/decide/')
        ->and($theirs)->not->toContain('approve')
        ->and($business->getAttachments())->toBe([]);
});

it('tells a customer whose request was approved before the queue reached the move that it moved, and nothing about how the request stands, because by then it stands differently', function () {
    $held = expectingRequested($this);

    // The queue takes the jobs instead of running them inside the dispatch, which is the only
    // way to get an approval between the move and its message.
    config(['queue.default' => 'database']);

    expectingMove($this, $held['uuid'], $held['token'], '2026-11-03 15:00');
    app(BookingTransitionFactory::class)->approve(LunaFixture::tenant())->approve($held['uuid'], Actor::user(tenantUserId(EXPECTING_OWNER)));

    expect(seamSent())->toBe([])
        ->and(DB::table('jobs')->count())->toBe(3);

    Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--memory' => 0, '--no-interaction' => true]);

    $sent = seamSent();
    $moved = (string) $sent[0]->getTextBody();

    // Three messages in the order their transitions happened: the customer's move, the business's
    // notice of it, and the approval with the first calendar file. The move is still worded as the
    // move of a request, and says nothing about the request as it stands, because "nothing else has
    // changed" would be false of a request approved since.
    expect(seamAddresses())->toBe([EXPECTING_CUSTOMER, EXPECTING_OWNER, EXPECTING_CUSTOMER])
        ->and(LunaFixture::row($held['uuid'])['status'])->toBe('confirmed')
        ->and($sent[0]->getSubject())->toBe(expectingLine('moved.request.subject'))
        ->and($moved)->toContain(expectingLine('moved.request.by_customer', to: '2026-11-03 15:00'))
        ->and($moved)->not->toContain(expectingLine('moved.unchanged'))
        ->and($sent[0]->getAttachments())->toBe([])
        ->and($sent[1]->getSubject())->toBe(expectingNotice('move_notice.request.subject'))
        ->and((string) $sent[2]->getTextBody())->toContain(expectingLine('approved.confirmed.approved'))
        ->and((string) $sent[2]->getTextBody())->toContain('3 Nov 2026, 15:00')
        ->and($sent[2]->getAttachments())->toHaveCount(1)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('goes on telling the other audience when one key is switched off, because one switch never silences two audiences', function (string $off, string $act, string $stillTold) {
    $held = expectingConfirmed($this);
    seamSwitchOff([$off]);

    match ($act) {
        'cancel' => expectingCancel($this, $held['uuid'], $held['token']),
        'move' => expectingMove($this, $held['uuid'], $held['token']),
        default => throw new LogicException(sprintf('There is no act called "%s" here.', $act)),
    };

    expect(seamAddresses())->toBe([$stillTold]);
})->with([
    'cancelled off, the business still hears' => ['cancelled', 'cancel', EXPECTING_OWNER],
    'cancellation_notice off, the customer still hears' => ['cancellation_notice', 'cancel', EXPECTING_CUSTOMER],
    'moved off, the business still hears' => ['moved', 'move', EXPECTING_OWNER],
    'move_notice off, the customer still hears' => ['move_notice', 'move', EXPECTING_CUSTOMER],
]);

it('sends each of the four under its own key and under no other, with every other key in the catalog switched off', function (string $key, string $act, string $address) {
    // What a message is keyed is not visible in the mail it becomes, so it is asked of the one
    // thing that reads the key: the switches. Everything in the catalog but this key is off, so
    // a message that still goes carries this key and nothing else could have sent it.
    $held = expectingConfirmed($this);
    seamSwitchOff(seamEveryKeyBut(MessageKey::from($key)));

    match ($act) {
        'cancel' => expectingCancel($this, $held['uuid'], $held['token']),
        'move' => expectingMove($this, $held['uuid'], $held['token']),
        default => throw new LogicException(sprintf('There is no act called "%s" here.', $act)),
    };

    expect(seamAddresses())->toBe([$address]);
})->with([
    'the cancellation to the customer' => ['cancelled', 'cancel', EXPECTING_CUSTOMER],
    'the cancellation notice to the business' => ['cancellation_notice', 'cancel', EXPECTING_OWNER],
    'the move to the customer' => ['moved', 'move', EXPECTING_CUSTOMER],
    'the move notice to the business' => ['move_notice', 'move', EXPECTING_OWNER],
]);

it('sends the move its transition made true even where the booking was cancelled before the worker reached it, then the cancellation, with nothing readable in the rows', function () {
    $held = expectingConfirmed($this);

    // The queue takes the jobs instead of running them inside the dispatch, which is the only
    // way to get between a transition and its send.
    config(['queue.default' => 'database']);

    expectingMove($this, $held['uuid'], $held['token']);

    expect(seamSent())->toBe([])
        ->and(DB::table('jobs')->count())->toBe(2);

    expectingCancel($this, $held['uuid'], $held['token']);

    /** @var list<stdClass> $queued */
    $queued = DB::table('jobs')->orderBy('id')->get()->all();

    // Four messages and the cancel's payment flag, which finds no payment landed for this
    // booking and asks nothing.
    expect($queued)->toHaveCount(5);

    foreach ($queued as $row) {
        assert(is_string($row->payload));

        // Queued in the clear, and clear of anything that must not be: the tenant, the
        // booking, two facts and an instant, never a person and never the credential.
        expect($row->payload)->not->toContain($held['token'])
            ->and($row->payload)->not->toContain(EXPECTING_CUSTOMER)
            ->and($row->payload)->not->toContain('Perpetua');
    }

    Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--memory' => 0, '--no-interaction' => true]);

    $sent = seamSent();

    // A transition message announces what happened. The move happened, and so did the
    // cancellation, and a customer who did both is owed both in that order. The move's message
    // reads the booking as it stands, cancelled, and still says it moved.
    expect(seamAddresses())->toBe([EXPECTING_CUSTOMER, EXPECTING_OWNER, EXPECTING_CUSTOMER, EXPECTING_OWNER])
        ->and((string) $sent[0]->getTextBody())->toContain(expectingLine('moved.booking.by_customer'))
        ->and($sent[0]->getAttachments())->toHaveCount(1)
        ->and((string) $sent[1]->getTextBody())->toContain(expectingNotice('move_notice.by.customer'))
        ->and((string) $sent[2]->getTextBody())->toContain(expectingLine('cancelled.booking.by_customer.cancelled'))
        ->and((string) $sent[3]->getTextBody())->toContain(expectingNotice('cancellation_notice.by.customer'))
        ->and(LunaFixture::row($held['uuid'])['status'])->toBe('cancelled')
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('sends nothing at all for a booking that is no longer there, because a re-read that finds nothing has nothing to say', function () {
    $held = expectingConfirmed($this);
    $from = LunaFixture::at('2026-11-03 10:00');

    DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->delete();

    $log = new SeamLog;
    Log::swap($log);

    expectingPublish(new BookingCancelled($held['uuid'], BookingStatus::Confirmed, BookingStatus::Cancelled, null, Actor::customer()));
    expectingPublish(new BookingRescheduled($held['uuid'], BookingStatus::Confirmed, null, Actor::customer(), $from, LunaFixture::at('2026-11-04 15:00')));

    expect(seamSent())->toBe([])
        ->and($log->errors())->toBe([]);
});

it('tells the business although the customer message failed before it, because one listener is not the other', function () {
    $held = expectingConfirmed($this);

    $log = new SeamLog;
    Log::swap($log);

    // The customer's move job reads the clock to stamp its calendar file and the business's
    // notice reads no clock at all, so a clock that cannot answer fails exactly one of the two.
    // The record is announced directly because the move itself reads the clock for its window.
    app()->instance(Clock::class, new HaltedClock);

    expectingPublish(new BookingRescheduled($held['uuid'], BookingStatus::Confirmed, null, Actor::customer(), LunaFixture::at('2026-11-03 10:00'), LunaFixture::at('2026-11-04 15:00')));

    $errors = $log->errors();

    expect(seamAddresses())->toBe([EXPECTING_OWNER])
        ->and(array_map(fn (array $record): mixed => $record['context']['job'] ?? null, $errors))->toBe([SendMove::class])
        ->and(array_map(fn (array $record): mixed => $record['context']['booking'] ?? null, $errors))->toBe([$held['uuid']])
        ->and(array_map(fn (array $record): mixed => $record['message'], $errors))->toBe(['A message could not be queued'])
        ->and($log->text())->not->toContain($held['token'])
        ->and($log->text())->not->toContain(EXPECTING_CUSTOMER);
});

it('cancels the booking whatever the transport does, and logs neither the address nor the token', function () {
    $held = expectingConfirmed($this);
    $log = new SeamLog;
    Log::swap($log);
    seamMailer()->setSymfonyTransport(new RefusingTransport);

    expectingCancel($this, $held['uuid'], $held['token']);

    $errors = $log->errors();

    expect(array_map(fn (array $record): mixed => $record['context']['key'] ?? null, $errors))->toBe(['cancelled', 'cancellation_notice'])
        ->and(array_map(fn (array $record): mixed => $record['context']['exception'] ?? null, $errors))->toBe([TransportException::class, TransportException::class])
        ->and(array_map(fn (array $record): mixed => $record['context']['booking'] ?? null, $errors))->toBe([$held['uuid'], $held['uuid']])
        ->and($log->text())->not->toContain(EXPECTING_CUSTOMER)
        ->and($log->text())->not->toContain(EXPECTING_OWNER)
        ->and($log->text())->not->toContain($held['token'])
        ->and(LunaFixture::row($held['uuid'])['status'])->toBe('cancelled');
});
