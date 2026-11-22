<?php

use App\Http\Controllers\Admin\LoginController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Pest\Browser\Api\Webpage;
use Tests\Browser\Support\Admin;
use Tests\Support\FakePaymentAdapter;

/*
 * The setup wizard in a real browser, on a business made here by tenant:provision with the staff
 * feature, so all six steps apply; the seeded businesses have finished setup and show neither the
 * wizard nor its banner. The first sign-in brings the wizard up by itself on the first step, over
 * Home with the banner under it.
 *
 * The whole walk, at the desktop's width and on a phone: the profile saved, a service added, a team
 * member added who performs it, the week saved for every member, online payments passed with Skip
 * for now, and Finish on the booking link, after which the wizard closes saying setup is complete,
 * the banner is gone, and neither comes back on the next load. At the desktop's width the walk
 * reads where it stands from the step list, on a phone from the one line naming the step's place.
 *
 * Closing mid-way keeps what was saved: the banner counts the steps done, Continue reopens on the
 * first open step, Team skipped with no member counts as closed and offers Next on the way back,
 * the saved fields hold their values, and a sign-out and a second sign-in land on Home with the
 * banner and no wizard, the wizard having come up by itself once already. The wizard is closed
 * once by its close button and once by Escape.
 *
 * The provider's hosted onboarding is stood in for: the page's leaving for the provider's address
 * is caught and held where the router sends it, and the walk then opens the return address the
 * provider was handed, as the provider would at the end of its form. Home comes back with the
 * wizard on Online payments and the connection pending.
 */

const WIZARD_SLUG = 'fern';

const WIZARD_PASSWORD = 'correct horse battery staple';

/** The steps in the order the wizard walks them, every one applying with the staff feature. */
const WIZARD_STEPS = ['profile', 'services', 'team', 'hours', 'payments', 'link'];

/**
 * Holds the page where it is when it is about to leave for another origin, and keeps the address
 * it was sent to: the provider's hosted onboarding, which no browser here can reach.
 */
const WIZARD_HOLD_LEAVING = <<<'JS'
    window.navigation.addEventListener('navigate', (event) => {
        if (new URL(event.destination.url).origin !== window.location.origin) {
            event.preventDefault();
            window.wizardLeftFor = event.destination.url;
        }
    });
    JS;

beforeEach(function () {
    DB::statement(sprintf('drop schema if exists tenant_%s cascade', WIZARD_SLUG));

    expect(Artisan::call('tenant:provision', [
        'slug' => WIZARD_SLUG,
        '--name' => 'Fern Studio',
        '--timezone' => 'Europe/Athens',
        '--currency' => 'EUR',
        '--owner-name' => 'Fay Owner',
        '--owner-email' => Admin::owner(WIZARD_SLUG),
        '--owner-password' => WIZARD_PASSWORD,
        '--features' => 'staff',
    ]))->toBe(0);
});

afterEach(fn () => DB::statement(sprintf('drop schema if exists tenant_%s cascade', WIZARD_SLUG)));

/**
 * Waits for the wizard to stand on the step given: at the desktop's width its button in the list
 * marked current and its name under the list, on a phone the one line with the step's place among
 * the six and its name, and no list.
 *
 * @param  Webpage  $page
 */
function wizardOn($page, string $step, bool $phone): void
{
    $name = adminLine('admin/setup.steps.'.$step.'.name');

    if ($phone) {
        $number = (string) (array_search($step, WIZARD_STEPS, true) + 1);

        $page->assertSeeIn('.admin-setup__position', adminLine('admin/setup.position', ['number' => $number, 'total' => (string) count(WIZARD_STEPS)]))
            ->assertSeeIn('.admin-setup__position .admin-setup__name', $name)
            ->assertMissing('.admin-setup__steps');

        return;
    }

    $page->assertVisible(sprintf('.admin-setup__step[data-step="%s"][aria-current="step"]', $step))
        ->assertSeeIn('h2.admin-setup__name', $name);
}

/** The banner's count of the steps done or skipped. */
function wizardProgress(int $done): string
{
    return adminLine('admin/setup.banner.progress', ['done' => (string) $done, 'total' => (string) count(WIZARD_STEPS)]);
}

/**
 * The business's setup facts as stored, each read as whether it is set.
 *
 * @return array<string, bool>
 */
