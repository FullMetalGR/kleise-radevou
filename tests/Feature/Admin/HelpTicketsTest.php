<?php

use App\Application\Clock;
use App\Help\AfterHelpMessage;
use App\Help\DeskTicket;
use App\Help\HelpAuthor;
use App\Help\HelpDesk;
use App\Help\HelpLedger;
use App\Help\HelpMessageStored;
use App\Help\HelpSide;
use App\Help\HelpStatus;
use App\Help\HelpTickets;
use App\Http\AdminSession;
use App\Http\Middleware\HandleInertiaRequests;
use App\Infrastructure\Db\SqlText;
use App\Models\User;
use App\Platform\Operator;
use App\Site\Brand;
use App\Tenancy\Team;
use App\Tenancy\Tenant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\Support\SeamLog;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The help tickets of a business, its side and the console's. Every signed-in user of the business
 * opens tickets, replies, lists and reads every ticket of the business and none of another's, which
 * answers as one that does not exist. A support session gets no bubble and its writes answer 404;
 * the demo account gets the answers and no tickets. Ten new tickets and sixty messages a user in
 * any hour, counted at the write. A ticket is unread for a user when a platform message is later
 * than their last opening of it. The console replies, closes and reopens, and lists open tickets
 * first. The hook that follows a message runs after the commit, and its failure fails nothing.
 *
 * A subject or a message that is not text a column can hold is refused by its field. A message
 * reaches its page numbered by its place in its thread. Messages are stored at instants that rise
 * in the order they were stored, whatever their writers' clocks said. An opening is one statement,
 * its mark the newest message's stored instant and never the clock's. A write holds its user's row
 * as one whose key stays, and an opening its ticket's by the key alone. The hook is built when a
 * message is announced and for nothing else.
 *
 * The clock is pinned and moved by hand where a test needs time to pass.
 */

const HELP_CALENDAR = '/admin/calendar';

beforeEach(function () {
    $this->withoutVite();
    helpClock('2026-11-17 09:00');
    provisionTenant('luna', ['name' => 'Luna Hair Studio', 'ownerEmail' => 'owner@luna.example'], ['staff']);
});

function helpClock(string $at): void
{
    app()->instance(Clock::class, FixedClock::at($at));
    freshRouteControllers();
}

function helpUser(string $email = 'owner@luna.example'): User
{
    return User::query()->where('email', $email)->sole();
}

/**
 * The partial reload of the Calendar the panel makes, naming the props given.
 *
 * @return TestResponse<Response>
 */
function helpReload(TestCase $test, User $user, string $only, string $slug = 'luna'): TestResponse
{
    return $test->actingAs($user)->get(tenantUrl($slug, HELP_CALENDAR), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'Calendar',
        'X-Inertia-Partial-Data' => $only,
    ]);
}

/**
 * A ticket opened through the route as the user given; answers its uuid.
 */
function helpOpen(TestCase $test, User $user, string $subject = 'Moving a booking', string $body = "How do I move a booking?\nThanks.", string $slug = 'luna'): string
{
    $test->actingAs($user)->from(tenantUrl($slug, HELP_CALENDAR))
        ->post(tenantUrl($slug, '/admin/help'), ['subject' => $subject, 'body' => $body])
        ->assertRedirect(tenantUrl($slug, HELP_CALENDAR));

    $uuid = DB::table('help_tickets')->where('subject', $subject)->orderByDesc('id')->value('uuid');
    assert(is_string($uuid));

    return $uuid;
}

function helpTicketId(string $uuid): int
{
    $id = DB::table('help_tickets')->where('uuid', $uuid)->value('id');
    assert(is_int($id));

    return $id;
}

function helpTenantId(string $slug = 'luna'): int
{
    $id = DB::table('tenants')->where('slug', $slug)->value('id');
    assert(is_int($id));

    return $id;
}

/**
 * The instants a ticket's messages were stored at, in the order they were stored, each to the
 * microsecond.
 *
 * @return array<mixed>
 */
function helpStoredAt(int $ticketId): array
{
    return array_column(DB::select(sprintf(
        'select %s as at from help_messages where ticket_id = ? order by id',
        SqlText::instantColumn('created_at'),
    ), [$ticketId]), 'at');
}

/**
 * What a ticket says of its newest message, each instant to the microsecond.
 *
 * @return array<mixed>
 */
function helpNewest(int $ticketId): array
{
    $row = DB::selectOne(sprintf(
        'select last_side, %s as last_message_at, %s as last_platform_at from help_tickets where id = ?',
        SqlText::instantColumn('last_message_at'),
        SqlText::instantColumn('last_platform_at'),
    ), [$ticketId]);

    return $row instanceof stdClass ? get_object_vars($row) : [];
}

/**
 * The row locks a run of statements asks for, in the order asked: each the table whose rows are
 * held and how strongly, as the statement words it.
 *
 * @param  array<int, string>  $statements
 * @return list<array{string, string}>
 */
function helpRowLocks(array $statements): array
{
    $locks = [];

    foreach ($statements as $statement) {
        preg_match_all('/\bfrom "?(\w+)"?(?:(?!\bfrom\b).)*?\bfor ((?:no key )?update|(?:key )?share)\b/i', (string) preg_replace('/\s+/', ' ', $statement), $found, PREG_SET_ORDER);

        foreach ($found as [, $table, $strength]) {
            $locks[] = [$table, strtolower($strength)];
        }
    }

    return $locks;
}

function helpOperator(): Operator
{
    return Operator::query()->firstOrCreate(['email' => 'pat@inbox.example'], ['name' => 'Pat Operator', 'password' => 'operator password']);
}

/**
 * A stand-in for the hook that follows a message, bound in its place: it records what it is
 * handed and the transaction depth it runs at, and throws where told. Answers the record.
 *
 * @return ArrayObject<int, array{stored: HelpMessageStored, depth: int}>
 */
function helpHook(bool $throws = false): ArrayObject
{
    /** @var ArrayObject<int, array{stored: HelpMessageStored, depth: int}> $calls */
    $calls = new ArrayObject;

    app()->instance(AfterHelpMessage::class, new readonly class($calls, $throws) extends AfterHelpMessage
    {
        /**
         * @param  ArrayObject<int, array{stored: HelpMessageStored, depth: int}>  $calls
         */
        public function __construct(private ArrayObject $calls, private bool $throws) {}

        public function handle(HelpMessageStored $stored): void
        {
            $this->calls->append(['stored' => $stored, 'depth' => DB::transactionLevel()]);

            if ($this->throws) {
                throw new RuntimeException('the hook failed');
            }
        }
    });

    return $calls;
}

