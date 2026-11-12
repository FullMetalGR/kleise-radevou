<?php

use App\Application\Clock;
use App\Http\ConsoleSession;
use App\Http\Controllers\Admin\LoginController;
use App\Http\Controllers\Admin\SetPasswordController;
use App\Http\Middleware\KeepLinkTokensOutOfTheSession;
use App\Http\Requests\PasswordRule;
use App\Http\Requests\SetPasswordRequest;
use App\Models\User;
use App\Platform\PasswordLinks;
use App\Site\SiteLanguage;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\Support\SeamLog;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The set-password link's tenant half: on the tenant's own host, under the admin prefix, GET
 * /admin/set-password/{token} renders one password field from the admin bundle outside the shell,
 * and its POST judges the field by PasswordRule and sets the password in the statement that clears
 * the link, where the link's hash names a user of this tenant and its expiry is ahead of the
 * clock's now. One row moved lands on the sign-in page with a success flash; anything else, a link
 * used, lapsed, replaced, unknown, tampered or of the wrong shape, answers the sign-in page with
 * the expired sentence and changes nothing. The old password keeps working until the link is used.
 *
 * The clock is pinned and moved: the mint writes its expiry from the clock, and the set compares
 * the row against the clock, never against the database's own now(), so 72 hours are read here
 * by moving the clock alone.
 *
 * The set also ends the user's other sessions and stamps the address verified, in the statement's
 * own transaction: the sessions rows are the framework's, deleted by user_id, so the case that
 * reads them runs on the database session driver, the feature suite's own being the array.
 */

const SETPW_NEW = 'a brand new password';

beforeEach(function () {
    $this->withoutVite();
    app()->instance(Clock::class, FixedClock::at('2026-10-14 09:00'));
    provisionTenant('luna', ['name' => 'Luna Hair Studio', 'ownerEmail' => 'owner@luna.example', 'ownerPassword' => 'the old password']);
    provisionTenant('pier', ['name' => 'Pier Physiotherapy', 'ownerEmail' => 'owner@pier.example', 'ownerPassword' => 'pier old password']);
});

/**
 * Mints a link for the user at that address at the bound clock and answers its clear token.
 */
function setpwMint(string $email = 'owner@luna.example'): string
{
    return app(PasswordLinks::class)->mint(User::query()->where('email', $email)->sole());
}

/**
 * A users row as a set changes it or leaves it: the password's hash and the link's two columns,
 * the expiry in UTC to the second.
 *
 * @return array<mixed>
 */
function setpwRow(string $email = 'owner@luna.example'): array
{
    return (array) DB::selectOne(
        "select password, password_link_hash as hash, to_char(password_link_expires_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as expires_at from users where email = ?",
        [$email],
    );
}

/**
 * The password hash a users row holds.
 */
function setpwHash(string $email = 'owner@luna.example'): string
{
    $hash = setpwRow($email)['password'] ?? null;

    return is_string($hash) ? $hash : throw new LogicException(sprintf('No password hash for %s.', $email));
}

/**
 * The page's POST for the token given, as the page's form sends it.
 *
 * @return TestResponse<Response>
 */
function setpwPost(TestCase $test, string $token, mixed $password = SETPW_NEW, string $slug = 'luna'): TestResponse
{
    return $test->post(tenantUrl($slug, '/admin/set-password/'.$token), $password === null ? [] : ['password' => $password]);
}

/**
 * Writes a sessions row as the framework's database driver writes one, its payload the session's
 * values as JSON in base64, for the case that reads which rows a set leaves.
 *
 * @param  array<string, string>  $values
 */
function setpwSessionRow(string $id, ?int $userId, array $values = []): void
{
    DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => base64_encode(json_encode($values, JSON_THROW_ON_ERROR)), 'last_activity' => time()]);
}

/**
 * Every sessions row as its id and user_id, the ids in the order PHP sorts them in, which is
 * the order an expectation's own keys are sorted in. The database's collation is free to order
 * them otherwise, and a new session's id is random.
 *
 * @return array<string, int|null>
 */
function setpwSessionOwners(): array
{
    $owners = [];

    foreach (DB::table('sessions')->get(['id', 'user_id']) as $row) {
        assert(is_string($row->id) && (is_int($row->user_id) || $row->user_id === null));
        $owners[$row->id] = $row->user_id;
    }

    ksort($owners);

    return $owners;
}