function wizardFacts(): array
{
    $columns = ['setup_opened_at', 'setup_profile_saved_at', 'hours_confirmed_at', 'setup_team_skipped_at', 'setup_payments_skipped_at', 'setup_link_finished_at', 'link_copied_at', 'setup_finished_at'];
    $row = DB::table('tenant_'.WIZARD_SLUG.'.settings')->where('id', 1)->first($columns);
    assert(is_object($row));
    $facts = [];

    foreach ($columns as $column) {
        $facts[$column] = $row->{$column} !== null;
    }

    return $facts;
}

it('walks a fresh business through every step to Finish, after which the wizard and the banner are gone for good', function (bool $phone) {
    $page = $phone
        ? Admin::signInOnPhone($this, WIZARD_SLUG, Admin::owner(WIZARD_SLUG), WIZARD_PASSWORD)
        : Admin::signIn($this, WIZARD_SLUG, Admin::owner(WIZARD_SLUG), WIZARD_PASSWORD);

    $page->assertPathIs('/admin')
        ->assertSeeIn('.admin-setup .admin-modal__title', adminLine('admin/setup.title'))
        ->assertSeeIn('.admin-setup-banner__progress', wizardProgress(0));
    wizardOn($page, 'profile', $phone);

    $page->assertSeeIn('.admin-setup-profile__help', adminLine('admin/setup.steps.profile.help'))
        ->assertMissing('.admin-setup__back')
        ->assertMissing('.admin-setup__skip')
        ->fill('#settings-about', 'Cuts and colour in a small studio.')
        ->fill('#settings-address-line1', '12 Example Street')
        ->fill('#settings-city', 'Athens')
        ->fill('#settings-postal-code', '10431')
        ->fill('#settings-phone', '+30 210 000 0000')
        ->assertSeeIn('.admin-setup__primary', adminLine('admin/setup.steps.profile.save'))
        ->click('.admin-setup__primary');
    wizardOn($page, 'services', $phone);

    $page->assertSeeIn('.admin-setup-services__empty', adminLine('admin/setup.steps.services.empty'))
        ->assertSeeIn('#admin-setup-blocked', adminLine('admin/setup.steps.services.blocked'))
        ->fill('#service-name', 'Trim')
        ->fill('#service-price', '20')
        ->click('.admin-setup-services__add')
        ->assertSeeIn('.admin-setup-services__row .admin-setup-services__name', 'Trim')
        ->assertScript(Admin::focusIsOn('#service-name'), true)
        ->assertMissing('#admin-setup-blocked')
        ->assertSeeIn('.admin-setup__primary', adminLine('admin/setup.next'))
        ->click('.admin-setup__primary');
    wizardOn($page, 'team', $phone);

    $trim = DB::table('tenant_'.WIZARD_SLUG.'.services')->where('name', 'Trim')->value('id');
    assert(is_int($trim));

    $page->assertSeeIn('.admin-setup-team__empty', adminLine('admin/setup.steps.team.empty'))
        ->assertSeeIn('.admin-setup__skip', adminLine('admin/setup.skip'))
        ->assertMissing('.admin-setup__primary')
        ->fill('#staff-name', 'Nia Stylist')
        ->check('#staff-service-'.$trim)
        ->click('.admin-setup-team__add')
        ->assertSeeIn('.admin-setup-team__row .admin-setup-team__name', 'Nia Stylist')
        ->assertSeeIn('.admin-setup-team__row .admin-setup-team__services', 'Trim')
        ->assertScript(Admin::focusIsOn('#staff-name'), true)
        ->assertMissing('.admin-setup__skip')
        ->assertSeeIn('.admin-setup__primary', adminLine('admin/setup.next'))
        ->click('.admin-setup__primary');
    wizardOn($page, 'hours', $phone);

    $page->assertSeeIn('.admin-setup-hours__members', 'Nia Stylist')
        ->assertSeeIn('.admin-setup__primary', adminLine('admin/setup.steps.hours.save'))
        ->click('.admin-setup__primary');
    wizardOn($page, 'payments', $phone);

    $page->assertSeeIn('.admin-setup-payments__help', adminLine('admin/setup.steps.payments.help'))
        ->assertSeeIn('.admin-setup__skip', adminLine('admin/setup.skip'))
        ->click('.admin-setup__skip');
    wizardOn($page, 'link', $phone);

    // Skip moves on at once and records the skip by a write of its own; the banner under the
    // wizard counting five is that write landed, before Finish sends the last one.
    $page->assertSeeIn('.admin-setup-banner__progress', wizardProgress(5))
        ->assertSeeIn('.admin-setup-link__url', hostedPage(WIZARD_SLUG))
        ->assertSeeIn('.admin-setup__primary', adminLine('admin/setup.steps.link.finish'))
        ->click('.admin-setup__primary')
        ->assertSeeIn('.admin-toast', adminLine('admin/setup.complete'))
        ->assertMissing('.admin-setup')
        ->assertMissing('.admin-setup-banner')
        ->assertNoJavaScriptErrors();

    expect(wizardFacts())->toBe([
        'setup_opened_at' => true, 'setup_profile_saved_at' => true, 'hours_confirmed_at' => true, 'setup_team_skipped_at' => false,
        'setup_payments_skipped_at' => true, 'setup_link_finished_at' => true, 'link_copied_at' => false, 'setup_finished_at' => true,
    ])
        ->and(DB::table('tenant_'.WIZARD_SLUG.'.settings')->where('id', 1)->value('city'))->toBe('Athens')
        ->and(DB::table('tenant_'.WIZARD_SLUG.'.staff')->where('name', 'Nia Stylist')->exists())->toBeTrue();

    // The week saved on Opening hours is hers, a member holding none until one is given, and the
    // service she was added with is linked to her.
    $nia = DB::table('tenant_'.WIZARD_SLUG.'.staff')->where('name', 'Nia Stylist')->value('id');
    assert(is_int($nia));

    expect(DB::table('tenant_'.WIZARD_SLUG.'.shift_patterns')->where('staff_id', $nia)->whereRaw('jsonb_array_length(windows) > 0')->count())->toBe(1)
        ->and(DB::table('tenant_'.WIZARD_SLUG.'.service_staff')->where('service_id', $trim)->where('staff_id', $nia)->count())->toBe(1);

    // Home drawn again is the proof the page has settled, the wizard and the banner being drawn
    // with it where they show at all.
    $page->navigate(Admin::url(WIZARD_SLUG))
        ->assertVisible(Admin::HOME)
        ->assertMissing('.admin-setup')
        ->assertMissing('.admin-setup-banner')
        ->assertNoJavaScriptErrors();
})->with(['at the desktop width' => false, 'on a phone' => true]);

