<?php

use App\Application\Clock;
use App\Application\Dto\FigureRange;
use App\Application\Notification\Audience;
use App\Application\Notification\MessageKey;
use App\Billing\PlanChangeRefused;
use App\Domain\Availability\CalendarDay;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\SettingsController;
use App\Platform\ClosingWindow;
use App\Site\SiteLanguage;
use App\Tenancy\Profile;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Browser\Support\Admin;
use Tests\Support\WeeklyHours;

/*
 * The admin in Greek in a real browser (a walk by keys): with luna's owner at el, a customer's page
 * read through to its erasure prompt, a service opened in its form, the door at a tenant whose
 * default is el and the shell a sign-in lands in, the Bookings list with a booking opened in its
 * dialog and its Move form, Home with every part shown, read on this week, this month and today, a
 * member's page with its closures card's date picker open and the Staff list searched for nobody,
 * the Settings page with a provider's credentials open, the Settings page whole on Solo with
 * Opening hours' weekly editor, a refused save and a landed one, the Team screen with a removal
 * opened to its confirm, the Calendar on Day, Week and Month with its menus and the closure dialog,
 * Shifts with its two editors, the Billing screen in a trial with the plan choice open and then on
 * the plan with Staff with the removal refused, and the Settings page's Emails list and Danger zone
 * with the close's dialog opened and kept, and Reports on an empty range, on this week with every
 * part shown, with a member chosen and with a custom range picked through the date picker. On each
 * the visible text and every node's aria-label, title, placeholder and alt hold no English value of
 * the walked files of two words or more and no placeholder left unfilled, read from the files
 * through the translator on this side: a value on the shared list (languageSharedValues) is left
 * out, and so is a value an admin file still without its Greek twin holds too; a file with its
 * Greek twin excuses nothing, so the rail, the top bar and the kit around every screen walked read
 * Greek. The tenant's own text, the services' and the members' names, the closures' reasons, the
 * customer's and the users', the business's profile, is taken out of the text and of each attribute
 * first. The root carries lang el. The bookings' walk, Home's, the Staff walks, the Settings walks,
 * the Calendar's and Shifts' also find no English month or weekday name, ICU's in English, shown in
 * any case, and the bookings' walk opens the Move form's month grid, where the names show, to see
 * the booking's month as ICU names it in Greek. A screen given its Greek is added here as a case,
 * and its files to the walk.
 *
 * The bookings are written straight to luna's rows, days from today, because the browser and the
 * application both read the real clock here.
 */

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => SiteLanguage::Greek->value]);
});

afterEach(fn () => dropDemoSchemas());

/** The files the walk reads, by their path under lang/{en,el}. */
const ADMIN_GREEK_FILES = ['admin/customers', 'admin/services', 'admin/shell', 'admin/bookings', 'admin/home', 'admin/staff', 'admin/payments', 'admin/settings', 'admin/team', 'admin/billing', 'admin/emails', 'admin/shifts', 'admin/calendar', 'admin/reports'];

/** The page's language. */
const ADMIN_GREEK_LANG = "document.documentElement.getAttribute('lang')";

/**
 * The patterns the walk looks for: each key of the walked files as the client would show it bare
 * where the section lacks it, and each English line of the walked files that is not on the shared
 * list and that no admin file without a Greek twin (no lang/el/admin/<file>.php) and not walked
 * holds too, each choice form apart with its :words free, kept where its fixed words number two or
 * more; as [key, regular expression source]. Every key gives one bare pattern, so the list is
 * asserted longer than the walked files' keys together, whichever case reads it.
 *
 * @return list<array{0: string, 1: string}>
 */
function adminGreekPatterns(): array
{
    $elsewhere = [];

    foreach (glob(lang_path('en/admin/*.php')) ?: [] as $path) {
        $file = 'admin/'.basename($path, '.php');

        if (! in_array($file, ADMIN_GREEK_FILES, true) && ! is_file(lang_path('el/'.$file.'.php'))) {
            $elsewhere = [...$elsewhere, ...array_values(greekWalkLines((array) trans($file, [], 'en')))];
        }
    }

    $patterns = [];
    $keys = 0;

    foreach (ADMIN_GREEK_FILES as $file) {
        $greek = greekWalkLines((array) trans($file, [], 'el'));
        $keys += count($greek);

        foreach (greekWalkLines((array) trans($file, [], 'en')) as $key => $line) {
            // A key the page's section lacks answers itself on the client, so it shows bare.
            $patterns[] = [$file.'.'.$key, '(?<![\\w.])'.preg_quote($key, '/').'(?![\\w.])'];

            if (in_array($line, languageSharedValues(), true) || in_array($line, $elsewhere, true)) {
                continue;
            }

            foreach (explode('|', $line) as $form) {
                $fixed = preg_split('/:[A-Za-z][A-Za-z0-9_]*/', trim((string) preg_replace('/^\s*[{\[][^}\]]*[}\]]/', '', $form))) ?: [];

                if (str_word_count(implode(' ', $fixed)) >= 2) {
                    $patterns[] = [$file.'.'.$key, implode('.+?', array_map(fn (string $part): string => preg_quote($part, '/'), $fixed))];
                }
            }
        }
    }

    expect(count($patterns))->toBeGreaterThan($keys);

    return $patterns;
}

/**
 * The text luna's owner and the customers typed, which the admin shows as typed in both
 * languages: the services' names, descriptions and categories, the members' names and titles,
 * the closures' reasons, the customers' names and addresses, the business's profile (About, the
 * address, the phone, the links and Important info), and luna's users' names an activity row
 * shows acting and the Team screen lists, longest first.
 *
 * @return list<string>
 */
