<?php

use App\Application\Clock;
use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use App\Domain\Lifetime;
use App\Models\User;
use App\Notifications\AccountClosing;
use App\Notifications\ContactMessageReceived;
use App\Notifications\OperatorSignedIn;
use App\Notifications\SetPasswordLink;
use App\Notifications\TenantReopened;
use App\Notifications\TenantResumed;
use App\Notifications\TenantSuspended;
use App\Notifications\VerificationLink;
use App\Notifications\Welcome;
use App\Platform\AccountOwners;
use App\Platform\ClosingWindow;
use App\Platform\PasswordLinks;
use App\Site\ContactMessage;
use App\Site\SiteLanguage;
use App\Tenancy\Signup;
use App\Tenancy\SignupVerification;
use App\Tenancy\Tenant;
use App\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FixedClock;
use Tests\Support\RecordingPlatformMessages;

pest()->use(RefreshDatabase::class);

/*
 * The platform's mails in their recipient's language: every one of the eight BrandedMail classes,
 * sent to three owners of one tenant, one who chose Greek, one who chose English and one who
 * chose none. Each message is in its recipient's users.locale, the one who chose none reading the
 * tenant default, settings.language over the row's tenants.locale. The verification link, sent
 * before the trial's build to an owner no sign-in has reached, reads tenants.locale, the tenant
 * default of a row with no schema.
 *
 * A message's language is read off what it carries: its HTML part's lang and its subject, the
 * subject read through the translator in that language. The words themselves are proven per mail
 * by their own files (BrandedMailTest, TrialNoticeTest, SignupInGreekTest and the others). The
 * clock is pinned, so the instants are the ones spelled here.
 */

beforeEach(function () {
    app()->instance(Clock::class, FixedClock::at('2026-11-03 09:00'));
    $this->seam = new RecordingPlatformMessages;
    app()->instance(PlatformMessenger::class, $this->seam);
    provisionTenant('luna', ['name' => 'Luna Hair Studio', 'ownerEmail' => 'greek@luna.example']);
    addTenantUser('english@luna.example', 'owner');
    addTenantUser('none@luna.example', 'owner');
    DB::table('users')->where('email', 'greek@luna.example')->update(['locale' => 'el']);
    DB::table('users')->where('email', 'english@luna.example')->update(['locale' => 'en']);
});

/**
 * Luna as the case sets it: its row's language and its settings' choice, read back fresh.
 */
function mailLanguageTenant(SiteLanguage $locale, ?SiteLanguage $default): Tenant
{
    DB::table('tenants')->where('slug', 'luna')->update(['locale' => $locale->value]);
    DB::table('tenant_luna.settings')->where('id', 1)->update(['language' => $default?->value]);

    return Tenant::query()->where('slug', 'luna')->sole();
}

/**
 * Luna as its owners are told about it, read as the console's writes read it.
 */
function mailLanguageOwners(Tenant $tenant): AccountOwners
{
    $row = DB::selectOne('select slug, name, timezone from tenants where id = ?', [$tenant->id]);
    assert($row instanceof stdClass);

    return AccountOwners::read(DB::connection(), $tenant, $row, 'The test');
}

/**
 * The language a message went out in, as its HTML part's lang names it, and its subject.
 *
 * @return array{string, string}
 */
function mailLanguageOf(PlatformMessage $message): array
{
    preg_match('/<html lang="([a-z]+)">/', (string) $message->html, $found);

    return [$found[1] ?? '', $message->subject];
}

/**
 * Each mail as the platform sends it to luna's owners, by its kind: the subject's key and what
 * fills it, and the send. The trial notices and the lapse's close run through their commands, so
 * the owner reader of TrialDeadlines is the one read; the console's and the owner's writes hand
 * their mails the owners as AccountOwners reads them; the set-password link and the welcome go to
 * each user in turn.
 *
 * @return array<string, array{string, array<string, string>, Closure(Tenant): void}>
 */