/**
 * A refusal of the set-password form in a language, the rule's floor and ceiling filled from its
 * constants.
 */
function setpwRefusal(string $key, SiteLanguage $language = SiteLanguage::English): string
{
    return adminLine($key, PasswordRule::fills(), $language);
}

/**
 * Asserts the answer every dead link reads: the sign-in page with the expired sentence as an error.
 *
 * @param  TestResponse<Response>  $response
 */
function setpwExpired(TestResponse $response, string $slug = 'luna'): void
{
    $response->assertRedirect(tenantUrl($slug, '/admin/login'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => adminLine(SetPasswordController::EXPIRED)]);
}

it('renders the page from the admin bundle outside the shell for a live link, no-store and no-referrer, its props holding no token', function () {
    $token = setpwMint();

    $response = $this->get(tenantUrl('luna', '/admin/set-password/'.$token));
    $response->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertInertia(fn (Assert $page) => $page->component('auth/SetPassword')->missing('nav')->where('floor', PasswordRule::FLOOR_CHARACTERS)->where('strings', ['shell' => __('admin/shell', [], 'en')]));
    $page = $response->viewData('page');

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(is_array($page) ? json_encode($page['props'] ?? null, JSON_THROW_ON_ERROR) : '')->not->toContain($token)
        ->and(setpwRow())->toMatchArray(['hash' => hash('sha256', $token), 'expires_at' => '2026-10-17 09:00:00']);
});

it('sets the password once inside 72 hours under the rule, clearing the link in the same statement, and lands on the sign-in page with the flash; the new password signs in and the old one no longer does', function () {
    $token = setpwMint();
    $before = setpwRow();
    app()->instance(Clock::class, FixedClock::at('2026-10-17 08:59:59'));

    setpwPost($this, $token)
        ->assertRedirect(tenantUrl('luna', '/admin/login'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(SetPasswordController::SET)]);

    $after = setpwRow();

    expect($after['hash'])->toBeNull()
        ->and($after['expires_at'])->toBeNull()
        ->and($after['password'])->not->toBe($before['password'])
        ->and(is_string($after['password']) && Hash::check(SETPW_NEW, $after['password']))->toBeTrue()
        ->and(password_get_info(is_string($after['password']) ? $after['password'] : '')['algoName'])->toBe('bcrypt');

    $this->post(tenantUrl('luna', '/admin/login'), ['email' => 'owner@luna.example', 'password' => 'the old password'])
        ->assertSessionHasErrors(['email' => adminLine(LoginController::REFUSED)]);
    $this->assertGuest();
    $this->post(tenantUrl('luna', '/admin/login'), ['email' => 'owner@luna.example', 'password' => SETPW_NEW])
        ->assertRedirect(tenantUrl('luna', '/admin'));
});

it('ends every other session of the user by user_id in the set, the current session surviving under a new id, and leaves every other user\'s and every visitor\'s', function () {
    config(['session.driver' => 'database']);
    app('session')->forgetDrivers();
    $token = setpwMint();
    $owner = tenantUserId();
    $pier = tenantUserId('owner@pier.example');
    [$current, $laptop, $phone, $pierRow, $visitor] = [str_repeat('c', 40), str_repeat('a', 40), str_repeat('b', 40), str_repeat('d', 40), str_repeat('e', 40)];
    setpwSessionRow($current, $owner, ['marker' => 'the current session']);
    setpwSessionRow($laptop, $owner);
    setpwSessionRow($phone, $owner);
    setpwSessionRow($pierRow, $pier);
    setpwSessionRow($visitor, null);
    $cookie = config('session.cookie');
    assert(is_string($cookie));

    $this->withCookie($cookie, $current)
        ->post(tenantUrl('luna', '/admin/set-password/'.$token), ['password' => SETPW_NEW])
        ->assertRedirect(tenantUrl('luna', '/admin/login'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(SetPasswordController::SET)])
        ->assertSessionHas('marker', 'the current session');

    $regenerated = session()->getId();
    $expected = [$pierRow => $pier, $visitor => null, $regenerated => null];
    ksort($expected);

    expect($regenerated)->not->toBe($current)
        ->and(setpwSessionOwners())->toBe($expected);
});

