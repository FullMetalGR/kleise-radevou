<?php

use App\Application\Clock;
use App\Application\Notification\MessageKey;
use App\Domain\Enum\HoldClass;
use App\Infrastructure\Db\BookingReader;
use App\Jobs\SendConfirmation;
use App\Notifications\Confirmation;
use App\Notifications\CustomerMailLines;
use App\Notifications\IcsBuilder;
use App\Site\SiteLanguage;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Part\DataPart;
use Tests\Support\FixedClock;
use Tests\Support\HaltedClock;
use Tests\Support\LunaFixture;
use Tests\Support\RefusingTransport;
use Tests\Support\SeamLog;

pest()->use(DatabaseTruncation::class);

/*
 * The confirmation and the new-booking notice over luna's real rows: who hears about a settled
 * booking, what each of them is told, what travels with it, and what a switched-off key does to
 * one audience without touching the other.
 *
 * Not wrapped in a transaction: the hold and the confirm enter the transaction runner, which
 * refuses to open inside another.
 *
 * The seam helpers, seamHold, seamHeld, seamSent, seamOnly, seamAt, seamAddresses, seamSwitchOff,
 * seamEveryKeyBut, seamConfirm and seamAnnounceConfirmed, live in tests/Pest.php, and SeamLog,
 * RefusingTransport and HaltedClock under tests/Support. They are shared rather than copied, so
 * that two notification tests cannot acquire two ideas of what a sent message is, and this file
 * runs alone.
 *
 * luna is in Europe/Athens, UTC+2 in November. 2026-11-03 is a Tuesday, when Elena works 09:00
 * to 13:00. Haircut is the ordinary pay-on-site service, Bridal trial the one requiring
 * approval. The clock the planner reads stands on the Monday before. luna's demo rows carry one
 * user, the owner, and its named staff rows have no addresses of their own, so the business
 * recipient rule resolves to the owner alone unless a test seeds otherwise, and one here does;
 * the rule itself is proved over its own fixtures in BusinessRecipientsTest.
 */

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00:00'));
    seamTransport()->flush();

    // A confirmed booking schedules the reminder as well, which under the suite's sync queue
    // runs inside the confirm and would be counted here as a third message. It is switched
    // off at the tenant rather than filtered out of every assertion: a lead of zero is how a
    // tenant sends no reminders, and the reminder has its own file, ReminderSeamTest.
    settingsOf('luna')->update(['reminder_lead_hours' => 0]);
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

it('confirms to the customer with the calendar file and a link that works, and tells the business it happened', function () {
    [$url, $body] = seamHold('Haircut');
    $held = seamHeld($this->postJson($url, $body));
    seamConfirm($this, $held['uuid'], $held['token']);

    // Under the prefix of the booking's language, English here.
    $link = app(TenantUrls::class)->manageLink(Slug::of('luna'), $held['uuid'], $held['token'], SiteLanguage::English);
    $customer = seamAt('perpetua.vandersnoot@example.test');
    $business = seamAt('owner@luna.example');
    $text = (string) $customer->getTextBody();

    expect(seamSent())->toHaveCount(2)
        ->and($customer->getSubject())->toBe(customerMailLine('customer-mail.confirmed.subject', ['business' => 'Luna Hair Studio']))
        ->and($text)->toContain(customerMailLine('customer-mail.confirmed.confirmed', ['business' => 'Luna Hair Studio']))
        ->and($text)->toContain('Haircut')
        // 10:00 in Athens is 08:00 in the row. The customer is told the hour the salon means.
        ->and($text)->toContain('3 Nov 2026, 10:00')
        ->and($text)->not->toContain(', 08:00')
        ->and(substr_count($text, 'Europe/Athens'))->toBe(1)
        ->and($text)->toContain(customerMailLine('customer-mail.zone', ['zone' => 'Europe/Athens', 'business' => 'Luna Hair Studio']))
        ->and($text)->toContain('35.00 EUR')
        ->and(substr_count($text, $link))->toBe(1)
        // luna's owner set no Important info, so there is no heading over nothing.
        ->and($text)->not->toContain(__(Confirmation::IMPORTANT_INFO_HEADING, [], 'en'));

    $attachments = $customer->getAttachments();

    expect(array_map(fn (DataPart $part): ?string => $part->getFilename(), $attachments))->toBe(['booking.ics'])
        ->and(array_map(fn (DataPart $part): string => $part->getMediaType().'/'.$part->getMediaSubtype(), $attachments))->toBe(['text/calendar'])
        ->and($attachments[0]->getBody())->toContain('UID:'.$held['uuid'])
        ->and($attachments[0]->getBody())->not->toContain($held['token']);

    $notice = (string) $business->getTextBody();

    expect($notice)->toContain('Haircut')
        ->and($notice)->toContain('Perpetua Vandersnoot')
        ->and($notice)->not->toContain($held['token'])
        ->and($notice)->not->toContain('/manage/')
        ->and($business->getAttachments())->toBe([]);

    // The link in the customer's hand is the one the manage route answers.
    $this->getJson(tenantUrl('luna', '/api/v1/bookings/'.$held['uuid'].'?token='.$held['token']))
        ->assertOk()
        ->assertJsonPath('uuid', $held['uuid']);

    expect(array_map(fn (array $row): mixed => $row['action'], LunaFixture::trail()))->toBe(['held', 'confirmed']);
});