it('opens a ticket for any signed-in user and sends its thread back with the write, read by its writer, the line breaks kept', function () {
    $owner = helpUser();
    $uuid = helpOpen($this, $owner);

    expect(SqlText::isUuid($uuid))->toBeTrue();

    helpReload($this, $owner, 'help_thread,help_tickets')
        ->assertOk()
        ->assertJsonPath('props.help_thread.uuid', $uuid)
        ->assertJsonPath('props.help_thread.subject', 'Moving a booking')
        ->assertJsonPath('props.help_thread.status', 'open')
        ->assertJsonPath('props.help_thread.messages.0.side', 'business')
        ->assertJsonPath('props.help_thread.messages.0.name', 'Alex Owner')
        ->assertJsonPath('props.help_thread.messages.0.mine', true)
        ->assertJsonPath('props.help_thread.messages.0.body', "How do I move a booking?\nThanks.")
        ->assertJsonPath('props.help_thread.messages.0.at', '2026-11-17T09:00:00+00:00')
        ->assertJsonPath('props.help_tickets.0.uuid', $uuid)
        ->assertJsonPath('props.help_tickets.0.last_message_at', '2026-11-17T09:00:00+00:00')
        ->assertJsonPath('props.help_tickets.0.unread', false);

    expect(DB::table('help_reads')->where('ticket_id', helpTicketId($uuid))->pluck('user_id')->all())->toBe([$owner->id])
        ->and(DB::table('help_tickets')->where('uuid', $uuid)->first(['status', 'last_side']))->toEqual((object) ['status' => 'open', 'last_side' => 'business']);
});

it('shows every ticket of the business to each of its users, whatever the role, and none of another business, whose tickets answer as nothing', function () {
    addTenantUser('sam@luna.example', 'staff');
    $owner = helpUser();
    $first = helpOpen($this, $owner, 'First');
    helpClock('2026-11-17 09:10');
    $second = helpOpen($this, helpUser('sam@luna.example'), 'Second');

    helpReload($this, helpUser('sam@luna.example'), 'help_tickets')
        ->assertJsonPath('props.help_tickets.0.uuid', $second)
        ->assertJsonPath('props.help_tickets.1.uuid', $first)
        ->assertJsonCount(2, 'props.help_tickets');

    provisionTenant('sol', ['name' => 'Sol Spa', 'ownerEmail' => 'owner@sol.example']);
    $sol = helpUser('owner@sol.example');

    helpReload($this, $sol, 'help_tickets,help', 'sol')->assertJsonPath('props.help_tickets', [])->assertJsonPath('props.help.unread', false);
    $this->actingAs($sol)->post(tenantUrl('sol', '/admin/help/'.$first.'/messages'), ['body' => 'Hello'])->assertNotFound();
    $this->actingAs($sol)->post(tenantUrl('sol', '/admin/help/'.$first.'/read'))->assertNotFound();
    $this->actingAs($sol)->get(tenantUrl('sol', '/admin/help/'.$first))->assertNotFound();

    $nobody = '00000000-0000-4000-8000-000000000000';
    $this->actingAs($owner)->post(tenantUrl('luna', '/admin/help/'.$nobody.'/messages'), ['body' => 'Hello'])->assertNotFound();
    $this->actingAs($owner)->post(tenantUrl('luna', '/admin/help/'.$nobody.'/read'))->assertNotFound();
    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/help/'.$nobody))->assertNotFound();

    // One user asks for another business's ticket and for one nobody holds, by every route and as a
    // page and as data, both through the one line below so that an answer which names its caller
    // names the same one: the two are answered alike to the byte, so neither says the ticket exists.
    $answers = fn (string $uuid): array => array_map(
        fn (TestResponse $answer): array => [$answer->getStatusCode(), str_replace($uuid, '', (string) $answer->getContent())],
        [
            $this->actingAs($sol)->post(tenantUrl('sol', '/admin/help/'.$uuid.'/messages'), ['body' => 'Hello']),
            $this->actingAs($sol)->postJson(tenantUrl('sol', '/admin/help/'.$uuid.'/messages'), ['body' => 'Hello']),
            $this->actingAs($sol)->post(tenantUrl('sol', '/admin/help/'.$uuid.'/read')),
            $this->actingAs($sol)->postJson(tenantUrl('sol', '/admin/help/'.$uuid.'/read')),
            $this->actingAs($sol)->get(tenantUrl('sol', '/admin/help/'.$uuid)),
            $this->actingAs($sol)->getJson(tenantUrl('sol', '/admin/help/'.$uuid)),
        ],
    );
    [$theirs, $nobodys] = array_map($answers, [$first, $nobody]);

    expect($theirs)->toBe($nobodys)
        ->and(array_column($theirs, 0))->toBe([404, 404, 404, 404, 404, 404]);

    expect(DB::table('help_messages')->count())->toBe(2)
        ->and(DB::table('help_reads')->where('user_id', $sol->id)->count())->toBe(0);
});

it('sends every page of a signed-in user the bubble alone, and the panel\'s props only when a reload names them', function () {
    $owner = helpUser();

    $this->actingAs($owner)->get(tenantUrl('luna', HELP_CALENDAR))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('help', ['tickets' => true, 'unread' => false, 'open' => null])
            ->missing('help_panel')
            ->missing('help_tickets')
            ->missing('help_thread')
            ->where('strings.shell.help', ['name' => 'Help', 'unread' => 'Help, a ticket has a new reply']));

    helpReload($this, $owner, 'help_panel')
        ->assertJsonPath('props.help_panel.words.refusals.demo', 'Tickets are not available on the demo account.')
        ->assertJsonPath('props.help_panel.answers.0.key', 'getting_started')
        ->assertJsonPath('props.help_panel.answers.0.answers.0.key', 'setup')
        ->assertJsonPath('props.help_panel.answers.0.answers.0.answer.0', __('admin/help_answers.getting_started.answers.setup.answer.0', [], 'en'))
        ->assertJsonMissingPath('props.help_tickets')
        ->assertJsonMissingPath('props.help');

    DB::table('users')->where('id', $owner->id)->update(['locale' => 'el']);

    helpReload($this, helpUser(), 'help_panel')
        ->assertJsonPath('props.help_panel.answers.0.title', __('admin/help_answers.getting_started.title', [], 'el'))
        ->assertJsonPath('props.help_panel.words.refusals.demo', __('admin/help.refusals.demo', [], 'el'));

    helpReload($this, helpUser(), 'help_thread')->assertJsonPath('props.help_thread', null);
});

