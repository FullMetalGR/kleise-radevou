<?php

use App\Application\Clock;
use App\Application\Notification\MessageKey;
use App\Application\TransitionRefused;
use App\Domain\Audit\Actor;
use App\Domain\Enum\BookingOutcome;
use App\Http\Controllers\Admin\BookingActionController;
use App\Infrastructure\BookingTransitionFactory;
use App\Jobs\SendThankYou;
use App\Models\User;
use App\Notifications\ThankYou;
use App\Site\SiteLanguage;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\FixedClock;
use Tests\Support\LunaFixture;
use Tests\Support\RefusingTransport;
use Tests\Support\SeamLog;

pest()->use(DatabaseTruncation::class);

/*
 * The thank-you through the seam: the business marks a booking completed, and once that has
 * committed the customer is thanked for their visit, once, by a listener handing one queued job the
 * tenant and the uuid; the job re-reads the booking and sends only while it is still completed,
 * with the tenant's Google reviews link where the profile holds one, Book again, and the closing
 * line to My bookings.
 *
 * Not wrapped in a transaction: the mark enters the transaction runner, which refuses to open
 * inside another.
 *
 * luna is the demo seeder's; the booking is LunaFixture's, a confirmed Haircut on Elena at 10:00 in
 * Athens on Tuesday 2026-11-03, and the clock stands an hour past that start, when the owner may
 * say how it went.
 */

const THANKS_CUSTOMER = LunaFixture::CUSTOMER_EMAIL;

const THANKS_REVIEWS = 'https://g.page/r/luna-hair-studio/review';

beforeEach(function () {
    $this->withoutVite();
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-11-03 09:00:00'));
    seamTransport()->flush();
});

afterEach(function () {
    DB::purge('thanks_probe');
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * The owner marks a booking completed through the use case, as the dialog's action does.
 */
function thanksMark(string $uuid): void
{
    app(BookingTransitionFactory::class)->markOutcome(LunaFixture::tenant())->mark($uuid, BookingOutcome::Completed, Actor::user(tenantUserId()));
}

/**
 * Book again for a booking of luna's first item, spelled from the rows rather than by the
 * builder under test: the hosted page under the prefix of the booking's language, English
 * here, with the service and, unless it is the implicit member, the staff.
 */
function thanksAgain(string $service = 'Haircut', ?string $staff = 'Elena Papadaki'): string
{
    return app(TenantUrls::class)->hostedPage(Slug::of('luna'), SiteLanguage::English).'?service='.seamId('services', $service).($staff === null ? '' : '&staff='.seamId('staff', $staff));
}

function thanksMine(): string
{
    return app(TenantUrls::class)->myBookings(Slug::of('luna'), SiteLanguage::English);
}

/**
 * One line of the thank-you in English, the business filled in with what else is given.
 *
 * @param  array<string, string>  $replace
 */
function thanksLine(string $key, array $replace = []): string
{
    return customerMailLine($key, [...$replace, 'business' => 'Luna Hair Studio']);
}

it('thanks the customer once, after the mark has committed, when the owner completes a begun booking from the dialog, with the reviews link, Book again and the closing line', function () {
    settingsOf('luna')->update(['google_reviews_url' => THANKS_REVIEWS]);
    $uuid = LunaFixture::booking('confirmed');
    $ran = [];

    // Which job ran, at what depth of transaction, and what a second connection reads of the
    // booking as it starts, the house's probe of a commit (MarkBookingOutcomeTest): the record is
    // announced after the commit, so the job's re-read is of a row nobody can still roll back,
    // and another connection already sees it completed.
    Queue::before(function (JobProcessing $event) use (&$ran, $uuid): void {
        $ran[] = [$event->job->resolveName(), DB::transactionLevel(), LunaFixture::probe('thanks_probe')->scalar('select status from tenant_luna.bookings where uuid = ?::uuid', [$uuid])];
    });

    $owner = User::query()->where('email', 'owner@luna.example')->sole();
    $this->actingAs($owner)
        ->from(tenantUrl('luna', '/admin/bookings/'.$uuid))
        ->post(tenantUrl('luna', '/admin/bookings/'.$uuid.'/complete'))
        ->assertRedirect()
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => __(BookingActionController::COMPLETED)]);

    $message = seamAt(THANKS_CUSTOMER);
    $text = (string) $message->getTextBody();

    expect(LunaFixture::row($uuid)['status'])->toBe('completed')
        ->and($ran)->toBe([[SendThankYou::class, 0, 'completed']])
        ->and(seamAddresses())->toBe([THANKS_CUSTOMER])
        ->and($message->getSubject())->toBe(thanksLine(ThankYou::SUBJECT))
        ->and($text)->toContain(thanksLine('customer-mail.greeting', ['customer' => 'Perpetua Vandersnoot']))
        ->and($text)->toContain(thanksLine(ThankYou::BODY))
        ->and($text)->toContain('    '.decisionLine('decision.facts.item', ['service' => 'Haircut', 'staff' => 'Elena Papadaki']))
        ->and($text)->toContain(thanksLine('customer-mail.thank_you.again'))
        ->and($text)->toContain(thanksLine(ThankYou::REVIEW_LINE, ['link' => THANKS_REVIEWS]))
        ->and($text)->toContain(thanksLine('customer-mail.book_again', ['link' => thanksAgain()]))
        ->and($text)->toContain(thanksLine('customer-mail.my_bookings', ['link' => thanksMine()]))
        ->and(seamLinksIn($text))->toBe([THANKS_REVIEWS, thanksAgain(), thanksMine()])
        ->and($message->getAttachments())->toBe([]);
});