it('tells everybody the rule names, once each, where the business is more than one person', function () {
    // The arrangement luna's demo rows do not have: a second person who runs the diary, and a
    // chair with an address of her own. The rule itself is proved over its own fixtures in
    // BusinessRecipientsTest; what is on trial here is that a confirm reaches it.
    addTenantUser('marina@luna.example', 'manager');

    DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->update(['email' => 'elena@luna.example']);

    [$url, $body] = seamHold('Haircut');
    $held = seamHeld($this->postJson($url, $body));
    seamConfirm($this, $held['uuid'], $held['token']);

    $addresses = seamAddresses();
    sort($addresses);

    $manager = (string) seamAt('marina@luna.example')->getTextBody();
    $chair = (string) seamAt('elena@luna.example')->getTextBody();

    expect($addresses)->toBe([
        'elena@luna.example',
        'marina@luna.example',
        'owner@luna.example',
        'perpetua.vandersnoot@example.test',
    ])
        // One message each and not one message to four people: a mailbox that refuses silences
        // nobody else, and a decision link is signed per person.
        ->and($manager)->toContain('Perpetua Vandersnoot')
        ->and($chair)->toContain('Haircut')
        // The manager holds manageBookings and is told how to reach the customer; the chair's
        // own mailbox belongs to a person who never signs in and holds nothing, so she is told
        // whose booking it is and no more.
        ->and($manager)->toContain('perpetua.vandersnoot@example.test')
        ->and($manager)->toContain('+30 210 1234567')
        ->and($chair)->toContain('Perpetua Vandersnoot')
        ->and($chair)->not->toContain('perpetua.vandersnoot@example.test')
        ->and($chair)->not->toContain('+30 210 1234567');
});

it('tells the business nothing where the business is the one who decided, and confirms to the customer all the same', function () {
    [$url, $body] = seamHold('Bridal trial');
    $held = seamHeld($this->postJson($url, $body));

    // The two messages the hold itself sent, the customer's acknowledgement and the business's
    // request to decide, are not this test's subject.
    seamTransport()->flush();

    // The request is still awaiting its decision, and the announcement says it was confirmed. A
    // transition message announces what happened, so a re-read finding the booking elsewhere
    // still sends and the later transition's own message follows.
    seamAnnounceConfirmed($held['uuid'], HoldClass::Approval, $held['token']);

    expect(seamAddresses())->toBe(['perpetua.vandersnoot@example.test'])
        ->and((string) seamOnly()->getTextBody())->toContain('Bridal trial');
});