it('gives a support session no bubble and answers each of its help routes as a page that does not exist, before reading a body', function () {
    $uuid = helpOpen($this, helpUser());
    $now = app(Clock::class)->now();
    $session = DB::table('support_sessions')->insertGetId([
        'operator_id' => helpOperator()->id,
        'tenant_id' => Tenant::query()->where('slug', 'luna')->value('id'),
        'opened_at' => SqlText::instant($now),
        'expires_at' => SqlText::instant($now->add(new DateInterval('PT60M'))),
        'created_at' => SqlText::instant($now),
    ]);
    // The owner's request shared its props into the one application the test's requests share;
    // the next request's own process would start without them.
    freshGuards();
    Inertia::flushShared();
    $this->withSession([AdminSession::SUPPORT_KEY => $session]);

    $this->get(tenantUrl('luna', HELP_CALENDAR))->assertOk()->assertInertia(fn (Assert $page) => $page->missing('help'));
    $this->post(tenantUrl('luna', '/admin/help'), ['subject' => 'From support', 'body' => 'Hello'])->assertNotFound();
    $this->post(tenantUrl('luna', '/admin/help'), [])->assertNotFound();
    $this->post(tenantUrl('luna', '/admin/help/'.$uuid.'/messages'), ['body' => 'Hello'])->assertNotFound();
    $this->post(tenantUrl('luna', '/admin/help/'.$uuid.'/read'))->assertNotFound();
    $this->get(tenantUrl('luna', '/admin/help/'.$uuid))->assertNotFound();

    expect(DB::table('help_messages')->count())->toBe(1);
});

it('gives the demo account the answers and no tickets, and refuses its ticket writes by the field they stand beside', function () {
    config(['site.demo_slug' => 'sol']);
    provisionTenant('sol', ['name' => 'Demo Salon', 'ownerEmail' => 'owner@sol.example']);
    $demo = helpUser('owner@sol.example');

    $this->actingAs($demo)->get(tenantUrl('sol', HELP_CALENDAR))
        ->assertInertia(fn (Assert $page) => $page->where('help', ['tickets' => false, 'unread' => false, 'open' => null]));

    helpReload($this, $demo, 'help_panel,help_tickets,help_thread', 'sol')
        ->assertJsonPath('props.help_panel.answers.0.key', 'getting_started')
        ->assertJsonPath('props.help_tickets', [])
        ->assertJsonPath('props.help_thread', null);

    $this->actingAs($demo)->postJson(tenantUrl('sol', '/admin/help'), ['subject' => 'Hi', 'body' => 'Hello'])
        ->assertStatus(403)
        ->assertExactJson(['code' => 'help_demo', 'message' => 'help_demo', 'data' => ['status' => 403, 'detail' => 'Tickets are not available on the demo account.', 'field' => 'subject']]);

    $nobody = '00000000-0000-4000-8000-000000000000';
    $this->actingAs($demo)->postJson(tenantUrl('sol', '/admin/help/'.$nobody.'/messages'), ['body' => 'Hello'])->assertStatus(403)->assertJsonPath('data.field', 'body');
    $this->actingAs($demo)->postJson(tenantUrl('sol', '/admin/help/'.$nobody.'/read'))->assertStatus(403)->assertJsonMissingPath('data.field');
    $this->actingAs($demo)->get(tenantUrl('sol', '/admin/help/'.$nobody))->assertNotFound();

    expect(DB::table('help_tickets')->count())->toBe(0);
});

it('holds a subject to 150 characters and a message to 5000, Greek letters each one, refusing by the field', function () {
    $owner = helpUser();
    $refuse = fn (array $body, string $field, string $detail) => $this->actingAs($owner)->postJson(tenantUrl('luna', '/admin/help'), $body)
        ->assertStatus(400)
        ->assertJsonPath('code', 'invalid_request')
        ->assertJsonPath('data.field', $field)
        ->assertJsonPath('data.detail', $detail);

    $subject = 'Write a subject of at most 150 characters.';
    $message = 'Write a message of at most 5000 characters.';
    $refuse(['subject' => '', 'body' => 'Hello'], 'subject', $subject);
    $refuse(['subject' => str_repeat('a', 151), 'body' => 'Hello'], 'subject', $subject);
    $refuse(['subject' => 'Hi', 'body' => '   '], 'body', $message);
    $refuse(['subject' => 'Hi', 'body' => str_repeat('a', 5001)], 'body', $message);

    helpOpen($this, $owner, str_repeat("\u{03B1}", 150), str_repeat("\u{03B2}", 5000));
    $uuid = DB::table('help_tickets')->value('uuid');
    assert(is_string($uuid));

    $this->actingAs($owner)->postJson(tenantUrl('luna', '/admin/help/'.$uuid.'/messages'), ['body' => str_repeat('a', 5001)])
        ->assertStatus(400)
        ->assertJsonPath('data.field', 'body')
        ->assertJsonPath('data.detail', $message);

    expect(DB::table('help_messages')->count())->toBe(1);
});

it('refuses a user\'s eleventh ticket in an hour by the subject, in their language, lets a colleague through, and lets them through again an hour on', function () {
    $owner = helpUser();
    addTenantUser('sam@luna.example', 'manager');

    foreach (range(1, 10) as $n) {
        helpOpen($this, $owner, 'Ticket '.$n);
    }

    $this->actingAs($owner)->postJson(tenantUrl('luna', '/admin/help'), ['subject' => 'Eleventh', 'body' => 'Hello'])
        ->assertStatus(429)
        ->assertExactJson(['code' => 'help_limited', 'message' => 'help_limited', 'data' => ['status' => 429, 'detail' => 'You can open at most 10 tickets an hour. Please try again later.', 'field' => 'subject']]);

    DB::table('users')->where('id', $owner->id)->update(['locale' => 'el']);
    $this->actingAs(helpUser())->postJson(tenantUrl('luna', '/admin/help'), ['subject' => 'Eleventh', 'body' => 'Hello'])
        ->assertStatus(429)
        ->assertJsonPath('data.detail', trans_choice('admin/help.refusals.tickets', 10, [], 'el'));

    helpOpen($this, helpUser('sam@luna.example'), 'From a colleague');

    helpClock('2026-11-17 09:59:59');
    $this->actingAs(helpUser())->postJson(tenantUrl('luna', '/admin/help'), ['subject' => 'Eleventh', 'body' => 'Hello'])->assertStatus(429);

    helpClock('2026-11-17 10:00');
    helpOpen($this, helpUser(), 'Eleventh');

    expect(DB::table('help_tickets')->count())->toBe(12);
});