function adminGreekTenantText(): array
{
    $typed = [];

    $profile = array_values(array_diff(Profile::FIELDS, ['country']));

    foreach (['services' => ['name', 'description', 'category'], 'staff' => ['name', 'title'], 'blackouts' => ['reason'], 'bookings' => ['customer_name', 'customer_email'], 'settings' => $profile] as $table => $columns) {
        foreach (DB::table('tenant_luna.'.$table)->get($columns) as $row) {
            foreach ($columns as $column) {
                $value = $row->{$column} ?? null;

                if (is_string($value) && trim($value) !== '') {
                    $typed[] = trim($value);
                }
            }
        }
    }

    foreach (DB::table('users')->where('tenant_id', DB::table('tenants')->where('slug', 'luna')->value('id'))->pluck('name') as $name) {
        if (is_string($name) && trim($name) !== '') {
            $typed[] = trim($name);
        }
    }

    $typed = array_values(array_unique($typed));
    usort($typed, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

    return $typed;
}

/**
 * The script that answers what the page leaves in English: the count of the patterns it looked
 * for, a colon, and the key of each one found in the visible text or in a node's aria-label,
 * title, placeholder or alt, with each placeholder left unfilled (a :word) beside them, joined by
 * bars, the tenant's text taken out of each first. "N:" for a page wholly in Greek. The read is
 * the body's, or the one element the selector given names, "no root" where the page has none.
 */
function adminGreekScript(string $root = 'body'): string
{
    $patterns = json_encode(adminGreekPatterns(), JSON_THROW_ON_ERROR);
    $tenant = json_encode(adminGreekTenantText(), JSON_THROW_ON_ERROR);
    $selector = json_encode($root, JSON_THROW_ON_ERROR);

    return <<<JS
        (() => {
            const root = document.querySelector({$selector});
            if (root === null) {
                return 'no root';
            }
            const own = (text) => {$tenant}.reduce((rest, typed) => rest.split(typed).join(' '), text);
            const labels = [...root.querySelectorAll('*')].flatMap((node) => ['aria-label', 'title', 'placeholder', 'alt'].map((name) => node.getAttribute(name) || '')).filter((text) => text !== '');
            const texts = [own(root.innerText || ''), ...labels.map(own)];
            const patterns = {$patterns};
            const left = patterns.filter(([, source]) => texts.some((text) => new RegExp(source).test(text))).map(([key]) => key);
            for (const text of texts) {
                for (const match of text.matchAll(/(?<![A-Za-z0-9_:\\/]):[A-Za-z][A-Za-z0-9_]*/g)) {
                    left.push(match[0]);
                }
            }
            return patterns.length + ':' + [...new Set(left)].sort().join('|');
        })()
        JS;
}

/**
 * The script that answers the English month and weekday names the page shows, ICU's in English,
 * whole and abbreviated, as found without regard to case in the visible text or a node's
 * aria-label, title, placeholder or alt with the tenant's text taken out, joined by bars; empty
 * where it shows none. The read is the body's, or the one element the selector given names, "no
 * root" where the page has none. A list of names ICU gave empty is refused here, since the check
 * would then pass on any page.
 */
function adminGreekNamesScript(string $root = 'body'): string
{
    $english = [];
    // A Monday in January, four days past the epoch's Thursday, from which twelve steps of a day
    // and of a month reach every weekday and every month.
    $monday = (new DateTimeImmutable('@'.(4 * 86400), new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));

    foreach (['MMMM', 'MMM', 'EEEE', 'EEE'] as $pattern) {
        $format = new IntlDateFormatter('en', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN, $pattern);

        foreach (range(0, 11) as $step) {
            $english[] = (string) $format->format($monday->add(new DateInterval(sprintf('P%dM', $step))));
            $english[] = (string) $format->format($monday->add(new DateInterval(sprintf('P%dD', $step))));
        }
    }

    $english = array_values(array_unique(array_filter($english, fn (string $name): bool => trim($name) !== '')));

    if ($english === []) {
        throw new LogicException('ICU gave no English month or weekday name, so the names check would pass on any page.');
    }

    $names = json_encode($english, JSON_THROW_ON_ERROR);
    $tenant = json_encode(adminGreekTenantText(), JSON_THROW_ON_ERROR);
    $selector = json_encode($root, JSON_THROW_ON_ERROR);

    return <<<JS
        (() => {
            const root = document.querySelector({$selector});
            if (root === null) {
                return 'no root';
            }
            const own = (text) => {$tenant}.reduce((rest, typed) => rest.split(typed).join(' '), text);
            const labels = [...root.querySelectorAll('*')].flatMap((node) => ['aria-label', 'title', 'placeholder', 'alt'].map((name) => node.getAttribute(name) || '')).filter((text) => text !== '');
            const texts = [own(root.innerText || ''), ...labels.map(own)];
            return {$names}.filter((name) => texts.some((text) => new RegExp('(?<![A-Za-z])' + name + '(?![A-Za-z])', 'i').test(text))).sort().join('|');
        })()
        JS;
}

/**
 * The script that waits up to five seconds for a month grid to open, a date field's popover
 * rendering the kit's day calendar, and answers whether it did.
 */
function adminGreekGridScript(): string
{
    return <<<'JS'
        (() => new Promise((resolve) => {
            const began = Date.now();
            const look = () => {
                if (document.querySelector('.admin-day-calendar') !== null) {
                    resolve(true);
                } else if (Date.now() - began > 5000) {
                    resolve(false);
                } else {
                    setTimeout(look, 50);
                }
            };
            look();
        }))()
        JS;
}

/**
 * The script that answers whether the page shows the name given, in the visible text or a node's
 * aria-label, title, placeholder or alt, compared without regard to case, so a stylesheet or a
 * formatter that changes the case still shows it.
 */
function adminGreekShownScript(string $name): string
{
    $wanted = json_encode($name, JSON_THROW_ON_ERROR);

    return <<<JS
        (() => {
            const labels = [...document.body.querySelectorAll('*')].flatMap((node) => ['aria-label', 'title', 'placeholder', 'alt'].map((name) => node.getAttribute(name) || '')).filter((text) => text !== '');
            const texts = [document.body.innerText || '', ...labels];
            return {$wanted} !== '' && texts.some((text) => text.toLocaleLowerCase('el').includes({$wanted}.toLocaleLowerCase('el')));
        })()
        JS;
}

/**
 * A confirmed Haircut on Elena's row under an address, at the instant given.
 */
function adminGreekBooking(string $name, string $email, DateTimeImmutable $start): void
{
    $utc = fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed',
        'uuid' => (string) Str::uuid(), 'status' => 'confirmed', 'customer_name' => $name, 'customer_email' => $email,
        'customer_phone' => '+30 210 7654321', 'total_minor' => 3500, 'currency' => 'EUR',
        'payment_mode' => 'onsite', 'requires_approval' => false, 'on_approval_timeout' => 'expire',
    ]);
    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $id, 'sort' => 0,
        'service_id' => DB::table('tenant_luna.services')->where('name', 'Haircut')->value('id'),
        'staff_id' => DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->value('id'),
        'start_utc' => $utc($start), 'end_utc' => $utc($start->add(new DateInterval('PT45M'))),
        'block_start_utc' => $utc($start), 'block_end_utc' => $utc($start->add(new DateInterval('PT45M'))),
        'price_minor' => 3500, 'blocks' => false,
    ]);
}

it('reads a customer\'s page and its erasure prompt in Greek, no English value of the walked files left', function () {
    adminGreekBooking('Casey Customer', 'casey@example.com', athensDaysOn(-3));
    $walk = adminGreekScript();
    $clean = count(adminGreekPatterns()).':';

    Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->navigate(Admin::url('luna', '/customers/casey%40example.com'))
        ->assertVisible('.admin-customer-danger__what')
        ->assertSeeIn('.admin-customer-danger__what', adminLine('admin/customers.erasure.what', [], SiteLanguage::Greek))
        ->assertScript(ADMIN_GREEK_LANG, 'el')
        ->assertScript($walk, $clean)
        ->click('.admin-customer-erase')
        ->assertVisible('.admin-erase-prompt__count')
        ->assertSeeIn('.admin-erase-prompt__count', adminChoice('admin/customers.erasure.count', 1, [], SiteLanguage::Greek))
        ->assertScript($walk, $clean)
        ->assertNoJavaScriptErrors();
});

it('reads a service opened in its form in Greek, no English value of the walked files left', function () {
    $haircut = DB::table('tenant_luna.services')->where('name', 'Haircut')->value('id');
    assert(is_int($haircut));
    $walk = adminGreekScript();
    $clean = count(adminGreekPatterns()).':';

    Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->navigate(Admin::url('luna', '/services/'.$haircut))
        ->assertVisible('.admin-service-delete')
        ->assertScript(ADMIN_GREEK_LANG, 'el')
        ->assertSeeIn('.admin-service-delete', adminLine('admin/services.danger.delete', [], SiteLanguage::Greek))
        ->assertScript($walk, $clean)
        ->assertNoJavaScriptErrors();
});

it('reads the door in Greek at a tenant whose default is el, and the rail and the top bar of the shell a sign-in lands in, no English value of the walked files left', function () {
    DB::table('tenants')->where('slug', 'luna')->update(['locale' => SiteLanguage::Greek->value]);
    DB::table('tenant_luna.settings')->where('id', 1)->update(['language' => null]);
    $walk = adminGreekScript();
    $clean = count(adminGreekPatterns()).':';

    $this->visit(Admin::url('luna', '/login'))
        ->assertVisible('.admin-login-submit')
        ->assertSeeIn('.admin-login-submit', adminLine('admin/shell.login.submit', [], SiteLanguage::Greek))
        ->assertScript(ADMIN_GREEK_LANG, 'el')
        ->assertScript($walk, $clean);

    Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->assertSeeIn(Admin::RAIL, navLabel('calendar', SiteLanguage::Greek))
        ->assertSeeIn(Admin::RAIL, navLabel('groups.business', SiteLanguage::Greek))
        ->assertScript(ADMIN_GREEK_LANG, 'el')
        ->assertScript($walk, $clean)
        ->assertNoJavaScriptErrors();
});