function mailLanguageSends(): array
{
    $at = new DateTimeImmutable('2026-11-03 09:00:00', new DateTimeZone('UTC'));
    $eachUser = fn (Closure $send): Closure => function (Tenant $tenant) use ($send): void {
        foreach (User::query()->where('tenant_id', $tenant->id)->orderBy('id')->get() as $user) {
            $send($tenant, $user);
        }
    };

    return [
        'the trial\'s three days notice' => ['trial.three_days.subject', [], function (Tenant $tenant): void {
            DB::table('tenants')->where('id', $tenant->id)->update(['subscription_state' => 'trial', 'trial_ends_at' => '2026-11-05 09:00:00+00']);
            Artisan::call('trial:notify');
        }],
        'the lapse\'s close after an unpaid trial' => ['closing.subject', [], function (Tenant $tenant): void {
            DB::table('tenants')->where('id', $tenant->id)->update(['subscription_state' => 'canceled', 'trial_ends_at' => '2026-10-01 09:00:00+00']);
            Artisan::call('billing:lapse');
        }],
        'the owner\'s close' => ['closing.subject', [], fn (Tenant $tenant) => app(AccountClosing::class)->ownClose(mailLanguageOwners($tenant), $at, false)],
        'the console\'s close' => ['closing.subject', [], fn (Tenant $tenant) => app(AccountClosing::class)->consoleClose(mailLanguageOwners($tenant), $at, false, 'Chargebacks under review.')],
        'the sweep\'s close of a lapsed subscriber' => ['closing.subject', [], fn (Tenant $tenant) => app(AccountClosing::class)->lapsedClose(mailLanguageOwners($tenant), $at, false, null)],
        'the suspension' => ['suspension.subject', [], fn (Tenant $tenant) => app(TenantSuspended::class)->send(mailLanguageOwners($tenant), $at, 'Chargebacks under review.')],
        'the resumption' => ['resumption.subject', [], fn (Tenant $tenant) => app(TenantResumed::class)->send(mailLanguageOwners($tenant), $at)],
        'the reopening' => ['reopened.subject', [], fn (Tenant $tenant) => app(TenantReopened::class)->send(mailLanguageOwners($tenant), $at)],
        'the set-password link' => ['set_password.subject', [], $eachUser(fn (Tenant $tenant, User $user) => app(SetPasswordLink::class)->send($tenant, $user, str_repeat('a', 64)))],
        'the welcome' => ['welcome.subject', ['business' => 'Luna Hair Studio'], $eachUser(function (Tenant $tenant, User $user): void {
            DB::table('tenants')->where('id', $tenant->id)->update(['trial_ends_at' => '2026-11-10 09:00:00+00']);
            app(Welcome::class)->send(Tenant::query()->whereKey($tenant->id)->sole(), $user);
        })],
    ];
}

/**
 * A mail's subject by its key under mail.php in a language: the closing email's a choice by the
 * retention window's days, every other a sentence with the replacements given.
 *
 * @param  array<string, string>  $replace
 */
function mailLanguageSubject(string $key, SiteLanguage $language, array $replace): string
{
    return $key === 'closing.subject'
        ? mailChoice('mail.closing.subject', ClosingWindow::days(), $language)
        : siteSentence('mail.'.$key, $language, $replace);
}

/**
 * The cases: the row's language, the settings' choice, and the
 * language each of the three owners is mailed in, the one who chose Greek, the one who chose
 * English and the one who chose none.
 *
 * @return array<string, array{SiteLanguage, ?SiteLanguage, SiteLanguage, SiteLanguage, SiteLanguage}>
 */
function mailLanguageCases(): array
{
    $en = SiteLanguage::English;
    $el = SiteLanguage::Greek;

    return [
        'an English row: each their own, none the row\'s English' => [$en, null, $el, $en, $en],
        'a Greek row: each their own, none the row\'s Greek' => [$el, null, $el, $en, $el],
        'an English row whose settings chose Greek: none the settings\' Greek' => [$en, $el, $el, $en, $el],
    ];
}

it('mails each owner in their own language', function (string $mail, SiteLanguage $locale, ?SiteLanguage $default, SiteLanguage $greek, SiteLanguage $english, SiteLanguage $none) {
    [$subjectKey, $replace, $send] = mailLanguageSends()[$mail];

    $send(mailLanguageTenant($locale, $default));

    $sent = [];

    foreach ($this->seam->sent as $message) {
        $sent[$message->toAddress] = mailLanguageOf($message);
    }

    $expected = fn (SiteLanguage $language): array => [$language->value, mailLanguageSubject($subjectKey, $language, $replace)];

    expect($sent)->toBe([
        'greek@luna.example' => $expected($greek),
        'english@luna.example' => $expected($english),
        'none@luna.example' => $expected($none),
    ])
        ->and(count($this->seam->sent))->toBe(3)
        ->and(mailLanguageSubject($subjectKey, SiteLanguage::Greek, $replace))->not->toBe(mailLanguageSubject($subjectKey, SiteLanguage::English, $replace));
})->with(array_keys(mailLanguageSends()))->with(mailLanguageCases());

it('mails the verification link in the row\'s tenants.locale, the tenant default of a row whose schema is not built', function (SiteLanguage $locale) {
    $tenant = app(TenantProvisioner::class)->reserve(new Signup('acme', 'Acme Salon', new DateTimeZone('Europe/Athens'), 'EUR', 'Alex Owner', 'owner@acme.example', 'correct horse battery staple', locale: $locale));

    app(VerificationLink::class)->send($tenant, 'owner@acme.example', 'Alex Owner', str_repeat('c', 64));

    expect(mailLanguageOf($this->seam->sent[0]))->toBe([$locale->value, siteSentence('mail.verification.subject', $locale)]);
})->with([
    'a Greek signup' => [SiteLanguage::Greek],
    'an English signup' => [SiteLanguage::English],
]);

