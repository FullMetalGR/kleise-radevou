<?php

use App\Application\Clock;
use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use App\Billing\BillingProviderFailed;
use App\Billing\NoticeKind;
use App\Http\AdminSession;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Middleware\RequiresActiveSubscription;
use App\Infrastructure\Db\SqlText;
use App\Models\User;
use App\Notifications\BrandedMails;
use App\Platform\AccountClose;
use App\Platform\AccountOwners;
use App\Platform\Closer;
use App\Platform\CloseRefused;
use App\Platform\ClosingWindow;
use App\Platform\LockedAccount;
use App\Site\SiteLanguage;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeBillingProvider;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;
use Tests\Support\RecordingPlatformMessages;
use Tests\Support\SeamLog;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * Closing the account from the Settings Danger zone, through POST /admin/settings/close: the
 * lapse's shape written for one tenant under the row's lock, a closing row refused at 409 with
 * nothing written, the route under manageSettings and outside the subscription gate; the one
 * writer the console's close shares, driven in the console's shape; the provider subscription
 * cancelled once where one is live and never otherwise, a refusal or a landing that throws logged
 * with the close standing; the closing email's own-close variant once per owner in the tenant's
 * language naming the day in the tenant's clock; the freeze by the status, a settings save refused
 * with its sentence while a hold and its confirm still land; the way back through the Billing
 * screen's checkout and its completion; and the two screens' props, the Billing notice and the
 * Danger zone's day, way back and closing sentence.
 *
 * The demo's luna is a free tenant, the console's shape (no subscriptions row, no trial end), so
 * a case needing a subscription writes one. The clock is pinned 26 hours before the fixture's
 * start, 2026-11-02 08:00 in Athens and 06:00 in UTC, so the window ends on Wednesday 2 December
 * 2026 at 08:00 in Athens; a case needing the tenant's zone to name another day than UTC's binds
 * its own. A hold and its confirm enter the transaction runner, so this file truncates.
 */