it('keeps what was saved when closed mid-way, counts it on the banner, reopens on the first open step from Continue, takes Team\'s skip, and stays down at a second sign-in', function () {
    $page = Admin::signIn($this, WIZARD_SLUG, Admin::owner(WIZARD_SLUG), WIZARD_PASSWORD)->assertPathIs('/admin');
    wizardOn($page, 'profile', false);

    $page->fill('#settings-about', 'Cuts and colour in a small studio.')
        ->fill('#settings-city', 'Athens')
        ->click('.admin-setup__primary');
    wizardOn($page, 'services', false);

    $page->fill('#service-name', 'Trim')
        ->click('.admin-setup-services__add')
        ->assertSeeIn('.admin-setup-services__row .admin-setup-services__name', 'Trim')
        ->assertScript(Admin::focusIsOn('#service-name'), true)
        ->assertSeeIn('.admin-setup__step[data-step="profile"]', adminLine('admin/setup.done'))
        ->assertSeeIn('.admin-setup__step[data-step="services"]', adminLine('admin/setup.done'))
        ->click('.admin-setup__close')
        ->assertMissing('.admin-setup')
        ->assertScript(Admin::focusIsOn('.admin-setup-banner__continue'), true)
        ->assertSeeIn('.admin-setup-banner__progress', wizardProgress(2))
        ->click('.admin-setup-banner__continue');
    wizardOn($page, 'team', false);

    $page->assertSeeIn('.admin-setup__skip', adminLine('admin/setup.skip'))
        ->assertMissing('.admin-setup__primary')
        ->click('.admin-setup__skip');
    wizardOn($page, 'hours', false);

    // The skip's own write landed when the banner under the wizard counts three; Team, skipped
    // with no member, then offers Next beside Back and no second skip.
    $page->assertSeeIn('.admin-setup-banner__progress', wizardProgress(3))
        ->assertSeeIn('.admin-setup__step[data-step="team"]', adminLine('admin/setup.skipped'))
        ->click('.admin-setup__back');
    wizardOn($page, 'team', false);

    $page->assertSeeIn('.admin-setup-team__empty', adminLine('admin/setup.steps.team.empty'))
        ->assertMissing('.admin-setup__skip')
        ->assertSeeIn('.admin-setup__primary', adminLine('admin/setup.next'))
        ->assertVisible('.admin-setup__back')
        ->click('.admin-setup__step[data-step="profile"]');
    wizardOn($page, 'profile', false);

    // Closed this time by Escape, pressed on the open step's own button in the list.
    $page->assertValue('#settings-about', 'Cuts and colour in a small studio.')
        ->assertValue('#settings-city', 'Athens')
        ->keys('.admin-setup__step[data-step="profile"]', 'Escape')
        ->assertMissing('.admin-setup')
        ->assertScript(Admin::focusIsOn('.admin-setup-banner__continue'), true)
        ->click('.admin-signout')
        ->waitForText(adminLine(LoginController::SIGNED_OUT))
        ->assertPathIs('/admin/login')
        ->fill('email', Admin::owner(WIZARD_SLUG))
        ->fill('password', WIZARD_PASSWORD)
        ->click('.admin-login-submit')
        ->assertVisible(Admin::HOME)
        ->assertSeeIn('.admin-setup-banner__progress', wizardProgress(3))
        ->assertMissing('.admin-setup')
        ->assertNoJavaScriptErrors();

    expect(wizardFacts())->toMatchArray(['setup_opened_at' => true, 'setup_profile_saved_at' => true, 'setup_team_skipped_at' => true, 'setup_finished_at' => false]);
});