it('refuses a user\'s sixty-first message in an hour by the message, the first of a ticket counted, across every thread, and lets them write again an hour on', function () {
    $owner = helpUser();
    $first = helpOpen($this, $owner, 'First');
    $second = helpOpen($this, $owner, 'Second');

    foreach (range(1, 58) as $n) {
        $this->actingAs($owner)->post(tenantUrl('luna', '/admin/help/'.($n % 2 === 0 ? $first : $second).'/messages'), ['body' => 'Reply '.$n])->assertRedirect();
    }

    $this->actingAs($owner)->postJson(tenantUrl('luna', '/admin/help/'.$first.'/messages'), ['body' => 'One too many'])
        ->assertStatus(429)
        ->assertExactJson(['code' => 'help_limited', 'message' => 'help_limited', 'data' => ['status' => 429, 'detail' => 'You can send at most 60 messages an hour. Please try again later.', 'field' => 'body']]);
    $this->actingAs($owner)->postJson(tenantUrl('luna', '/admin/help'), ['subject' => 'Third', 'body' => 'Hello'])
        ->assertStatus(429)
        ->assertJsonPath('data.field', 'body');

    expect(DB::table('help_messages')->where('user_id', $owner->id)->count())->toBe(60);

    helpClock('2026-11-17 09:59:59');
    $this->actingAs($owner)->postJson(tenantUrl('luna', '/admin/help/'.$first.'/messages'), ['body' => 'Still one too many'])
        ->assertStatus(429)
        ->assertJsonPath('data.field', 'body');

    helpClock('2026-11-17 10:00');
    $this->actingAs($owner)->post(tenantUrl('luna', '/admin/help/'.$first.'/messages'), ['body' => 'An hour on'])->assertRedirect();

    expect(DB::table('help_messages')->where('user_id', $owner->id)->count())->toBe(61)
        ->and(DB::table('help_messages')->where('body', 'Still one too many')->exists())->toBeFalse();
});

it('marks a ticket unread for every user when the platform writes, read again by each user\'s own opening, a colleague\'s message making nothing unread', function () {
    addTenantUser('sam@luna.example', 'staff');
    $owner = helpUser();
    $uuid = helpOpen($this, $owner);

    helpClock('2026-11-17 09:05');
    $this->actingAs(helpUser('sam@luna.example'))->post(tenantUrl('luna', '/admin/help/'.$uuid.'/messages'), ['body' => 'Me too'])->assertRedirect();

    helpReload($this, $owner, 'help,help_tickets')->assertJsonPath('props.help.unread', false)->assertJsonPath('props.help_tickets.0.unread', false);

    helpClock('2026-11-17 09:10');
    expect(app(HelpDesk::class)->reply(helpTicketId($uuid), helpOperator(), 'We can help.'))->toBeTrue();

    helpReload($this, $owner, 'help,help_tickets')->assertJsonPath('props.help.unread', true)->assertJsonPath('props.help_tickets.0.unread', true);
    helpReload($this, helpUser('sam@luna.example'), 'help')->assertJsonPath('props.help.unread', true);

    helpClock('2026-11-17 09:12');
    $this->actingAs($owner)->from(tenantUrl('luna', HELP_CALENDAR))->post(tenantUrl('luna', '/admin/help/'.$uuid.'/read'))->assertRedirect(tenantUrl('luna', HELP_CALENDAR));

    $page = helpReload($this, $owner, 'help_thread,help')
        ->assertJsonPath('props.help.unread', false)
        ->assertJsonPath('props.help_thread.messages.1.name', 'sam@luna.example')
        ->assertJsonPath('props.help_thread.messages.1.mine', false)
        ->assertJsonPath('props.help_thread.messages.2.side', 'platform')
        ->assertJsonPath('props.help_thread.messages.2.name', app(Brand::class)->name.' Support')
        ->assertJsonPath('props.help_thread.messages.2.at', '2026-11-17T09:10:00+00:00');
    helpReload($this, helpUser('sam@luna.example'), 'help')->assertJsonPath('props.help.unread', true);

    expect((string) $page->getContent())->toContain('We can help.')->not->toContain('Pat Operator');
});

it('lights the dot for a ticket among the fifty listed alone', function () {
    $owner = helpUser();
    $old = helpOpen($this, $owner, 'Old');
    helpClock('2026-11-17 09:01');
    app(HelpDesk::class)->reply(helpTicketId($old), helpOperator(), 'Answered');

    $tenant = Tenant::query()->where('slug', 'luna')->value('id');

    foreach (range(1, 50) as $n) {
        DB::table('help_tickets')->insert([
            'uuid' => sprintf('00000000-0000-4000-8000-%012d', $n),
            'tenant_id' => $tenant,
            'subject' => 'Newer '.$n,
            'last_message_at' => '2026-11-17 09:30:00+00',
            'last_side' => 'business',
            'created_at' => '2026-11-17 09:30:00+00',
            'updated_at' => '2026-11-17 09:30:00+00',
        ]);
    }

    helpReload($this, $owner, 'help,help_tickets')->assertJsonPath('props.help.unread', false)->assertJsonCount(50, 'props.help_tickets');

    DB::table('help_tickets')->where('subject', 'Newer 1')->delete();

    helpReload($this, $owner, 'help,help_tickets')->assertJsonPath('props.help.unread', true)->assertJsonPath('props.help_tickets.49.uuid', $old);
});

it('reopens a closed ticket on a business message, the platform\'s reply leaving the status as it is', function () {
    $owner = helpUser();
    $uuid = helpOpen($this, $owner);
    $id = helpTicketId($uuid);
    $desk = app(HelpDesk::class);

    expect($desk->close($id, helpOperator()->id))->toBeTrue()
        ->and($desk->close($id, helpOperator()->id))->toBeFalse()
        ->and($desk->reply($id, helpOperator(), 'Still closed'))->toBeTrue()
        ->and(DB::table('help_tickets')->where('id', $id)->value('status'))->toBe('closed');

    $this->actingAs($owner)->post(tenantUrl('luna', '/admin/help/'.$uuid.'/messages'), ['body' => 'One more thing'])->assertRedirect();

    expect(DB::table('help_tickets')->where('id', $id)->first(['status', 'last_side']))->toEqual((object) ['status' => 'open', 'last_side' => 'business'])
        ->and($desk->reopen($id, helpOperator()->id))->toBeFalse()
        ->and($desk->close(999999, helpOperator()->id))->toBeFalse()
        ->and($desk->reply(999999, helpOperator(), 'Nobody'))->toBeFalse();
});