beforeEach(function () {
    $this->withoutVite();
    dropDemoSchemas();
    seedDemo();
    FixtureDay::pinClock();
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('subscriptions')->delete();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/** The day the window ends against the pinned clock, in Athens. */
const CLOSE_DAY = 'Wednesday 2 December 2026';

function closeOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

/**
 * luna's owner reading the admin in the language given, so a case run over both languages reads
 * each closing line as that owner does.
 */
function closeReader(SiteLanguage $language): User
{
    closeOwner()->forceFill(['locale' => $language->value])->save();

    return closeOwner();
}

/**
 * The day a window opened at the instant given ends on, in Athens, spelled by ICU in the language
 * given, as ClosingWindow::dayOf spells it for the reader: CLOSE_DAY in English against the pinned
 * clock, so a case reads its month and weekday from the clock it pinned, never typed.
 */
function closeDayOf(string $closingAtUtc, SiteLanguage $language): string
{
    return ClosingWindow::dayOf(new DateTimeImmutable($closingAtUtc, new DateTimeZone('UTC')), new DateTimeZone('Europe/Athens'), $language);
}

/**
 * A line of the closing's or the close's, read through the translator in the language given, and
 * for Greek asserted unlike its English, so a Greek case cannot pass on the fallback.
 *
 * @param  array<string, string>  $replace
 */
function closeLine(string $key, array $replace, SiteLanguage $language): string
{
    $line = adminLine($key, $replace, $language);

    if ($language === SiteLanguage::Greek) {
        expect($line)->not->toBe(adminLine($key, $replace), $key);
    }

    return $line;
}

dataset('close readers', [
    'the owner at en' => [SiteLanguage::English],
    'the owner at el' => [SiteLanguage::Greek],
]);

function closeUrl(): string
{
    return tenantUrl('luna', '/admin/settings/close');
}

/**
 * The owner's press, as the dialog's router posts it.
 *
 * @return TestResponse<Response>
 */
function closePress(TestCase $test, ?User $by = null): TestResponse
{
    return $test->actingAs($by ?? closeOwner())->from(tenantUrl('luna', '/admin/settings'))->post(closeUrl());
}

/**
 * The status and the closing of luna's row, closing_at spelled in UTC to the minute.
 *
 * @return array<mixed>
 */
function closeColumns(): array
{
    return (array) DB::selectOne("select status, to_char(closing_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as closing_at from tenants where slug = 'luna'");
}

/**
 * luna subscribed at the provider on the staff plan, its subscriptions row naming sub_luna at the
 * provider's status given, the tenant row in the state given as the sync would have left it, and
 * the fake answering the same subscription.
 */
function closeSubscribed(string $state = 'active', string $status = 'active'): void
{
    DB::table('subscriptions')->insert([
        'tenant_id' => LunaFixture::tenant()->id,
        'provider_customer_id' => 'cus_luna',
        'provider_subscription_id' => 'sub_luna',
        'status' => $status,
        'items' => json_encode([['price' => 'price_staff_test', 'quantity' => 1]], JSON_THROW_ON_ERROR),
    ]);
    billingSet(['plan_ref' => 'price_staff_test', 'entitlements' => '{staff}', 'subscription_state' => $state, 'grace_ends_at' => $state === 'grace' ? '2026-11-05 09:00:00+00' : null]);
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', $status, ['price_staff_test']));
}

/**
 * The questions the fake was asked to cancel, by subscription.
 *
 * @return list<mixed>
 */
function closeCancels(): array
{
    return array_values(array_map(fn (array $question): mixed => $question[1]['subscription'] ?? null, array_filter(billingFake()->asked, fn (array $question): bool => $question[0] === 'cancel')));
}

function closeSeam(): RecordingPlatformMessages
{
    $seam = new RecordingPlatformMessages;
    app()->instance(PlatformMessenger::class, $seam);

    return $seam;
}

function closeLogger(): SeamLog
{
    $log = new SeamLog;
    app()->instance(LoggerInterface::class, $log);

    return $log;
}

/**
 * Each message the seam was handed, as its address and kind.
 *
 * @return list<string>
 */
function closeSent(RecordingPlatformMessages $seam): array
{
    return array_map(fn (PlatformMessage $message): string => $message->toAddress.' '.$message->kind->value, $seam->sent);
}

/**
 * An instant as the platform's mails spell it in a language, in Athens.
 */
function closeInstant(string $utc, SiteLanguage $language = SiteLanguage::English): string
{
    return app(BrandedMails::class)->in($language)->instant(new DateTimeImmutable($utc, new DateTimeZone('UTC')), new DateTimeZone('Europe/Athens'));
}

it('closes an active account under the row\'s lock in one write, closing_at the clock\'s now and the status closing, lands on Billing with the day in the flash in the reader\'s language, and writes no audit row', function (SiteLanguage $language) {
    closeSeam();
    $trail = DB::table('tenant_luna.audit_log')->count();

    expect(closeColumns())->toBe(['status' => 'active', 'closing_at' => null])
        ->and(closeDayOf('2026-11-02 06:00', SiteLanguage::English))->toBe(CLOSE_DAY);

    closePress($this, closeReader($language))
        ->assertRedirect(tenantUrl('luna', '/admin/billing'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => closeLine(ClosingWindow::CLOSED, ['day' => closeDayOf('2026-11-02 06:00', $language)], $language)]);

    expect(closeColumns())->toBe(['status' => 'closing', 'closing_at' => '2026-11-02 06:00'])
        ->and(DB::table('platform_audit_log')->count())->toBe(0)
        ->and(DB::table('tenant_luna.audit_log')->count())->toBe($trail);
})->with('close readers');

/**
 * A closing sentence as the server joins it, read through the translator: its first half with
 * the day, then the way back, a plan or, for a free tenant, support.
 */
function closeSentence(string $first, string $day, ?string $support = null, SiteLanguage $language = SiteLanguage::English): string
{
    $wayBack = $support === null ? closeLine(ClosingWindow::WAY_BACK, [], $language) : closeLine(ClosingWindow::WAY_BACK_FREE, ['address' => $support], $language);

    return closeLine($first, ['day' => $day], $language).' '.$wayBack;
}

/**
 * The retention window as the Danger zone's prop carries it: its days and their spelling by ICU in
 * the language its line is read in, the reader's.
 *
 * @return array{count: int, spelled: string}
 */
function closeWindow(SiteLanguage $language = SiteLanguage::English): array
{
    return ['count' => 30, 'spelled' => (string) (new NumberFormatter($language->value, NumberFormatter::SPELLOUT))->format(30)];
}

/** The platform's support address, the free tenant's way back. */
function closeSupport(): string
{
    $address = config('site.contact_address');

    return is_string($address) ? $address : throw new LogicException('No support address is configured.');
}

it('refuses an account already closing with the 409 envelope and its sentence in the reader\'s language, writing nothing and sending nothing, whoever closed it', function (string $closingAt, ?string $trialEndedAt, SiteLanguage $language) {
    $seam = closeSeam();
    billingSet(['status' => 'closing', 'closing_at' => $closingAt, ...($trialEndedAt === null ? [] : ['subscription_state' => 'canceled', 'trial_ends_at' => $trialEndedAt])]);
    $before = closeColumns();

    $this->actingAs(closeReader($language))->postJson(closeUrl())
        ->assertStatus(409)
        ->assertExactJson(['code' => 'already_closing', 'message' => 'already_closing', 'data' => ['status' => 409, 'detail' => closeLine(CloseRefused::ALREADY_CLOSING, [], $language)]]);

    expect(closeColumns())->toBe($before)
        ->and($seam->sent)->toBe([])
        ->and(billingFake()->asked)->toBe([]);
})->with([
    'closed by its owner' => ['2026-10-20 09:00:00+00', null],
    'closed by the platform after an unpaid trial' => ['2026-10-20 09:00:00+00', '2026-09-20 09:00:00+00'],
])->with('close readers');

it('refuses a row the operator suspended since the page was read, met under the lock, as not_closable at 409 with its sentence in the reader\'s language, writing nothing', function (SiteLanguage $language) {
    $reader = closeReader($language);
    $seam = closeSeam();
    $suspended = false;

    // A suspended host answers the resolver's static page, so the refusal answers a race alone:
    // the press resolves luna while it is active, and the console suspends it before the close
    // takes the row's lock.
    Tenant::retrieved(function (Tenant $tenant) use (&$suspended): void {
        if (! $suspended && $tenant->slug === 'luna') {
            $suspended = true;
            billingSet(['status' => 'suspended', 'suspended_at' => '2026-11-02 05:00:00+00', 'suspension_reason' => 'Asked to pause']);
        }
    });

    $this->actingAs($reader)->postJson(closeUrl())
        ->assertStatus(409)
        ->assertExactJson(['code' => 'not_closable', 'message' => 'not_closable', 'data' => ['status' => 409, 'detail' => closeLine(CloseRefused::NOT_OPEN, [], $language)]]);

    expect($suspended)->toBeTrue()
        ->and(closeColumns())->toBe(['status' => 'suspended', 'closing_at' => null])
        ->and($seam->sent)->toBe([])
        ->and(billingFake()->asked)->toBe([]);
})->with('close readers');

it('closes a suspended row in the console\'s shape through the one writer: closing_at set and the suspension cleared in one write, the closer\'s record in that transaction, the cancel landed, then every owner told with the reason', function () {
    $seam = closeSeam();
    closeSubscribed();
    addTenantUser('second@luna.example', 'owner');
    billingSet(['status' => 'suspended', 'suspended_at' => '2026-11-02 05:00:00+00', 'suspension_reason' => 'Asked to pause']);
    $operator = addOperator();
    $reason = 'The owner asked us by telephone to close the account.';

    // Each write of luna's row that moves its status, its closing or its suspension records what
    // it moved and the transaction it ran in. The function lives in luna's schema, so the schema's
    // drop after the case takes the trigger with it.
    DB::unprepared(<<<'SQL'
        create table tenant_luna.close_probe (seq bigserial primary key, moved text not null, txid bigint not null);
        create function tenant_luna.close_probe() returns trigger language plpgsql as $$
        begin
            insert into tenant_luna.close_probe (moved, txid) values (
                old.status || ' to ' || new.status
                    || case when new.closing_at is null then ', no closing' else ', closing at ' || to_char(new.closing_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI') end
                    || case when new.suspended_at is null and new.suspension_reason is null then ', no suspension' else ', suspended' end,
                txid_current()
            );
            return null;
        end
        $$;
        create trigger close_probe after update on tenants for each row
            when (old.status is distinct from new.status or old.closing_at is distinct from new.closing_at
                or old.suspended_at is distinct from new.suspended_at or old.suspension_reason is distinct from new.suspension_reason)
            execute function tenant_luna.close_probe();
        SQL);

    // The console's closer, standing in: the operator closes an active or a suspended row with
    // a reason, writes its record where tenant_closed is to be written and tells each owner the
    // reason; it notes what it saw when it was asked.
    $closer = new class($operator->id, $reason) implements Closer
    {
        /** @var array<string, mixed> */
        public array $recorded = [];

        /** @var array<string, mixed> */
        public array $told = [];

        public function __construct(private int $operator, private string $reason) {}

        public function refusal(LockedAccount $account): ?CloseRefused
        {
            return $account->status === TenantStatus::Active || $account->status === TenantStatus::Suspended ? null : CloseRefused::notOpen();
        }

        public function record(Connection $db, Tenant $tenant): void
        {
            $this->recorded = ['operator' => $this->operator, 'tenant' => $tenant->id, 'reason' => $this->reason, 'txid' => $db->scalar('select txid_current()::text')];
        }

        public function tell(AccountOwners $account, DateTimeImmutable $closedAt, bool $free): void
        {
            $this->told = ['at' => $closedAt->format(DATE_ATOM), 'free' => $free, 'state' => DB::table('tenants')->where('id', $account->id)->value('subscription_state')];

            foreach ($account->owners as $owner) {
                app(PlatformMessenger::class)->send(PlatformMessageKind::Closing, $account->id, fn (): PlatformMessage => new PlatformMessage(PlatformMessageKind::Closing, $account->id, $owner['address'], $owner['name'], 'Your account closes in 30 days', $this->reason));
            }
        }
    };

    $closedAt = app(AccountClose::class)->close(LunaFixture::tenant(), $closer);
    $probed = array_map(fn (mixed $row): array => (array) $row, DB::select('select moved, txid::text as txid from tenant_luna.close_probe order by seq'));

    expect($closedAt->format(DATE_ATOM))->toBe('2026-11-02T06:00:00+00:00')
        ->and(closeColumns())->toBe(['status' => 'closing', 'closing_at' => '2026-11-02 06:00'])
        ->and((array) DB::selectOne("select suspended_at, suspension_reason from tenants where slug = 'luna'"))->toBe(['suspended_at' => null, 'suspension_reason' => null])
        ->and(array_column($probed, 'moved'))->toBe(['suspended to closing, closing at 2026-11-02 06:00, no suspension'])
        ->and($closer->recorded)->toBe(['operator' => $operator->id, 'tenant' => LunaFixture::tenant()->id, 'reason' => $reason, 'txid' => $probed[0]['txid'] ?? null])
        ->and(closeCancels())->toBe(['sub_luna'])
        ->and(billingColumns()['subscription_state'])->toBe('canceled')
        ->and(subscriptionColumns()['status'])->toBe('canceled')
        ->and($closer->told)->toBe(['at' => '2026-11-02T06:00:00+00:00', 'free' => false, 'state' => 'canceled'])
        ->and(closeSent($seam))->toBe(['owner@luna.example closing', 'second@luna.example closing'])
        ->and(array_map(fn (PlatformMessage $message): string => $message->body, $seam->sent))->toBe([$reason, $reason]);
});

it('closes under manageSettings alone: a staff member is refused, a signed-out caller is sent to sign in, and a manager closes and is sent back to Settings, holding no manageBilling', function () {
    closeSeam();
    $staff = User::query()->findOrFail(addTenantUser('staff@luna.example', 'staff'));
    $manager = User::query()->findOrFail(addTenantUser('manager@luna.example', 'manager'));

    $this->post(closeUrl())->assertRedirect(tenantUrl('luna', '/admin/login'));
    closePress($this, $staff)->assertForbidden();

    expect(closeColumns())->toBe(['status' => 'active', 'closing_at' => null]);

    closePress($this, $manager)
        ->assertRedirect(tenantUrl('luna', '/admin/settings'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(ClosingWindow::CLOSED, ['day' => CLOSE_DAY])]);

    expect(closeColumns())->toBe(['status' => 'closing', 'closing_at' => '2026-11-02 06:00']);
});

it('refuses the close to a support session with a 403, writing nothing and sending nothing, since an operator closes an account from the console alone, where the close takes a reason and leaves an audit row', function () {
    $seam = closeSeam();
    $now = app(Clock::class)->now();
    $session = DB::table('support_sessions')->insertGetId([
        'operator_id' => addOperator()->id,
        'tenant_id' => LunaFixture::tenant()->id,
        'opened_at' => SqlText::instant($now),
        'expires_at' => SqlText::instant($now->add(new DateInterval('PT60M'))),
        'created_at' => SqlText::instant($now),
    ]);

    $this->withSession([AdminSession::SUPPORT_KEY => $session])
        ->from(tenantUrl('luna', '/admin/settings'))
        ->post(closeUrl())
        ->assertForbidden();

    expect(closeColumns())->toBe(['status' => 'active', 'closing_at' => null])
        ->and(closeSent($seam))->toBe([]);

    DB::table('support_sessions')->delete();
});

it('closes outside the subscription gate: a canceled owner closes, the ended subscription cancelled nothing', function () {
    closeSeam();
    closeSubscribed('canceled', 'canceled');

    closePress($this)->assertRedirect(tenantUrl('luna', '/admin/billing'));

    expect(closeColumns())->toBe(['status' => 'closing', 'closing_at' => '2026-11-02 06:00'])
        ->and(billingFake()->asked)->toBe([]);
});

it('cancels a live subscription at the provider once after the commit and lands its answer, the row canceled and still closing, whatever state the provider is still collecting in', function (string $state, string $status) {
    closeSeam();
    closeSubscribed($state, $status);

    closePress($this)->assertRedirect(tenantUrl('luna', '/admin/billing'));

    expect(closeCancels())->toBe(['sub_luna'])
        ->and(billingFake()->asked)->toHaveCount(1)
        ->and(closeColumns())->toBe(['status' => 'closing', 'closing_at' => '2026-11-02 06:00'])
        ->and(billingColumns())->toMatchArray(['subscription_state' => 'canceled', 'grace_ends_at' => null])
        ->and(subscriptionColumns()['status'])->toBe('canceled');
})->with([
    'active' => ['active', 'active'],
    'in grace' => ['grace', 'past_due'],
    'past due' => ['past_due', 'past_due'],
    'its first payment pending' => ['incomplete', 'incomplete'],
]);

it('cancels nothing where no provider subscription is live: the free shape, a trial whose checkout names none, and an ended one', function (Closure $shape) {
    closeSeam();
    $shape();

    closePress($this)->assertRedirect(tenantUrl('luna', '/admin/billing'));

    expect(billingFake()->asked)->toBe([])
        ->and(closeColumns())->toBe(['status' => 'closing', 'closing_at' => '2026-11-02 06:00']);
})->with([
    'free, no subscriptions row' => [fn () => null],
    'a trial with a checkout opened and no subscription' => [function (): void {
        syncWith()->attachCustomer(LunaFixture::tenant(), openedCheckout('cus_luna'));
        billingSet(['subscription_state' => 'trial', 'trial_ends_at' => '2026-11-05 09:00:00+00']);
    }],
    'an ended subscription' => [fn () => closeSubscribed('canceled', 'canceled')],
]);

it('logs a provider refusal once at error and lets the close stand, the state left as it was and the owners still mailed', function () {
    $seam = closeSeam();
    $log = closeLogger();
    closeSubscribed();
    billingFake()->cancelFails = BillingProviderFailed::refused('cancel a subscription', 500, 'api_error', 'Something went wrong');

    closePress($this)->assertRedirect(tenantUrl('luna', '/admin/billing'));

    $errors = array_values(array_filter($log->records, fn (array $record): bool => $record['level'] === 'error'));

    expect(closeCancels())->toBe(['sub_luna'])
        ->and($errors)->toHaveCount(1)
        ->and($errors[0]['message'])->toBe(AccountClose::CANCEL_LOGGED)
        ->and($errors[0]['context'])->toBe(['tenant' => 'luna', 'subscription' => 'sub_luna', 'message' => 'The billing provider refused to cancel a subscription with status 500, code api_error: Something went wrong'])
        ->and(closeColumns())->toBe(['status' => 'closing', 'closing_at' => '2026-11-02 06:00'])
        ->and(billingColumns()['subscription_state'])->toBe('active')
        ->and(closeSent($seam))->toBe(['owner@luna.example closing']);
});

it('lets the close stand when the landing of the provider\'s answer throws after the cancel answered: the row still closing, the failure logged once at error by its class and SQLSTATE alone, the owners still mailed, and the press answered as a landed close', function () {
    $seam = closeSeam();
    $log = closeLogger();
    closeSubscribed();

    // The database refuses the sync's write of the subscriptions row, so its locked transaction
    // throws once the fake has answered the cancel. The function lives in luna's schema, so the
    // schema's drop after the case takes the trigger with it.
    DB::unprepared(<<<'SQL'
        create function tenant_luna.refuse_landing() returns trigger language plpgsql as $$
        begin
            raise exception 'the landing is refused';
        end
        $$;
        create trigger refuse_landing before update on subscriptions for each row execute function tenant_luna.refuse_landing();
        SQL);

    closePress($this)
        ->assertRedirect(tenantUrl('luna', '/admin/billing'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(ClosingWindow::CLOSED, ['day' => CLOSE_DAY])]);

    $errors = array_values(array_filter($log->records, fn (array $record): bool => $record['level'] === 'error'));

    expect(billingFake()->asked)->toBe([['cancel', ['subscription' => 'sub_luna']]])
        ->and($errors)->toHaveCount(1)
        ->and($errors[0]['message'])->toBe(AccountClose::LANDING_LOGGED)
        ->and($errors[0]['context'])->toBe(['tenant' => 'luna', 'subscription' => 'sub_luna', 'exception' => QueryException::class, 'sqlstate' => 'P0001'])
        ->and(closeColumns())->toBe(['status' => 'closing', 'closing_at' => '2026-11-02 06:00'])
        ->and(billingColumns()['subscription_state'])->toBe('active')
        ->and(subscriptionColumns()['status'])->toBe('active')
        ->and(closeSent($seam))->toBe(['owner@luna.example closing']);
});

it('mails every owner once, and nobody else, the own close\'s variant naming when it was set, that nothing more is charged, the window\'s end in the tenant\'s clock with the zone, what stays and the Billing screen as the way back', function () {
    $seam = closeSeam();
    closeSubscribed();
    addTenantUser('second@luna.example', 'owner');
    addTenantUser('manager@luna.example', 'manager');
    addTenantUser('staff@luna.example', 'staff');

    closePress($this);

    expect(closeSent($seam))->toBe(['owner@luna.example closing', 'second@luna.example closing']);
    $message = $seam->sent[0];

    expect($message->kind)->toBe(PlatformMessageKind::Closing)
        ->and($message->tenantId)->toBe(LunaFixture::tenant()->id)
        ->and($message->toName)->toBe('Demo Owner')
        ->and($message->replyTo)->toBeNull()
        ->and($message->subject)->toBe('Your account closes in 30 days')
        ->and($message->body)->toBe(implode("\n", [
            'Hello Demo Owner,',
            '',
            'The account for Luna Hair Studio was set to close from its settings on Monday 2 November 2026 at 08:00, so it is now closing and nothing more will be charged for it.',
            '',
            'We keep it for 30 days, until Wednesday 2 December 2026 at 08:00.',
            'Until then your bookings keep running and your booking page stays open, and changes to your setup stay paused. After that, the data in your account is deleted.',
            '',
            'To keep your account, choose a plan on the Billing screen before then:',
            '',
            tenantUrl('luna', '/admin/billing'),
            '',
            'Times are in Europe/Athens, the clock you keep.',
        ]))
        ->and($message->html)->toContain('<html lang="en">')
        ->and($message->html)->toContain('<a href="'.tenantUrl('luna', '/admin/billing').'"')
        ->and($message->html)->toContain('was set to close from its settings on Monday 2 November 2026 at 08:00')
        ->and($message->html)->toContain(siteSentence('mail.closing.reason', SiteLanguage::English, ['business' => 'Luna Hair Studio']));
});

it('points a free tenant\'s owners to support for the way back, its Billing screen offering no plan to choose', function () {
    $seam = closeSeam();

    closePress($this);

    expect($seam->sent)->toHaveCount(1)
        ->and($seam->sent[0]->body)->toContain("\n\nTo keep your account, write to us at ".closeSupport()." before then.\n\nTimes are in Europe/Athens")
        ->and($seam->sent[0]->body)->not->toContain('/admin/billing')
        ->and($seam->sent[0]->body)->not->toContain('choose a plan');
});

it('mails a Greek row\'s owners in Greek, the own close\'s lines from lang/el and both instants spelled in Greek in the tenant\'s clock', function () {
    $seam = closeSeam();
    closeSubscribed();
    billingSet(['locale' => 'el']);

    closePress($this);

    $message = $seam->sent[0];

    expect($seam->sent)->toHaveCount(1)
        ->and($message->subject)->toBe(mailChoice('mail.closing.subject', ClosingWindow::days(), SiteLanguage::Greek))
        ->and($message->body)->toContain(siteSentence('mail.closing.set', SiteLanguage::Greek, ['business' => 'Luna Hair Studio', 'at' => closeInstant('2026-11-02 06:00', SiteLanguage::Greek)]))
        ->and($message->body)->toContain(pageChoice('mail.closing.kept', ClosingWindow::days(), ['until' => closeInstant('2026-12-02 06:00', SiteLanguage::Greek)], SiteLanguage::Greek))
        ->and($message->body)->toContain(siteSentence('mail.closing.billing', SiteLanguage::Greek))
        ->and($message->html)->toContain('<html lang="el">');
});

it('names the day in the tenant\'s clock, half an hour past midnight in Athens while UTC still reads the day before, in the flash and in the mail', function () {
    app()->instance(Clock::class, FixedClock::at('2026-11-02 22:30'));
    $seam = closeSeam();

    closePress($this)->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(ClosingWindow::CLOSED, ['day' => 'Thursday 3 December 2026'])]);

    expect(closeColumns()['closing_at'])->toBe('2026-11-02 22:30')
        ->and($seam->sent[0]->body)->toContain('until '.closeInstant('2026-12-02 22:30').'.')
        ->and(closeInstant('2026-12-02 22:30'))->toBe('Thursday 3 December 2026 at 00:30');
});

it('freezes configuration writes by the status alone once closed, the subscription still active, with the closing sentence naming the day and the way back in the reader\'s language, while a hold and its confirm still land', function (SiteLanguage $language) {
    closeSeam();
    closeSubscribed();
    billingFake()->cancelFails = BillingProviderFailed::unreachable('cancel a subscription', 'Could not resolve host');
    closeLogger();

    closePress($this, closeReader($language));

    expect(billingColumns()['subscription_state'])->toBe('active');

    $this->actingAs(closeOwner())->putJson(tenantUrl('luna', '/admin/settings'), [])
        ->assertForbidden()
        ->assertExactJson(['code' => RequiresActiveSubscription::CLOSING, 'message' => RequiresActiveSubscription::CLOSING, 'data' => [
            'status' => 403,
            'detail' => closeSentence(ClosingWindow::FROZEN, closeDayOf('2026-11-02 06:00', $language), null, $language),
            'closes_at' => '2026-12-02T06:00:00+00:00',
        ]]);

    $held = $this->postJson(tenantUrl('luna', '/api/v1/holds'), [
        'customer_name' => 'Casey Customer',
        'customer_email' => 'casey@example.com',
        'customer_phone' => '+30 210 0000000',
        'items' => [['service_id' => LunaFixture::id('services', 'Haircut'), 'staff_id' => LunaFixture::id('staff', 'Elena Papadaki')]],
        'start' => FixtureDay::start()->format(DateTimeImmutable::RFC3339),
    ])->assertCreated()->json();

    assert(is_array($held) && is_string($held['uuid'] ?? null) && is_string($held['manage_token'] ?? null));

    $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$held['uuid'].'/confirm'), ['token' => $held['manage_token']])->assertOk();

    expect(DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->value('status'))->toBe('confirmed');
})->with('close readers');