it('tells the business although the customer message failed before it, because one listener is not the other', function () {
    [$url, $body] = seamHold('Haircut');
    $held = seamHeld($this->postJson($url, $body));
    seamTransport()->flush();

    $log = new SeamLog;
    Log::swap($log);

    // The customer's listener is registered first, and what it hands to the queue runs inside
    // the dispatch under the sync driver. A fault in there would reach the dispatcher, which
    // stops at the first listener that throws, and the business would hear nothing about a
    // booking nobody at the business had been asked about.
    app()->instance(Clock::class, new HaltedClock);

    seamAnnounceConfirmed($held['uuid'], HoldClass::Checkout, $held['token']);

    $errors = $log->errors();

    expect(seamAddresses())->toBe(['owner@luna.example'])
        ->and(array_map(fn (array $record): mixed => $record['context']['job'] ?? null, $errors))->toBe([SendConfirmation::class])
        ->and(array_map(fn (array $record): mixed => $record['context']['booking'] ?? null, $errors))->toBe([$held['uuid']])
        ->and(array_map(fn (array $record): mixed => $record['context']['tenant'] ?? null, $errors))->toBe(['luna'])
        // The failure is the listener's own line, not the publisher's catch one level up, and
        // it names neither the credential nor a person.
        ->and(array_map(fn (array $record): mixed => $record['message'], $errors))->toBe(['A message could not be queued'])
        ->and($log->text())->not->toContain($held['token'])
        ->and($log->text())->not->toContain('perpetua.vandersnoot@example.test');
});

it('carries the tenant\'s Important info to the customer under its heading where the owner set it, and not to the business', function () {
    settingsOf('luna')->update(['important_info' => 'Please arrive five minutes early.']);
    [$url, $body] = seamHold('Haircut');
    $held = seamHeld($this->postJson($url, $body));
    seamConfirm($this, $held['uuid'], $held['token']);

    expect(seamSent())->toHaveCount(2)
        // Under the review step's own heading, one key of booking.php, read in the booking's
        // language; the owner's words as typed.
        ->and((string) seamAt('perpetua.vandersnoot@example.test')->getTextBody())->toContain(__(Confirmation::IMPORTANT_INFO_HEADING, [], 'en')."\nPlease arrive five minutes early.")
        ->and((string) seamAt('owner@luna.example')->getTextBody())->not->toContain('Please arrive five minutes early.');
});

it('is the confirmation too where there was no hold at all, and carries no manage link where no token came with it, Book again and the closing line its only links', function () {
    [$url, $body] = seamHold('Haircut');
    $held = seamHeld($this->postJson($url, $body));

    seamAnnounceConfirmed($held['uuid'], null);

    $text = (string) seamOnly()->getTextBody();

    expect(seamAddresses())->toBe(['perpetua.vandersnoot@example.test'])
        ->and($text)->not->toContain('/manage/')
        ->and($text)->not->toContain($held['token'])
        ->and($text)->toContain('Haircut')
        // Book again preselecting Haircut on Elena, and My bookings: neither carries a credential,
        // each under the prefix of the booking's language.
        ->and(seamLinksIn($text))->toBe([
            app(TenantUrls::class)->bookingLink(Slug::of('luna'), ['service' => seamId('services', 'Haircut'), 'staff' => seamId('staff', 'Elena Papadaki')], SiteLanguage::English),
            app(TenantUrls::class)->myBookings(Slug::of('luna'), SiteLanguage::English),
        ])
        ->and(seamOnly()->getAttachments())->toHaveCount(1);
});

it('still tells the business when the customer message is switched off, and the confirm still answers 200', function () {
    seamSwitchOff(['confirmed']);

    [$url, $body] = seamHold('Haircut');
    $held = seamHeld($this->postJson($url, $body));
    seamConfirm($this, $held['uuid'], $held['token']);

    expect(seamAddresses())->toBe(['owner@luna.example']);
});

it('still confirms to the customer when the business notice is switched off', function () {
    seamSwitchOff(['new_booking']);

    [$url, $body] = seamHold('Haircut');
    $held = seamHeld($this->postJson($url, $body));
    seamConfirm($this, $held['uuid'], $held['token']);

    expect(seamAddresses())->toBe(['perpetua.vandersnoot@example.test'])
        ->and(seamOnly()->getAttachments())->toHaveCount(1);
});