it('comes back from the provider\'s hosted onboarding onto the Online payments step, the connection pending and the step still open', function () {
    config(['payments.platform_fee' => ['percent' => '1.5'], 'payments.platform.'.FakePaymentAdapter::SLUG => FakePaymentAdapter::PLATFORM_KEYS]);

    $page = Admin::signIn($this, WIZARD_SLUG, Admin::owner(WIZARD_SLUG), WIZARD_PASSWORD)->assertPathIs('/admin');
    wizardOn($page, 'profile', false);

    $page->click('.admin-setup__step[data-step="payments"]');
    wizardOn($page, 'payments', false);

    $page->script(WIZARD_HOLD_LEAVING);
    $page->click('.admin-payments-provider[data-provider="paydesk"] .admin-payments-platform')
        ->assertScript("(window.wizardLeftFor ?? '').startsWith('https://onboarding.paydesk.test/')", true);

    $links = paymentFake()->links;

    expect($links)->toHaveCount(1)
        ->and($page->script('window.wizardLeftFor'))->toBe('https://onboarding.paydesk.test/'.$links[0]['account'].'/1')
        ->and($links[0]['return'])->toBe(Admin::url(WIZARD_SLUG, '/setup/payments/back'));

    $page->navigate($links[0]['return'])
        ->assertPathIs('/admin');
    wizardOn($page, 'payments', false);

    $page->assertVisible('.admin-payments-connection[data-state="pending"]')
        ->assertSeeIn('.admin-setup__skip', adminLine('admin/setup.skip'))
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenant_'.WIZARD_SLUG.'.payment_connections')->get(['provider', 'mode', 'state'])->map(fn (stdClass $row): array => (array) $row)->all())
        ->toBe([['provider' => FakePaymentAdapter::SLUG, 'mode' => 'platform', 'state' => 'pending']])
        ->and(wizardFacts()['setup_payments_skipped_at'])->toBeFalse();
});

/**
 * Monday's first window on the Opening hours step as it stands: whether the day's name stands
 * beside the window, the row on one line; whether Start, End and Remove keep one level, the two
 * fields' tops and feet the same and Remove's foot on theirs; whether Remove stands beside the
 * two fields, starting above the End field's foot and past its right edge; whether the day's name
 * and Add window end on the fields' foot, which only a row on one line can; whether each time is
 * shown whole, on one line inside its field's padding; whether a refusal's sentence runs under
 * the fields from the window's left edge to its right, inside the window; whether it takes one
 * line where the row is one line and no more than two where it stacks; whether Start points at it
 * and is marked; and whether the page scrolls sideways.
 */