it('words a free tenant\'s freeze with support as the way back, in the reader\'s language', function (SiteLanguage $language) {
    closeSeam();
    closePress($this, closeReader($language));

    $this->actingAs(closeOwner())->postJson(tenantUrl('luna', '/admin/blackouts'), ['date' => '2026-12-25'])
        ->assertForbidden()
        ->assertJsonPath('code', RequiresActiveSubscription::CLOSING)
        ->assertJsonPath('data.detail', closeSentence(ClosingWindow::FROZEN, closeDayOf('2026-11-02 06:00', $language), closeSupport(), $language));
})->with('close readers');

it('takes the account back through the Billing screen\'s checkout completed inside the window: closing_at cleared, active, and configuration writes admitted again', function () {
    closeSeam();
    closeSubscribed();
    $screen = tenantUrl('luna', '/admin/billing');

    closePress($this);

    expect(billingColumns()['subscription_state'])->toBe('canceled');

    $this->actingAs(closeOwner())->post(tenantUrl('luna', '/admin/billing/checkout'), ['plan' => 'staff'], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', FakeBillingProvider::checkoutUrl('cus_luna', 'staff', $screen));
    billingFake()->script(billingSnapshot('sub_back', 'cus_luna', 'active', ['price_staff_test']));
    deliverBillingNotice($this, FakeBillingProvider::notice('evt_back', NoticeKind::CheckoutCompleted, 'cus_luna', 'sub_back'))->assertOk();

    expect(closeColumns())->toBe(['status' => 'active', 'closing_at' => null])
        ->and(billingColumns()['subscription_state'])->toBe('active')
        ->and(subscriptionColumns()['provider_subscription_id'])->toBe('sub_back');

    $this->actingAs(closeOwner())->from($screen)->post(tenantUrl('luna', '/admin/blackouts'), ['date' => '2026-12-25'])->assertRedirect($screen);

    expect(DB::table('tenant_luna.blackouts')->where('date', '2026-12-25')->exists())->toBeTrue();
});

it('shows the Billing screen\'s closing notice in place of the state\'s, the day, the deletion and the way back with the plan choice beside it, and support for a free tenant, in the reader\'s language', function (SiteLanguage $language) {
    closeSeam();
    closeSubscribed();
    closePress($this, closeReader($language));
    $day = closeDayOf('2026-11-02 06:00', $language);

    $page = $this->actingAs(closeOwner())->get(tenantUrl('luna', '/admin/billing'))->assertOk();
    $page->assertInertia(fn (Assert $page) => $page
        ->where('billing.notice', ['kind' => 'warning', 'sentence' => closeSentence(ClosingWindow::CLOSES, $day, null, $language), 'paused' => closeLine(BillingController::PAUSED, [], $language)])
        ->where('billing.resubscribe.label', closeLine(BillingController::SUBSCRIBE_AGAIN, [], $language)));

    expect((string) $page->getContent())->toContain('<html lang="'.$language->value.'">');

    DB::table('subscriptions')->delete();
    billingSet(['subscription_state' => 'active', 'plan_ref' => null]);

    $this->actingAs(closeOwner())->get(tenantUrl('luna', '/admin/billing'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('billing.notice.sentence', closeSentence(ClosingWindow::CLOSES, $day, closeSupport(), $language))
            ->where('billing.resubscribe', null));
})->with('close readers');

it('hands the Danger zone the day the account would close on now in the tenant\'s clock, the window\'s days spelled by ICU and the way back, and while closing the sentence that replaces the control, in the reader\'s language', function (SiteLanguage $language) {
    closeSeam();
    closeSubscribed();
    $settings = tenantUrl('luna', '/admin/settings');
    $day = closeDayOf('2026-11-02 06:00', $language);

    $page = $this->actingAs(closeReader($language))->get($settings)->assertOk();
    $page->assertInertia(fn (Assert $page) => $page->where('close', ['day' => $day, 'way_back' => closeLine(ClosingWindow::WAY_BACK, [], $language), 'notice' => null, 'window' => closeWindow($language)]));

    expect((string) $page->getContent())->toContain('<html lang="'.$language->value.'">');

    if ($language === SiteLanguage::Greek) {
        expect($day)->not->toBe(CLOSE_DAY)
            ->and(closeWindow($language))->not->toBe(closeWindow());
    }

    // Closed a fortnight before the pinned now: the dialog's day is still now's, the sentence the
    // closing's own.
    expect(closeDayOf('2026-10-20 09:00', SiteLanguage::English))->toBe('Thursday 19 November 2026');
    billingSet(['status' => 'closing', 'closing_at' => '2026-10-20 09:00:00+00']);

    $this->actingAs(closeOwner())->get($settings)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('close', [
            'day' => $day,
            'way_back' => closeLine(ClosingWindow::WAY_BACK, [], $language),
            'notice' => closeSentence(ClosingWindow::CLOSES, closeDayOf('2026-10-20 09:00', $language), null, $language),
            'window' => closeWindow($language),
        ]));
})->with('close readers');
