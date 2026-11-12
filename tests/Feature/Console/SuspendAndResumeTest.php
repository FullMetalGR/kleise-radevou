<?php

use App\Application\Clock;
use App\Application\Notification\PlatformMessenger;
use App\Http\ConsoleSession;
use App\Http\Controllers\Console\SuspensionController;
use App\Http\Requests\SuspendRequest;
use App\Models\User;
use App\Platform\Operator;
use App\Platform\Suspension;
use App\Site\SiteLanguage;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\Support\LevelledPlatformMessages;
use Tests\Support\LunaFixture;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * Suspend and resume: the operator pauses a business with a reason the owners read, and every route
 * of the suspended tenant answers the resolver's static page; resume reverses it. Each write takes
 * the tenant row for update and reads its status again, writes the status and the two columns with
 * its platform audit row in one transaction, and mails every owner user through the platform's seam
 * after the commit, its flash saying so, or, for a tenant with no owner user, saying nobody was
 * told. Neither touches billing.
 *
 * The resume's proof is that the probe answers again, and the probe enters the transaction runner,
 * which refuses a transaction this file would wrap each test in; so the file truncates instead and
 * seeds the demo afresh, luna with its owner, and drops the schemas after each test. The seam is a
 * recorder that notes the transaction level each message was handed over at, which is how "after
 * the commit" is read: nought, with no transaction open. The clock is pinned, so the instant the
 * suspend writes is the one spelled here.
 *
 * Both notices are branded mails in the row's language: their words are mail.php's in
 * tenants.locale in both parts, the suspension's sentence naming the public name where it once
 * named the codebase, and the Greek case lays Greek lines over the loaded lang/el/mail.php, as
 * TrialNoticeTest does, to tell the test's lines from the file's whatever the file holds.
 */

const SUSPENSION_REASON = 'Chargebacks under review with the card network.';

