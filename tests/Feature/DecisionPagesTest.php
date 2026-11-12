<?php

use App\Application\Checkpoint;
use App\Application\Clock;
use App\Application\Event\BookingApproved;
use App\Application\Event\BookingRejected;
use App\Application\Event\TransitionEvent;
use App\Application\GuardedWrite;
use App\Domain\Audit\Actor;
use App\Domain\Enum\Decision;
use App\Infrastructure\BookingTransitionFactory;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\Busy;
use App\Tenancy\DecisionLinks;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\Support\LunaFixture;

pest()->use(DatabaseTruncation::class);

/*
 * The signed decision links and the two pages they open, over luna's real rows: what a GET
 * does, what a POST does, who may do either, and what a link that is late or replayed answers.
 *
 * Not wrapped in a transaction: the hold and every decision enter the transaction runner, which
 * refuses to open inside another.
 *
 * The clock is the application's own, unpinned, and the request is placed relative to today
 * rather than on a named date. Whether there is still a decision to make is the database's
 * answer, hold_expires_at against now() inside the statement, and the hold writes that column
 * from the same clock; a file that pinned the application's clock weeks away would be reading a
 * window the server wrote against a clock that wrote none of it, which is exactly the drift
 * these pages may not have. A test that wants a clock of its own binds one for itself, and what
 * it proves there is the link's own lifetime, which is a credential's question and not the
 * booking's.
 *
 * luna is in Europe/Athens. Bridal trial is Elena's one service that requires approval and she
 * works Tuesdays 09:00 to 13:00, so a hold on the Tuesday of the first whole availability
 * window lands awaiting_approval on an approval hold, which is what the owner decides on.
 */

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
});