it('ends nobody\'s session and hashes nothing for a link that sets nothing, judging the link by a read before the password is hashed (08d)', function () {
    $hasher = new class(app(Hasher::class)) implements Hasher
    {
        public int $made = 0;

        public function __construct(private readonly Hasher $inner) {}

        /** @return array<mixed> */
        public function info($hashedValue): array
        {
            return $this->inner->info($hashedValue);
        }

        /** @param  array<string, mixed>  $options */
        public function make(#[SensitiveParameter] $value, array $options = []): string
        {
            $this->made++;

            return $this->inner->make($value, $options);
        }

        /** @param  array<string, mixed>  $options */
        public function check(#[SensitiveParameter] $value, $hashedValue, array $options = []): bool
        {
            return $this->inner->check($value, $hashedValue, $options);
        }

        /** @param  array<string, mixed>  $options */
        public function needsRehash($hashedValue, array $options = []): bool
        {
            return $this->inner->needsRehash($hashedValue, $options);
        }
    };
    app()->instance(Hasher::class, $hasher);
    $token = setpwMint();

    setpwExpired(setpwPost($this, str_repeat('ab', 32)));
    setpwExpired(setpwPost($this, strtoupper($token)));

    expect($hasher->made)->toBe(0);

    setpwPost($this, $token)->assertSessionHas('flash.kind', 'success');

    expect($hasher->made)->toBe(1);
});

it('stamps the address verified at the clock\'s now where it was not, a used link proving it, and keeps a stamp there was', function () {
    DB::table('users')->where('email', 'owner@pier.example')->update(['email_verified_at' => '2026-10-01 07:30:00+00:00']);
    $luna = setpwMint();
    $pier = setpwMint('owner@pier.example');
    app()->instance(Clock::class, FixedClock::at('2026-10-15 12:00', 'Europe/Athens'));

    setpwPost($this, $luna)->assertSessionHas('flash.kind', 'success');
    setpwPost($this, $pier, slug: 'pier')->assertSessionHas('flash.kind', 'success');

    $stamps = array_map(fn (mixed $row): mixed => $row instanceof stdClass ? $row->stamp : null, DB::select(
        "select to_char(email_verified_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as stamp from users where email in ('owner@luna.example', 'owner@pier.example') order by email",
    ));

    expect($stamps)->toBe(['2026-10-15 09:00:00', '2026-10-01 07:30:00']);
});

it('reads the user verified on the console\'s detail once their link has set a password', function () {
    $token = setpwMint();
    setpwPost($this, $token)->assertSessionHas('flash.kind', 'success');

    $this->actingAs(addOperator(), ConsoleSession::GUARD)
        ->withSession(freshConsoleSession())
        ->get(consoleUrl('/tenants/luna'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('console/Tenant')->where('tenant.users.0.email', 'owner@luna.example')->where('tenant.users.0.verified', true));
});

it('writes no password, keeps the link and ends no session where the sessions cannot be ended, the set and the deletion being one transaction', function () {
    $token = setpwMint();
    $before = setpwRow();
    DB::unprepared(<<<'SQL'
        create function setpw_refuse_session_delete() returns trigger language plpgsql as $$ begin raise exception 'the sessions delete is refused'; end $$;
        create trigger setpw_refuse_session_delete before delete on sessions for each statement execute function setpw_refuse_session_delete();
        SQL);

    setpwPost($this, $token)->assertStatus(500);

    expect(setpwRow())->toBe($before)
        ->and(DB::table('users')->where('email', 'owner@luna.example')->value('email_verified_at'))->toBeNull();
});

it('keeps the old password working until the link is used: after the mint and after the page is opened', function () {
    $token = setpwMint();
    $this->get(tenantUrl('luna', '/admin/set-password/'.$token))->assertOk();

    $this->post(tenantUrl('luna', '/admin/login'), ['email' => 'owner@luna.example', 'password' => 'the old password'])
        ->assertRedirect(tenantUrl('luna', '/admin'));

    expect(setpwRow())->toMatchArray(['hash' => hash('sha256', $token), 'expires_at' => '2026-10-17 09:00:00']);
});

it('answers the sign-in page with the expired sentence and changes nothing for {case}, on the page and on its POST', function (Closure $arrange) {
    $token = $arrange($this, setpwMint());
    assert(is_string($token));
    $before = [setpwRow(), setpwRow('owner@pier.example')];

    setpwExpired($this->get(tenantUrl('luna', '/admin/set-password/'.$token)));
    setpwExpired(setpwPost($this, $token, 'yet another password'));

    expect([setpwRow(), setpwRow('owner@pier.example')])->toBe($before);
})->with([
    'a link used once already' => [function (TestCase $test, string $token): string {
        setpwPost($test, $token)->assertSessionHas('flash.kind', 'success');

        return $token;
    }],
    'a link 72 hours old, on a moved clock' => [function (TestCase $test, string $token): string {
        app()->instance(Clock::class, FixedClock::at('2026-10-17 09:00'));

        return $token;
    }],
    'a link replaced by a second send' => [function (TestCase $test, string $token): string {
        setpwMint();

        return $token;
    }],
    'an unknown token' => [fn (): string => str_repeat('ab', 32)],
    'a tampered token' => [fn (TestCase $test, string $token): string => substr($token, 0, 63).($token[63] === 'a' ? 'b' : 'a')],
    'a token of the wrong shape' => [fn (TestCase $test, string $token): string => strtoupper($token)],
    'a token too short' => [fn (TestCase $test, string $token): string => substr($token, 0, 63)],
    'a token too long' => [fn (TestCase $test, string $token): string => $token.'a'],
    'the live link of another tenant\'s user' => [fn (): string => setpwMint('owner@pier.example')],
]);

it('opens another tenant\'s link at its own tenant alone, where it still sets', function () {
    $token = setpwMint('owner@pier.example');
    setpwExpired(setpwPost($this, $token));

    setpwPost($this, $token, slug: 'pier')
        ->assertRedirect(tenantUrl('pier', '/admin/login'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(SetPasswordController::SET)]);

    expect(Hash::check(SETPW_NEW, setpwHash('owner@pier.example')))->toBeTrue()
        ->and(Hash::check('the old password', setpwHash()))->toBeTrue();
});

it('refuses a password that is {case} on the field with the rule\'s sentence, back on the link\'s own page, keeping the link and the old password', function (mixed $password, string $sentence) {
    $token = setpwMint();
    $before = setpwRow();

    setpwPost($this, $token, $password)
        ->assertRedirect(tenantUrl('luna', '/admin/set-password/'.$token))
        ->assertSessionHasErrors(['password' => setpwRefusal($sentence)]);

    expect(setpwRow())->toBe($before);
})->with([
    'missing' => [null, SetPasswordRequest::TOO_SHORT],
    'under eight characters' => ['seven77', SetPasswordRequest::TOO_SHORT],
    'past 72 bytes' => [str_repeat('a', 73), SetPasswordRequest::TOO_LONG],
    'not text' => [['a list'], SetPasswordRequest::TOO_SHORT],
]);

it('answers a refused password posted to a link of the wrong shape back to that link\'s page, which answers the sign-in page with the expired sentence, nothing changed and the link kept out of the session (08g)', function () {
    $token = setpwMint();
    $before = setpwRow();
    $malformed = strtoupper($token);

    setpwPost($this, $malformed, 'short')
        ->assertRedirect(tenantUrl('luna', '/admin/set-password/'.$malformed))
        ->assertSessionHasErrors(['password' => setpwRefusal(SetPasswordRequest::TOO_SHORT)]);
    setpwExpired($this->get(tenantUrl('luna', '/admin/set-password/'.$malformed)));

    $session = json_encode(session()->all(), JSON_THROW_ON_ERROR);

    expect(setpwRow())->toBe($before)
        ->and($session)->not->toContain($malformed)
        ->and($session)->not->toContain($token);
});

it('constrains the page\'s token on the route to 64 lowercase hex characters at the HTTP boundary, the link\'s own shape', function () {
    $route = Route::getRoutes()->getByName('admin.set-password');
    assert($route !== null);

    expect($route->wheres)->toBe(['token' => PasswordLinks::TOKEN])
        ->and(PasswordLinks::TOKEN)->toBe('[0-9a-f]{64}')
        ->and(setpwMint())->toMatch('/\A'.PasswordLinks::TOKEN.'\z/');
});

it('keeps the link\'s URL out of the session\'s previous-URL slot, on the page and on its malformed sibling alike', function () {
    $token = setpwMint();
    $this->get(tenantUrl('luna', '/admin/login'))->assertOk();

    $this->get(tenantUrl('luna', '/admin/set-password/'.$token))->assertOk();
    $afterPage = (string) session()->previousUrl();
    setpwExpired($this->get(tenantUrl('luna', '/admin/set-password/'.strtoupper($token))));

    expect(KeepLinkTokensOutOfTheSession::ROUTES)->toBe(['my-bookings.open', 'my-bookings.open.act', 'admin.set-password', 'admin.set-password.malformed', 'admin.support.open', 'admin.support.malformed', 'manage', 'pay', 'pay.link', 'api.booking', 'api.booking.availability', 'signup.verify', 'signup.verify.malformed'])
        ->and($afterPage)->toBe(tenantUrl('luna', '/admin/login'))
        ->and((string) session()->previousUrl())->toBe(tenantUrl('luna', '/admin/login'));
});

it('keeps the clear token out of every log line, flash and session value, and out of the set\'s answers', function () {
    $log = new SeamLog;
    Log::swap($log);
    $token = setpwMint();

    $this->get(tenantUrl('luna', '/admin/set-password/'.$token))->assertOk();
    setpwPost($this, $token, 'short')->assertSessionHasErrors('password');
    $set = setpwPost($this, $token);
    $again = setpwPost($this, $token);

    foreach ([$set, $again] as $answer) {
        expect((string) $answer->getContent())->not->toContain($token)
            ->and((string) $answer->headers)->not->toContain($token);
    }

    expect(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain($token)
        ->and($log->text())->not->toContain($token)
        ->and(json_encode(DB::table('users')->get(), JSON_THROW_ON_ERROR))->not->toContain($token);
});

it('holds the page\'s two refusals to the shell\'s validation.set_password and its two flashes to the shell\'s flashes, each a line the file has', function () {
    $keys = [SetPasswordController::SET, SetPasswordController::EXPIRED, SetPasswordRequest::TOO_SHORT, SetPasswordRequest::TOO_LONG];

    foreach ([SetPasswordRequest::TOO_SHORT, SetPasswordRequest::TOO_LONG] as $key) {
        expect($key)->toStartWith('admin/shell.validation.set_password.');
    }

    foreach ([SetPasswordController::SET, SetPasswordController::EXPIRED] as $key) {
        expect($key)->toStartWith('admin/shell.flashes.');
    }

    foreach ($keys as $key) {
        expect(app('translator')->has($key, 'en', false))->toBeTrue();
    }

    expect(array_unique($keys))->toHaveCount(4)
        ->and(setpwRefusal(SetPasswordRequest::TOO_SHORT))->toContain((string) PasswordRule::FLOOR_CHARACTERS)
        ->and(setpwRefusal(SetPasswordRequest::TOO_LONG))->toContain((string) PasswordRule::CEILING_BYTES);
});

it('words the page\'s flashes and refusals in the tenant default, nobody being signed in, and not in the link\'s user\'s own choice: Greek at a default of el, through the translator', function () {
    settingsOf('luna')->update(['language' => 'el']);
    User::query()->where('email', 'owner@luna.example')->update(['locale' => 'en']);
    $token = setpwMint();
    $greek = fn (string $key): string => setpwRefusal($key, SiteLanguage::Greek);

    setpwPost($this, $token, 'short')->assertSessionHasErrors(['password' => $greek(SetPasswordRequest::TOO_SHORT)]);
    setpwPost($this, $token, str_repeat('a', 73))->assertSessionHasErrors(['password' => $greek(SetPasswordRequest::TOO_LONG)]);
    setpwPost($this, $token)->assertSessionHas('flash', ['kind' => 'success', 'message' => $greek(SetPasswordController::SET)]);
    setpwPost($this, $token)->assertSessionHas('flash', ['kind' => 'error', 'message' => $greek(SetPasswordController::EXPIRED)]);

    $keys = [SetPasswordRequest::TOO_SHORT, SetPasswordRequest::TOO_LONG, SetPasswordController::SET, SetPasswordController::EXPIRED];
    expect(array_map(fn (string $key): bool => $greek($key) !== setpwRefusal($key), $keys))->toBe([true, true, true, true])
        ->and($greek(SetPasswordRequest::TOO_SHORT))->toContain((string) PasswordRule::FLOOR_CHARACTERS)
        ->and($greek(SetPasswordRequest::TOO_LONG))->toContain((string) PasswordRule::CEILING_BYTES);
});