beforeEach(function () {
    $this->withoutVite();
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-10-14 09:00'));
    $this->seam = suspensionSeam();
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('platform_audit_log')->delete();
    DB::table('operators')->delete();
    DB::table('subscriptions')->delete();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * The platform's seam as a recorder of what it was handed and the transaction level it was handed
 * over at, bound for the test.
 */
function suspensionSeam(): LevelledPlatformMessages
{
    $seam = app(LevelledPlatformMessages::class);
    app()->instance(PlatformMessenger::class, $seam);

    return $seam;
}

/**
 * A POST to a console write on a tenant's detail, as the operator given sends it from the detail.
 *
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function suspensionPost(TestCase $test, Operator $operator, string $slug, string $action, array $body = []): TestResponse
{
    return $test->actingAs($operator, ConsoleSession::GUARD)
        ->withSession(freshConsoleSession())
        ->from(consoleUrl('/tenants/'.$slug))
        ->post(consoleUrl('/tenants/'.$slug.'/'.$action), $body);
}

/**
 * The tenant row's status and suspension, the instant in UTC to the second.
 *
 * @return array<mixed>
 */
function suspensionColumns(string $slug = 'luna'): array
{
    return (array) DB::selectOne(
        "select status, to_char(suspended_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as suspended_at, suspension_reason from tenants where slug = ?",
        [$slug],
    );
}

/**
 * Every platform audit row as its operator, action, tenant and payload, the payload decoded.
 *
 * @return list<array<mixed>>
 */
function suspensionAudit(): array
{
    $rows = [];

    foreach (DB::select('select operator_id, action, tenant_id, payload::text as payload from platform_audit_log order by id') as $row) {
        $row = (array) $row;
        $rows[] = [...$row, 'payload' => json_decode(is_string($row['payload'] ?? null) ? $row['payload'] : '', true, 512, JSON_THROW_ON_ERROR)];
    }

    return $rows;
}

/**
 * The billing columns of a tenant row and its subscriptions row, as they stood.
 *
 * @return array<mixed>
 */
function suspensionBilling(string $slug = 'luna'): array
{
    return [
        (array) DB::selectOne('select entitlements, narrowed, plan_ref, subscription_state, grace_ends_at::text as grace_ends_at, trial_ends_at::text as trial_ends_at, closing_at::text as closing_at from tenants where slug = ?', [$slug]),
        DB::table('subscriptions')->where('tenant_id', LunaFixture::tenant()->id)->get()->map(fn (object $row): array => (array) $row)->all(),
    ];
}

/**
 * luna's people beside its owner: a second owner, a manager and a member of staff.
 */
function suspensionPeople(): void
{
    addTenantUser('ada@luna.example', 'owner');
    addTenantUser('max@luna.example', 'manager');
    addTenantUser('sam@luna.example', 'staff');
}

it('suspends an active tenant: the status, the clock\'s now and the reason in one write with the audit row, and every owner mailed the reason after the commit, no manager or staff', function () {
    suspensionPeople();
    $operator = addOperator();

    suspensionPost($this, $operator, 'luna', 'suspend', ['reason' => SUSPENSION_REASON])
        ->assertRedirect(consoleUrl('/tenants/luna'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => SuspensionController::SUSPENDED]);

    expect(suspensionColumns())->toBe(['status' => 'suspended', 'suspended_at' => '2026-10-14 09:00:00', 'suspension_reason' => SUSPENSION_REASON])
        ->and(suspensionAudit())->toBe([
            ['operator_id' => $operator->id, 'action' => 'tenant_suspended', 'tenant_id' => LunaFixture::tenant()->id, 'payload' => ['reason' => SUSPENSION_REASON]],
        ])
        ->and($this->seam->lines())->toBe([
            'owner@luna.example suspension 0',
            'ada@luna.example suspension 0',
        ])
        ->and($this->seam->message(0)->subject)->toBe(siteSentence('mail.suspension.subject', SiteLanguage::English))
        ->and($this->seam->message(0)->tenantId)->toBe(LunaFixture::tenant()->id);
});

it('words the suspension\'s mail: the business, the public name, the instant in the tenant\'s clock with the zone named, the reason, what is paused and what keeps running, in mail.php\'s English, with the same words as HTML in the layout', function () {
    suspensionPost($this, addOperator(), 'luna', 'suspend', ['reason' => SUSPENSION_REASON])->assertRedirect();

    $message = $this->seam->message(0);
    $owner = User::query()->where('email', 'owner@luna.example')->sole();
    $paused = siteSentence('mail.suspension.paused', SiteLanguage::English, ['business' => 'Luna Hair Studio', 'end' => 'Wednesday 14 October 2026 at 12:00']);
    $whatPauses = 'Until the pause ends, your booking page and your admin show that booking is paused, and customers cannot book, cancel or reschedule online.';
    $whatKeeps = 'Messages about bookings already made, reminders among them, keep going to your customers, and we will email you when the pause ends.';

    expect($message->subject)->toBe('Your booking page is paused')
        ->and($message->replyTo)->toBeNull()
        ->and($message->body)->toBe(implode("\n", [
            sprintf('Hello %s,', $owner->name),
            '',
            $paused,
            '',
            'The reason for the pause:',
            '',
            SUSPENSION_REASON,
            '',
            $whatPauses,
            $whatKeeps,
            '',
            'Times are in Europe/Athens, the clock you keep.',
        ]))
        ->and($message->html)->toContain('<html lang="en">')
        ->and($message->html)->toContain('<title>Your booking page is paused</title>')
        ->and($paused)->toContain('Luna Hair Studio')->toContain(siteName())->toContain('Wednesday 14 October 2026 at 12:00')
        ->and($message->html)->toContain(e($paused))
        ->and($message->html)->toContain(e(SUSPENSION_REASON))
        ->and($message->html)->toContain($whatPauses)
        ->and($message->html)->toContain($whatKeeps)
        ->and($message->html)->toContain('Times are in Europe/Athens, the clock you keep.')
        ->and($message->html)->toContain(siteSentence('mail.suspension.reason', SiteLanguage::English, ['business' => 'Luna Hair Studio']))
        ->and($message->html)->toContain(siteSentence('mail.layout.sent_by', SiteLanguage::English))
        ->and($message->html)->not->toContain('<img');
});

it('tells a Greek row\'s owners in Greek: both notices\' words from lang/el through the translator in both parts, each instant spelled by the formatter in Greek in the tenant\'s clock, the documents in Greek', function () {
    DB::table('tenants')->where('slug', 'luna')->update(['locale' => 'el']);
    // Greek lines are laid over the loaded lang/el/mail.php to tell the test's lines from the
    // file's whatever the file holds; the dates themselves are the formatter's, each joined to its
    // time by the file's own words, and never spelled.
    __('mail.greeting', [], 'el');
    app('translator')->addLines([
        'mail.suspension.subject' => 'GREEK PAUSED',
        'mail.suspension.paused' => 'GREEK :business ON :name AT :end',
        'mail.suspension.zone' => 'GREEK ZONE :zone',
        'mail.resumption.subject' => 'GREEK OPEN AGAIN',
        'mail.resumption.ended' => 'GREEK :business RESUMED :end',
        'mail.resumption.zone' => 'GREEK CLOCK :zone',
    ], 'el');
    $formatter = new IntlDateFormatter('el', IntlDateFormatter::FULL, IntlDateFormatter::NONE, 'Europe/Athens', IntlDateFormatter::GREGORIAN, 'EEEE d MMMM y');
    $pausedAt = siteSentence('mail.time.instant', SiteLanguage::Greek, ['date' => (string) $formatter->format(new DateTimeImmutable('2026-10-14 09:00:00', new DateTimeZone('UTC'))), 'time' => '12:00']);
    $resumedAt = siteSentence('mail.time.instant', SiteLanguage::Greek, ['date' => (string) $formatter->format(new DateTimeImmutable('2026-10-15 16:30:00', new DateTimeZone('UTC'))), 'time' => '19:30']);
    $operator = addOperator();

    suspensionPost($this, $operator, 'luna', 'suspend', ['reason' => SUSPENSION_REASON])->assertRedirect();
    app()->instance(Clock::class, FixedClock::at('2026-10-15 16:30'));
    suspensionPost($this, $operator, 'luna', 'resume')->assertRedirect();

    $suspended = $this->seam->message(0);
    $resumed = $this->seam->message(1);

    expect($this->seam->lines())->toBe(['owner@luna.example suspension 0', 'owner@luna.example resumption 0'])
        ->and($suspended->subject)->toBe('GREEK PAUSED')
        ->and($suspended->body)->toContain("\n\nGREEK Luna Hair Studio ON ".siteName().' AT '.$pausedAt."\n\n")
        ->and($suspended->body)->toContain("\n\n".SUSPENSION_REASON."\n\n")
        ->and($suspended->body)->toEndWith("\n\nGREEK ZONE Europe/Athens")
        ->and($suspended->body)->not->toContain('Wednesday')
        ->and(preg_match('/[^\x00-\x7F]/', $suspended->body))->toBe(1)
        ->and($suspended->html)->toContain('<html lang="el">')
        ->and($suspended->html)->toContain('<title>GREEK PAUSED</title>')
        ->and($suspended->html)->toContain('GREEK Luna Hair Studio ON '.siteName().' AT '.$pausedAt)
        ->and($suspended->html)->toContain(siteSentence('mail.suspension.reason', SiteLanguage::Greek, ['business' => 'Luna Hair Studio']))
        ->and($resumed->subject)->toBe('GREEK OPEN AGAIN')
        ->and($resumed->body)->toContain("\n\nGREEK Luna Hair Studio RESUMED ".$resumedAt."\n\n".hostedPage('luna')."\n\n")
        ->and($resumed->body)->toEndWith("\n\nGREEK CLOCK Europe/Athens")
        ->and($resumed->body)->not->toContain('Thursday')
        ->and($resumed->html)->toContain('<html lang="el">')
        ->and($resumed->html)->toContain('<title>GREEK OPEN AGAIN</title>')
        ->and($resumed->html)->toContain('GREEK Luna Hair Studio RESUMED '.$resumedAt)
        ->and($resumed->html)->toContain('<a href="'.hostedPage('luna').'"')
        ->and($resumed->html)->toContain(siteSentence('mail.resumption.reason', SiteLanguage::Greek, ['business' => 'Luna Hair Studio']));
});

it('serves the paused page on every tenant route of a tenant the console suspended, the probe included', function () {
    suspensionPost($this, addOperator(), 'luna', 'suspend', ['reason' => SUSPENSION_REASON])->assertRedirect(consoleUrl('/tenants/luna'));

    pausedPageWalk($this, 'luna');
});

it('resumes a suspended tenant: active, both columns cleared, tenant_resumed recorded and the owners mailed after the commit, and the probe, the admin and the booking page answer again', function () {
    suspensionPeople();
    $operator = addOperator();
    suspensionPost($this, $operator, 'luna', 'suspend', ['reason' => SUSPENSION_REASON])->assertRedirect();
    $this->get(tenantUrl('luna', '/probe'))->assertStatus(503);
    app()->instance(Clock::class, FixedClock::at('2026-10-15 16:30'));

    suspensionPost($this, $operator, 'luna', 'resume')
        ->assertRedirect(consoleUrl('/tenants/luna'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => SuspensionController::RESUMED]);

    expect(suspensionColumns())->toBe(['status' => 'active', 'suspended_at' => null, 'suspension_reason' => null])
        ->and(array_map(fn (array $row): array => [$row['action'], $row['tenant_id'], $row['payload']], suspensionAudit()))->toBe([
            ['tenant_suspended', LunaFixture::tenant()->id, ['reason' => SUSPENSION_REASON]],
            ['tenant_resumed', LunaFixture::tenant()->id, []],
        ])
        ->and(array_slice($this->seam->lines(), 2))->toBe([
            'owner@luna.example resumption 0',
            'ada@luna.example resumption 0',
        ])
        ->and($this->seam->message(2)->subject)->toBe('Your booking page is open again')
        ->and($this->seam->message(2)->subject)->toBe(siteSentence('mail.resumption.subject', SiteLanguage::English))
        ->and($this->seam->message(2)->body)->toBe(implode("\n", [
            'Hello Demo Owner,',
            '',
            'The pause on Luna Hair Studio ended on Thursday 15 October 2026 at 19:30. Your booking page and your admin are open again, and customers can book as before:',
            '',
            hostedPage('luna'),
            '',
            'Times are in Europe/Athens, the clock you keep.',
        ]))
        ->and($this->seam->message(2)->html)->toContain('<html lang="en">')
        ->and($this->seam->message(2)->html)->toContain('<title>Your booking page is open again</title>')
        ->and($this->seam->message(2)->html)->toContain('<a href="'.hostedPage('luna').'"')
        ->and($this->seam->message(2)->html)->toContain('The pause on Luna Hair Studio ended on Thursday 15 October 2026 at 19:30.')
        ->and($this->seam->message(2)->html)->toContain('Times are in Europe/Athens, the clock you keep.')
        ->and($this->seam->message(2)->html)->toContain(siteSentence('mail.resumption.reason', SiteLanguage::English, ['business' => 'Luna Hair Studio']))
        ->and($this->seam->message(2)->html)->not->toContain('<img');

    $this->get(tenantUrl('luna', '/probe'))->assertOk()->assertExactJson(['slug' => 'luna', 'schema' => 'tenant_luna']);
    $this->get(hostedPage('luna'))->assertOk()->assertDontSee(pageSentence('pages.suspended.heading'));
    // On the admin's guard by name: acting as the operator made the console's the default, and the
    // admin reads the web guard alone.
    $this->actingAs(User::query()->where('email', 'owner@luna.example')->sole(), 'web')
        ->get(tenantUrl('luna', '/admin/services'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Services'));
});

it('suspends and resumes a tenant with no owner user with a flash that promises no mail, and mails nobody', function () {
    // An active row whose owners have all gone, the one way a tenant has no owner user here, since
    // a suspend refuses every row that is not active: the people left are a manager and a member
    // of staff, who are never told.
    DB::table('users')->where('email', 'owner@luna.example')->delete();
    addTenantUser('max@luna.example', 'manager');
    addTenantUser('sam@luna.example', 'staff');
    $operator = addOperator();

    suspensionPost($this, $operator, 'luna', 'suspend', ['reason' => SUSPENSION_REASON])
        ->assertRedirect(consoleUrl('/tenants/luna'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => SuspensionController::SUSPENDED_NO_OWNER]);

    expect(suspensionColumns())->toBe(['status' => 'suspended', 'suspended_at' => '2026-10-14 09:00:00', 'suspension_reason' => SUSPENSION_REASON])
        ->and($this->seam->lines())->toBe([]);

    suspensionPost($this, $operator, 'luna', 'resume')
        ->assertRedirect(consoleUrl('/tenants/luna'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => SuspensionController::RESUMED_NO_OWNER]);

    expect(suspensionColumns())->toBe(['status' => 'active', 'suspended_at' => null, 'suspension_reason' => null])
        ->and(array_column(suspensionAudit(), 'action'))->toBe(['tenant_suspended', 'tenant_resumed'])
        ->and($this->seam->lines())->toBe([]);
});

it('words the two flashes for a tenant with no owner without a promise of mail, and the two for one with owners as before', function () {
    expect(SuspensionController::SUSPENDED)->toBe('The account is suspended, and its owners have been sent the reason.')
        ->and(SuspensionController::RESUMED)->toBe('The account is active again, and its owners have been told.')
        ->and(SuspensionController::SUSPENDED_NO_OWNER)->toBe('The account is suspended. It has no owner, so the reason was sent to nobody.')
        ->and(SuspensionController::RESUMED_NO_OWNER)->toBe('The account is active again. It has no owner, so nobody was told.');
});

it('refuses to suspend a closing row with the sentence as an error flash, and writes nothing and sends nothing', function () {
    DB::table('tenants')->where('slug', 'luna')->update(['status' => 'closing', 'closing_at' => '2026-10-10 09:00:00+00:00']);

    suspensionPost($this, addOperator(), 'luna', 'suspend', ['reason' => SUSPENSION_REASON])
        ->assertRedirect(consoleUrl('/tenants/luna'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => 'A closing account cannot be suspended.']);

    expect(suspensionColumns())->toBe(['status' => 'closing', 'suspended_at' => null, 'suspension_reason' => null])
        ->and(suspensionAudit())->toBe([])
        ->and($this->seam->sent)->toBe([]);
});

it('refuses to {action} a {status} row with its own sentence, writing nothing and sending nothing', function (string $action, string $status, string $sentence) {
    DB::table('tenants')->where('slug', 'luna')->update([
        'status' => $status,
        ...($status === 'suspended' ? ['suspended_at' => '2026-10-13 09:00:00+00:00', 'suspension_reason' => 'Earlier.'] : []),
        ...($status === 'closing' ? ['closing_at' => '2026-10-10 09:00:00+00:00'] : []),
    ]);
    $before = suspensionColumns();

    suspensionPost($this, addOperator(), 'luna', $action, $action === 'suspend' ? ['reason' => SUSPENSION_REASON] : [])
        ->assertRedirect(consoleUrl('/tenants/luna'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => $sentence]);

    expect(suspensionColumns())->toBe($before)
        ->and(suspensionAudit())->toBe([])
        ->and($this->seam->sent)->toBe([]);
})->with([
    ['suspend', 'suspended', Suspension::ALREADY_SUSPENDED],
    ['suspend', 'provisioning', Suspension::PROVISIONING],
    ['resume', 'active', Suspension::NOT_SUSPENDED],
    ['resume', 'closing', Suspension::NOT_SUSPENDED],
    ['resume', 'provisioning', Suspension::NOT_SUSPENDED],
]);

it('refuses a reason that is {case} on the field with its sentence, and writes nothing', function (mixed $reason, string $sentence) {
    suspensionPost($this, addOperator(), 'luna', 'suspend', $reason === null ? [] : ['reason' => $reason])
        ->assertRedirect(consoleUrl('/tenants/luna'))
        ->assertSessionHasErrors(['reason' => $sentence]);

    expect(suspensionColumns())->toBe(['status' => 'active', 'suspended_at' => null, 'suspension_reason' => null])
        ->and(suspensionAudit())->toBe([])
        ->and($this->seam->sent)->toBe([]);
})->with([
    'missing' => [null, SuspendRequest::REQUIRED],
    'blank' => ["  \t ", SuspendRequest::REQUIRED],
    'past 500 characters' => [str_repeat('a', 501), SuspendRequest::TOO_LONG],
    'not text' => [['a list'], SuspendRequest::NOT_TEXT],
    'holding a NUL' => ["Charge\0backs", SuspendRequest::NOT_TEXT],
    'not UTF-8' => ["Chargebacks \xC3\x28", SuspendRequest::NOT_TEXT],
]);

it('takes a reason of 500 characters and keeps it trimmed', function () {
    $reason = str_repeat('e', 499).'.';

    suspensionPost($this, addOperator(), 'luna', 'suspend', ['reason' => "  {$reason}\n "])->assertSessionHasNoErrors();

    expect(suspensionColumns()['suspension_reason'])->toBe($reason)
        ->and(suspensionAudit()[0]['payload'])->toBe(['reason' => $reason]);
});

it('answers the console\'s 404 for {case}, and writes nothing', function (string $slug, string $action) {
    Tenant::factory()->create(['slug' => 'support', 'name' => 'A row on a reserved label']);

    suspensionPost($this, addOperator(), $slug, $action, ['reason' => SUSPENSION_REASON])
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('console/NotFound'));

    expect(suspensionAudit())->toBe([])
        ->and($this->seam->sent)->toBe([])
        ->and(DB::table('tenants')->where('status', 'suspended')->count())->toBe(0);
})->with([
    'a slug no row holds, suspended' => ['nobody', 'suspend'],
    'a slug no row holds, resumed' => ['nobody', 'resume'],
    'a malformed slug' => ['luna_studio', 'suspend'],
    'a reserved slug a row holds' => ['support', 'suspend'],
]);

it('sends a visitor with no operator session to the door, and writes nothing', function () {
    $this->post(consoleUrl('/tenants/luna/suspend'), ['reason' => SUSPENSION_REASON])->assertRedirect(consoleUrl('/login'));
    $this->post(consoleUrl('/tenants/luna/resume'))->assertRedirect(consoleUrl('/login'));

    expect(suspensionColumns()['status'])->toBe('active')
        ->and(suspensionAudit())->toBe([]);
});

it('writes nothing and sends nothing when the audit row cannot be written, the status and the row sharing one transaction', function () {
    $ghost = new Operator(['name' => 'Nobody', 'email' => 'nobody@inbox.example', 'password' => 'x']);
    $ghost->id = 999999;

    expect(fn () => app(Suspension::class)->suspend(LunaFixture::tenant(), $ghost, SUSPENSION_REASON))->toThrow(QueryException::class);

    expect(suspensionColumns())->toBe(['status' => 'active', 'suspended_at' => null, 'suspension_reason' => null])
        ->and(suspensionAudit())->toBe([])
        ->and($this->seam->sent)->toBe([]);
});

it('leaves billing as it stood: the subscription columns and the subscriptions row untouched by a suspend and a resume', function () {
    DB::table('subscriptions')->insert(['tenant_id' => LunaFixture::tenant()->id, 'provider_customer_id' => 'cus_luna', 'provider_subscription_id' => 'sub_luna', 'status' => 'past_due', 'current_period_end' => '2026-11-01 09:00:00+00:00']);
    DB::table('tenants')->where('slug', 'luna')->update(['entitlements' => '{staff}', 'plan_ref' => 'price_staff_test', 'subscription_state' => 'grace', 'grace_ends_at' => '2026-10-20 09:00:00+00:00']);
    $before = suspensionBilling();
    $operator = addOperator();

    suspensionPost($this, $operator, 'luna', 'suspend', ['reason' => SUSPENSION_REASON])->assertSessionHas('flash.kind', 'success');
    $suspended = suspensionBilling();
    suspensionPost($this, $operator, 'luna', 'resume')->assertSessionHas('flash.kind', 'success');

    expect($suspended)->toBe($before)
        ->and(suspensionBilling())->toBe($before);
});

it('still sweeps a suspended tenant\'s expired holds, which may block a slot in a diary that reopens', function () {
    $uuid = LunaFixture::booking('pending', holdClass: 'checkout', expiry: 'past');
    suspensionPost($this, addOperator(), 'luna', 'suspend', ['reason' => SUSPENSION_REASON])->assertSessionHas('flash.kind', 'success');

    expect(Artisan::call('holds:sweep'))->toBe(0)
        ->and(Artisan::output())->toContain('Queued 2 sweeps.')
        ->and(LunaFixture::row($uuid))->toMatchArray(['status' => 'expired'])
        ->and(LunaFixture::tenant()->status)->toBe(TenantStatus::Suspended);
});