it('sends each of the two under its own key and under no other, with every other key in the catalog switched off', function (string $key, string $address) {
    // What a message is keyed is not visible in the mail it becomes, so it is asked of the one
    // thing that reads the key: the switches. Everything in the catalog but this key is off, so
    // a message that still goes carries this key and nothing else could have sent it. The list
    // is built from MessageKey::cases(), so a key added later joins the test by existing.
    seamSwitchOff(seamEveryKeyBut(MessageKey::from($key)));

    [$url, $body] = seamHold('Haircut');
    $held = seamHeld($this->postJson($url, $body));
    seamConfirm($this, $held['uuid'], $held['token']);

    expect(seamAddresses())->toBe([$address]);
})->with([
    'the customer confirmation' => ['confirmed', 'perpetua.vandersnoot@example.test'],
    'the business notice' => ['new_booking', 'owner@luna.example'],
]);

it('confirms the booking whatever the transport does, and logs neither the address nor the token', function () {
    $log = new SeamLog;
    Log::swap($log);
    seamMailer()->setSymfonyTransport(new RefusingTransport);

    [$url, $body] = seamHold('Haircut');
    $held = seamHeld($this->postJson($url, $body));
    seamConfirm($this, $held['uuid'], $held['token']);

    $errors = $log->errors();

    expect(array_map(fn (array $record): mixed => $record['context']['key'] ?? null, $errors))->toBe(['confirmed', 'new_booking'])
        ->and(array_map(fn (array $record): mixed => $record['context']['exception'] ?? null, $errors))->toBe([TransportException::class, TransportException::class])
        ->and(array_map(fn (array $record): mixed => $record['context']['booking'] ?? null, $errors))->toBe([$held['uuid'], $held['uuid']])
        ->and($log->text())->not->toContain('perpetua.vandersnoot@example.test')
        ->and($log->text())->not->toContain('owner@luna.example')
        ->and($log->text())->not->toContain($held['token'])
        ->and(array_map(fn (array $row): mixed => $row['action'], LunaFixture::trail()))->toBe(['held', 'confirmed']);
});

it('stamps the calendar file with the instant it was built at, from the one clock the application reads', function () {
    [$url, $body] = seamHold('Haircut');
    $held = seamHeld($this->postJson($url, $body));
    seamConfirm($this, $held['uuid'], $held['token']);

    $file = seamAt('perpetua.vandersnoot@example.test')->getAttachments()[0]->getBody();

    // The fixed clock this file binds stands at 06:00 UTC on the Monday, and the stamp is what
    // a second file for the same booking is compared against when one is sent.
    expect($file)->toContain('DTSTAMP:20261102T060000Z')
        ->and($file)->toContain(icsSequenceLine(0))
        ->and($file)->toContain('DTSTART:20261103T080000Z');
});

