<?php

use App\Application\Clock;
use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use App\Http\ConsoleSession;
use App\Http\Controllers\Admin\SetPasswordController;
use App\Http\Controllers\Console\PasswordLinkController;
use App\Platform\Operator;
use App\Platform\PasswordLinks;
use App\Site\SiteLanguage;
use App\Tenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\Support\LevelledPlatformMessages;
use Tests\Support\LunaFixture;
use Tests\Support\SeamLog;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * The set-password link's console half: the operator sends any user of a tenant a link, and the
 * console never types or learns a password. The POST mints 32 random bytes as hex, stores the
 * sha256 and an expiry 72 hours from the clock's now on the users row, replacing any link there
 * was, and records password_link_sent naming the user by id in the same transaction; after the
 * commit the link goes to that user through the platform's seam. The clear token travels from
 * the mint to the seam in memory alone.
 *
 * "After the commit" is read off a recorder that notes the transaction level each message was
 * handed over at, nought with no transaction open, so the file truncates rather than wrapping
 * each test in a transaction, and seeds the demo afresh, luna and pier with their owners. The
 * clock is pinned, so the expiry the mint writes is the one spelled here.
 *
 * The link's mail is a branded mail in the row's language: its words are mail.php's in
 * tenants.locale in both parts, and the Greek case lays Greek lines over the loaded
 * lang/el/mail.php, as TrialNoticeTest does, to tell the test's lines from the file's whatever
 * the file holds.
 */