it('opens the admin from a thread\'s link on the user\'s landing screen with the thread named, through sign-in when needed', function () {
    $owner = helpUser();
    $uuid = helpOpen($this, $owner);
    $link = tenantUrl('luna', '/admin/help/'.$uuid);
    auth()->logout();
    $this->flushSession();

    $this->get($link)->assertRedirect(tenantUrl('luna', AdminSession::LOGIN));
    $this->post(tenantUrl('luna', AdminSession::LOGIN), ['email' => 'owner@luna.example', 'password' => 'correct horse battery staple'])->assertRedirect($link);

    $landed = $this->get($link);
    $landed->assertRedirect();
    $home = (string) $landed->headers->get('Location');

    expect(str_starts_with($home, tenantUrl('luna', '/admin')))->toBeTrue();

    $this->get($home)->assertOk()->assertInertia(fn (Assert $page) => $page->where('help.open', $uuid));
    $this->get($home)->assertOk()->assertInertia(fn (Assert $page) => $page->where('help.open', null));
});

it('keeps a removed member\'s messages under the name they wrote with and drops their read marks', function () {
    $samId = addTenantUser('sam@luna.example', 'staff');
    $uuid = helpOpen($this, helpUser('sam@luna.example'));
    $tenant = Tenant::query()->where('slug', 'luna')->sole();

    expect(app(Team::class)->remove($tenant, $samId, helpUser()->id))->not->toBeNull()
        ->and(DB::table('users')->where('id', $samId)->exists())->toBeFalse()
        ->and(DB::table('help_reads')->where('user_id', $samId)->count())->toBe(0);

    helpReload($this, helpUser(), 'help_thread,help_tickets')->assertJsonPath('props.help_tickets.0.uuid', $uuid);
    $this->actingAs(helpUser())->post(tenantUrl('luna', '/admin/help/'.$uuid.'/read'))->assertRedirect();

    helpReload($this, helpUser(), 'help_thread')
        ->assertJsonPath('props.help_thread.messages.0.name', 'sam@luna.example')
        ->assertJsonPath('props.help_thread.messages.0.mine', false);
});

it('hands each stored message to the hook once its transaction has committed, from either side, and never a close or a reopen', function () {
    $hook = helpHook();
    $depth = DB::transactionLevel();
    $owner = helpUser();
    $uuid = helpOpen($this, $owner);
    $id = helpTicketId($uuid);

    $this->actingAs($owner)->post(tenantUrl('luna', '/admin/help/'.$uuid.'/messages'), ['body' => 'More'])->assertRedirect();
    $desk = app(HelpDesk::class);
    $desk->reply($id, helpOperator(), 'Answer');
    $desk->close($id, helpOperator()->id);
    $desk->reopen($id, helpOperator()->id);

    $calls = $hook->getArrayCopy();
    $stored = array_map(fn (array $call): HelpMessageStored => $call['stored'], $calls);

    expect(array_column($calls, 'depth'))->toBe([$depth, $depth, $depth])
        ->and(array_map(fn (HelpMessageStored $s): array => [$s->opened, $s->side, $s->body, $s->ticketUuid, $s->subject, $s->authorName], $stored))->toBe([
            [true, HelpSide::Business, "How do I move a booking?\nThanks.", $uuid, 'Moving a booking', 'Alex Owner'],
            [false, HelpSide::Business, 'More', $uuid, 'Moving a booking', 'Alex Owner'],
            [false, HelpSide::Platform, 'Answer', $uuid, 'Moving a booking', 'Pat Operator'],
        ])
        ->and($stored[0]->role?->value)->toBe('owner')
        ->and($stored[0]->userId)->toBe($owner->id)
        ->and($stored[2]->operatorId)->toBe(helpOperator()->id)
        ->and($stored[2]->role)->toBeNull()
        ->and($stored[0]->tenantId)->toBe(Tenant::query()->where('slug', 'luna')->value('id'))
        ->and(DB::table('help_messages')->where('id', $stored[1]->messageId)->value('body'))->toBe('More');
});

it('keeps a failing hook from the write: the message stands, the answer is the same, and the log names the ticket and the message alone', function () {
    helpHook(true);
    $logger = new class extends AbstractLogger
    {
        /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
        }
    };
    app()->instance(LoggerInterface::class, $logger);

    $uuid = helpOpen($this, helpUser(), 'Failing hook', 'A secret message');
    $id = helpTicketId($uuid);
    $message = DB::table('help_messages')->where('ticket_id', $id)->value('id');

    expect($logger->records)->toBe([['level' => 'error', 'message' => HelpLedger::HOOK_FAILED, 'context' => ['ticket' => $id, 'message' => $message, 'exception' => RuntimeException::class]]])
        ->and(json_encode($logger->records))->not->toContain('secret');
});

it('lists every business\'s tickets for the console, open before closed and then the newest activity, with the count awaiting a reply', function () {
    provisionTenant('sol', ['name' => 'Sol Spa', 'ownerEmail' => 'owner@sol.example']);
    $a = helpOpen($this, helpUser(), 'A');
    helpClock('2026-11-17 09:10');
    $b = helpOpen($this, helpUser('owner@sol.example'), 'B', 'Hello', 'sol');
    helpClock('2026-11-17 09:20');
    $c = helpOpen($this, helpUser(), 'C');
    helpClock('2026-11-17 09:30');
    $desk = app(HelpDesk::class);
    $desk->reply(helpTicketId($a), helpOperator(), 'Answered');
    $desk->close(helpTicketId($c), helpOperator()->id);

    $row = fn (DeskTicket $t): array => [$t->uuid, $t->tenantName, $t->tenantSlug, $t->status, $t->awaiting, $t->lastMessageAt->format('H:i')];
    $page = $desk->page(1);
    $rows = array_map($row, $page['records']);

    // A page asked for past the list's end is the list's last page, here its only one.
    $past = $desk->page(2);

    expect($rows)->toBe([
        [$a, 'Luna Hair Studio', 'luna', HelpStatus::Open, false, '09:30'],
        [$b, 'Sol Spa', 'sol', HelpStatus::Open, true, '09:10'],
        [$c, 'Luna Hair Studio', 'luna', HelpStatus::Closed, false, '09:20'],
    ])
        ->and($page['total'])->toBe(3)
        ->and($desk->awaiting())->toBe(1)
        ->and($page['page'])->toBe(1)
        ->and(array_map($row, $past['records']))->toBe($rows)
        ->and([$past['total'], $past['page']])->toBe([3, 1]);

    $thread = $desk->thread(helpTicketId($a));

    expect($thread?->ticket->subject)->toBe('A')
        ->and(array_map(fn ($m): array => [$m->side, $m->authorName, $m->body], $thread->messages ?? []))->toBe([
            [HelpSide::Business, 'Alex Owner', "How do I move a booking?\nThanks."],
            [HelpSide::Platform, 'Pat Operator', 'Answered'],
        ])
        ->and($desk->thread(999999))->toBeNull()
        ->and(DB::table('help_tickets')->where('uuid', $c)->value('last_message_at'))->toContain('09:20');
});

