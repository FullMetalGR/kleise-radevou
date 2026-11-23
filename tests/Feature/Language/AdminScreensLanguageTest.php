<?php

use App\Application\Clock;
use App\Billing\EntitlementResolver;
use App\Billing\Entitlements;
use App\Billing\Feature;
use App\Infrastructure\Db\SqlText;
use App\Models\User;
use App\Site\SiteLanguage;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;

pest()->use(RefreshDatabase::class);

/*
 * The admin's screens in the user's language, walked by keys. aslRoutes() is a table of the admin's
 * routes, each with the props its page carries and the section of lang/{en,el}/admin each prop
 * holds, a group of one after a dot, whether the tenant is read without the staff feature, and
 * whether the colleague at en must be an owner; the shell's section rides every admin page, so
 * aslProps() adds it to every row. For each row a user at el reads every prop equal to lang/el's
 * section key for key, no leaf equal to its English twin but a shared value (languageSharedValues),
 * under <html lang="el"> on the full page load, and a colleague at en at the same tenant reads
 * lang/en's on the same route in the same case. The pages nobody is signed in on, the door and the
 * set-password page, read the tenant default, and the 404 inside the shell the user's language: a
 * case of their own walks them. The Calendar's strings are the same in every view, so its row reads
 * them once, and a case of its own reads them under each view and for a viewer pinned to her own
 * row, each state asserted on the response. A new admin screen adds its row and the walked counts
 * with it. The sentences a request provokes, the validations, the refusals and the flashes, are
 * each screen's own test file's. The wizard's availability read is no row of the table: its
 * sentences are api.php's, proven where NewBookingTest provokes them.
 *
 * luna is the demo seeder's, its fixture customer
 * given one booking so the customer's page answers; the clock is pinned first.
 */

beforeEach(function () {
    $this->withoutVite();
    seedDemo();
    FixtureDay::pinClock();
});

/**
 * The routes walked: each by its name, its path under the tenant's host with {customer},
 * {service}, {booking} and {staff} standing for the fixture customer's address, Haircut's id, the
 * fixture customer's latest booking's uuid and Elena's id, its props, each a prop's dotted path
 * with the section it holds, and true where the tenant is read with no entitlement, the staff
 * feature off. The wizard opens over any screen, so its group rides on a page that is not the
 * Customers screen as the shared prop wizard. The Home screen carries the bookings section for
 * the booking dialog a row opens; luna's setup is finished, so it is read once so and once again
 * with setup opened for two owners, the only viewers it is offered to, when the setup section
 * rides too, and with it the sections its steps borrow their forms' words from: the settings,
 * services, staff and payments sections. The Shifts screen, which asks manageSettings, answers both users. The Calendar carries the calendar
 * section, the bookings section for the booking dialog and the shifts section for the closures a
 * Day column draws and the day editor it opens, the three CalendarController names. The Staff list
 * renders the floor's closures alone without the staff feature, so it is walked in both states.
 * Settings carries every section SettingsController names, the settings, payments, billing and
 * emails sections, the last two the Danger zone's and the Emails list's. The Team screen and the
 * Billing screen ask manageBilling, an owner's, so their rows carry true as a fourth element: the
 * colleague at en who reads them is a second owner, never the manager, who is answered 403.
 * Reports asks viewBookings, which the manager holds. Its page chooses no word on the server: the
 * props are figures, dates, keys and the tenant's own names, so with figures or empty, filtered
 * or not, on any range, it sends one and the same reports section, walked here whole; a word
 * chosen by a state is proved in the CSV's cases and in the browser walk.
 *
 * @return array<string, array{0: string, 1: array<string, string>, 2?: bool, 3?: bool}>
 */