it('reads the Bookings list, a booking opened in its dialog and the Move form in it in Greek, no English value of the walked files and no English month or weekday name left', function () {
    $start = athensDaysOn(3, 11);
    adminGreekBooking('Casey Customer', 'casey@example.com', $start);
    $walk = adminGreekScript();
    $clean = count(adminGreekPatterns()).':';
    $names = adminGreekNamesScript();
    // The booking's month as ICU names it in Greek, standing alone as the grid's caption shows
    // it, computed here and never spelled.
    $month = (string) (new IntlDateFormatter(SiteLanguage::Greek->value, IntlDateFormatter::NONE, IntlDateFormatter::NONE, $start->getTimezone(), IntlDateFormatter::GREGORIAN, 'LLLL'))->format($start);

    Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->navigate(Admin::url('luna', '/bookings'))
        ->assertVisible('.admin-bookings')
        ->assertSeeIn('.admin-bookings', adminLine('admin/bookings.table.title', [], SiteLanguage::Greek))
        ->assertScript(ADMIN_GREEK_LANG, 'el')
        ->assertScript($walk, $clean)
        ->assertScript($names, '')
        ->click('Casey Customer')
        ->waitForText(adminLine('admin/bookings.dialog.items.title', [], SiteLanguage::Greek))
        ->assertScript($walk, $clean)
        ->assertScript($names, '')
        ->click('.admin-booking-move')
        ->waitForText(adminLine('admin/bookings.move.time_help', [], SiteLanguage::Greek))
        ->assertScript($walk, $clean)
        ->assertScript($names, '')
        ->click('.admin-move__date')
        ->assertScript(adminGreekGridScript(), true)
        ->assertScript($names, '')
        ->assertScript(adminGreekShownScript($month), true)
        ->assertScript($walk, $clean)
        ->assertNoJavaScriptErrors();
});

/**
 * A Haircut on Elena's row for the customer named, null for one erased, in the status given at
 * the instant given and at the price given in euro cents, a request on a live hold two days long.
 * Answers its id.
 */
function adminGreekHomeBooking(?string $name, DateTimeImmutable $start, string $status, int $price = 3500): int
{
    $utc = fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    $request = $status === 'awaiting_approval';
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed', 'uuid' => (string) Str::uuid(), 'status' => $status, 'customer_name' => $name,
        'customer_email' => $name === null ? null : Str::slug($name).'@example.test', 'total_minor' => $price, 'currency' => 'EUR',
        'hold_class' => $request ? 'approval' : null,
        'hold_expires_at' => $request ? $utc(new DateTimeImmutable('+2 days', new DateTimeZone('UTC'))) : null,
        'payment_mode' => 'onsite', 'requires_approval' => $request, 'on_approval_timeout' => 'expire',
    ]);
    $end = $start->add(new DateInterval('PT45M'));
    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $id, 'sort' => 0,
        'service_id' => DB::table('tenant_luna.services')->where('name', 'Haircut')->value('id'),
        'staff_id' => DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->value('id'),
        'start_utc' => $utc($start), 'end_utc' => $utc($end), 'block_start_utc' => $utc($start), 'block_end_utc' => $utc($end),
        'price_minor' => $price, 'blocks' => false,
    ]);

    return $id;
}

/**
 * The script that answers the text of the node the selector names with its runs of white space,
 * the no-break spaces among them, read as one space, empty where no node matches.
 */
function adminGreekTextScript(string $selector): string
{
    $wanted = json_encode($selector, JSON_THROW_ON_ERROR);

    return <<<JS
        (() => (document.querySelector({$wanted})?.textContent || '').replace(/\\s+/g, ' ').trim())()
        JS;
}

it('reads Home in Greek with every part shown, on this week, this month and today, no English value of the walked files and no English month or weekday name left, the revenue in Greek number forms', function () {
    DB::table('tenants')->where('slug', 'luna')->update(['locale' => SiteLanguage::Greek->value]);
    DB::table('tenant_luna.settings')->where('id', 1)->update(['language' => null, 'hours_confirmed_at' => null]);
    DB::table('tenant_luna.audit_log')->delete();
    $owner = DB::table('users')->where('email', Admin::owner('luna'))->value('id');
    assert(is_int($owner));

    // The starts of today's and later bookings of the seed in a status Next up lists,
    // confirmed or awaiting approval.
    $listed = [
        'today_first' => athensDaysOn(0, 0, 30),
        'today_second' => athensDaysOn(0, 0, 45),
        'tomorrow' => athensDaysOn(1),
        'day_after' => athensDaysOn(2),
        'day_after_request' => athensDaysOn(2, 11),
    ];
    // Today two confirmed bookings, a no-show and an erased customer's cancellation; yesterday
    // one confirmed and one cancelled, so today reads a change, the same and a rise from nought.
    $today = adminGreekHomeBooking('Cora Cut', $listed['today_first'], 'confirmed');
    adminGreekHomeBooking('Dora Dye', $listed['today_second'], 'confirmed', 4250);
    adminGreekHomeBooking('Nora Noshow', athensDaysOn(0, 0, 15), 'no_show');
    $erased = adminGreekHomeBooking(null, athensDaysOn(0, 0, 20), 'cancelled');
    $yesterday = adminGreekHomeBooking('Yuri Yesterday', athensDaysOn(-1), 'confirmed');
    adminGreekHomeBooking('Yana Cancel', athensDaysOn(-1, 11), 'cancelled');
    // Ahead: tomorrow a confirmed booking; the day after a confirmed booking and a request on a
    // live hold, so Next up and Awaiting approval list rows and one of the seven days holds both.
    $tomorrow = adminGreekHomeBooking('Fay Future', $listed['tomorrow'], 'confirmed');
    $moved = adminGreekHomeBooking('Sam Second', $listed['day_after'], 'confirmed');
    adminGreekHomeBooking('Rhea Request', $listed['day_after_request'], 'awaiting_approval');

    // Five rows of five shapes, newest first: a customer's online booking, a member's approval,
    // an expiry, a member's move and an erased customer's cancellation.
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $rows = [
        [$today, 'held', 'customer', ['status_to' => 'pending']],
        [$tomorrow, 'approved', 'user:'.$owner, []],
        [$yesterday, 'expired', 'job:sweeper', ['hold_class' => 'approval']],
        [$moved, 'rescheduled', 'user:'.$owner, []],
        [$erased, 'cancelled', 'customer', []],
    ];

    foreach ($rows as $index => [$booking, $action, $actor, $payload]) {
        DB::table('tenant_luna.audit_log')->insert([
            'booking_id' => $booking, 'actor' => $actor, 'action' => $action,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => $now->sub(new DateInterval(sprintf('PT%dM', $index + 1)))->format('Y-m-d H:i:sP'),
        ]);
    }

    $walk = adminGreekScript();
    $clean = count(adminGreekPatterns()).':';
    $names = adminGreekNamesScript();
    // The dates in luna's own format, the ranges' bounds as FigureRange takes them on its today.
    $format = DB::table('tenant_luna.settings')->where('id', 1)->value('date_format');
    $startOfWeek = DB::table('tenant_luna.settings')->where('id', 1)->value('start_of_week');
    assert(is_string($format) && is_int($startOfWeek));
    $date = fn (string $day): string => (new DateTimeImmutable($day, new DateTimeZone('Europe/Athens')))->format($format);
    $line = fn (FigureRange $range): string => adminLine('admin/home.range.line.'.$range->word, $range->word === FigureRange::TODAY
        ? ['date' => $date($range->from)]
        : ['from' => $date($range->from), 'to' => $date($range->to)], SiteLanguage::Greek);
    $on = fn (string $word): FigureRange => FigureRange::named($word, athensDaysOn(0)->format('Y-m-d'), $startOfWeek);
    // A range option pressed by its Greek label, exact, inside the range card alone.
    $option = fn (string $word): string => '.admin-home-range button:text-is('.json_encode(adminLine('admin/home.range.'.$word, [], SiteLanguage::Greek), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).')';
    // Today's revenue, the two confirmed bookings, as PHP's ICU writes it in Greek, computed here
    // and never spelled, the amount a decimal by the currency the rows carry; its spaces read as
    // one, as the script reads the tile's.
    $revenue = (string) (new NumberFormatter(SiteLanguage::Greek->value, NumberFormatter::CURRENCY))->formatCurrency((float) (new Money(3500 + 4250, Currency::of('EUR')))->decimal(), 'EUR');
    $revenue = trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $revenue));
    // The comparison of a count that changed, its words after the signed figure.
    $by = trim(str_replace(':change', '', adminLine('admin/home.change.day.by', [], SiteLanguage::Greek)));

    // Next up lists the bookings starting from the server's now on, earliest first, at most
    // HomeController::FEED (5), which the seed's five never pass. Each start is counted against
    // the instant read here, so today's two count until they start, at 00:30 and 00:45 Athens,
    // and lie behind the clock after: no hour of the day breaks the figure.
    $visitAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $ahead = count(array_filter($listed, fn (DateTimeImmutable $start): bool => $start >= $visitAt));

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->assertPathIs('/admin')
        ->assertVisible(Admin::HOME)
        ->assertScript(ADMIN_GREEK_LANG, 'el')
        ->assertSeeIn(Admin::HOME.' h1', adminLine('admin/home.title', [], SiteLanguage::Greek))
        ->assertSeeIn('.admin-setup', adminLine('admin/home.setup.title', [], SiteLanguage::Greek))
        ->assertAttribute('.admin-setup__item[data-fact="hours"]', 'data-done', 'false')
        ->assertSeeIn('.admin-home-range', $line($on(FigureRange::WEEK)))
        ->assertCount('.admin-home-next__row', $ahead)
        ->assertCount('.admin-home-activity__row', 5)
        ->assertCount('.admin-home-awaiting__row', 1)
        ->assertSeeIn('.admin-home-awaiting', adminLine('admin/home.awaiting.title', [], SiteLanguage::Greek))
        ->assertCount('.admin-home-day', 7)
        ->assertAttribute('.admin-home-day[data-date="'.athensDaysOn(2)->format('Y-m-d').'"]', 'data-awaiting', '1')
        ->assertSeeIn('.admin-home-activity__row:last-child .admin-home-activity__sentence', adminLine('admin/home.activity.erased.cancelled.customer', [], SiteLanguage::Greek))
        ->assertScript($walk, $clean)
        ->assertScript($names, '');

    $page->click($option('month'))
        ->waitForText($line($on(FigureRange::MONTH)))
        ->assertScript($walk, $clean)
        ->assertScript($names, '');

    $page->click($option('today'))
        ->waitForText($line($on(FigureRange::TODAY)))
        ->assertSee(adminLine('admin/home.change.day.same', [], SiteLanguage::Greek))
        ->assertSee(adminLine('admin/home.change.day.from_zero', [], SiteLanguage::Greek))
        ->assertSee($by)
        ->assertScript(adminGreekTextScript('.admin-figure-tile--revenue .admin-figure-tile__value'), $revenue)
        ->assertCount('.admin-home-top__row', 2)
        ->assertScript($walk, $clean)
        ->assertScript($names, '')
        ->assertNoJavaScriptErrors();
});