it('refuses a subject or a message that is not text a column can hold, bytes that are no UTF-8 or a NUL, by its field and its sentence, nothing stored', function () {
    $owner = helpUser();
    $uuid = helpOpen($this, $owner);
    $subject = 'Write a subject of at most 150 characters.';
    $message = 'Write a message of at most 5000 characters.';
    $refuse = fn (string $path, array $form, string $field, string $detail) => $this->actingAs($owner)->post(tenantUrl('luna', $path), $form)
        ->assertStatus(400)
        ->assertJsonPath('code', 'invalid_request')
        ->assertJsonPath('data.field', $field)
        ->assertJsonPath('data.detail', $detail);

    $refuse('/admin/help', ['subject' => "\xFF", 'body' => 'Hello'], 'subject', $subject);
    $refuse('/admin/help', ['subject' => "Mov\0ing", 'body' => 'Hello'], 'subject', $subject);
    $refuse('/admin/help', ['subject' => 'Hi', 'body' => "\xFF"], 'body', $message);
    $refuse('/admin/help', ['subject' => 'Hi', 'body' => "Hel\0lo"], 'body', $message);
    $refuse('/admin/help/'.$uuid.'/messages', ['body' => "\xFF"], 'body', $message);
    $refuse('/admin/help/'.$uuid.'/messages', ['body' => "Hel\0lo"], 'body', $message);

    expect(DB::table('help_tickets')->count())->toBe(1)
        ->and(DB::table('help_messages')->count())->toBe(1);
});

it('numbers a thread\'s messages by their place in it, from one in every business, and sends the business no number that counts the platform\'s rows', function () {
    $owner = helpUser();
    $first = helpOpen($this, $owner, 'First');
    $this->actingAs($owner)->post(tenantUrl('luna', '/admin/help/'.$first.'/messages'), ['body' => 'More'])->assertRedirect();
    $this->actingAs($owner)->post(tenantUrl('luna', '/admin/help/'.$first.'/messages'), ['body' => 'And more'])->assertRedirect();

    expect(helpReload($this, $owner, 'help_thread')->json('props.help_thread.messages.*.id'))->toBe([1, 2, 3]);

    // More of luna's rows, so the other business's serials stand well past any place in a thread.
    foreach (range(1, 4) as $n) {
        helpOpen($this, $owner, 'Padding '.$n);
    }

    provisionTenant('sol', ['name' => 'Sol Spa', 'ownerEmail' => 'owner@sol.example']);
    $sol = helpUser('owner@sol.example');
    $theirs = helpOpen($this, $sol, 'Theirs', 'Hello', 'sol');
    $this->actingAs($sol)->post(tenantUrl('sol', '/admin/help/'.$theirs.'/messages'), ['body' => 'Again'])->assertRedirect();

    $page = helpReload($this, $sol, 'help,help_tickets,help_thread', 'sol');
    $props = [$page->json('props.help'), $page->json('props.help_tickets'), $page->json('props.help_thread')];
    $serials = array_filter(DB::table('help_messages')->where('ticket_id', helpTicketId($theirs))->pluck('id')->push(helpTicketId($theirs))->all(), 'is_int');
    $numbers = [];
    array_walk_recursive($props, function (mixed $value) use (&$numbers): void {
        if (is_int($value)) {
            $numbers[] = $value;
        }
    });

    expect($page->json('props.help_thread.messages.*.id'))->toBe([1, 2])
        ->and($page->json('props.help_thread.messages.*.body'))->toBe(['Hello', 'Again'])
        ->and($page->json('props.help_tickets.*.uuid'))->toBe([$theirs])
        ->and($numbers)->toBe([1, 2])
        ->and(count($serials))->toBe(3)
        ->and(array_intersect($serials, [1, 2, 3]))->toBe([]);
});

it('keeps the console list\'s index in the very order the list asks for, so a page of it is read off the index and nothing is sorted', function () {
    $index = 'help_tickets_status_activity_index';
    $definition = DB::table('pg_indexes')->where('schemaname', DB::raw('current_schema()'))->where('indexname', $index)->value('indexdef');

    expect($definition)->toBeString()
        ->toStartWith('CREATE INDEX '.$index.' ON ')
        ->toEndWith("help_tickets USING btree (((status = 'open'::text)) DESC, last_message_at DESC, id DESC)");

    /** @var ArrayObject<int, QueryExecuted> $asked */
    $asked = new ArrayObject;
    DB::listen(function (QueryExecuted $query) use ($asked): void {
        if (str_contains($query->sql, 'order by')) {
            $asked->append($query);
        }
    });
    app(HelpDesk::class)->page(1);

    expect(count($asked))->toBe(1);

    // The list as the console asks for it, planned with sorting priced out of reach: the planner
    // then reads the rows in an index's order wherever an index keeps the order asked for, and
    // sorts only where none does, as it must once this index is gone.
    $list = $asked[0] ?? throw new LogicException('The console\'s list asked for no order.');
    $plan = fn (): string => json_encode($list->connection->select('explain '.$list->sql, $list->bindings), JSON_THROW_ON_ERROR);
    $list->connection->statement('set local enable_sort = off');

    expect($plan())->toContain($index)->not->toContain('Sort');

    $list->connection->statement('drop index '.$index);

    expect($plan())->toContain('Sort');
});