function aslRoutes(): array
{
    return [
        'the Home screen' => ['/admin', ['strings.home' => 'home', 'strings.bookings' => 'bookings']],
        'the Home screen with setup open' => ['/admin', ['strings.home' => 'home', 'strings.bookings' => 'bookings', 'strings.setup' => 'setup', 'strings.settings' => 'settings', 'strings.services' => 'services', 'strings.staff' => 'staff', 'strings.payments' => 'payments'], false, true],
        'the Bookings list' => ['/admin/bookings', ['strings.bookings' => 'bookings']],
        'a booking opened by its own address' => ['/admin/bookings/{booking}', ['strings.bookings' => 'bookings']],
        'the Customers list' => ['/admin/customers', ['strings.customers' => 'customers']],
        'a customer\'s page' => ['/admin/customers/{customer}', ['strings.customers' => 'customers', 'strings.bookings' => 'bookings']],
        'the Services list' => ['/admin/services', ['strings.services' => 'services']],
        'a service opened in its form' => ['/admin/services/{service}', ['strings.services' => 'services']],
        'a new service in its form' => ['/admin/services/new', ['strings.services' => 'services']],
        'the Calendar with its closure words, the day editor\'s and the wizard over it' => ['/admin/calendar', ['strings.calendar' => 'calendar', 'strings.bookings' => 'bookings', 'strings.shifts' => 'shifts', 'wizard' => 'customers.wizard']],
        'the Shifts screen' => ['/admin/shifts', ['strings.shifts' => 'shifts']],
        'the Staff list' => ['/admin/staff', ['strings.staff' => 'staff', 'strings.bookings' => 'bookings']],
        'the floor\'s closures, the staff feature off' => ['/admin/staff', ['strings.staff' => 'staff', 'strings.bookings' => 'bookings'], true],
        'a new staff member in its form' => ['/admin/staff/new', ['strings.staff' => 'staff', 'strings.bookings' => 'bookings']],
        'a staff member\'s page' => ['/admin/staff/{staff}', ['strings.staff' => 'staff', 'strings.bookings' => 'bookings']],
        'the Settings page' => ['/admin/settings', ['strings.settings' => 'settings', 'strings.payments' => 'payments', 'strings.billing' => 'billing', 'strings.emails' => 'emails']],
        'the Team screen' => ['/admin/team', ['strings.team' => 'team'], false, true],
        'the Billing screen' => ['/admin/billing', ['strings.billing' => 'billing'], false, true],
        'the Reports screen' => ['/admin/reports', ['strings.reports' => 'reports']],
    ];
}

/**
 * A row's props with the shell's section first, which every admin page carries.
 *
 * @param  array<string, string>  $props
 * @return array<string, string>
 */
function aslProps(array $props): array
{
    return ['strings.shell' => 'shell'] + $props;
}

/**
 * A row's path with the fixture's customer, service, booking and member in place of their
 * stand-ins.
 */
function aslPath(string $path): string
{
    $booking = str_contains($path, '{booking}')
        ? DB::table('tenant_luna.bookings')->where('customer_email', LunaFixture::CUSTOMER_EMAIL)->orderByDesc('id')->value('uuid')
        : null;

    return strtr($path, [
        '{customer}' => rawurlencode(LunaFixture::CUSTOMER_EMAIL),
        '{service}' => (string) LunaFixture::id('services', 'Haircut'),
        '{booking}' => is_string($booking) ? $booking : '',
        '{staff}' => (string) LunaFixture::id('staff', 'Elena Papadaki'),
    ]);
}

/**
 * The tenant read with no entitlement where the row asks for it, the staff feature off, and with
 * its stored entitlements otherwise.
 */
function aslEntitlements(bool $none): void
{
    if (! $none) {
        app()->forgetInstance(EntitlementResolver::class);

        return;
    }

    app()->instance(EntitlementResolver::class, new class implements EntitlementResolver
    {
        public function resolve(Tenant $tenant): Entitlements
        {
            return Entitlements::none();
        }
    });
}

/**
 * A section of lang/{locale}/admin in the language given, read through the translator, or the
 * group of one named after a dot.
 *
 * @return array<mixed>
 */
function aslSection(string $section, SiteLanguage $language): array
{
    $file = Str::before($section, '.');
    $lines = __('admin/'.$file, [], $language->value);
    assert(is_array($lines));
    $group = $file === $section ? $lines : Arr::get($lines, Str::after($section, '.'));
    assert(is_array($group));

    return $group;
}

/**
 * The keys of a Greek section whose leaf is its English twin's, a shared value aside.
 *
 * @param  array<mixed>  $greek
 * @param  array<mixed>  $english
 * @return list<string>
 */
function aslKeptEnglish(array $greek, array $english): array
{
    $twins = Arr::dot($english);
    $kept = [];

    foreach (Arr::dot($greek) as $key => $line) {
        if (($twins[$key] ?? null) === $line && ! in_array($line, languageSharedValues(), true)) {
            $kept[] = (string) $key;
        }
    }

    return $kept;
}

/**
 * A user of luna by address with users.locale the language given.
 */
function aslUser(string $email, SiteLanguage $language): User
{
    DB::table('users')->where('email', $email)->update(['locale' => $language->value]);

    return User::query()->where('email', $email)->sole();
}

/**
 * The lang of the page's root element as the response wrote it, null where it wrote none.
 *
 * @param  TestResponse<Response>  $response
 */
function aslRootLang(TestResponse $response): ?string
{
    return preg_match('/<html lang="([a-z]+)">/', (string) $response->getContent(), $found) === 1 ? $found[1] : null;
}