it('carries the whole chain as one calendar entry, from the first segment to the last', function () {
    $held = seamHeld($this->postJson(tenantUrl('luna', '/api/v1/holds'), [
        'customer_name' => 'Perpetua Vandersnoot',
        'customer_email' => 'perpetua.vandersnoot@example.test',
        'items' => [
            ['service_id' => seamId('services', 'Colour'), 'staff_id' => seamId('staff', 'Elena Papadaki')],
            ['service_id' => seamId('services', 'Haircut'), 'staff_id' => seamId('staff', 'Elena Papadaki')],
        ],
        'start' => (new DateTimeImmutable('2026-11-03 09:00', new DateTimeZone('Europe/Athens')))->format(DateTimeImmutable::RFC3339),
    ]));

    seamConfirm($this, $held['uuid'], $held['token']);

    $file = seamAt('perpetua.vandersnoot@example.test')->getAttachments()[0]->getBody();
    $snapshot = app(BookingReader::class)->snapshot(Tenant::query()->where('slug', 'luna')->sole(), $held['uuid']) ?? throw new RuntimeException('The booking is gone.');
    $items = DB::select('select start_utc::text as start_utc, end_utc::text as end_utc from tenant_luna.booking_items order by sort');

    expect($items)->toHaveCount(2);

    [$first, $last] = $items;

    assert($first instanceof stdClass && is_string($first->start_utc));
    assert($last instanceof stdClass && is_string($last->end_utc));

    // The column comes back as text carrying its offset, and the session this cluster hands
    // out is not UTC, so the zone is set on the way out as well as named on the way in.
    // Reading one of these without the conversion is how a test agrees with a file that is two
    // hours wrong.
    $stamp = fn (string $value): string => (new DateTimeImmutable($value, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Ymd\THis\Z');

    // One entry for the whole of it, running from the first segment's start to the last one's
    // end: the colour develops for half an hour in between with the staff member free, and
    // that gap is the salon's arrangement rather than a second appointment in the customer's
    // diary.
    expect(substr_count($file, 'BEGIN:VEVENT'))->toBe(1)
        ->and($file)->toContain('DTSTART:'.$stamp($first->start_utc))
        ->and($file)->toContain('DTEND:'.$stamp($last->end_utc))
        ->and($file)->toContain('Colour')
        ->and($file)->toContain('Haircut')
        ->and($file)->toBe(IcsBuilder::file(
            $snapshot,
            CustomerMailLines::summary($snapshot, 'Luna Hair Studio', SiteLanguage::English),
            new DateTimeImmutable('2026-11-02 06:00:00', new DateTimeZone('UTC')),
            0,
        ));
});

it('sends nothing at all for a booking that is no longer there, because a re-read that finds nothing has nothing to say', function () {
    [$url, $body] = seamHold('Haircut');
    $held = seamHeld($this->postJson($url, $body));

    DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->delete();

    $log = new SeamLog;
    Log::swap($log);

    seamAnnounceConfirmed($held['uuid'], HoldClass::Checkout, $held['token']);

    expect(seamSent())->toBe([])
        ->and($log->errors())->toBe([]);
});

it('queues both messages with the credential unreadable in the row, and sends them when the worker gets to them', function () {
    config(['queue.default' => 'database']);

    [$url, $body] = seamHold('Haircut');
    $held = seamHeld($this->postJson($url, $body));
    seamConfirm($this, $held['uuid'], $held['token']);

    /** @var list<stdClass> $queued */
    $queued = DB::table('jobs')->orderBy('id')->get()->all();

    expect($queued)->toHaveCount(2)
        ->and(seamSent())->toBe([]);

    $payloads = [];

    foreach ($queued as $row) {
        assert(is_string($row->payload));

        $payloads[] = $row->payload;

        // Neither row may carry the credential, whichever job it is. The one that holds the
        // plaintext is encrypted, so its whole command is ciphertext; the notice carries no
        // token to begin with.
        expect($row->payload)->not->toContain($held['token'])
            ->and($row->payload)->not->toContain('perpetua.vandersnoot@example.test');
    }

    $confirmation = array_values(array_filter($payloads, fn (string $payload): bool => str_contains($payload, 'SendConfirmation')));

    expect($confirmation)->toHaveCount(1)
        // The encrypted job's command says nothing about which booking it is for either, which
        // is what ciphertext in that column looks like from outside.
        ->and($confirmation[0])->not->toContain($held['uuid']);

    // No memory ceiling for the worker: its check reads the memory of the whole test process,
    // which a full run grows past the default 128 MB by the time this file runs, and a worker
    // over it stops after the first job and leaves the other due ones queued.
    Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--memory' => 0, '--no-interaction' => true]);

    // Under the prefix of the booking's language, English here.
    $link = app(TenantUrls::class)->manageLink(Slug::of('luna'), $held['uuid'], $held['token'], SiteLanguage::English);

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and((string) seamAt('perpetua.vandersnoot@example.test')->getTextBody())->toContain($link)
        ->and(seamAt('owner@luna.example')->getAttachments())->toBe([]);
});
