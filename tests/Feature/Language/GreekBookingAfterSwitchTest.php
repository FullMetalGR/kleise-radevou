<?php

use App\Application\Clock;
use App\Application\Notification\MessageKey;
use App\Infrastructure\Db\BookingReader;
use App\Jobs\SendReminder;
use App\Site\SiteLanguage;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mime\Email;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;

pest()->use(DatabaseTruncation::class);

/*
 * A booking made in Greek at a tenant whose default is el keeps its language when the default
 * moves. Its confirmation arrives in Greek; the owner then turns the default to English; the
 * reminder its job sends, a move from the manage page and a cancellation from it each arrive in
 * Greek all the same. The move and the cancellation go over the wire as the manage view sends
 * them, with no lang, so nothing but the booking's own language can choose the Greek. Every
 * expected line is read through the translator in el, never spelled here.
 */

/** Where the manage page moves the booking to: the Wednesday at 15:00 in Athens. */
const GBS_MOVED_TO = '2026-11-04 15:00';

beforeEach(function () {
    $this->withoutVite();
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00:00'));
    // The confirmation alone at first: a queue that runs inside the dispatch sends the reminder
    // the confirm schedules at once, so each step below turns on the key it asks about. The
    // business's messages are the other scenario's.
    seamSwitchOff(seamEveryKeyBut(MessageKey::Confirmed));
    seamTransport()->flush();
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * A line of customer-mail.php in Greek, filled with luna's values for the Haircut: the customer,
 * the business, the zone, and the start the move left and the one it took as ICU spells them in
 * Greek.
 */
function gbsLine(string $key): string
{
    return customerMailLine('customer-mail.'.$key, [
        'customer' => LunaFixture::CUSTOMER_NAME,
        'business' => LunaFixture::tenant()->name,
        'service' => 'Haircut',
        'zone' => FixtureDay::ZONE,
        'from' => lunaInstant(LunaFixture::at(FixtureDay::START), SiteLanguage::Greek),
        'to' => lunaInstant(LunaFixture::at(GBS_MOVED_TO), SiteLanguage::Greek),
    ], SiteLanguage::Greek);
}

/**
 * The one message the customer was sent since the transport was last flushed, held to its
 * subject and the lines named, each read in Greek, and to no line of customer-mail.php shown in
 * English.
 *
 * @param  list<string>  $lines
 */
function gbsInGreek(string $subject, array $lines): Email
{
    $mail = seamAt(LunaFixture::CUSTOMER_EMAIL);
    $text = (string) $mail->getTextBody();

    expect($mail->getSubject())->toBe(gbsLine($subject));

    foreach ($lines as $line) {
        expect($text)->toContain(gbsLine($line));
    }

    expect(linesShownIn($mail->getSubject()."\n".$text, 'customer-mail', SiteLanguage::English))->toBe([]);
    seamTransport()->flush();

    return $mail;
}

it('writes a booking made in Greek its confirmation in Greek, and after the tenant\'s default moved to English its reminder, a move from the manage page and a cancellation from it in Greek all the same', function () {
    settingsOf('luna')->update(['language' => SiteLanguage::Greek->value]);
    [$url, $body] = seamHold('Haircut', FixtureDay::START);
    ['uuid' => $uuid, 'token' => $token] = seamHeld($this->postJson($url, $body));
    seamConfirm($this, $uuid, $token);

    expect(DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('language'))->toBe('el');
    gbsInGreek('confirmed.subject', ['greeting', 'confirmed.confirmed', 'zone']);

    // The owner turns the default to English; the booking keeps its own.
    settingsOf('luna')->update(['language' => SiteLanguage::English->value]);
    seamSwitchOff(seamEveryKeyBut(MessageKey::Reminder));

    $reader = app(BookingReader::class);
    $booking = $reader->snapshot(LunaFixture::tenant(), $uuid) ?? throw new LogicException('No booking '.$uuid);
    app(Dispatcher::class)->dispatchNow(new SendReminder(LunaFixture::tenant()->id, $uuid, SendReminder::instant($booking->startsAt()), $reader->revision(LunaFixture::tenant(), $uuid)));
    gbsInGreek('reminder.subject', ['greeting', 'reminder.reminder', 'zone']);

    seamSwitchOff(seamEveryKeyBut(MessageKey::Moved, MessageKey::Cancelled));
    $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'/reschedule'), [
        'token' => $token,
        'start' => LunaFixture::at(GBS_MOVED_TO)->format(DateTimeImmutable::RFC3339),
    ])->assertOk();
    gbsInGreek('moved.booking.subject', ['greeting', 'moved.booking.by_customer', 'moved.calendar', 'zone']);

    $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'/cancel'), ['token' => $token])->assertOk();
    gbsInGreek('cancelled.booking.by_customer.subject', ['greeting', 'cancelled.booking.by_customer.cancelled', 'cancelled.booking.by_customer.means', 'zone']);

    expect(settingsOf('luna')->value('language'))->toBe('en')
        ->and(DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('language'))->toBe('el');
});