it('reads a member\'s page with its closures card\'s date picker open, and the Staff list searched for nobody, in Greek, no English value of the walked files and no English month or weekday name left', function () {
    $elena = DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->value('id');
    assert(is_int($elena));
    $walk = adminGreekScript();
    $clean = count(adminGreekPatterns()).':';
    $names = adminGreekNamesScript();

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->navigate(Admin::url('luna', '/staff/'.$elena))
        ->assertVisible('.admin-blackouts__date')
        ->assertScript(ADMIN_GREEK_LANG, 'el')
        ->assertSee(adminLine('admin/staff.form.weekly', [], SiteLanguage::Greek))
        ->assertScript($walk, $clean)
        ->assertScript($names, '')
        ->click('.admin-blackouts__date')
        ->assertScript(adminGreekGridScript(), true)
        ->assertScript($walk, $clean)
        ->assertScript($names, '');

    $page->navigate(Admin::url('luna', '/staff'))
        ->assertVisible('.admin-staff-search')
        ->fill('.admin-staff-search', 'nobody-token')
        ->assertVisible('.admin-staff-no-match')
        ->assertSeeIn('.admin-staff-no-match', adminLine('admin/staff.page.no_match', [], SiteLanguage::Greek))
        ->assertScript($walk, $clean)
        ->assertScript($names, '')
        ->assertNoJavaScriptErrors();
});

/*
 * The Payments section with a provider's credentials open. The walk and the names check read the
 * whole Settings page, the Danger zone and the Emails list below the section included, so the
 * bare-key pattern for admin/home.php's key "with", which the Danger zone's English held, finds no
 * such word on the Greek page.
 */
it('reads the Settings page\'s Payments section with a provider\'s credentials open in Greek, no English value of the walked files and no English month or weekday name left on the page', function () {
    $walk = adminGreekScript();
    $clean = count(adminGreekPatterns()).':';
    $names = adminGreekNamesScript();

    Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->navigate(Admin::url('luna', '/settings'))
        ->assertVisible('.admin-section--payments')
        ->assertScript(ADMIN_GREEK_LANG, 'el')
        ->assertSeeIn('.admin-section--payments', adminLine('admin/payments.degrade', [], SiteLanguage::Greek))
        ->assertScript($walk, $clean)
        ->assertScript($names, '')
        ->click('.admin-payments-provider[data-provider="stripe"] .admin-payments-own')
        ->assertVisible('#payments-stripe-secret_key')
        ->assertSeeIn('.admin-section--payments', adminLine('admin/payments.fields.stripe.secret_key.help', [], SiteLanguage::Greek))
        ->assertScript($walk, $clean)
        ->assertScript($names, '')
        ->assertNoJavaScriptErrors();
});

/*
 * Settings on Solo, the staff feature off and luna's members but the implicit one inactive, so
 * Opening hours shows the weekly editor: the profile typed and the map failed by the seeding, every
 * section seen in turn, the closed Sunday and a day given a second window, a save the page
 * refuses before it is sent, its button held beside the sentence, and one that lands. Opening
 * hours names the weekdays by ICU in Greek, so the names check runs while the editor shows.
 */
it('reads the Settings page on Solo through every section, the weekly editor with a day of two windows and a closed day, a refused save and a landed one, in Greek, no English value of the walked files and no English month or weekday name left', function () {
    DB::table('tenants')->where('slug', 'luna')->update(['entitlements' => '{}']);
    DB::table('tenant_luna.staff')->whereIn('name', ['Elena Papadaki', 'Nikos Georgiou'])->update(['status' => 'inactive']);
    DB::table('tenant_luna.settings')->where('id', 1)->update([
        'about' => 'about-token', 'address_line1' => 'address-token', 'city' => 'city-token', 'postal_code' => '10552',
        'phone' => '+30 210 0000000', 'important_info' => 'important-token', 'map_state' => 'failed',
    ]);
    $clean = count(adminGreekPatterns()).':';
    $names = adminGreekNamesScript();
    // Each section of the page and the save bar, read by its own element, the Emails list and the
    // Danger zone among them.
    $parts = [...array_map(fn (string $section): string => '.admin-section--'.$section, ['general', 'booking-page', 'booking-rules', 'opening-hours', 'payments', 'emails', 'danger']), '.admin-save-bar'];
    $walks = array_map(fn (string $part): string => adminGreekScript($part), $parts);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->navigate(Admin::url('luna', '/settings'))
        ->assertScript(ADMIN_GREEK_LANG, 'el')
        ->assertVisible('.admin-settings-theme[data-value="violet"]')
        ->assertVisible('.admin-settings-logo-upload')
        ->assertVisible('.admin-settings-cover-upload')
        ->assertVisible('.admin-settings-gallery-add')
        ->assertVisible('#settings-about')
        ->assertSee(adminLine('admin/settings.links.title', [], SiteLanguage::Greek))
        ->assertSee(adminLine('admin/settings.map.failed', [], SiteLanguage::Greek))
        ->assertSee(adminLine('admin/settings.hours.intro', [], SiteLanguage::Greek))
        ->assertVisible('.admin-weekly__day:last-child .admin-weekly__closed')
        ->click('.admin-weekly__day[data-weekday="1"] .admin-weekly__add')
        ->assertCount('.admin-weekly__day[data-weekday="1"] .admin-weekly__window', 2)
        ->assertScript($names, '');

    // A part the page lacks answers no root and fails its walk.
    foreach ($walks as $walk) {
        $page->assertScript($walk, $clean);
    }

    $page->clear('#settings-currency')
        ->type('#settings-currency', 'EU')
        ->assertSee(adminLine('admin/settings.validation.currency', [], SiteLanguage::Greek))
        ->assertDisabled('.admin-settings-save')
        ->assertScript($walks[0], $clean);

    expect(DB::table('tenants')->where('slug', 'luna')->value('currency'))->toBe('EUR');

    $page->clear('#settings-currency')
        ->type('#settings-currency', 'EUR')
        ->click('.admin-settings-save')
        ->waitForText(adminLine('admin/settings.flashes.saved', [], SiteLanguage::Greek))
        ->assertScript($names, '')
        ->assertNoJavaScriptErrors();

    foreach ($walks as $walk) {
        $page->assertScript($walk, $clean);
    }
});