afterEach(function () {
    Checkpoint::observe(null);
    DB::purge('decision_probe');
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * A request lodged with the business, taken the way the booking page takes one. Its window is
 * the one the hold wrote, 48 hours from the server's own clock, and nothing here moves it: what
 * the pages ask about it they ask of the database.
 */
function decisionRequested(): string
{
    return LunaFixture::hold(['Bridal trial'], decisionStart()->format('Y-m-d H:i'));
}

/**
 * When the request is for: Athens 09:00 on the Tuesday of the first whole availability window,
 * which lies wholly in the future whatever the clock reads.
 */
function decisionStart(): DateTimeImmutable
{
    return firstWindowDay(2)->setTime(9, 0);
}

/**
 * The request's window closed, with the sweeper not yet past it: written by the database's own
 * clock, because that is the clock that decides whether a hold is still live and the only one
 * whose answer the pages read.
 */
function decisionWindowClosed(string $uuid): void
{
    DB::update(
        "update tenant_luna.bookings set hold_expires_at = now() - interval '1 minute' where uuid = ?::uuid",
        [$uuid],
    );
}

/**
 * Now, as an instant naming its zone, and the two a test forges a link's own expiry from. A
 * week is further than any clock in this cluster drifts and further than any test's run takes.
 */
function decisionNow(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function decisionAhead(): DateTimeImmutable
{
    return decisionNow()->add(new DateInterval('P7D'));
}

function decisionBehind(): DateTimeImmutable
{
    return decisionNow()->sub(new DateInterval('P7D'));
}

/**
 * When the request's window closes, which is the expiry the link carries, read from the row
 * rather than computed here.
 */
function decisionWindowOf(string $uuid): DateTimeImmutable
{
    $booking = app(BookingReader::class)->snapshot(LunaFixture::tenant(), $uuid);

    assert($booking?->holdExpiresAt instanceof DateTimeImmutable);

    return $booking->holdExpiresAt;
}

/**
 * A link as the approval request carries one: signed, on luna's origin, for one booking, one
 * decision and one user.
 */
function decisionLink(string $uuid, Decision $decision, ?int $user = null, ?DateTimeImmutable $expiry = null): string
{
    return app(DecisionLinks::class)->link(
        LunaFixture::tenant(),
        $uuid,
        $decision,
        $user ?? tenantUserId(),
        $expiry ?? decisionWindowOf($uuid),
    );
}

/**
 * Everything a page said, as one string.
 *
 * @param  TestResponse<Response>  $response
 */
function decisionText(TestResponse $response): string
{
    return (string) $response->getContent();
}

it('renders the confirm page of a link it minted, naming the booking and offering the one decision the link carries', function () {
    $uuid = decisionRequested();

    $response = $this->get(decisionLink($uuid, Decision::Approve));
    $page = decisionText($response);

    $response->assertOk();

    expect($page)->toContain('Luna Hair Studio')
        ->toContain(decisionLine('decision.title', ['business' => 'Luna Hair Studio']))
        ->toContain(LunaFixture::CUSTOMER_NAME)
        ->toContain(decisionLine('decision.facts.item', ['service' => 'Bridal trial', 'staff' => 'Elena Papadaki']))
        // The hour the owner's own diary means, and never the hour the row holds: Athens 09:00
        // is 06:00 or 07:00 in UTC depending on the season, and the page says neither. ICU's
        // medium date and short time on luna's formats, day first on the 24-hour clock.
        ->toContain(lunaInstant(decisionStart()))
        ->toContain(', 09:00')
        ->not->toContain(', '.decisionStart()->setTimezone(new DateTimeZone('UTC'))->format('H:i'))
        ->toContain(decisionLine('decision.facts.total', ['amount' => '150.00 EUR']))
        ->toContain(decisionLine('decision.confirm.waiting').' '.decisionLine('decision.decisions.approve.consequence'))
        ->toContain(decisionLine('decision.decisions.approve.verb'))
        // One decision to a link: the approve link offers approving and nothing else.
        ->not->toContain(decisionLine('decision.decisions.reject.verb'))
        ->not->toContain(decisionLine('decision.confirm.reason'))
        // The zone is named once, on every screen this build renders.
        ->and(substr_count($page, 'Europe/Athens'))->toBe(1);
});

it('offers the reason box on the reject page and on no other', function () {
    $uuid = decisionRequested();

    expect(decisionText($this->get(decisionLink($uuid, Decision::Reject))))
        ->toContain(decisionLine('decision.decisions.reject.verb'))
        ->toContain(decisionLine('decision.decisions.reject.consequence'))
        ->toContain(decisionLine('decision.confirm.reason'))
        ->toContain('name="reason"')
        ->and(decisionText($this->get(decisionLink($uuid, Decision::Approve))))
        ->not->toContain('name="reason"');
});

it('decides nothing on a GET however many times the link is fetched, because a mail scanner fetches every one of them', function () {
    $uuid = decisionRequested();
    $link = decisionLink($uuid, Decision::Approve);
    $published = [];

    // Every transition record and not the approval alone: the claim is that the GET publishes
    // nothing at all, so listening for the one event it might have published would pass on a
    // page that rejected, cancelled or moved the booking instead. The marker is what every
    // record carries and the dispatcher resolves a listener by the interfaces an event
    // implements, TransitionEvent.
    Event::listen(TransitionEvent::class, function (TransitionEvent $event) use (&$published): void {
        $published[] = $event::class;
    });

    for ($fetch = 0; $fetch < 10; $fetch++) {
        $this->get($link)->assertOk();
    }

    expect(LunaFixture::row($uuid)['status'])->toBe('awaiting_approval')
        ->and(array_map(fn (array $row): mixed => $row['action'], LunaFixture::trail()))->toBe(['held'])
        ->and($published)->toBe([]);
});

it('shows the customer email and phone to a user who runs the diary and to no other, because a page is a surface like any other', function () {
    $uuid = decisionRequested();
    $staff = addTenantUser('elena@luna.example', 'staff', staff: 'Elena Papadaki');

    $owner = decisionText($this->get(decisionLink($uuid, Decision::Approve)));
    $chair = decisionText($this->get(decisionLink($uuid, Decision::Approve, $staff)));

    // customer_email and customer_phone require manageBookings, and approving a booking does
    // not qualify. Both pages name the customer, because a name is how a person recognises
    // the morning they are being asked about.
    expect($owner)->toContain(LunaFixture::CUSTOMER_EMAIL)
        ->toContain(LunaFixture::CUSTOMER_PHONE)
        ->and($chair)->toContain(LunaFixture::CUSTOMER_NAME)
        ->not->toContain(LunaFixture::CUSTOMER_EMAIL)
        ->not->toContain(LunaFixture::CUSTOMER_PHONE);
});

/**
 * A link with one of the five things the signature covers changed. Each is spelled here rather
 * than in the dataset, so what a case names is a change to the link and not a closure the test
 * has to run to find out what it meant.
 */
function decisionTampered(string $link, string $uuid, string $part): string
{
    return match ($part) {
        // The first character of the signature, changed to a different one. Replacing it with
        // a fixed letter would leave one link in sixteen untouched, and a link nobody changed
        // verifies: the case would pass or fail on what the hash happened to start with.
        'signature' => (string) preg_replace_callback(
            '/signature=([0-9a-f])/',
            fn (array $matched): string => 'signature='.($matched[1] === 'a' ? 'b' : 'a'),
            $link,
        ),
        'booking' => str_replace($uuid, (string) Str::uuid(), $link),
        'decision' => str_replace('/approve', '/reject', $link),
        'user' => (string) preg_replace('/user=\d+/', 'user=999999', $link),
        'expiry' => (string) preg_replace('/expires=\d+/', 'expires=99999999999', $link),
        default => throw new LogicException(sprintf('A decision link carries no part called "%s".', $part)),
    };
}

it('refuses a link whose signature, booking, decision, user or expiry was changed, and says nothing about the booking while it refuses', function (string $part) {
    $uuid = decisionRequested();
    $link = decisionTampered(decisionLink($uuid, Decision::Approve), $uuid, $part);

    $response = $this->get($link);
    $page = decisionText($response);

    $response->assertForbidden();

    expect($page)->not->toContain($uuid)
        ->not->toContain(LunaFixture::CUSTOMER_NAME)
        ->not->toContain('Bridal trial')
        ->not->toContain(decisionLine('decision.decisions.approve.verb'))
        ->toContain('<title>'.decisionLine('decision.refused.title').'</title>')
        ->toContain('<h1>'.decisionLine('decision.refused.title').'</h1>')
        ->toContain(decisionLine('decision.refused.changed'))
        ->toContain(decisionLine('decision.refused.again'));
})->with([
    'a changed signature' => ['signature'],
    'another booking' => ['booking'],
    'the other decision' => ['decision'],
    'another user' => ['user'],
    'a later expiry' => ['expiry'],
]);
it('refuses a link naming a user of another tenant, and one naming nobody at all', function () {
    $uuid = decisionRequested();
    $elsewhere = tenantUserId('owner@pier.example');

    $this->get(decisionLink($uuid, Decision::Approve, $elsewhere))->assertForbidden();
    $this->get(decisionLink($uuid, Decision::Approve, 999999))->assertForbidden();
});

it('lets a staff user decide a booking with an item on their own row, and refuses them one without', function () {
    $uuid = decisionRequested();
    $elena = addTenantUser('elena@luna.example', 'staff', staff: 'Elena Papadaki');
    $nikos = addTenantUser('nikos@luna.example', 'staff', staff: 'Nikos Georgiou');

    // Bridal trial is Elena's alone, so the booking has an item on her row and none on his.
    $this->get(decisionLink($uuid, Decision::Approve, $elena))->assertOk();
    $this->get(decisionLink($uuid, Decision::Approve, $nikos))->assertForbidden();
    $this->post(decisionLink($uuid, Decision::Approve, $nikos))->assertForbidden();

    $this->post(decisionLink($uuid, Decision::Approve, $elena))->assertOk();

    // approveBookings belongs to a staff member too, scoped to their own row, and it is not
    // manageBookings. She has just decided a booking she may not otherwise touch.
    expect(LunaFixture::row($uuid)['status'])->toBe('confirmed')
        ->and(LunaFixture::row($uuid)['approved_by'])->toBe($elena)
        ->and(array_map(fn (array $row): mixed => $row['actor'], LunaFixture::trail()))->toBe(['customer', 'user:'.$elena]);
});

it('admits an owner and a manager to any booking of their tenant, whoever it is on', function () {
    $uuid = decisionRequested();
    $manager = addTenantUser('marina@luna.example', 'manager');

    $this->get(decisionLink($uuid, Decision::Approve))->assertOk();
    $this->get(decisionLink($uuid, Decision::Approve, $manager))->assertOk();
});

it('verifies a link on the tenant it was minted for and on no other, because the host is inside the signature', function () {
    $uuid = decisionRequested();
    $link = decisionLink($uuid, Decision::Approve);

    $this->get(str_replace('//luna.', '//pier.', $link))->assertForbidden();
});

it('decides as the user the link names when the page posts back, and says so in the row, the trail and the event', function () {
    $uuid = decisionRequested();
    $manager = addTenantUser('marina@luna.example', 'manager');
    $published = [];

    Event::listen(BookingApproved::class, function (BookingApproved $event) use (&$published): void {
        $published[] = $event->uuid;
    });

    $response = $this->post(decisionLink($uuid, Decision::Approve, $manager));

    $response->assertOk();

    // The outcome page, which is what a replay of the same link would get as well: pressing it
    // twice is not a mistake worth a different answer.
    expect(decisionText($response))->toContain(decisionLine('decision.outcome.confirmed'))
        ->and(LunaFixture::row($uuid)['status'])->toBe('confirmed')
        // The decision landed as the person the link was minted for, which is the whole reason
        // a link is minted per user rather than per booking.
        ->and(LunaFixture::row($uuid)['approved_by'])->toBe($manager)
        ->and(array_map(fn (array $row): mixed => $row['actor'], LunaFixture::trail()))->toBe(['customer', 'user:'.$manager])
        ->and(array_map(fn (array $row): mixed => $row['action'], LunaFixture::trail()))->toBe(['held', 'approved'])
        ->and($published)->toBe([$uuid]);
});

it('rejects with the reason the owner typed, and stores it as given', function () {
    $uuid = decisionRequested();
    $owner = tenantUserId();
    $published = [];

    Event::listen(BookingRejected::class, function (BookingRejected $event) use (&$published): void {
        $published[] = $event->uuid;
    });

    $response = $this->post(decisionLink($uuid, Decision::Reject, $owner), ['reason' => 'We are closed that week.']);

    $response->assertOk();

    expect(decisionText($response))->toContain(decisionLine('decision.outcome.rejected'))
        ->and(LunaFixture::row($uuid)['status'])->toBe('rejected')
        ->and(LunaFixture::row($uuid)['rejection_reason'])->toBe('We are closed that week.')
        ->and(array_map(fn (array $row): mixed => $row['actor'], LunaFixture::trail()))->toBe(['customer', 'user:'.$owner])
        ->and(array_map(fn (array $row): mixed => $row['action'], LunaFixture::trail()))->toBe(['held', 'rejected'])
        ->and($published)->toBe([$uuid]);
});

it('rejects with no reason where the box was left empty and where no box was sent at all, because an empty reason never blocks a rejection', function (array $body) {
    $uuid = decisionRequested();

    $this->post(decisionLink($uuid, Decision::Reject), $body)->assertOk();

    // Confirming fires with whatever is in the box, the empty string included. A form always
    // sends its textarea, and the framework's own conversion turns an empty one into no value
    // at all before anything here sees it, so the reason a page can produce is a sentence or
    // nothing.
    expect(LunaFixture::row($uuid)['status'])->toBe('rejected')
        ->and(LunaFixture::row($uuid)['rejection_reason'])->toBeNull();
})->with([
    'an empty box' => [['reason' => '']],
    'a box of spaces' => [['reason' => '   ']],
    'no box at all' => [[]],
]);

it('renders the outcome page and decides nothing for a link past its expiry, on the GET and on the POST alike', function () {
    $uuid = decisionRequested();
    $link = decisionLink($uuid, Decision::Approve, expiry: decisionBehind());

    $page = decisionText($this->get($link));
    $posted = decisionText($this->post($link));

    foreach ([$page, $posted] as $answer) {
        expect($answer)->toContain(decisionLine('decision.outcome.awaiting_approval'))
            ->not->toContain('<button');
    }

    expect(LunaFixture::row($uuid)['status'])->toBe('awaiting_approval')
        ->and(array_map(fn (array $row): mixed => $row['action'], LunaFixture::trail()))->toBe(['held']);
});

it('says what the clock will make of a request whose window closed before anybody answered', function (string $policy, string $sentence) {
    $uuid = decisionRequested();

    DB::table('tenant_luna.bookings')->where('uuid', $uuid)->update(['on_approval_timeout' => $policy]);
    decisionWindowClosed($uuid);

    $link = decisionLink($uuid, Decision::Approve);

    expect(decisionText($this->get($link)))
        ->toContain(decisionLine('decision.outcome.awaiting_approval'))
        ->toContain(decisionLine($sentence))
        ->not->toContain('<button');
})->with([
    'a service that releases the slot' => ['expire', 'decision.window.expire'],
    'a service that says yes for the owner' => ['auto_approve', 'decision.window.auto_approve'],
]);

it('tells a user who does not run the diary that the window closed and nothing about the policy, because on_approval_timeout is a manage-only column', function () {
    $uuid = decisionRequested();
    $elena = addTenantUser('elena@luna.example', 'staff', staff: 'Elena Papadaki');

    DB::table('tenant_luna.bookings')->where('uuid', $uuid)->update(['on_approval_timeout' => 'auto_approve']);
    decisionWindowClosed($uuid);

    // On every surface, the sentence about what happens next is that column in words, so
    // the owner reads it and the chair is told the window has closed.
    expect(decisionText($this->get(decisionLink($uuid, Decision::Approve, $elena))))
        ->toContain(decisionLine('decision.window.closed'))
        ->not->toContain(decisionLine('decision.window.auto_approve'))
        ->not->toContain(decisionLine('decision.window.expire'))
        ->and(decisionText($this->get(decisionLink($uuid, Decision::Approve))))
        ->toContain(decisionLine('decision.window.auto_approve'));
});

it('offers no button on a request the database says is over, however the link reads, because the sweeper and the page answer one question', function () {
    $uuid = decisionRequested();

    decisionWindowClosed($uuid);

    // The link is a week from lapsing and the row's window closed a minute ago. Whether there
    // is still a decision to make is hold_expires_at against now() inside the statement, which
    // is what the sweep selects on and what the re-read under the lock answers; a page reading
    // any other clock would offer a button the sweeper has voided.
    $page = decisionText($this->get(decisionLink($uuid, Decision::Approve, expiry: decisionAhead())));

    expect($page)->toContain(decisionLine('decision.outcome.awaiting_approval'))
        ->toContain(decisionLine('decision.window.expire'))
        ->not->toContain('<button');
});

it('never says a window has closed while the database says it is live, however far the application clock has drifted', function () {
    $uuid = decisionRequested();

    // A clock a fortnight ahead of the server's, which is drift no deployment should have and
    // every deployment eventually does. The row's window is 48 hours from the hold and still
    // open; only the link, whose lifetime is this clock's to judge, has lapsed.
    app()->instance(Clock::class, FixedClock::at(decisionAhead()->add(new DateInterval('P7D'))->format('Y-m-d H:i:s')));

    $page = decisionText($this->get(decisionLink($uuid, Decision::Approve)));

    expect($page)->toContain(decisionLine('decision.outcome.awaiting_approval'))
        ->not->toContain(decisionLine('decision.window.expire'))
        ->not->toContain(decisionLine('decision.window.auto_approve'))
        ->not->toContain(decisionLine('decision.window.closed'))
        ->not->toContain('<button');
});

it('answers the outcome page for a booking that has moved on, naming where it stands now', function (string $status) {
    $uuid = LunaFixture::booking($status);
    $link = decisionLink($uuid, Decision::Approve, expiry: decisionAhead());
    $sentence = decisionLine('decision.outcome.'.$status);

    expect(decisionText($this->get($link)))->toContain($sentence)
        ->not->toContain('<button')
        ->and(decisionText($this->post($link)))->toContain($sentence)
        ->and(LunaFixture::row($uuid)['status'])->toBe($status)
        ->and(LunaFixture::trail())->toBe([]);
})->with([
    'approved already' => ['confirmed'],
    'rejected already' => ['rejected'],
    'cancelled' => ['cancelled'],
    'expired' => ['expired'],
]);

it('renders the outcome page in the tenant clock as well, with the zone named once, because it is a screen like any other', function () {
    // A booking of luna written straight to its rows on a named date, so the hour on the page
    // is exact rather than relative. It has moved on already, which is what the outcome page
    // is the answer to.
    $uuid = LunaFixture::booking('confirmed', start: '2026-11-03 10:00');
    $page = decisionText($this->get(decisionLink($uuid, Decision::Approve, expiry: decisionAhead())));

    expect($page)->toContain(decisionLine('decision.outcome.confirmed'))
        // 10:00 in Athens is 08:00 in the row, November. Every time on every screen renders
        // in the tenant's timezone, and the zone is named once, by the page's own line.
        // ICU's medium date and short time, with no connector word.
        ->toContain('3 Nov 2026, 10:00')
        ->not->toContain(', 08:00')
        ->toContain(decisionLine('decision.facts.zone', ['zone' => 'Europe/Athens']))
        ->and(substr_count($page, 'Europe/Athens'))->toBe(1);
});

it('answers the outcome page to a POST that raced another decision and lost, rather than an error', function () {
    $uuid = decisionRequested();
    $owner = tenantUserId();
    $link = decisionLink($uuid, Decision::Approve, $owner);

    // The page was open in front of somebody while the request was still waiting.
    $this->get($link)->assertOk();

    // Somebody else answered it in the meantime, through the admin or through their own link.
    app(BookingTransitionFactory::class)->reject(LunaFixture::tenant())->reject($uuid, Actor::user($owner));

    $response = $this->post($link);

    $response->assertOk();

    expect(decisionText($response))->toContain(decisionLine('decision.outcome.rejected'))
        ->and(LunaFixture::row($uuid)['status'])->toBe('rejected')
        ->and(array_map(fn (array $row): mixed => $row['action'], LunaFixture::trail()))->toBe(['held', 'rejected']);
});

/**
 * A connection of its own to the same database, for moving a booking while a transition of this
 * one stands at a checkpoint.
 */
function decisionProbe(): Connection
{
    return LunaFixture::probe('decision_probe');
}

it('answers the outcome page to a POST that lost to a move rather than to a decision, because losing a race is not an error whatever it lost to', function () {
    $uuid = decisionRequested();
    $link = decisionLink($uuid, Decision::Approve);
    $moves = 0;

    // A concurrent reschedule, which is the one thing that takes a booking's items off the
    // staff-days a transition locked. The write rolls back and runs again from its pre-read,
    // finds them moved a second time and answers busy. The checkpoint is between the mutex
    // rows and the booking row, so the move is another transaction's and commits on its own;
    // the item rows are locked by nobody.
    Checkpoint::observe(function (string $name) use ($uuid, &$moves): void {
        if ($name !== GuardedWrite::AFTER_LOCK) {
            return;
        }

        $moves++;

        decisionProbe()->update(
            "update tenant_luna.booking_items set start_utc = start_utc + interval '1 day',"
            ." end_utc = end_utc + interval '1 day', block_start_utc = block_start_utc + interval '1 day',"
            ." block_end_utc = block_end_utc + interval '1 day'"
            .' from tenant_luna.bookings b where b.id = booking_id and b.uuid = ?::uuid',
            [$uuid],
        );
    });

    $response = $this->post($link);

    $response->assertOk();

    // The other way a write can lose: the owner is shown where the booking stands rather than
    // the framework's error page, which is what they would otherwise get, since the error
    // envelope answers for the API alone and this is a page.
    expect($moves)->toBe(GuardedWrite::ATTEMPTS)
        ->and(decisionText($response))->toContain(decisionLine('decision.outcome.awaiting_approval'))
        ->not->toContain('<button')
        ->and(LunaFixture::row($uuid)['status'])->toBe('awaiting_approval')
        ->and(array_map(fn (array $row): mixed => $row['action'], LunaFixture::trail()))->toBe(['held']);
});

it('answers the outcome page to a POST whose write met the request\'s own staff-day held past the lock timeout, because a busy lock is a race lost like the others, and the runner\'s line is the one log record', function () {
    $uuid = decisionRequested();
    $link = decisionLink($uuid, Decision::Approve);
    $log = runnerLog();
    $probe = bookingStaffDaysHeld('decision_probe', $uuid);

    try {
        $response = $this->post($link);
    } finally {
        $probe->rollBack();
    }

    $response->assertOk();
    $lines = $log->getRecords();

    expect(decisionText($response))->toContain(decisionLine('decision.outcome.awaiting_approval'))
        ->not->toContain('<button')
        ->and(LunaFixture::row($uuid)['status'])->toBe('awaiting_approval')
        ->and(array_map(fn (array $row): mixed => $row['action'], LunaFixture::trail()))->toBe(['held'])
        ->and($lines)->toHaveCount(1)
        ->and($lines[0]->context['failure'] ?? null)->toBe(Busy::class)
        ->and($lines[0]->context['sqlstate'] ?? null)->toBe('55P03');
});

it('refuses the POST of a link it would refuse the page of, and decides nothing while refusing', function () {
    $uuid = decisionRequested();
    $nikos = addTenantUser('nikos@luna.example', 'staff', staff: 'Nikos Georgiou');

    $this->post(decisionLink($uuid, Decision::Approve, $nikos))->assertForbidden();
    $this->post(decisionLink($uuid, Decision::Approve, tenantUserId('owner@pier.example')))->assertForbidden();

    expect(LunaFixture::row($uuid)['status'])->toBe('awaiting_approval')
        ->and(array_map(fn (array $row): mixed => $row['action'], LunaFixture::trail()))->toBe(['held']);
});

it('carries a cross-site token on the form the page submits, like every other page of this host', function () {
    $uuid = decisionRequested();

    expect(decisionText($this->get(decisionLink($uuid, Decision::Approve))))->toContain('name="_token"');
});

it('is ASCII on every page it renders', function () {
    $uuid = decisionRequested();
    $approve = decisionLink($uuid, Decision::Approve);

    $pages = [
        decisionText($this->get($approve)),
        decisionText($this->get(decisionLink($uuid, Decision::Reject))),
        decisionText($this->get(str_replace('/approve', '/reject', $approve))),
        decisionText($this->post($approve)),
    ];

    foreach ($pages as $page) {
        expect(preg_match('/\A[\x09\x0a\x0d\x20-\x7e]+\z/', $page))->toBe(1);
    }
});

it('answers a link naming a booking this tenant does not have with a 404, because there is no outcome to render', function () {
    $uuid = (string) Str::uuid();

    $this->get(decisionLink($uuid, Decision::Approve, expiry: decisionAhead()))
        ->assertNotFound();
});

it('keeps the link out of every cache and out of the Referer of whatever the page loads, because a signature is a credential', function () {
    $uuid = decisionRequested();

    $this->get(decisionLink($uuid, Decision::Approve))
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Referrer-Policy', 'no-referrer');
});