const WIZARD_FIRST_WINDOW = <<<'JS'
    document.fonts.ready.then(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => {
        const day = document.querySelector('.admin-weekly__day[data-weekday="1"]');
        const line = day.querySelector('.admin-weekly__window');
        const [from, to] = [line.querySelector('.admin-weekly__start'), line.querySelector('.admin-weekly__end')];
        const [name, add, frame, start, end, remove] = [day.querySelector('h3'), day.querySelector('.admin-weekly__add'), line, from, to, line.querySelector('.admin-weekly__remove')].map((part) => part.getBoundingClientRect());
        const sentence = line.querySelector('.admin-field__error');
        const told = sentence === null ? null : sentence.getBoundingClientRect();
        const near = (one, other) => Math.abs(one - other) <= 1;
        const whole = (time) => {
            const text = document.createRange();
            text.selectNodeContents(time);
            const [drawn, look] = [text.getBoundingClientRect(), getComputedStyle(time)];
            return text.getClientRects().length === 1 && drawn.width <= time.clientWidth - parseFloat(look.paddingLeft) - parseFloat(look.paddingRight) + 0.5;
        };
        const oneLine = name.right <= frame.left + 1 && name.top < frame.bottom;
        resolve({
            oneLine,
            level: near(start.top, end.top) && near(start.bottom, end.bottom) && near(remove.bottom, end.bottom),
            removeBeside: remove.top < end.bottom && remove.left >= end.right - 1,
            nameAndAddOnFoot: oneLine && near(name.bottom, start.bottom) && near(add.bottom, start.bottom),
            timesWhole: [from, to].every(whole),
            sentenceAcross: told !== null && told.top >= start.bottom && near(told.left, frame.left) && near(told.right, frame.right) && told.bottom <= frame.bottom + 1,
            sentenceBrief: told !== null && Math.round(told.height / parseFloat(getComputedStyle(sentence).lineHeight)) <= (oneLine ? 1 : 2),
            startPointsAtIt: sentence !== null && from.getAttribute('aria-describedby') === sentence.id && from.getAttribute('aria-invalid') === 'true',
            pageScrolls: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
        });
    }))))
    JS;

it('keeps a refused window on one level on the Opening hours step in Greek, at 1440px, at 1024px and on a phone: Start, End and Remove together, the day\'s name and Add window on the fields\' foot where the row is one line, the sentence on a line of its own across the window with Start pointing at it, and on the phone Remove beside its two fields with no time cut', function () {
    $monday = '.admin-weekly__day[data-weekday="1"]';
    // The owner reads the admin in Greek, and the page says so of itself.
    DB::table('users')->where('email', Admin::owner(WIZARD_SLUG))->update(['locale' => 'el']);

    $page = Admin::signIn($this, WIZARD_SLUG, Admin::owner(WIZARD_SLUG), WIZARD_PASSWORD)->assertPathIs('/admin')->resize(1440, 900);
    $page->assertVisible('.admin-setup__step[data-step="profile"][aria-current="step"]')
        ->assertScript('document.documentElement.lang', 'el')
        ->click('.admin-setup__step[data-step="hours"]')
        ->assertVisible('.admin-setup__step[data-step="hours"][aria-current="step"]')
        ->assertCount($monday.' .admin-weekly__window', 1);

    // Nothing is refused yet: the row as a phone stacks it.
    $rows = [];
    $page->resize(390, 844);
    $rows['on a phone, nothing refused'] = $page->script(WIZARD_FIRST_WINDOW);

    // End set before Start: the save is refused, and the sentence lands under that window.
    $page->resize(1440, 900)
        ->click($monday.' .admin-weekly__end')
        ->click('.admin-time-field__option[data-value="08:00"]')
        ->assertAttribute($monday.' .admin-weekly__end', 'value', '08:00')
        ->click('.admin-setup__primary')
        ->assertVisible($monday.' .admin-weekly__window .admin-field__error');

    foreach ([1440, 1024, 390] as $width) {
        $page->resize($width, 844);
        $rows['refused, at '.$width.'px'] = $page->script(WIZARD_FIRST_WINDOW);
    }

    $level = ['level' => true, 'removeBeside' => true, 'timesWhole' => true, 'pageScrolls' => false];
    $told = [...$level, 'sentenceAcross' => true, 'sentenceBrief' => true, 'startPointsAtIt' => true];

    expect($rows)->toEqual([
        'on a phone, nothing refused' => [...$level, 'oneLine' => false, 'nameAndAddOnFoot' => false, 'sentenceAcross' => false, 'sentenceBrief' => false, 'startPointsAtIt' => false],
        'refused, at 1440px' => [...$told, 'oneLine' => true, 'nameAndAddOnFoot' => true],
        'refused, at 1024px' => [...$told, 'oneLine' => true, 'nameAndAddOnFoot' => true],
        'refused, at 390px' => [...$told, 'oneLine' => false, 'nameAndAddOnFoot' => false],
    ]);

    $page->assertNoJavaScriptErrors();
});