/*
 * The Team screen as luna's owner: the owner marked You, an invited user marked with the
 * invitation's chip and a user linked to Elena's staff row, the role control, and a removal
 * opened to its confirm and kept.
 */
it('reads the Team screen with an invited user, a user linked to a staff row and a removal opened to its confirm, in Greek, no English value of the walked files left', function () {
    $utc = fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    addTenantUser('invited-token@luna.example', 'manager');
    // An invitation is out while the address is unproven and a link is held. The user's typed
    // name is the tenant's text, which the walk takes out.
    DB::table('users')->where('email', 'invited-token@luna.example')->update(['name' => 'Irini Markou', 'password_link_hash' => hash('sha256', 'invited-token'), 'password_link_expires_at' => $utc($now->add(new DateInterval('P1D')))]);
    addTenantUser('linked-token@luna.example', 'staff', 'Elena Papadaki');
    DB::table('users')->where('email', 'linked-token@luna.example')->update(['email_verified_at' => $utc($now)]);
    $walk = adminGreekScript();
    $clean = count(adminGreekPatterns()).':';

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->navigate(Admin::url('luna', '/team'))
        ->assertScript(ADMIN_GREEK_LANG, 'el')
        ->assertSeeIn('.admin-team-count', adminChoice('admin/team.page.count', 3, [], SiteLanguage::Greek))
        ->assertSeeIn('.admin-team-member:first-child', adminLine('admin/team.members.you', [], SiteLanguage::Greek))
        ->assertSeeIn('.admin-team-member:nth-child(2)', adminLine('admin/team.members.invited', [], SiteLanguage::Greek))
        ->assertSeeIn('.admin-team-member:nth-child(3)', 'Elena Papadaki')
        ->assertVisible('.admin-team-member:nth-child(2) .admin-team-role')
        ->assertAttribute('.admin-team-member:nth-child(2) .admin-team-role', 'aria-label', adminLine('admin/team.members.role_of', ['name' => 'Irini Markou'], SiteLanguage::Greek))
        ->assertVisible('.admin-team-invite__send')
        ->assertScript($walk, $clean)
        ->click('.admin-team-member:nth-child(2) .admin-team-remove')
        ->assertVisible('.admin-team-remove-confirm__keep')
        ->assertSee(adminLine('admin/team.members.remove_title', ['name' => 'Irini Markou'], SiteLanguage::Greek))
        ->assertSee(adminLine('admin/team.members.remove_body', ['name' => 'Irini Markou'], SiteLanguage::Greek))
        ->assertScript($walk, $clean)
        ->click('.admin-team-remove-confirm__keep')
        ->assertScript($walk, $clean)
        ->assertNoJavaScriptErrors();

    expect(DB::table('users')->where('email', 'invited-token@luna.example')->exists())->toBeTrue();
});

/** The tenant's today in Athens, YYYY-MM-DD. */
function adminGreekToday(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Athens')))->format('Y-m-d');
}

/** A date of luna's in the tenant's date format, as the two screens spell one. */
function adminGreekDate(string $date): string
{
    $format = DB::table('tenant_luna.settings')->where('id', 1)->value('date_format');
    assert(is_string($format));

    return (new DateTimeImmutable($date, new DateTimeZone('Europe/Athens')))->format($format);
}

/**
 * The script that answers whether the element the selector names reads the Greek line given,
 * a figure ICU spells standing where the token stands: a figure of hours is ICU's and stands
 * free, while a count of weeks is a bare number, typed.
 */
function adminGreekLineScript(string $selector, string $line, string $token): string
{
    $source = json_encode(str_replace(preg_quote($token, '/'), '.+', preg_quote($line, '/')), JSON_THROW_ON_ERROR);
    $target = json_encode($selector, JSON_THROW_ON_ERROR);

    return <<<JS
        (() => {
            const node = document.querySelector({$target});
            return node !== null && new RegExp({$source}).test(node.innerText || '');
        })()
        JS;
}

/** A member of luna's with no repeating pattern, added for a walk; their id. */
function adminGreekMemberWithoutPattern(string $name): int
{
    return DB::table('tenant_luna.staff')->insertGetId(['name' => $name, 'status' => 'active', 'sort' => 9]);
}

/*
 * The Calendar at a tenant whose default is el, seeded as AdminCalendarTest seeds Day: the next
 * Wednesday after the tenant's today, a day both of luna's members work, Elena with a booking at
 * 10:00 and a closure of 14:00 to 16:00 with a reason, which her Day column draws by
 * shifts.php's page.closed_reason, and a third member with no pattern, whose column Who's All
 * staff shows with no hours that day. The walk and the names check run at each state: Day with
 * Who's picker open and closed, a Day column's menu, the Add menu, the closure dialog opened from
 * it with Closed all day unticked so Start and End show, then Week and Month by the address. On
 * Month the Wednesday's chip reads the shell's whole Greek counted line at the one booking seeded.
 */
it('reads the Calendar in Greek on Day with its columns, Who, a column\'s menu, the Add menu and the closure dialog, then on Week and Month, no English value of the walked files and no English month or weekday name left', function () {
    DB::table('tenants')->where('slug', 'luna')->update(['locale' => SiteLanguage::Greek->value]);
    $weekday = (int) (new DateTimeImmutable(adminGreekToday(), new DateTimeZone('Europe/Athens')))->format('N');
    $days = (3 - $weekday + 7) % 7 ?: 7;
    $wednesday = athensDaysOn($days, 10);
    $date = $wednesday->format('Y-m-d');
    adminGreekBooking('Casey Customer', 'casey@example.com', $wednesday);
    $elena = DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->value('id');
    assert(is_int($elena));
    DB::table('tenant_luna.blackouts')->insert(['staff_id' => $elena, 'date' => $date, 'start_time' => '14:00', 'end_time' => '16:00', 'reason' => 'Dentist token']);
    $iris = adminGreekMemberWithoutPattern('Iris Token');
    $walk = adminGreekScript();
    $clean = count(adminGreekPatterns()).':';
    $names = adminGreekNamesScript();
    $column = fn (int $staffId): string => '.admin-calendar-column[data-column="member-'.$staffId.'"]';

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->navigate(Admin::url('luna', Admin::CALENDAR.'?view=day&date='.$date))
        ->waitForText(adminLine('admin/calendar.grid.block', ['customer' => 'Casey Customer', 'service' => 'Haircut'], SiteLanguage::Greek))
        ->assertScript(ADMIN_GREEK_LANG, 'el')
        ->click('#calendar-who')
        ->assertVisible('.admin-calendar-who__popover')
        ->assertScript($walk, $clean)
        ->assertScript($names, '')
        ->click('.admin-calendar-who__preset[data-who="all"]')
        ->assertSeeIn('#calendar-who', adminLine('admin/calendar.who.all', [], SiteLanguage::Greek))
        ->assertVisible($column($iris))
        ->assertSeeIn($column($elena), adminLine('admin/shifts.page.closed_reason', ['start' => '14:00', 'end' => '16:00', 'reason' => 'Dentist token'], SiteLanguage::Greek))
        ->assertSeeIn($column($iris), adminLine('admin/calendar.day.not_working', [], SiteLanguage::Greek))
        ->assertSeeIn('.admin-calendar-filters', adminLine('admin/calendar.filters.colour_by', [], SiteLanguage::Greek))
        ->assertSeeIn('.admin-calendar-legend__closure', adminLine('admin/calendar.legend.closure', [], SiteLanguage::Greek))
        ->assertScript($walk, $clean)
        ->assertScript($names, '');

    $page->click($column($elena).' .admin-calendar-head__menu')
        ->assertVisible('.admin-calendar-column__close')
        ->assertSeeIn('.admin-menu', adminLine('admin/calendar.day.staff_page', [], SiteLanguage::Greek))
        ->assertScript($walk, $clean)
        ->assertScript($names, '')
        ->keys('.admin-calendar-column__book', 'Escape')
        ->assertMissing('.admin-calendar-column__close');

    $page->click('.admin-calendar-add')
        ->assertSeeIn('.admin-menu', adminLine('admin/calendar.page.add_hint', [], SiteLanguage::Greek))
        ->assertScript($walk, $clean)
        ->assertScript($names, '')
        ->click('.admin-calendar-add__closure')
        ->waitForText(adminLine('admin/calendar.closure.title', [], SiteLanguage::Greek))
        ->assertChecked('#closure-all-day')
        ->click('#closure-all-day')
        ->assertVisible('#closure-start')
        ->assertVisible('#closure-end')
        ->assertScript($walk, $clean)
        ->assertScript($names, '')
        ->click('.admin-closure__cancel')
        ->assertMissing('.admin-closure');

    $page->navigate(Admin::url('luna', Admin::CALENDAR.'?view=week&date='.$date))
        ->waitForText(adminLine('admin/calendar.grid.block', ['customer' => 'Casey Customer', 'service' => 'Haircut'], SiteLanguage::Greek))
        ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.week', [], SiteLanguage::Greek))
        ->assertScript($walk, $clean)
        ->assertScript($names, '')
        ->navigate(Admin::url('luna', Admin::CALENDAR.'?view=month&date='.$date))
        ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.month', [], SiteLanguage::Greek))
        ->assertSeeIn('.admin-calendar-day[data-date="'.$date.'"] .admin-chip--live', adminChoice('admin/shell.kit.counted.confirmed', 1, [], SiteLanguage::Greek))
        ->assertScript($walk, $clean)
        ->assertScript($names, '')
        ->assertNoJavaScriptErrors();
});