it('reads every prop of every route in Greek for a user at el under lang el, and in English for a colleague at en at the same tenant', function () {
    LunaFixture::booking('confirmed');
    $greek = aslUser('owner@luna.example', SiteLanguage::Greek);
    addTenantUser('manager@luna.example', 'manager');
    $manager = aslUser('manager@luna.example', SiteLanguage::English);
    addTenantUser('second-owner@luna.example', 'owner');
    $owner = aslUser('second-owner@luna.example', SiteLanguage::English);
    $walked = ['rows' => 0, 'props' => 0, 'leaves' => 0];

    foreach (aslRoutes() as $name => $row) {
        [$path, $props] = $row;
        aslEntitlements($row[2] ?? false);
        $props = aslProps($props);
        $url = tenantUrl('luna', aslPath($path));
        $english = ($row[3] ?? false) ? $owner : $manager;

        if (array_key_exists('strings.setup', $props)) {
            settingsOf('luna')->update(['setup_finished_at' => null, 'setup_profile_saved_at' => null]);
        }

        $inGreek = $this->actingAs($greek)->get($url)->assertOk();
        $inEnglish = $this->actingAs($english)->get($url)->assertOk();

        expect(aslRootLang($inGreek))->toBe('el', $name)
            ->and(aslRootLang($inEnglish))->toBe('en', $name);

        if (array_key_exists('strings.home', $props)) {
            // Setup's words ride only with setup itself, so each Home row is read in its state.
            $inSetup = array_key_exists('strings.setup', $props);
            $inGreek->assertInertia(fn (Assert $page) => $inSetup ? $page->has('setup') : $page->missing('setup'));
            $inEnglish->assertInertia(fn (Assert $page) => $inSetup ? $page->has('setup') : $page->missing('setup'));
        }

        // The row read without the staff feature is read so, and the Staff list with it is not,
        // so the two Staff rows cannot pass as one state.
        if ($row[2] ?? false) {
            $inGreek->assertInertia(fn (Assert $page) => $page->where('entitlements', []));
        } elseif ($path === '/admin/staff') {
            $inGreek->assertInertia(fn (Assert $page) => $page->where('entitlements', fn (Collection $slugs): bool => $slugs->contains(Feature::Staff->value)));
        }

        foreach ($props as $prop => $section) {
            $el = aslSection($section, SiteLanguage::Greek);
            $en = aslSection($section, SiteLanguage::English);

            $inGreek->assertInertia(fn (Assert $page) => $page->where($prop, $el));
            $inEnglish->assertInertia(fn (Assert $page) => $page->where($prop, $en));
            expect($el)->not->toBe($en, $name)
                ->and(array_keys(Arr::dot($el)))->toBe(array_keys(Arr::dot($en)), $name)
                ->and(aslKeptEnglish($el, $en))->toBe([], $name.', '.$prop);

            $walked['props']++;
            $walked['leaves'] += count(Arr::dot($el));
        }

        $walked['rows']++;
    }

    // Nineteen rows and fifty-six props: shell.php's 145 leaves on every row, its kit's nine
    // counted lines among them; bookings.php's 181 on ten (the two Home rows, the Bookings list, a
    // booking opened by its address, a customer's page, the Calendar and the four Staff rows, whose
    // closures card lists bookings and opens the Move form); home.php's 181 on the two Home rows;
    // setup.php's on Home with setup open, counted below by its parts; customers.php's 155 on
    // two; services.php's 116 on four (the list, a service's form, the new service's form and
    // Home with setup open, for its Services step); the wizard's group's 47 over the Calendar;
    // staff.php's 170 on five (the Staff list with the staff feature and without it, a new
    // member's form, a member's page and Home with setup open, for its Team step); settings.php's
    // 144 and payments.php's 97 on two each (Settings, and Home with setup open for its Business
    // profile and Online payments steps); emails.php's 21 on Settings; team.php's 45 on the Team screen;
    // calendar.php's 85 on the Calendar; shifts.php's 79 on two (the Calendar and the Shifts
    // screen); billing.php's 88 on two (Settings and the Billing screen); and reports.php's 118
    // on the Reports screen. setup.php by its parts: the setup wizard's and the banner's own
    // sixteen, then each step's group, its name and its own words, one step to a line.
    $setup = 16
        + 6 // Business profile
        + 10 // Services
        + 10 // Team
        + 8 // Opening hours
        + 2 // Online payments
        + 5; // Booking link
    expect($walked)->toBe(['rows' => 19, 'props' => 56, 'leaves' => 19 * 145 + 10 * 181 + 2 * 181 + $setup + 2 * 155 + 4 * 116 + 47 + 5 * 170 + 2 * 144 + 2 * 97 + 45 + 85 + 2 * 79 + 2 * 88 + 21 + 118]);
});