it('stores a message later than every message stored before it, whatever its writer\'s clock says, and tells the ticket and the hook the instant stored', function () {
    $hook = helpHook();
    $owner = helpUser();
    $uuid = helpOpen($this, $owner);
    $id = helpTicketId($uuid);

    helpClock('2026-11-17 09:10');
    app(HelpDesk::class)->reply($id, helpOperator(), 'We can help.');

    // The user's reply by a clock that stands behind the message already stored.
    helpClock('2026-11-17 09:05');
    $this->actingAs($owner)->post(tenantUrl('luna', '/admin/help/'.$uuid.'/messages'), ['body' => 'Thanks'])->assertRedirect();

    $page = helpReload($this, $owner, 'help_thread,help,help_tickets');

    expect($page->json('props.help_thread.messages.*.body'))->toBe(["How do I move a booking?\nThanks.", 'We can help.', 'Thanks'])
        ->and($page->json('props.help_tickets.0.last_message_at'))->toBe('2026-11-17T09:10:00+00:00')
        ->and($page->json('props.help.unread'))->toBeFalse()
        ->and(helpStoredAt($id))->toBe(['2026-11-17 09:00:00.000000+00:00', '2026-11-17 09:10:00.000000+00:00', '2026-11-17 09:10:00.000001+00:00'])
        ->and(helpNewest($id))->toBe(['last_side' => 'business', 'last_message_at' => '2026-11-17 09:10:00.000001+00:00', 'last_platform_at' => '2026-11-17 09:10:00.000000+00:00']);

    // The platform's reply by a clock behind the user's message: stored after it, and so later
    // than the mark that message left, which is what lights the user's dot.
    helpClock('2026-11-17 09:07');
    app(HelpDesk::class)->reply($id, helpOperator(), 'One more thing.');

    expect(helpStoredAt($id)[3] ?? null)->toBe('2026-11-17 09:10:00.000002+00:00')
        ->and(helpNewest($id))->toBe(['last_side' => 'platform', 'last_message_at' => '2026-11-17 09:10:00.000002+00:00', 'last_platform_at' => '2026-11-17 09:10:00.000002+00:00']);

    helpReload($this, $owner, 'help,help_tickets')->assertJsonPath('props.help.unread', true)->assertJsonPath('props.help_tickets.0.unread', true);

    // The user's opening by that same slow clock still reads every message stored.
    $this->actingAs($owner)->from(tenantUrl('luna', HELP_CALENDAR))->post(tenantUrl('luna', '/admin/help/'.$uuid.'/read'))->assertRedirect(tenantUrl('luna', HELP_CALENDAR));
    $page = helpReload($this, $owner, 'help_thread,help');

    expect($page->json('props.help_thread.messages.*.body'))->toBe(["How do I move a booking?\nThanks.", 'We can help.', 'Thanks', 'One more thing.'])
        ->and($page->json('props.help.unread'))->toBeFalse()
        ->and(array_map(fn (array $call): string => SqlText::instant($call['stored']->at), $hook->getArrayCopy()))->toBe(helpStoredAt($id));
});

it('marks a thread read in one statement, at the newest message\'s stored instant whatever the clock says and never back, and answers no such ticket for another business\'s, an unknown and a deleted one', function () {
    $owner = helpUser();
    $uuid = helpOpen($this, $owner);
    $id = helpTicketId($uuid);
    provisionTenant('sol', ['name' => 'Sol Spa', 'ownerEmail' => 'owner@sol.example']);
    $sol = helpUser('owner@sol.example');
    $luna = helpTenantId();
    $other = helpTenantId('sol');
    $mark = fn (): mixed => DB::scalar(sprintf('select %s from help_reads where ticket_id = ? and user_id = ?', SqlText::instantColumn('read_at')), [$id, $owner->id]);

    /** @var ArrayObject<int, string> $asked */
    $asked = new ArrayObject;
    DB::listen(function (QueryExecuted $query) use ($asked): void {
        $asked->append($query->sql);
    });

    // The answer of a mark and how many statements it took.
    $opening = function (int $tenantId, int $userId, string $uuid) use ($asked): array {
        $tickets = app(HelpTickets::class);
        $before = count($asked);

        return [$tickets->markRead($tenantId, $userId, $uuid), count($asked) - $before];
    };

    // The writer's own mark gone, so the opening writes the row: at the newest message's instant,
    // not at the later instant the clock gives.
    DB::table('help_reads')->delete();
    helpClock('2026-11-17 09:30');

    expect($opening($luna, $owner->id, $uuid))->toBe([true, 1])
        ->and($mark())->toBe('2026-11-17 09:00:00.000000+00:00');

    // A mark standing later than the newest message is left where it stands.
    DB::table('help_reads')->update(['read_at' => '2026-11-17 09:05:00+00']);

    expect($opening($luna, $owner->id, $uuid))->toBe([true, 1])
        ->and($mark())->toBe('2026-11-17 09:05:00.000000+00:00');

    // A message stored at an instant the reader's clock has not reached is read by the opening.
    helpClock('2026-11-17 09:40');
    app(HelpDesk::class)->reply($id, helpOperator(), 'We can help.');
    helpClock('2026-11-17 09:35');

    expect(app(HelpTickets::class)->unread($luna, $owner->id))->toBeTrue()
        ->and($opening($luna, $owner->id, $uuid))->toBe([true, 1])
        ->and($mark())->toBe('2026-11-17 09:40:00.000000+00:00')
        ->and(app(HelpTickets::class)->unread($luna, $owner->id))->toBeFalse();

    expect($opening($other, $sol->id, $uuid))->toBe([false, 1])
        ->and($opening($luna, $owner->id, '00000000-0000-4000-8000-000000000000'))->toBe([false, 1])
        ->and($opening($luna, $owner->id, 'no uuid at all'))->toBe([false, 0])
        ->and(DB::table('help_reads')->pluck('user_id')->all())->toBe([$owner->id]);

    DB::table('help_tickets')->where('id', $id)->delete();

    expect($opening($luna, $owner->id, $uuid))->toBe([false, 1])
        ->and(DB::table('help_reads')->count())->toBe(0);
});

it('lights the dot for a platform reply stored after an opening, though the reply\'s clock stands behind the opening\'s', function () {
    $owner = helpUser();
    $uuid = helpOpen($this, $owner);
    $id = helpTicketId($uuid);

    // The newest message is the user's own, of 09:00. They open the thread half an hour on.
    helpClock('2026-11-17 09:30');
    $this->actingAs($owner)->from(tenantUrl('luna', HELP_CALENDAR))->post(tenantUrl('luna', '/admin/help/'.$uuid.'/read'))->assertRedirect(tenantUrl('luna', HELP_CALENDAR));

    // The platform replies by a clock ten minutes behind that opening's.
    helpClock('2026-11-17 09:20');
    app(HelpDesk::class)->reply($id, helpOperator(), 'We can help.');

    expect(helpStoredAt($id))->toBe(['2026-11-17 09:00:00.000000+00:00', '2026-11-17 09:20:00.000000+00:00']);

    helpReload($this, $owner, 'help,help_tickets')->assertJsonPath('props.help.unread', true)->assertJsonPath('props.help_tickets.0.unread', true);

    // Their next opening reads it, by a clock further behind still.
    helpClock('2026-11-17 09:10');
    $this->actingAs($owner)->from(tenantUrl('luna', HELP_CALENDAR))->post(tenantUrl('luna', '/admin/help/'.$uuid.'/read'))->assertRedirect(tenantUrl('luna', HELP_CALENDAR));

    helpReload($this, $owner, 'help,help_tickets')->assertJsonPath('props.help.unread', false)->assertJsonPath('props.help_tickets.0.unread', false);
});