/*
 * Shifts at a tenant whose default is el, on the system clock in the tenant's week: Elena on her
 * weekly pattern with the week's second day changed for that day alone and its third a day off,
 * Nikos's pattern every two weeks from the week's first day, a third member with no pattern and
 * the week's fourth day closed whole for the business with a reason; Nikos closed for a window
 * with a reason on the week's fifth day and for a window with none on its sixth, and Elena closed
 * the whole of its seventh with no reason, each closure's line asserted in its cell. The row heads
 * are read against the Greek lines with ICU's figure free, and the labels that carry a typed name
 * whole: the third member's fifth day's edit control, Elena's pattern button and the pattern
 * editor's title. The walk and the names check run on the week, on the day editor opened on the
 * third member's fifth day with two windows added, on the flash of that day saved, and on the
 * pattern editor opened on Elena with Repeats every two weeks and Ends on a date, so every field
 * shows, then cancelled.
 */
it('reads Shifts in Greek with every kind of row and day, the day editor with two windows and its flash, and the pattern editor with every field, no English value of the walked files and no English month or weekday name left', function () {
    DB::table('tenants')->where('slug', 'luna')->update(['locale' => SiteLanguage::Greek->value]);
    $startOfWeek = DB::table('tenant_luna.settings')->where('id', 1)->value('start_of_week');
    assert(is_int($startOfWeek));
    $first = CalendarDay::weekStart(adminGreekToday(), $startOfWeek);
    $elena = DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->value('id');
    $nikos = DB::table('tenant_luna.staff')->where('name', 'Nikos Georgiou')->value('id');
    assert(is_int($elena) && is_int($nikos));
    $iris = adminGreekMemberWithoutPattern('Iris Token');
    DB::table('tenant_luna.shift_overrides')->insert([
        ['staff_id' => $elena, 'date' => CalendarDay::plusDays($first, 1), 'windows' => '[{"start": "10:00", "end": "12:00"}]'],
        ['staff_id' => $elena, 'date' => CalendarDay::plusDays($first, 2), 'windows' => '[]'],
    ]);
    // Nikos's week as luna's demo hours give it, Tuesday to Saturday, now every two weeks.
    WeeklyHours::pattern('tenant_luna', $nikos, $first, 2, null, array_map(fn (int $weekday): array => [$weekday, '10:00', '19:00'], range(2, 6)));
    DB::table('tenant_luna.blackouts')->insert(['staff_id' => null, 'date' => CalendarDay::plusDays($first, 3), 'reason' => 'Refit token']);
    DB::table('tenant_luna.blackouts')->insert([
        ['staff_id' => $nikos, 'date' => CalendarDay::plusDays($first, 4), 'start_time' => '11:00', 'end_time' => '12:00', 'reason' => 'Errand token'],
        ['staff_id' => $nikos, 'date' => CalendarDay::plusDays($first, 5), 'start_time' => '15:00', 'end_time' => '16:00', 'reason' => null],
        ['staff_id' => $elena, 'date' => CalendarDay::plusDays($first, 6), 'start_time' => null, 'end_time' => null, 'reason' => null],
    ]);
    $cell = fn (int $staffId, int $day): string => sprintf('.admin-shifts__row[data-staff="%d"] .admin-shifts__cell[data-date="%s"]', $staffId, CalendarDay::plusDays($first, $day));
    $head = fn (int $staffId): string => sprintf('.admin-shifts__row[data-staff="%d"] .admin-shifts__row-head', $staffId);
    $token = 'figure-token';
    $walk = adminGreekScript();
    $clean = count(adminGreekPatterns()).':';
    $names = adminGreekNamesScript();

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->navigate(Admin::url('luna', '/shifts'))
        ->waitForText(adminLine('admin/shifts.page.lead', [], SiteLanguage::Greek))
        ->assertScript(ADMIN_GREEK_LANG, 'el')
        ->assertAttribute($cell($elena, 1), 'data-source', 'override')
        ->assertSeeIn($cell($elena, 1), adminLine('admin/shifts.page.this_day_only', [], SiteLanguage::Greek))
        ->assertSeeIn($cell($elena, 2), adminLine('admin/shifts.page.not_working', [], SiteLanguage::Greek))
        ->assertSeeIn($cell($elena, 3), adminLine('admin/shifts.page.closed_all_day_reason', ['reason' => 'Refit token'], SiteLanguage::Greek))
        ->assertSeeIn($cell($nikos, 4), adminLine('admin/shifts.page.closed_reason', ['start' => '11:00', 'end' => '12:00', 'reason' => 'Errand token'], SiteLanguage::Greek))
        ->assertSeeIn($cell($nikos, 5), adminLine('admin/shifts.page.closed', ['start' => '15:00', 'end' => '16:00'], SiteLanguage::Greek))
        ->assertSeeIn($cell($elena, 6), adminLine('admin/shifts.page.closed_all_day', [], SiteLanguage::Greek))
        ->assertScript(adminGreekLineScript($head($elena), adminLine('admin/shifts.page.row_weekly', ['hours' => $token], SiteLanguage::Greek), $token), true)
        ->assertScript(adminGreekLineScript($head($nikos), adminLine('admin/shifts.page.row_cadence', ['hours' => $token, 'weeks' => '2', 'from' => adminGreekDate($first)], SiteLanguage::Greek), $token), true)
        ->assertScript(adminGreekLineScript($head($iris), adminLine('admin/shifts.page.row_none', ['hours' => $token], SiteLanguage::Greek), $token), true)
        ->assertSeeIn('.admin-shifts__footnote', adminLine('admin/shifts.page.footnote', [], SiteLanguage::Greek))
        ->assertVisible('.admin-shifts__legend')
        ->assertScript($walk, $clean)
        ->assertScript($names, '');

    $page->assertSeeIn($cell($iris, 4).' .admin-shifts__edit-what', adminLine('admin/shifts.page.edit_day', ['name' => 'Iris Token', 'date' => adminGreekDate(CalendarDay::plusDays($first, 4))], SiteLanguage::Greek))
        ->click($cell($iris, 4).' .admin-shifts__edit')
        ->assertVisible('.admin-shift-day')
        ->assertSeeIn('.admin-shift-day__pattern', adminLine('admin/shifts.editor.pattern_none', [], SiteLanguage::Greek))
        ->click('.admin-shift-day__add')
        ->assertVisible('.admin-shift-day__window[data-window="0"]')
        ->click('.admin-shift-day__add')
        ->assertVisible('.admin-shift-day__window[data-window="1"]')
        ->assertVisible('.admin-shift-day__hours')
        ->assertScript($walk, $clean)
        ->assertScript($names, '')
        ->click('.admin-shift-day__save')
        ->waitForText(adminLine('admin/shifts.flashes.day_saved', [], SiteLanguage::Greek))
        ->assertMissing('.admin-shift-day')
        ->assertScript($walk, $clean)
        ->assertScript($names, '');

    $page->assertAttribute(sprintf('.admin-shifts__row[data-staff="%d"] .admin-shifts__pattern', $elena), 'aria-label', adminLine('admin/shifts.page.edit_pattern', ['name' => 'Elena Papadaki'], SiteLanguage::Greek))
        ->click(sprintf('.admin-shifts__row[data-staff="%d"] .admin-shifts__pattern', $elena))
        ->assertVisible('.admin-shift-pattern')
        ->assertSeeIn('.admin-shift-pattern', adminLine('admin/shifts.pattern.title', ['name' => 'Elena Papadaki'], SiteLanguage::Greek))
        ->select('.admin-shift-pattern__repeats', '2')
        ->assertVisible('.admin-shift-pattern__starts')
        ->select('.admin-shift-pattern__ends', 'on')
        ->assertVisible('.admin-shift-pattern__ends-on')
        ->assertSeeIn('.admin-shift-pattern__help', adminLine('admin/shifts.pattern.help_cadence', ['weeks' => '2'], SiteLanguage::Greek))
        ->assertScript($walk, $clean)
        ->assertScript($names, '')
        ->click('.admin-shift-pattern__cancel')
        ->assertMissing('.admin-shift-pattern')
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenant_luna.shift_overrides')->where('staff_id', $iris)->count())->toBe(1);
});

