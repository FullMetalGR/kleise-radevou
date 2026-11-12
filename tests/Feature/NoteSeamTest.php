<?php

use App\Infrastructure\Db\BookingReader;
use App\Jobs\SendAcknowledgement;
use App\Jobs\SendApprovalRequest;
use App\Jobs\SendCancellation;
use App\Jobs\SendConfirmation;
use App\Jobs\SendMove;
use App\Jobs\SendNewBookingNotice;
use App\Jobs\SendReminder;
use App\Jobs\SendRequestApproved;
use App\Jobs\SendRequestExpired;
use App\Jobs\SendRequestRejected;
use App\Notifications\CustomerNote;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * The customer's note through the notification seam: shown to the business in its two messages,
 * the new-booking notice and the approval request, under its heading, and never echoed in any of
 * the customer's own.
 *
 * Not wrapped in a transaction: the hold enters the transaction runner, which refuses to open
 * inside another. On the system clock, the bookings placed relative to today, as every seam test
 * that drives the hold through the route is: the Tuesday of the second window, when Elena works
 * 09:00 to 13:00. Bridal trial is luna's approval service, two hours on Elena; Haircut its
 * ordinary pay-on-site one. The seam helpers live in tests/Pest.php.
 */

const NOTE_WORDS = "Allergic to lavender.\nPlease use the quiet room.";

const NOTE_CUSTOMER = 'perpetua.vandersnoot@example.test';

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    seamTransport()->flush();
    // The approval hold's three nags would run inside the hold on the sync queue; the nag carries
    // no note and has its own file.
    seamSwitchOff(['approval_nag']);
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * A hold through the route as the booking page sends one, with the note.
 *
 * @return array{uuid: string, token: string}
 */
function noteHeld(TestCase $test, string $service, int $hour): array
{
    [$url, $body] = seamHold($service, firstWindowDay(2)->setTime($hour, 0)->format('Y-m-d H:i'));

    return seamHeld($test->postJson($url, [...$body, 'note' => NOTE_WORDS]));
}

/**
 * Every message sent to an address, in the order sent.
 *
 * @return list<Email>
 */
function noteSentTo(string $address): array
{
    return array_values(array_filter(seamSent(), fn (Email $message): bool => in_array($address, array_map(fn ($to): string => $to->getAddress(), $message->getTo()), true)));
}

function noteTenant(): Tenant
{
    return Tenant::query()->where('slug', 'luna')->sole();
}

it('puts the note in the approval request the business is asked and not in the customer\'s acknowledgement', function () {
    noteHeld($this, 'Bridal trial', 9);

    $owner = (string) seamAt('owner@luna.example')->getTextBody();
    $customer = noteSentTo(NOTE_CUSTOMER);

    expect($owner)->toContain(businessMailLine(CustomerNote::HEADING)."\n".NOTE_WORDS."\n\n".decisionLine('decision.facts.total', ['amount' => '150.00 EUR']))
        ->and($customer)->toHaveCount(1)
        ->and((string) ($customer[0] ?? null)?->getTextBody())->not->toContain('lavender')->not->toContain(businessMailLine(CustomerNote::HEADING));
});

it('puts the note in the new-booking notice the business is told and not in the customer\'s confirmation', function () {
    $held = noteHeld($this, 'Haircut', 11);
    seamTransport()->flush();

    seamConfirm($this, $held['uuid'], $held['token']);

    $owner = noteSentTo('owner@luna.example');
    $customer = noteSentTo(NOTE_CUSTOMER);

    expect($owner)->toHaveCount(1)
        ->and((string) ($owner[0] ?? null)?->getTextBody())->toContain(businessMailLine(CustomerNote::HEADING)."\n".NOTE_WORDS."\n\n".decisionLine('decision.facts.total', ['amount' => '35.00 EUR']))
        // The confirmation, and the reminder the sync queue runs inside the confirm.
        ->and($customer)->not->toBe([]);

    foreach ($customer as $message) {
        expect((string) $message->getTextBody())->not->toContain('lavender')->not->toContain(businessMailLine(CustomerNote::HEADING));
    }
});

it('never echoes the note in any of the customer\'s eight keys, each sent for a booking that carries one', function () {
    $held = noteHeld($this, 'Haircut', 11);
    seamConfirm($this, $held['uuid'], $held['token']);
    seamTransport()->flush();

    $tenant = noteTenant();
    $reader = app(BookingReader::class);
    $booking = $reader->snapshot($tenant, $held['uuid']);
    assert($booking !== null);
    $start = SendReminder::instant($booking->startsAt());
    $earlier = SendReminder::instant($booking->startsAt()->sub(new DateInterval('P1D')));

    // One job per customer key, each the job its listener queues, run now against the booking.
    Bus::dispatchSync(new SendAcknowledgement($tenant->id, $held['uuid'], $held['token']));
    Bus::dispatchSync(new SendConfirmation($tenant->id, $held['uuid'], $held['token']));
    Bus::dispatchSync(new SendRequestApproved($tenant->id, $held['uuid']));
    Bus::dispatchSync(new SendRequestRejected($tenant->id, $held['uuid']));
    Bus::dispatchSync(new SendMove($tenant->id, $held['uuid'], false, true, $earlier));
    Bus::dispatchSync(new SendReminder($tenant->id, $held['uuid'], $start, $reader->revision($tenant, $held['uuid'])));
    Bus::dispatchSync(new SendRequestExpired($tenant->id, $held['uuid']));
    Bus::dispatchSync(new SendCancellation($tenant->id, $held['uuid'], false, true));

    $customer = noteSentTo(NOTE_CUSTOMER);
    $subjects = array_unique(array_map(fn (Email $message): string => (string) $message->getSubject(), $customer));

    expect($customer)->toHaveCount(8)
        ->and($subjects)->toHaveCount(8)
        ->and(seamAddresses())->toBe(array_fill(0, 8, NOTE_CUSTOMER));

    foreach ($customer as $message) {
        expect((string) $message->getTextBody())->not->toContain('lavender')->not->toContain('quiet room')->not->toContain(businessMailLine(CustomerNote::HEADING))
            ->and((string) $message->getHtmlBody())->not->toContain('lavender');
    }

    // The business's two jobs, against the same booking, carry it.
    Bus::dispatchSync(new SendNewBookingNotice($tenant->id, $held['uuid']));
    Bus::dispatchSync(new SendApprovalRequest($tenant->id, $held['uuid']));

    $business = noteSentTo('owner@luna.example');

    expect($business)->toHaveCount(2);

    foreach ($business as $message) {
        expect((string) $message->getTextBody())->toContain(businessMailLine(CustomerNote::HEADING)."\n".NOTE_WORDS);
    }
});