/*
 * A read mark's row refers to its user and to its ticket, and the server holds each row referred
 * to by its key while the mark is written. A reply holds its writer's row and then asks for the
 * whole of the ticket's. Were the writer's row held whole as well, an opening that reached the
 * ticket first would wait for the user's row while the reply waited for the ticket's, each on the
 * other. Held as a row whose key stays, the user's row lets the mark's reference through; and the
 * opening holds the ticket by its key alone, so it waits only for a write that holds the whole
 * row, whose message it then reads, and two users' openings of one ticket do not wait on each
 * other.
 */
it('holds a writer\'s row as one whose key stays, before the ticket\'s, and an opening\'s ticket by its key alone, so an opening and a reply of one user at once cannot wait on each other', function () {
    helpHook();
    $owner = helpUser();
    $uuid = helpOpen($this, $owner);
    $luna = helpTenantId();
    $author = new HelpAuthor($owner->id, $owner->name, $owner->role);
    $tickets = app(HelpTickets::class);

    /** @var ArrayObject<int, string> $asked */
    $asked = new ArrayObject;
    DB::listen(function (QueryExecuted $query) use ($asked): void {
        $asked->append($query->sql);
    });

    // The row locks a call asks for, in the order it asks.
    $locks = function (Closure $call) use ($asked): array {
        $before = count($asked);
        $call();

        return helpRowLocks(array_slice($asked->getArrayCopy(), $before));
    };

    expect($locks(fn (): bool => $tickets->markRead($luna, $owner->id, $uuid)))->toBe([['help_tickets', 'key share']])
        ->and($locks(fn (): bool => $tickets->reply($luna, $author, $uuid, 'Thanks')))->toBe([['users', 'no key update'], ['help_tickets', 'update']])
        ->and($locks(fn (): string => $tickets->open($luna, $author, 'Another question', 'And one more thing.')))->toBe([['users', 'no key update']]);
});

it('builds the hook when a message is announced and for no page, whichever of the help props the page carries', function () {
    $built = 0;
    app()->bind(AfterHelpMessage::class, function () use (&$built): AfterHelpMessage {
        $built++;

        return new readonly class extends AfterHelpMessage
        {
            public function __construct() {}

            public function handle(HelpMessageStored $stored): void {}
        };
    });
    $owner = helpUser();

    $this->actingAs($owner)->get(tenantUrl('luna', HELP_CALENDAR))->assertOk()->assertInertia(fn (Assert $page) => $page->where('help.tickets', true));
    helpReload($this, $owner, 'help,help_panel,help_tickets,help_thread')
        ->assertOk()
        ->assertJsonPath('props.help.unread', false)
        ->assertJsonPath('props.help_tickets', [])
        ->assertJsonPath('props.help_thread', null);

    expect($built)->toBe(0);

    $uuid = helpOpen($this, $owner);

    expect($built)->toBe(1);

    helpReload($this, $owner, 'help,help_tickets,help_thread')
        ->assertJsonPath('props.help_tickets.0.uuid', $uuid)
        ->assertJsonPath('props.help_thread.uuid', $uuid);
    $this->actingAs($owner)->post(tenantUrl('luna', '/admin/help/'.$uuid.'/read'))->assertRedirect();

    expect($built)->toBe(1);

    $this->actingAs($owner)->post(tenantUrl('luna', '/admin/help/'.$uuid.'/messages'), ['body' => 'More'])->assertRedirect();

    expect($built)->toBe(2);
});

it('keeps a hook that cannot be built from the write as it keeps one that throws: the message stands, the answer is the same, and the log holds one line with no text, name or address in it', function () {
    app()->bind(AfterHelpMessage::class, fn () => throw new RuntimeException('No hook for Alex Owner at owner@luna.example: A secret message'));
    $log = new SeamLog;
    app()->instance(LoggerInterface::class, $log);
    $owner = helpUser();

    $uuid = helpOpen($this, $owner, 'Failing hook', 'A secret message');
    $id = helpTicketId($uuid);
    $message = DB::table('help_messages')->where('ticket_id', $id)->value('id');

    helpReload($this, $owner, 'help_thread')
        ->assertJsonPath('props.help_thread.uuid', $uuid)
        ->assertJsonPath('props.help_thread.messages.0.body', 'A secret message');

    expect($log->records)->toBe([['level' => 'error', 'message' => HelpLedger::HOOK_FAILED, 'context' => ['ticket' => $id, 'message' => $message, 'exception' => RuntimeException::class]]])
        ->and($log->text())->not->toContain('secret')
        ->not->toContain('Failing hook')
        ->not->toContain('Alex Owner')
        ->not->toContain('luna.example');
});

it('answers a new ticket by the tickets limit where the user has reached both limits, and a reply by the messages limit', function () {
    $owner = helpUser();
    $uuids = array_map(fn (int $n): string => helpOpen($this, $owner, 'Ticket '.$n), range(1, 10));

    foreach (range(1, 50) as $n) {
        $this->actingAs($owner)->post(tenantUrl('luna', '/admin/help/'.$uuids[$n % 10].'/messages'), ['body' => 'Reply '.$n])->assertRedirect();
    }

    $this->actingAs($owner)->postJson(tenantUrl('luna', '/admin/help'), ['subject' => 'Eleventh', 'body' => 'Hello'])
        ->assertStatus(429)
        ->assertExactJson(['code' => 'help_limited', 'message' => 'help_limited', 'data' => ['status' => 429, 'detail' => 'You can open at most 10 tickets an hour. Please try again later.', 'field' => 'subject']]);
    $this->actingAs($owner)->postJson(tenantUrl('luna', '/admin/help/'.$uuids[0].'/messages'), ['body' => 'One too many'])
        ->assertStatus(429)
        ->assertExactJson(['code' => 'help_limited', 'message' => 'help_limited', 'data' => ['status' => 429, 'detail' => 'You can send at most 60 messages an hour. Please try again later.', 'field' => 'body']]);

    expect(DB::table('help_tickets')->count())->toBe(10)
        ->and(DB::table('help_messages')->where('user_id', $owner->id)->count())->toBe(60);
});