/**
 * luna subscribed on the plan with Staff, as AdminCloseAccountTest seeds it: the subscriptions row,
 * the tenant row as the sync would leave it, and the fake answering the same subscription.
 */
function adminGreekSubscribed(): void
{
    DB::table('subscriptions')->insert([
        'tenant_id' => DB::table('tenants')->where('slug', 'luna')->value('id'),
        'provider_customer_id' => 'cus_luna',
        'provider_subscription_id' => 'sub_luna',
        'status' => 'active',
        'items' => json_encode([['price' => 'price_staff_test', 'quantity' => 1]], JSON_THROW_ON_ERROR),
    ]);
    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'active', 'entitlements' => '{staff}', 'plan_ref' => 'price_staff_test', 'trial_ends_at' => null]);
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_staff_test']));
}

/*
 * The Billing screen as luna's owner, seeded as AdminBillingTest seeds each state: first a trial on
 * the system clock, its end five days on, read with its notice, its end, the Free trial card with
 * what it includes and the plan choice opened with both plans' lines and buttons; then subscribed
 * on the plan with Staff while the roster's active members number two or more, read with Change
 * plan's Remove Staff, the floor's refusal beside it, the link to the Staff screen and the payment
 * card, and the removal pressed and refused in its toast. A plan's name, Solo or Team, is the
 * catalogue's and stays.
 */
it('reads the Billing screen in a trial with the plan choice open, and on the plan with Staff with the removal refused, in Greek, no English value of the walked files and no English month or weekday name left', function () {
    $ends = (new DateTimeImmutable('now', new DateTimeZone('Europe/Athens')))->add(new DateInterval('P5D'))->setTime(9, 30);
    DB::table('tenants')->where('slug', 'luna')->update([
        'subscription_state' => 'trial',
        'entitlements' => '{staff}',
        'plan_ref' => null,
        'trial_ends_at' => $ends->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP'),
    ]);
    $walk = adminGreekScript();
    $clean = count(adminGreekPatterns()).':';
    $names = adminGreekNamesScript();
    $el = SiteLanguage::Greek;

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->navigate(Admin::url('luna', '/billing'))
        ->waitForText(adminLine(BillingController::TRIAL, [], $el))
        ->assertScript(ADMIN_GREEK_LANG, 'el')
        ->assertVisible('.admin-billing__trial')
        ->assertSeeIn('.admin-billing__plan-name', adminLine(BillingController::TRIAL_PLAN, [], $el))
        ->assertSeeIn('.admin-billing__includes', adminLine(BillingController::STAFF_INCLUDES, [], $el))
        ->assertSeeIn('.admin-billing__resubscribe', adminLine(BillingController::CHOOSE_PLAN, [], $el))
        ->assertScript($walk, $clean)
        ->assertScript($names, '')
        ->click('.admin-billing__resubscribe')
        ->assertSeeIn('[data-plan="floor"] .admin-billing__option-name', 'Solo')
        ->assertSeeIn('[data-plan="staff"] .admin-billing__option-name', 'Team')
        ->assertSeeIn('[data-plan="staff"] .admin-billing__option-button', adminLine('admin/billing.checkout.choose', ['plan' => 'Team'], $el))
        ->assertScript($walk, $clean)
        ->assertScript($names, '')
        ->assertNoJavaScriptErrors();

    adminGreekSubscribed();
    $active = DB::table('tenant_luna.staff')->where('status', 'active')->count();
    $refusal = adminChoice(PlanChangeRefused::DOWNGRADE_SENTENCE, $active, [], $el);

    expect($active)->toBeGreaterThan(1);

    $page->navigate(Admin::url('luna', '/billing'))
        ->waitForText(adminLine(BillingController::ACTIVE, [], $el))
        ->assertSeeIn('.admin-billing__plan-name', 'Team')
        ->assertSeeIn('.admin-billing__change-button', adminLine('admin/billing.change.remove', [], $el))
        ->assertSeeIn('.admin-billing__staff-note', $refusal)
        ->assertAttribute('.admin-billing__staff-link', 'href', '/admin/staff')
        ->assertSeeIn('.admin-billing__staff-link', adminLine('admin/billing.change.staff_link', [], $el))
        ->assertSeeIn('.admin-billing__payment', adminLine('admin/billing.payment.title', [], $el))
        ->assertScript($walk, $clean)
        ->click('.admin-billing__change-button')
        ->waitForText(adminLine('admin/shell.toaster.dismiss', [], $el))
        ->assertSeeIn('.admin-toast', $refusal)
        ->assertScript($walk, $clean)
        ->assertScript($names, '')
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenants')->where('slug', 'luna')->value('entitlements'))->toBe('{staff}');
});

/*
 * The Settings page's Emails list and Danger zone as luna's owner, subscribed as
 * AdminCloseAccountTest seeds it so the close's way back is the Billing screen: every message's
 * label with its audience tag, the export's line, the close's line with the window's days spelled
 * by ICU in Greek, and the close's dialog opened, its title, the day it would close on spelled in
 * Greek with the zone, what keeps running, what pauses, the billing line and its two buttons, then
 * kept. The account is not closed here: AccountCloseTest proves the closing lines.
 */
it('reads the Settings page\'s Emails list and Danger zone with the close\'s dialog opened and kept, in Greek, no English value of the walked files and no English month or weekday name left', function () {
    adminGreekSubscribed();
    $el = SiteLanguage::Greek;
    $days = (string) (new NumberFormatter($el->value, NumberFormatter::SPELLOUT))->format(ClosingWindow::days());
    // The day the window would end on if closed now, on the real clock in the tenant's zone, as
    // the server spells it for the reader.
    $day = ClosingWindow::dayOf(app(Clock::class)->now(), new DateTimeZone('Europe/Athens'), $el);
    $walk = adminGreekScript();
    $clean = count(adminGreekPatterns()).':';
    $names = adminGreekNamesScript();

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->navigate(Admin::url('luna', '/settings'))
        ->assertVisible('.admin-section--emails')
        ->assertScript(ADMIN_GREEK_LANG, 'el')
        ->assertCount('.admin-settings-email', count(MessageKey::cases()))
        ->assertCount('.admin-settings-email__audience', count(MessageKey::cases()));

    // Each label beside its checkbox, and its audience tag: the customer, the business, or the
    // owners alone for the one message only they receive.
    foreach (MessageKey::cases() as $key) {
        $tag = $key->audience() === Audience::Customer ? 'customer' : ($key->ownersAlone() ? 'owners' : 'business');

        $page->assertSeeIn('label[for="settings-email-'.$key->value.'"]', adminLine($key->labelKey(), [], $el))
            ->assertSeeIn('label[for="settings-email-'.$key->value.'"] .admin-settings-email__audience', adminLine('admin/emails.audience.'.$tag, [], $el));
    }

    $page->assertSeeIn('.admin-settings-danger', adminLine('admin/billing.danger.title', [], $el))
        ->assertSeeIn('.admin-settings-danger__what', adminLine('admin/billing.danger.export.what', [], $el))
        ->assertSeeIn('.admin-settings-export', adminLine('admin/billing.danger.export.button', [], $el))
        ->assertSeeIn('.admin-settings-close__what', adminChoice(SettingsController::CLOSE_WHAT, ClosingWindow::days(), ['count' => $days], $el))
        ->assertScript($walk, $clean)
        ->assertScript($names, '')
        ->click('.admin-settings-close__button')
        ->waitForText(adminLine('admin/billing.danger.close.title', [], $el))
        ->assertSeeIn('.admin-close-prompt__when', adminChoice('admin/billing.danger.close.when', ClosingWindow::days(), ['count' => $days, 'day' => $day, 'zone' => 'Europe/Athens'], $el))
        ->assertSeeIn('.admin-close-prompt__keeps', adminLine('admin/billing.danger.close.keeps', [], $el))
        ->assertSeeIn('.admin-close-prompt__pauses', adminLine('admin/billing.danger.close.pauses', [], $el))
        ->assertSeeIn('.admin-close-prompt__billing', adminLine('admin/billing.danger.close.billing', [], $el))
        ->assertSeeIn('.admin-close-prompt__way-back', adminLine(ClosingWindow::WAY_BACK, [], $el))
        ->assertSeeIn('.admin-close-prompt__confirm', adminLine('admin/billing.danger.close.confirm', [], $el))
        ->assertVisible('.admin-close-prompt__keep')
        ->assertScript($walk, $clean)
        ->assertScript($names, '')
        ->click('.admin-close-prompt__keep')
        ->assertCount('.admin-close-prompt__confirm', 0)
        ->assertScript($walk, $clean)
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenants')->where('slug', 'luna')->value('status'))->toBe('active');
});