beforeEach(function () {
    $this->withoutVite();
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-10-14 09:00'));
    $this->seam = app(LevelledPlatformMessages::class);
    app()->instance(PlatformMessenger::class, $this->seam);
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
 * The console's POST for a user's link, as the operator given sends it from the tenant's detail.
 *
 * @return TestResponse<Response>
 */
function plinkPost(TestCase $test, Operator $operator, string $slug, int|string $user): TestResponse
{
    return $test->actingAs($operator, ConsoleSession::GUARD)
        ->withSession(freshConsoleSession())
        ->from(consoleUrl('/tenants/'.$slug))
        ->post(consoleUrl('/tenants/'.$slug.'/users/'.$user.'/password-link'));
}

/**
 * A users row's link, the hash and the expiry in UTC to the second.
 *
 * @return array<mixed>
 */
function plinkColumns(int $userId): array
{
    return (array) DB::selectOne(
        "select password_link_hash as hash, to_char(password_link_expires_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as expires_at from users where id = ?",
        [$userId],
    );
}

/**
 * Every platform audit row as its operator, action, tenant and payload, the payload decoded.
 *
 * @return list<array<mixed>>
 */
function plinkAudit(): array
{
    $rows = [];

    foreach (DB::select('select operator_id, action, tenant_id, payload::text as payload from platform_audit_log order by id') as $row) {
        $row = (array) $row;
        $rows[] = [...$row, 'payload' => json_decode(is_string($row['payload'] ?? null) ? $row['payload'] : '', true, 512, JSON_THROW_ON_ERROR)];
    }

    return $rows;
}

/**
 * The clear token a message's link carries, the empty string where it carries none.
 */
function plinkTokenIn(string $body): string
{
    return preg_match('~/admin/set-password/([0-9a-f]{64})~', $body, $match) === 1 ? $match[1] : '';
}

it('mints a link for any user of the tenant, the {role} among them: the sha256 and the expiry 72 hours from the clock\'s now on the row, password_link_sent naming the user by id, and the link mailed to that user alone after the commit', function (string $role, string $email) {
    $userId = $role === 'owner' ? tenantUserId($email) : addTenantUser($email, $role);
    $others = DB::table('users')->where('id', '<>', $userId)->pluck('id')->all();
    $operator = addOperator();

    plinkPost($this, $operator, 'luna', $userId)
        ->assertRedirect(consoleUrl('/tenants/luna'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => sprintf(PasswordLinkController::SENT, $email)]);

    $message = $this->seam->message(0);
    $token = plinkTokenIn($message->body);

    expect($token)->toMatch('/\A[0-9a-f]{64}\z/')
        ->and(plinkColumns($userId))->toBe(['hash' => hash('sha256', $token), 'expires_at' => '2026-10-17 09:00:00'])
        ->and(plinkAudit())->toBe([
            ['operator_id' => $operator->id, 'action' => 'password_link_sent', 'tenant_id' => LunaFixture::tenant()->id, 'payload' => ['user' => $userId]],
        ])
        ->and($this->seam->lines())->toBe([$email.' set_password 0'])
        ->and($message->kind)->toBe(PlatformMessageKind::SetPassword)
        ->and($message->tenantId)->toBe(LunaFixture::tenant()->id)
        ->and($message->subject)->toBe(siteSentence('mail.set_password.subject', SiteLanguage::English))
        ->and($message->body)->toContain(tenantUrl('luna', '/admin/set-password/'.$token))
        ->and(DB::table('users')->whereIn('id', $others)->whereNotNull('password_link_hash')->count())->toBe(0);
})->with([
    ['owner', 'owner@luna.example'],
    ['manager', 'max@luna.example'],
    ['staff', 'sam@luna.example'],
]);

it('words the mail: the user by name, the business and its sign-in page, the link, its window by PasswordLinks::hours(), and that nothing changes until it is used, today\'s English character for character, with the same words as HTML in the layout', function () {
    plinkPost($this, addOperator(), 'luna', tenantUserId())->assertRedirect();

    $message = $this->seam->message(0);
    $token = plinkTokenIn($message->body);
    $window = mailChoice('mail.set_password.window', PasswordLinks::hours());

    expect($message->toName)->toBe('Demo Owner')
        ->and($message->replyTo)->toBeNull()
        ->and($message->subject)->toBe('Set your password')
        ->and($message->body)->toBe(implode("\n", [
            'Hello Demo Owner,',
            '',
            sprintf('Set a password to sign in to Luna Hair Studio at %s. Open this link and choose one:', tenantUrl('luna', '/admin/login')),
            '',
            tenantUrl('luna', '/admin/set-password/'.$token),
            '',
            $window,
            '',
            'If you were not expecting this email, you can ignore it.',
        ]))
        ->and($message->html)->toContain('<html lang="en">')
        ->and($message->html)->toContain('<title>Set your password</title>')
        ->and($message->html)->toContain('<a href="'.tenantUrl('luna', '/admin/set-password/'.$token).'"')
        ->and($message->html)->toContain(sprintf('Set a password to sign in to Luna Hair Studio at %s. Open this link and choose one:', tenantUrl('luna', '/admin/login')))
        ->and($message->html)->toContain($window)
        ->and($message->html)->toContain(siteSentence('mail.set_password.reason', SiteLanguage::English, ['business' => 'Luna Hair Studio']))
        ->and($message->html)->toContain(siteSentence('mail.layout.sent_by', SiteLanguage::English))
        ->and($message->html)->not->toContain('<img');
});

it('tells a user of a Greek row in Greek: the mail\'s words from lang/el through the translator in both parts, the document in Greek', function () {
    DB::table('tenants')->where('slug', 'luna')->update(['locale' => 'el']);
    // Greek lines are laid over the loaded lang/el/mail.php to tell the test's lines from the
    // file's whatever the file holds.
    __('mail.greeting', [], 'el');
    app('translator')->addLines([
        'mail.set_password.subject' => 'GREEK SET PASSWORD',
        'mail.set_password.choose' => 'GREEK :business AT :address',
        'mail.set_password.window' => 'GREEK WINDOW',
        'mail.set_password.window_first' => 'GREEK FIRST WINDOW',
    ], 'el');

    plinkPost($this, addOperator(), 'luna', tenantUserId())->assertRedirect();

    $message = $this->seam->message(0);
    $token = plinkTokenIn($message->body);

    expect($token)->toMatch('/\A[0-9a-f]{64}\z/')
        ->and($message->subject)->toBe('GREEK SET PASSWORD')
        ->and($message->body)->toBe(implode("\n", [
            siteSentence('mail.greeting', SiteLanguage::Greek, ['owner' => 'Demo Owner']),
            '',
            'GREEK Luna Hair Studio AT '.tenantUrl('luna', '/admin/login'),
            '',
            tenantUrl('luna', '/admin/set-password/'.$token),
            '',
            'GREEK WINDOW',
            '',
            siteSentence('mail.set_password.ignore', SiteLanguage::Greek),
        ]))
        ->and($message->html)->toContain('<html lang="el">')
        ->and($message->html)->toContain('<title>GREEK SET PASSWORD</title>')
        ->and($message->html)->toContain('GREEK Luna Hair Studio AT '.tenantUrl('luna', '/admin/login'))
        ->and($message->html)->toContain('<a href="'.tenantUrl('luna', '/admin/set-password/'.$token).'"')
        ->and($message->html)->toContain(siteSentence('mail.set_password.reason', SiteLanguage::Greek, ['business' => 'Luna Hair Studio']))
        ->and(DB::table('users')->where('email', 'owner@luna.example')->value('password_link_hash'))->toBe(hash('sha256', $token));
});

it('keeps the clear token out of every row, log line, flash, session, queue and answer, the hash alone kept', function () {
    $log = new SeamLog;
    Log::swap($log);
    $operator = addOperator();

    $posted = plinkPost($this, $operator, 'luna', tenantUserId());
    $token = plinkTokenIn($this->seam->bodyTo('owner@luna.example'));
    $detail = $this->actingAs($operator, ConsoleSession::GUARD)->get(consoleUrl('/tenants/luna'));

    expect($token)->not->toBe('');

    foreach ([$posted, $detail] as $answer) {
        expect((string) $answer->getContent())->not->toContain($token)
            ->and((string) $answer->headers)->not->toContain($token);
    }

    $rows = json_encode([DB::table('users')->get(), DB::table('platform_audit_log')->get(), DB::table('tenants')->get()], JSON_THROW_ON_ERROR);

    expect($rows)->not->toContain($token)
        ->and($rows)->toContain(hash('sha256', $token))
        ->and(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain($token)
        ->and($log->text())->not->toContain($token)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('replaces the link on a second send: the new hash and a new 72 hours on the row, and the first link dead from then on', function () {
    $operator = addOperator();
    $userId = tenantUserId();
    plinkPost($this, $operator, 'luna', $userId)->assertRedirect();
    $first = plinkTokenIn($this->seam->message(0)->body);
    app()->instance(Clock::class, FixedClock::at('2026-10-15 16:30'));
    freshRouteController('console.tenants.users.password-link');

    plinkPost($this, $operator, 'luna', $userId)->assertSessionHas('flash.kind', 'success');

    $second = plinkTokenIn($this->seam->message(1)->body);

    expect($second)->not->toBe($first)
        ->and(plinkColumns($userId))->toBe(['hash' => hash('sha256', $second), 'expires_at' => '2026-10-18 16:30:00'])
        ->and(array_column(plinkAudit(), 'payload'))->toBe([['user' => $userId], ['user' => $userId]]);

    $this->get(tenantUrl('luna', '/admin/set-password/'.$first))
        ->assertRedirect(tenantUrl('luna', '/admin/login'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => adminLine(SetPasswordController::EXPIRED)]);
    $this->get(tenantUrl('luna', '/admin/set-password/'.$second))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/SetPassword'));
});

it('locks nobody out: the old password still signs in once a link is sent', function () {
    plinkPost($this, addOperator(), 'luna', tenantUserId())->assertRedirect();
    restoreWebGuard();

    $this->post(tenantUrl('luna', '/admin/login'), ['email' => 'owner@luna.example', 'password' => 'password'])
        ->assertRedirect(tenantUrl('luna', '/admin'));
});

it('answers the console\'s 404 for {case}, and writes nothing and sends nothing', function (Closure $arrange) {
    Tenant::factory()->create(['slug' => 'support', 'name' => 'A row on a reserved label']);
    $target = $arrange();
    assert(is_array($target) && is_string($target[0] ?? null) && (is_int($target[1] ?? null) || is_string($target[1] ?? null)));
    [$slug, $user] = $target;

    plinkPost($this, addOperator(), $slug, $user)
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('console/NotFound'));

    expect(plinkAudit())->toBe([])
        ->and($this->seam->sent)->toBe([])
        ->and(DB::table('users')->whereNotNull('password_link_hash')->count())->toBe(0);
})->with([
    'a user of another tenant' => [fn (): array => ['luna', tenantUserId('owner@pier.example')]],
    'an id no user holds' => [fn (): array => ['luna', 424242]],
    'an id that is not a number' => [fn (): array => ['luna', 'owner']],
    'an id of nought' => [fn (): array => ['luna', '0']],
    'a user\'s id signed with a plus (08e)' => [fn (): array => ['luna', '+'.tenantUserId()]],
    'a user\'s id led by a space (08e)' => [fn (): array => ['luna', '%20'.tenantUserId()]],
    'a user\'s id led by a nought' => [fn (): array => ['luna', '0'.tenantUserId()]],
    'a slug no row holds' => [fn (): array => ['nobody', tenantUserId()]],
    'a malformed slug' => [fn (): array => ['luna_studio', tenantUserId()]],
    'a reserved slug a row holds' => [fn (): array => ['support', tenantUserId()]],
]);

it('sends a visitor with no operator session to the door, and writes nothing and sends nothing', function () {
    $this->post(consoleUrl('/tenants/luna/users/'.tenantUserId().'/password-link'))->assertRedirect(consoleUrl('/login'));

    expect(plinkAudit())->toBe([])
        ->and($this->seam->sent)->toBe([])
        ->and(plinkColumns(tenantUserId()))->toBe(['hash' => null, 'expires_at' => null]);
});

it('writes nothing and sends nothing when the audit row cannot be written, the link and the row sharing one transaction', function () {
    $ghost = new Operator(['name' => 'Nobody', 'email' => 'nobody@inbox.example', 'password' => 'x']);
    $ghost->id = 999999;

    expect(fn () => app(PasswordLinks::class)->send(LunaFixture::tenant(), $ghost, tenantUserId()))->toThrow(QueryException::class);

    expect(plinkColumns(tenantUserId()))->toBe(['hash' => null, 'expires_at' => null])
        ->and(plinkAudit())->toBe([])
        ->and($this->seam->sent)->toBe([]);
});