it('carries no reviews line where the profile holds no link, and still Book again', function () {
    $uuid = LunaFixture::booking('confirmed');

    thanksMark($uuid);

    $text = seamTextAt(THANKS_CUSTOMER);

    expect($text)->not->toContain(thanksLine(ThankYou::REVIEW_LINE, ['link' => THANKS_REVIEWS]))
        ->and($text)->toContain(thanksLine('customer-mail.thank_you.again')."\n\n".thanksLine('customer-mail.book_again', ['link' => thanksAgain()]))
        ->and(seamLinksIn($text))->toBe([thanksAgain(), thanksMine()]);
});

it('leaves the staff out of Book again where the first item runs on the implicit member', function () {
    $uuid = LunaFixture::booking('confirmed');
    DB::table('tenant_luna.booking_items')->update(['staff_id' => implicitStaffId('tenant_luna')]);

    thanksMark($uuid);

    $text = seamTextAt(THANKS_CUSTOMER);

    expect($text)->toContain(thanksLine('customer-mail.book_again', ['link' => thanksAgain(staff: null)]))
        ->and($text)->not->toContain('&staff=')
        ->and($text)->not->toContain(decisionLine('decision.facts.item', ['service' => 'Haircut', 'staff' => 'Luna Hair Studio']))
        ->and($text)->toContain("    Haircut\n");
});

it('sends it under its own key and under no other, with every other key in the catalog switched off', function () {
    // Everything but this key is off, so a message that still goes carries this key; the
    // bookings link among them, so the closing line is not there either.
    seamSwitchOff(seamEveryKeyBut(MessageKey::ThankYou));
    $uuid = LunaFixture::booking('confirmed');

    thanksMark($uuid);

    expect(seamAddresses())->toBe([THANKS_CUSTOMER])
        ->and(seamLinksIn(seamTextAt(THANKS_CUSTOMER)))->toBe([thanksAgain()]);
});

it('sends nothing with the key switched off, and the booking is completed all the same', function () {
    seamSwitchOff([MessageKey::ThankYou->value]);
    $uuid = LunaFixture::booking('confirmed');
    $log = new SeamLog;
    Log::swap($log);

    thanksMark($uuid);

    expect(seamSent())->toBe([])
        ->and(LunaFixture::row($uuid)['status'])->toBe('completed')
        ->and($log->errors())->toBe([]);
});

it('sends nothing and logs nothing when its job finds the booking {0}, because it thanks only for a visit still marked completed', function (string $status) {
    $uuid = LunaFixture::booking($status, blocks: $status === 'confirmed');
    $log = new SeamLog;
    Log::swap($log);

    Bus::dispatchSync(new SendThankYou(LunaFixture::tenant()->id, $uuid));

    expect(seamSent())->toBe([])
        ->and($log->records)->toBe([]);
})->with(['confirmed', 'no_show', 'cancelled']);

it('sends nothing and logs nothing for a booking that is no longer there', function () {
    $log = new SeamLog;
    Log::swap($log);

    Bus::dispatchSync(new SendThankYou(LunaFixture::tenant()->id, (string) Str::uuid()));

    expect(seamSent())->toBe([])
        ->and($log->records)->toBe([]);
});

it('thanks the customer once for a booking, because a booking is completed once: a second mark is refused and announces nothing', function () {
    $uuid = LunaFixture::booking('confirmed');

    thanksMark($uuid);

    expect(fn () => thanksMark($uuid))->toThrow(TransitionRefused::class)
        ->and(seamAddresses())->toBe([THANKS_CUSTOMER]);
});

it('completes the booking whatever the transport does, and logs neither the address nor a link', function () {
    settingsOf('luna')->update(['google_reviews_url' => THANKS_REVIEWS]);
    $uuid = LunaFixture::booking('confirmed');
    $log = new SeamLog;
    Log::swap($log);
    seamMailer()->setSymfonyTransport(new RefusingTransport);

    thanksMark($uuid);

    $errors = $log->errors();

    expect(LunaFixture::row($uuid)['status'])->toBe('completed')
        ->and(array_map(fn (array $record): mixed => $record['message'], $errors))->toBe(['A message could not be sent'])
        ->and(array_map(fn (array $record): mixed => $record['context']['key'] ?? null, $errors))->toBe(['thank_you'])
        ->and(array_map(fn (array $record): mixed => $record['context']['booking'] ?? null, $errors))->toBe([$uuid])
        ->and(array_map(fn (array $record): mixed => $record['context']['exception'] ?? null, $errors))->toBe([TransportException::class])
        ->and($log->text())->not->toContain(THANKS_CUSTOMER)
        ->and($log->text())->not->toContain('Perpetua')
        ->and($log->text())->not->toContain(THANKS_REVIEWS)
        ->and($log->text())->not->toContain('?service=');
});