/*
 * Reports as the Greek owner. The screen chooses its words on the client, so each state is opened
 * and read whole: last month, where nothing is seeded, with its empty words; this week with
 * figures in every tile, Over time's two charts (their aria-labels read by the walk with every
 * other attribute), the cards and tables and Occupancy by staff, the confirmed revenue in Greek
 * number forms; the filter card with a member chosen; a custom range picked through the admin's
 * date picker and applied, then a To before the From, whose sentence the page shows. The other
 * sentence the filter card holds, a custom range past 366 days, is a year of the picker's months
 * and is not provoked here: the walk by keys and the placeholder parity hold it. The days are the
 * Athens clock's, read as the page reads it.
 */
it('reads Reports in Greek on an empty range and on this week with every part shown, the filter card with a member chosen, a custom range picked through the date picker and a To before the From, no English value of the walked files and no English month or weekday name left', function () {
    $today = athensToday();
    // This week's bookings, all today on the tenant's clock: two confirmed with a price, one
    // completed, one a no-show and one cancelled, so every tile, card and chart holds a figure.
    adminGreekHomeBooking('Cora Cut', athensDaysOn(0, 0, 30), 'confirmed');
    adminGreekHomeBooking('Dora Dye', athensDaysOn(0, 0, 45), 'confirmed', 4250);
    adminGreekHomeBooking('Carl Complete', athensDaysOn(0, 0, 15), 'completed', 2000);
    adminGreekHomeBooking('Nora Noshow', athensDaysOn(0, 0, 20), 'no_show');
    adminGreekHomeBooking('Yana Cancel', athensDaysOn(0, 0, 10), 'cancelled');
    $elena = DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->value('id');
    assert(is_int($elena));

    $walk = adminGreekScript();
    $clean = count(adminGreekPatterns()).':';
    $names = adminGreekNamesScript();
    $format = DB::table('tenant_luna.settings')->where('id', 1)->value('date_format');
    $startOfWeek = DB::table('tenant_luna.settings')->where('id', 1)->value('start_of_week');
    assert(is_string($format) && is_int($startOfWeek));
    $date = fn (string $day): string => (new DateTimeImmutable($day, new DateTimeZone('Europe/Athens')))->format($format);
    $range = fn (string $word): FigureRange => FigureRange::named($word, $today->format('Y-m-d'), $startOfWeek, FigureRange::REPORTS);
    $stated = fn (string $word, string $from, string $to): string => adminLine('admin/reports.filters.stated.'.$word, ['from' => $date($from), 'to' => $date($to)], SiteLanguage::Greek);
    // The confirmed revenue, the two confirmed bookings, as PHP's ICU writes it in Greek, computed
    // here and never spelled; its spaces read as one, as the script reads the line.
    $revenue = (string) (new NumberFormatter(SiteLanguage::Greek->value, NumberFormatter::CURRENCY))->formatCurrency((float) (new Money(3500 + 4250, Currency::of('EUR')))->decimal(), 'EUR');
    $revenue = trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $revenue));
    $lastMonth = $range(FigureRange::LAST_MONTH);
    $week = $range(FigureRange::WEEK);
    // A custom range of today alone, then a To before the From inside today's month: the day
    // before as To, or on the first of a month the day after as From.
    $day = $today->format('Y-m-d');
    [$earlyFrom, $earlyTo] = (int) $today->format('j') > 1 ? [$day, CalendarDay::plusDays($day, -1)] : [CalendarDay::plusDays($day, 1), $day];
    $pick = fn (string $iso): string => '.admin-day-calendar button[data-date="'.$iso.'"]';

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->navigate(Admin::url('luna', '/reports?range=last_month'))
        ->assertSeeIn('.admin-reports-filters__stated', $stated('last_month', $lastMonth->from, $lastMonth->to))
        ->assertScript(ADMIN_GREEK_LANG, 'el')
        ->assertSeeIn('.admin-figure-tile--bookings .admin-figure-tile__value', '0')
        ->assertSee(adminLine('admin/reports.tables.no_completed', [], SiteLanguage::Greek))
        ->assertScript($walk, $clean)
        ->assertScript($names, '');

    $page->navigate(Admin::url('luna', '/reports'))
        ->assertSeeIn('.admin-reports-filters__stated', $stated('week', $week->from, $week->to))
        ->assertSeeIn('.admin-figure-tile--bookings .admin-figure-tile__value', '3')
        ->assertSeeIn('.admin-reports-channels', adminLine('admin/reports.tables.no_channel', [], SiteLanguage::Greek))
        ->assertSee(adminLine('admin/reports.occupancy.title', [], SiteLanguage::Greek))
        ->assertSee(adminLine('admin/reports.over_time.title', [], SiteLanguage::Greek))
        ->assertScript(adminGreekTextScript('.admin-reports-revenue__confirmed .admin-reports__amount'), $revenue)
        ->assertScript($walk, $clean)
        ->assertScript($names, '');

    $page->select('#reports-staff', (string) $elena)
        ->assertScript("() => new URL(window.location.href).searchParams.get('staff') === '{$elena}'", true)
        ->assertSelected('#reports-staff', (string) $elena)
        ->assertScript($walk, $clean)
        ->assertScript($names, '');

    $page->navigate(Admin::url('luna', '/reports?range=today'))
        ->assertSeeIn('.admin-reports-filters__stated', adminLine('admin/reports.filters.stated.today', ['date' => $date($day)], SiteLanguage::Greek))
        ->select('#reports-range', 'custom')
        ->assertVisible('.admin-reports-custom')
        ->click('#reports-from')
        ->assertScript(adminGreekGridScript(), true)
        ->assertScript($walk, $clean)
        ->assertScript($names, '')
        ->click($pick($day))
        ->click('#reports-to')
        ->assertScript(adminGreekGridScript(), true)
        ->click($pick($day))
        ->click('.admin-reports-apply')
        ->waitForText(adminLine('admin/reports.filters.stated.custom_day', ['date' => $date($day)], SiteLanguage::Greek))
        ->assertQueryStringHas('range', 'custom')
        ->assertScript($walk, $clean)
        ->assertScript($names, '');

    $page->click('#reports-from')
        ->assertScript(adminGreekGridScript(), true)
        ->click($pick($earlyFrom))
        ->click('#reports-to')
        ->assertScript(adminGreekGridScript(), true)
        ->click($pick($earlyTo))
        ->click('.admin-reports-apply')
        ->waitForText(adminLine('admin/reports.filters.to_before_from', [], SiteLanguage::Greek))
        ->assertScript($walk, $clean)
        ->assertScript($names, '')
        ->assertNoJavaScriptErrors();
});
