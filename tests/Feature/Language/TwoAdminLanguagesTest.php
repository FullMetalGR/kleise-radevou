<?php

use App\Application\Notification\MessageKey;
use App\Jobs\SendNewBookingNotice;
use App\Models\User;
use App\Site\SiteLanguage;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * An admin user in Greek beside a colleague in English at the same tenant. Each reads the same
 * screen in their own language, each changes it through the top bar's route for themself alone,
 * and the business mail about one booking reaches each in the language they read at the send.
 * Every expected line is read through the translator, never spelled here.
 */

const TAL_OWNER = 'owner@luna.example';

const TAL_MANAGER = 'marina@luna.example';

beforeEach(function () {
    $this->withoutVite();
    dropDemoSchemas();
    seedDemo();
    FixtureDay::pinClock();
    seamTransport()->flush();
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * A user of luna by address, read fresh, so the language a PUT wrote is the one the next request
 * carries.
 */
function talUser(string $email): User
{
    freshGuards();

    return User::query()->where('email', $email)->sole();
}

/**
 * The Bookings screen as the user given reads it, held to the language given: the page's
 * language, the shell's rail word for the calendar read through the translator in it, and the
 * root view's lang.
 *
 * @return TestResponse<Response>
 */
function talReads(TestCase $test, string $email, SiteLanguage $language): TestResponse
{
    $response = $test->actingAs(talUser($email))->get(tenantUrl('luna', '/admin/bookings'))->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('language', $language->value)
        ->where('strings.shell.nav.calendar', adminLine('admin/shell.nav.calendar', [], $language)));

    expect(preg_match('/<html lang="([a-z]+)">/', (string) $response->getContent(), $found) === 1 ? $found[1] : null)->toBe($language->value);

    return $response;
}

/**
 * The language a business message was written in: the language whose business-mail.php lines
 * its subject shows, the other's none; mixed otherwise.
 */
function talWrittenIn(string $address): string
{
    $subject = (string) seamAt($address)->getSubject();
    $greek = linesShownIn($subject, 'business-mail', SiteLanguage::Greek);
    $english = linesShownIn($subject, 'business-mail', SiteLanguage::English);

    return match (true) {
        $greek !== [] && $english === [] => 'el',
        $english !== [] && $greek === [] => 'en',
        default => 'mixed',
    };
}

it('shows one screen to a Greek owner and an English manager each in their own language, lets each change it through the top bar for themself alone, and writes the business mail about one booking to each in the language they read at the send', function () {
    settingsOf('luna')->update(['language' => SiteLanguage::Greek->value]);
    DB::table('users')->where('email', TAL_OWNER)->update(['locale' => 'el']);
    DB::table('users')->where('id', addTenantUser(TAL_MANAGER, 'manager'))->update(['locale' => 'en']);
    seamSwitchOff(seamEveryKeyBut(MessageKey::NewBooking));
    $uuid = LunaFixture::hold(['Haircut']);
    $tenant = LunaFixture::tenant()->id;

    // One screen, two languages.
    talReads($this, TAL_OWNER, SiteLanguage::Greek);
    talReads($this, TAL_MANAGER, SiteLanguage::English);

    // The business mail about the booking, each in their own.
    seamTransport()->flush();
    app(Dispatcher::class)->dispatchNow(new SendNewBookingNotice($tenant, $uuid));

    expect(talWrittenIn(TAL_OWNER))->toBe('el')
        ->and(talWrittenIn(TAL_MANAGER))->toBe('en')
        ->and(linesShownIn((string) seamAt(TAL_OWNER)->getSubject(), 'business-mail', SiteLanguage::Greek))->each->toStartWith('new_booking.');

    // The owner moves to English through the top bar; the manager keeps hers.
    $this->actingAs(talUser(TAL_OWNER))->from(tenantUrl('luna', '/admin/bookings'))
        ->put(tenantUrl('luna', '/admin/language/en'))
        ->assertRedirect(tenantUrl('luna', '/admin/bookings'));

    expect(DB::table('users')->whereIn('email', [TAL_OWNER, TAL_MANAGER])->orderBy('email')->pluck('locale', 'email')->all())
        ->toBe([TAL_MANAGER => 'en', TAL_OWNER => 'en']);

    // The manager moves to Greek through the same route; the owner keeps his.
    $this->actingAs(talUser(TAL_MANAGER))->from(tenantUrl('luna', '/admin/bookings'))
        ->put(tenantUrl('luna', '/admin/language/el'))
        ->assertRedirect(tenantUrl('luna', '/admin/bookings'));

    expect(DB::table('users')->whereIn('email', [TAL_OWNER, TAL_MANAGER])->orderBy('email')->pluck('locale', 'email')->all())
        ->toBe([TAL_MANAGER => 'el', TAL_OWNER => 'en'])
        ->and(settingsOf('luna')->value('language'))->toBe('el');

    talReads($this, TAL_OWNER, SiteLanguage::English);
    talReads($this, TAL_MANAGER, SiteLanguage::Greek);

    // The same booking's mail again: each now in the language they chose.
    seamTransport()->flush();
    app(Dispatcher::class)->dispatchNow(new SendNewBookingNotice($tenant, $uuid));

    expect(talWrittenIn(TAL_OWNER))->toBe('en')
        ->and(talWrittenIn(TAL_MANAGER))->toBe('el');
});