it('reads the Calendar in Greek for a user at el under view=day, view=week and view=month, and for a Greek viewer who holds viewOwnCalendar without viewBookings, pinned to her own row', function () {
    $owner = aslUser('owner@luna.example', SiteLanguage::Greek);
    addTenantUser('elena@luna.example', 'staff', staff: 'Elena Papadaki');
    $elena = aslUser('elena@luna.example', SiteLanguage::Greek);
    $calendar = aslSection('calendar', SiteLanguage::Greek);
    $read = [];

    expect($calendar)->not->toBe(aslSection('calendar', SiteLanguage::English));

    // Each view is asserted on the response itself: the view the link opened and the window's
    // length in days, one, seven and the month grid's forty-two, so no view passes as another.
    foreach (['day' => 1, 'week' => 7, 'month' => 42] as $view => $days) {
        $page = $this->actingAs($owner)->get(tenantUrl('luna', '/admin/calendar?view='.$view))->assertOk();
        $window = data_get($page->viewData('page'), 'props.window');
        assert(is_array($window) && is_string($window['from']) && is_string($window['to']));
        $zone = new DateTimeZone('UTC');
        $span = (new DateTimeImmutable($window['from'], $zone))->diff(new DateTimeImmutable($window['to'], $zone))->days;

        expect(aslRootLang($page))->toBe('el', $view)
            ->and($span)->toBe($days, $view);
        $page->assertInertia(fn (Assert $inertia) => $inertia
            ->where('opening.view', $view)
            ->where('pinned', false)
            ->where('strings.calendar', $calendar));
        $read[] = $view;
    }

    $page = $this->actingAs($elena)->get(tenantUrl('luna', '/admin/calendar'))->assertOk();
    $own = ['id' => LunaFixture::id('staff', 'Elena Papadaki'), 'name' => 'Elena Papadaki'];

    expect(aslRootLang($page))->toBe('el');
    $page->assertInertia(fn (Assert $inertia) => $inertia
        ->where('pinned', true)
        ->where('own', $own)
        ->where('window.members', [$own])
        ->where('strings.calendar', $calendar));
    $read[] = 'pinned';

    expect($read)->toBe(['day', 'week', 'month', 'pinned']);
});

it('reads the shell in the tenant default on the door and the set-password page, at a default of el and of en, the link\'s user at the other language each time, and in the user\'s language on the 404 inside the shell', function () {
    $token = str_repeat('b', 64);
    DB::table('users')->where('email', 'owner@luna.example')->update(['password_link_hash' => hash('sha256', $token), 'password_link_expires_at' => SqlText::instant(app(Clock::class)->now()->add(new DateInterval('PT72H')))]);
    $walked = ['pages' => 0, 'leaves' => 0];

    expect(aslSection('shell', SiteLanguage::Greek))->not->toBe(aslSection('shell', SiteLanguage::English));

    foreach ([SiteLanguage::Greek, SiteLanguage::English] as $default) {
        DB::table('tenants')->where('slug', 'luna')->update(['locale' => $default->value]);
        DB::table('tenant_luna.settings')->where('id', 1)->update(['language' => null]);
        // The link's user chose the other language, so the page proves the default decides.
        aslUser('owner@luna.example', $default === SiteLanguage::Greek ? SiteLanguage::English : SiteLanguage::Greek);

        foreach (['/admin/login', '/admin/set-password/'.$token] as $path) {
            $page = $this->get(tenantUrl('luna', $path))->assertOk();
            $shell = aslSection('shell', $default);

            expect(aslRootLang($page))->toBe($default->value, $path);
            $page->assertInertia(fn (Assert $inertia) => $inertia->where('auth.user', null)->where('strings.shell', $shell));

            $walked['pages']++;
            $walked['leaves'] += count(Arr::dot($shell));
        }
    }

    addTenantUser('manager@luna.example', 'manager');

    foreach (['owner@luna.example' => SiteLanguage::Greek, 'manager@luna.example' => SiteLanguage::English] as $email => $language) {
        $page = $this->actingAs(aslUser($email, $language))->get(tenantUrl('luna', '/admin/nothing-here'))->assertNotFound();
        $shell = aslSection('shell', $language);

        expect(aslRootLang($page))->toBe($language->value, $email);
        $page->assertInertia(fn (Assert $inertia) => $inertia->where('strings.shell', $shell));

        $walked['pages']++;
        $walked['leaves'] += count(Arr::dot($shell));
    }

    // Six pages, each the shell's 145 leaves: the door and the set-password page at a default
    // of el and at one of en, and the 404 for the user at el and the colleague at en.
    expect($walked)->toBe(['pages' => 6, 'leaves' => 6 * 145]);
});