it('reads each owner\'s users.locale beside the address and the name, in the order the owners were made', function () {
    $owners = mailLanguageOwners(mailLanguageTenant(SiteLanguage::English, null));

    expect($owners->owners)->toBe([
        ['address' => 'greek@luna.example', 'name' => 'Alex Owner', 'locale' => SiteLanguage::Greek],
        ['address' => 'english@luna.example', 'name' => 'english@luna.example', 'locale' => SiteLanguage::English],
        ['address' => 'none@luna.example', 'name' => 'none@luna.example', 'locale' => null],
    ])
        ->and($owners->tenant->id)->toBe($owners->id);
});

it('sends the operator\'s mails as plain English text with no HTML part, as before', function () {
    mailLanguageTenant(SiteLanguage::Greek, SiteLanguage::Greek);

    app(OperatorSignedIn::class)->send(addOperator(), new DateTimeImmutable('2026-11-03 09:00:00', new DateTimeZone('UTC')), '203.0.113.7');
    app(ContactMessageReceived::class)->send(new ContactMessage('Ada Person', 'ada@example.com', 'A question about the pricing.', SiteLanguage::Greek));

    expect(array_map(fn (PlatformMessage $message): array => [$message->kind, $message->html, preg_match('/[^\x00-\x7F]/', $message->subject.$message->body)], $this->seam->sent))->toBe([
        [PlatformMessageKind::OperatorSignIn, null, 0],
        [PlatformMessageKind::ContactMessage, null, 0],
    ])
        ->and($this->seam->sent[0]->subject)->toBe(sprintf(OperatorSignedIn::SUBJECT, siteName()))
        ->and($this->seam->sent[1]->subject)->toBe(sprintf(ContactMessageReceived::SUBJECT, siteName()));
});

/**
 * The two lines the links' mails fill from the code's constants, each sent once in Greek and then
 * once in English: the set-password link to the owner who chose Greek and to the one who chose
 * English, the verification link from a Greek row and then from an English one, as it reads the
 * row's language.
 *
 * @return array<string, array{string, int, Closure(): void}>
 */
function mailLanguageWindows(): array
{
    $passwordLink = fn (bool $first): Closure => function () use ($first): void {
        $tenant = mailLanguageTenant(SiteLanguage::English, null);

        foreach (['greek@luna.example', 'english@luna.example'] as $email) {
            app(SetPasswordLink::class)->send($tenant, User::query()->where('email', $email)->sole(), str_repeat('a', 64), $first);
        }
    };
    $verification = function (): void {
        foreach ([SiteLanguage::Greek, SiteLanguage::English] as $language) {
            app(VerificationLink::class)->send(mailLanguageTenant($language, null), 'greek@luna.example', 'name-token', str_repeat('b', 64));
        }
    };

    return [
        'a reset link\'s window, by its hours' => ['mail.set_password.window', PasswordLinks::hours(), $passwordLink(false)],
        'a first password\'s link window, by its hours' => ['mail.set_password.window_first', PasswordLinks::hours(), $passwordLink(true)],
        'the verification link\'s window, by its hours' => ['mail.verification.window', Lifetime::hours(SignupVerification::LINK_LIFETIME), $verification],
        'the trial\'s length the verification link names, by its days' => ['mail.verification.trial', TenantProvisioner::trialDays(), $verification],
    ];
}

it('fills {0} from the code\'s constant in both languages, a choice by its count with no figure spelled in mail.php, and sends it so in each', function (string $key, int $count, Closure $send) {
    foreach (SiteLanguage::cases() as $language) {
        $forms = array_map(fn (string $form): string => (string) preg_replace('/^\s*[{[][^}\]]*[}\]]\s*/', '', $form), explode('|', __($key, [], $language->value)));

        expect($forms)->toHaveCount(2, $language->value)
            ->and(implode('|', $forms))->not->toMatch('/[0-9]/')
            ->and($forms[1])->toContain(':count')
            ->and(mailChoice($key, $count, $language))->toContain((string) $count)->not->toContain(':count');
    }

    $send();

    expect($this->seam->sent)->toHaveCount(2);

    foreach ([SiteLanguage::Greek, SiteLanguage::English] as $at => $language) {
        $message = $this->seam->sent[$at];

        expect((string) $message->html)->toContain('<html lang="'.$language->value.'">')
            ->and($message->body)->toContain("\n\n".mailChoice($key, $count, $language)."\n\n")
            ->and((string) $message->html)->toContain(e(mailChoice($key, $count, $language)));
    }
})->with(fn () => mailLanguageWindows());

it('holds the trial to whole days, so the days the verification mail names are the trial\'s whole length and never a floor of it', function () {
    expect(Lifetime::hours(TenantProvisioner::TRIAL_LENGTH) % 24)->toBe(0)
        ->and(TenantProvisioner::trialDays() * 24)->toBe(Lifetime::hours(TenantProvisioner::TRIAL_LENGTH));
});
