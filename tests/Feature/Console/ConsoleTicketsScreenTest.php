<?php

use App\Application\Clock;
use App\Help\AfterHelpMessage;
use App\Help\HelpDesk;
use App\Help\HelpMessageStored;
use App\Http\ConsoleSession;
use App\Http\Requests\ConsoleTicketReplyRequest;
use App\Platform\Operator;
use App\Tenancy\Tenant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The console's help tickets: GET /tickets under operator lists every business's tickets, open
 * before closed and then newest activity first, twenty-five a page, a page past the end answering
 * the last, each with its subject, its business and the business's console page, its status, its
 * newest message's instant on the console's clock and whether it awaits a reply. GET /tickets/{id}
 * is one thread, its messages oldest first under their writers' names. POST /tickets/{id}/messages
 * replies as the operator, leaving the status alone; /close and /reopen move it. Each act records
 * its audit row with the ticket's id and the business, nothing of a message, and stands or falls
 * with that row. An id is digits alone, and anything else is the console's 404. The rail reads
 * Tenants, Messages, Tickets, Audit, the count of tickets awaiting a reply on Tickets.
 */

beforeEach(function () {
    $this->withoutVite();
    app()->instance(Clock::class, FixedClock::at('2026-11-17 09:00'));
    $this->hook = deskHook();
});

/**
 * Stands in for what follows a stored message, so no mail is built; answers the side and ticket
 * of each message it is handed, in order.
 *
 * @return ArrayObject<int, array{side: string, ticket: int}>
 */
function deskHook(): ArrayObject
{
    /** @var ArrayObject<int, array{side: string, ticket: int}> $calls */
    $calls = new ArrayObject;

    app()->instance(AfterHelpMessage::class, new readonly class($calls) extends AfterHelpMessage
    {
        /**
         * @param  ArrayObject<int, array{side: string, ticket: int}>  $calls
         */
        public function __construct(private ArrayObject $calls) {}

        public function handle(HelpMessageStored $stored): void
        {
            $this->calls->append(['side' => $stored->side->value, 'ticket' => $stored->ticketId]);
        }
    });

    return $calls;
}

function deskBusiness(string $slug, string $name): Tenant
{
    return Tenant::factory()->create(['slug' => $slug, 'name' => $name]);
}

/**
 * A ticket row as the business's side leaves it, its newest message at the UTC instant given and
 * written by the side given; answers its id.
 */
function deskTicket(Tenant $tenant, string $subject, string $lastUtc, string $lastSide = 'business', string $status = 'open'): int
{
    return DB::table('help_tickets')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'tenant_id' => $tenant->id,
        'subject' => $subject,
        'status' => $status,
        'last_message_at' => $lastUtc.'+00:00',
        'last_side' => $lastSide,
        'last_platform_at' => $lastSide === 'platform' ? $lastUtc.'+00:00' : null,
        'created_at' => $lastUtc.'+00:00',
        'updated_at' => $lastUtc.'+00:00',
    ]);
}

/**
 * As many tickets of the business as asked, each a minute newer than the one before and named by
 * its place from nought, so the newest is the last one made; answers their ids in the order made.
 *
 * @return list<int>
 */
function deskTickets(Tenant $tenant, int $count): array
{
    $start = new DateTimeImmutable('2026-11-15 06:00:00', new DateTimeZone('UTC'));
    $ids = [];

    for ($minute = 0; $minute < $count; $minute++) {
        $ids[] = deskTicket($tenant, 'Ticket '.$minute, $start->modify('+'.$minute.' minutes')->format('Y-m-d H:i:s'));
    }

    return $ids;
}

/**
 * The id a 404 case asks for. One that no row holds is the id after the ticket's own; a case that
 * writes {id} asks for the ticket's own id dressed as the case says, so a reading that took the
 * dress off would find the ticket and answer it.
 */
function deskAskedId(string $case, int $kept): string
{
    return $case === 'unknown' ? (string) ($kept + 1) : str_replace('{id}', (string) $kept, $case);
}

/**
 * A message on a ticket at the UTC instant given: a business user's, or the operator's given.
 */
function deskMessage(int $ticketId, string $name, string $body, string $utc, ?Operator $operator = null): int
{
    return DB::table('help_messages')->insertGetId([
        'ticket_id' => $ticketId,
        'side' => $operator === null ? 'business' : 'platform',
        'user_id' => $operator === null ? 7 : null,
        'operator_id' => $operator?->id,
        'author_name' => $name,
        'body' => $body,
        'created_at' => $utc.'+00:00',
    ]);
}

/**
 * A console page as the operator given asks for it.
 *
 * @return TestResponse<Response>
 */
function deskScreen(TestCase $test, Operator $operator, string $path = '/tickets'): TestResponse
{
    return $test->actingAs($operator, ConsoleSession::GUARD)
        ->withSession(freshConsoleSession())
        ->get(consoleUrl($path));
}

/**
 * The operator given presses an act on the thread of the id given: messages, close or reopen.
 *
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function deskPost(TestCase $test, Operator $operator, int|string $id, string $act, array $body = []): TestResponse
{
    return $test->actingAs($operator, ConsoleSession::GUARD)
        ->withSession(freshConsoleSession())
        ->from(consoleUrl('/tickets/'.$id))
        ->post(consoleUrl('/tickets/'.$id.'/'.$act), $body);
}

/**
 * The list's rows as the screen carries them.
 *
 * @param  TestResponse<Response>  $response
 * @return list<mixed>
 */
function deskRows(TestResponse $response): array
{
    $page = $response->viewData('page');

    assert(is_array($page) && is_array($page['props']) && is_array($page['props']['tickets']) && is_array($page['props']['tickets']['rows']));

    return array_values($page['props']['tickets']['rows']);
}

/**
 * A ticket's status, which side wrote last and its newest message's instant as UTC text.
 *
 * @return array{status: string, last_side: string, last_message_at: string}
 */
function deskState(int $id): array
{
    $row = DB::selectOne("select status, last_side, to_char(last_message_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as last_message_at from help_tickets where id = ?", [$id]);

    assert($row instanceof stdClass && is_string($row->status) && is_string($row->last_side) && is_string($row->last_message_at));

    return ['status' => $row->status, 'last_side' => $row->last_side, 'last_message_at' => $row->last_message_at];
}

/**
 * A ticket's messages in the order written.
 *
 * @return list<array{side: string, operator_id: int|null, author_name: string, body: string}>
 */
function deskMessages(int $id): array
{
    $rows = [];

    foreach (DB::select('select side, operator_id, author_name, body from help_messages where ticket_id = ? order by id', [$id]) as $row) {
        assert($row instanceof stdClass && is_string($row->side) && ($row->operator_id === null || is_int($row->operator_id)) && is_string($row->author_name) && is_string($row->body));

        $rows[] = ['side' => $row->side, 'operator_id' => $row->operator_id, 'author_name' => $row->author_name, 'body' => $row->body];
    }

    return $rows;
}

/**
 * Every platform audit row in the order written: its action, operator, tenant and payload.
 *
 * @return list<array{action: string, operator_id: int, tenant_id: int|null, payload: string}>
 */
function deskAudit(): array
{
    $rows = [];

    foreach (DB::select('select action, operator_id, tenant_id, payload::text as payload from platform_audit_log order by id') as $row) {
        assert($row instanceof stdClass && is_string($row->action) && is_int($row->operator_id) && ($row->tenant_id === null || is_int($row->tenant_id)) && is_string($row->payload));

        $rows[] = ['action' => $row->action, 'operator_id' => $row->operator_id, 'tenant_id' => $row->tenant_id, 'payload' => $row->payload];
    }

    return $rows;
}

it('lists every business\'s tickets open before closed, then newest activity first, each with its business, subject, instant, status and whether it awaits a reply, a suspended and a closing business\'s among them', function () {
    $pat = addOperator();
    $luna = deskBusiness('luna', 'Luna Hair Studio');
    $mare = deskBusiness('mare', 'Mare Nails');
    $gone = deskBusiness('gone', 'Gone Salon');
    $paused = deskBusiness('paused', 'Paused Barbers');
    $ending = deskBusiness('ending', 'Ending Spa');
    $waiting = deskTicket($luna, 'Moving a booking', '2026-11-16 08:00:00');
    $answered = deskTicket($mare, 'Adding a chair', '2026-11-16 10:00:00', 'platform');
    $closed = deskTicket($luna, 'An old question', '2026-11-16 12:00:00', 'business', 'closed');
    $orphan = deskTicket($gone, 'Before we left', '2026-11-15 09:00:00');
    $held = deskTicket($paused, 'Why are we paused', '2026-11-14 09:00:00');
    $leaving = deskTicket($ending, 'Our last export', '2026-11-13 09:00:00', 'platform');
    writeClosed('gone');
    DB::table('tenants')->where('id', $paused->id)->update(['status' => 'suspended', 'suspended_at' => '2026-11-14 08:00:00+00', 'suspension_reason' => 'An unpaid invoice.']);
    DB::table('tenants')->where('id', $ending->id)->update(['status' => 'closing', 'closing_at' => '2026-11-13 08:00:00+00']);

    $response = deskScreen($this, $pat);

    $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('console/Tickets')
        ->where('tickets.total', 6)
        ->where('tickets.page', 1)
        ->where('tickets.page_size', 25));

    expect(deskRows($response))->toBe([
        ['id' => $answered, 'subject' => 'Adding a chair', 'tenant' => ['name' => 'Mare Nails', 'url' => '/tenants/mare'], 'status' => 'open', 'awaiting' => false, 'last_message' => 'Monday 16 November 2026 at 12:00'],
        ['id' => $waiting, 'subject' => 'Moving a booking', 'tenant' => ['name' => 'Luna Hair Studio', 'url' => '/tenants/luna'], 'status' => 'open', 'awaiting' => true, 'last_message' => 'Monday 16 November 2026 at 10:00'],
        ['id' => $orphan, 'subject' => 'Before we left', 'tenant' => ['name' => 'Gone Salon', 'url' => '/tenants/closed/'.$gone->id], 'status' => 'open', 'awaiting' => true, 'last_message' => 'Sunday 15 November 2026 at 11:00'],
        ['id' => $held, 'subject' => 'Why are we paused', 'tenant' => ['name' => 'Paused Barbers', 'url' => '/tenants/paused'], 'status' => 'open', 'awaiting' => true, 'last_message' => 'Saturday 14 November 2026 at 11:00'],
        ['id' => $leaving, 'subject' => 'Our last export', 'tenant' => ['name' => 'Ending Spa', 'url' => '/tenants/ending'], 'status' => 'open', 'awaiting' => false, 'last_message' => 'Friday 13 November 2026 at 11:00'],
        ['id' => $closed, 'subject' => 'An old question', 'tenant' => ['name' => 'Luna Hair Studio', 'url' => '/tenants/luna'], 'status' => 'closed', 'awaiting' => false, 'last_message' => 'Monday 16 November 2026 at 14:00'],
    ]);

    expect(DB::table('tenants')->whereIn('id', [$paused->id, $ending->id])->orderBy('id')->pluck('status')->all())->toBe(['suspended', 'closing']);
});

it('keeps the same instant\'s tickets newest row first', function () {
    $pat = addOperator();
    $luna = deskBusiness('luna', 'Luna Hair Studio');
    $first = deskTicket($luna, 'First', '2026-11-16 08:00:00');
    $second = deskTicket($luna, 'Second', '2026-11-16 08:00:00');

    expect(array_column(deskRows(deskScreen($this, $pat)), 'id'))->toBe([$second, $first]);
});

it('pages twenty-five at a time, the next page carrying on where the first stopped', function () {
    $pat = addOperator();
    deskTickets(deskBusiness('luna', 'Luna Hair Studio'), 26);

    $first = deskScreen($this, $pat);
    $second = deskScreen($this, $pat, '/tickets?page=2');

    $first->assertInertia(fn (Assert $page) => $page->where('tickets.total', 26)->where('tickets.page', 1)->has('tickets.rows', 25)->where('tickets.rows.0.subject', 'Ticket 25'));
    $second->assertInertia(fn (Assert $page) => $page->where('tickets.total', 26)->where('tickets.page', 2)->has('tickets.rows', 1)->where('tickets.rows.0.subject', 'Ticket 0'));
});

it('reads a page that is {case} as the first', function (string $query) {
    $pat = addOperator();
    deskTicket(deskBusiness('luna', 'Luna Hair Studio'), 'Moving a booking', '2026-11-16 08:00:00');

    deskScreen($this, $pat, '/tickets?'.$query)->assertOk()->assertInertia(fn (Assert $page) => $page->where('tickets.page', 1)->has('tickets.rows', 1));
})->with([
    'nought' => ['page=0'],
    'below nought' => ['page=-1'],
    'not a number' => ['page=two'],
    'a fraction' => ['page=1.5'],
    'past nine digits' => ['page=9999999999'],
    'a list' => ['page[]=2'],
]);

it('answers {case} with the last page\'s tickets, saying which page it is', function (string $asked) {
    $pat = addOperator();
    deskTickets(deskBusiness('luna', 'Luna Hair Studio'), 26);

    deskScreen($this, $pat, '/tickets?page='.$asked)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('tickets.page', 2)
        ->where('tickets.total', 26)
        ->has('tickets.rows', 1)
        ->where('tickets.rows.0.subject', 'Ticket 0'));
})->with([
    'the page after the last' => ['3'],
    'the largest page read' => ['999999999'],
]);

it('answers the page an operator held with the one before it once the page\'s only ticket is gone', function () {
    $pat = addOperator();
    $ids = deskTickets(deskBusiness('luna', 'Luna Hair Studio'), 26);

    deskScreen($this, $pat, '/tickets?page=2')->assertInertia(fn (Assert $page) => $page->where('tickets.page', 2)->has('tickets.rows', 1));

    DB::table('help_tickets')->where('id', $ids[0])->delete();

    deskScreen($this, $pat, '/tickets?page=2')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('tickets.page', 1)
        ->where('tickets.total', 25)
        ->has('tickets.rows', 25)
        ->where('tickets.rows.0.subject', 'Ticket 25')
        ->where('tickets.rows.24.subject', 'Ticket 1'));
});

it('answers any page of a list with no ticket as the first, with no row', function (string $path) {
    deskScreen($this, addOperator(), $path)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('tickets.page', 1)
        ->where('tickets.total', 0)
        ->where('tickets.rows', []));
})->with([
    'the bare screen' => ['/tickets'],
    'a page past the first' => ['/tickets?page=3'],
]);

it('reads a page past the end in one count and one page statement', function () {
    deskTickets(deskBusiness('luna', 'Luna Hair Studio'), 26);

    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $read = app(HelpDesk::class)->page(7);

    expect($read['page'])->toBe(2)
        ->and($read['total'])->toBe(26)
        ->and($read['records'])->toHaveCount(1)
        ->and($statements)->toHaveCount(2)
        ->and($statements[0])->toBe('select count(*) from help_tickets')
        ->and($statements[1])->toContain('from help_tickets t')
        ->and($statements[1])->toContain('limit 25 offset 25');
});

it('reads a page off the status and activity index in its order, with no sort of its own', function () {
    $luna = deskBusiness('luna', 'Luna Hair Studio');
    deskTicket($luna, 'Moving a booking', '2026-11-16 08:00:00');
    deskTicket($luna, 'An old question', '2026-11-16 09:00:00', 'business', 'closed');

    $sql = null;
    DB::listen(function (QueryExecuted $query) use (&$sql): void {
        if (str_contains($query->sql, 'from help_tickets t') && str_contains($query->sql, 'limit')) {
            $sql = $query->sql;
        }
    });

    app(HelpDesk::class)->page(1);

    assert(is_string($sql));

    DB::statement('set local enable_seqscan = off');
    DB::statement('set local enable_sort = off');
    DB::statement('set local enable_incremental_sort = off');
    $plan = DB::selectOne('explain (format json) '.$sql);
    assert($plan instanceof stdClass);

    $text = $plan->{'QUERY PLAN'};
    assert(is_string($text));
    $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    assert(is_array($decoded));
    $nodes = [];
    $indexes = [];
    array_walk_recursive($decoded, function (mixed $value, string|int $key) use (&$nodes, &$indexes): void {
        if ($key === 'Node Type') {
            $nodes[] = $value;
        }

        if ($key === 'Index Name') {
            $indexes[] = $value;
        }
    });

    expect($indexes)->toContain('help_tickets_status_activity_index')
        ->and($nodes)->not->toContain('Sort')
        ->and($nodes)->not->toContain('Incremental Sort');
});

it('shows one thread: the ticket, its business and its messages oldest first under their writers\' names, line breaks kept', function () {
    $pat = addOperator();
    $luna = deskBusiness('luna', 'Luna Hair Studio');
    $id = deskTicket($luna, 'Moving a booking', '2026-11-16 10:00:00');
    $asked = deskMessage($id, 'Eleni Owner', "How do I move a booking?\nThanks.", '2026-11-16 08:00:00');
    $answered = deskMessage($id, 'Pat Operator', 'Drag it on the calendar.', '2026-11-16 09:00:00', $pat);
    $again = deskMessage($id, 'Eleni Owner', '<b>Done</b>, thank you https://example.com', '2026-11-16 10:00:00');

    deskScreen($this, $pat, '/tickets/'.$id)->assertOk()->assertInertia(fn (Assert $page) => $page->component('console/Ticket')
        ->where('ticket', ['id' => $id, 'subject' => 'Moving a booking', 'tenant' => ['name' => 'Luna Hair Studio', 'url' => '/tenants/luna'], 'status' => 'open', 'awaiting' => true, 'last_message' => 'Monday 16 November 2026 at 12:00'])
        ->where('messages', [
            ['id' => $asked, 'side' => 'business', 'name' => 'Eleni Owner', 'at' => 'Monday 16 November 2026 at 10:00', 'body' => "How do I move a booking?\nThanks."],
            ['id' => $answered, 'side' => 'platform', 'name' => 'Pat Operator', 'at' => 'Monday 16 November 2026 at 11:00', 'body' => 'Drag it on the calendar.'],
            ['id' => $again, 'side' => 'business', 'name' => 'Eleni Owner', 'at' => 'Monday 16 November 2026 at 12:00', 'body' => '<b>Done</b>, thank you https://example.com'],
        ]));
});

it('answers the console\'s 404 for a thread whose id is {case}', function (string $id) {
    $pat = addOperator();
    $kept = deskTicket(deskBusiness('luna', 'Luna Hair Studio'), 'Moving a booking', '2026-11-16 08:00:00');

    deskScreen($this, $pat, '/tickets/'.deskAskedId($id, $kept))
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('console/NotFound')->where('nav.2.current', false));
})->with([
    'held by no row' => ['unknown'],
    'nought' => ['0'],
    'negative' => ['-1'],
    'not a number' => ['abc'],
    'a fraction' => ['1.5'],
    'past the largest whole number' => ['99999999999999999999'],
    'a ticket\'s own after a plus sign' => ['+{id}'],
    'a ticket\'s own after a space' => [' {id}'],
    'a ticket\'s own after a nought' => ['0{id}'],
    'past thirty-two bits and held by no row' => ['2147483648'],
]);

it('hands the rail with Tickets between Messages and Audit, lit on the list and on a thread, counting the open tickets the business wrote last', function () {
    $pat = addOperator();
    $luna = deskBusiness('luna', 'Luna Hair Studio');
    $id = deskTicket($luna, 'Moving a booking', '2026-11-16 08:00:00');
    deskTicket($luna, 'Adding a chair', '2026-11-16 09:00:00');
    deskTicket($luna, 'Answered', '2026-11-16 10:00:00', 'platform');
    deskTicket($luna, 'Closed', '2026-11-16 11:00:00', 'business', 'closed');
    $rail = fn (bool $lit): array => [
        ['label' => 'Tenants', 'url' => '/', 'group' => 'Platform', 'pending' => null, 'current' => false],
        ['label' => 'Messages', 'url' => '/messages', 'group' => 'Platform', 'pending' => null, 'current' => false],
        ['label' => 'Tickets', 'url' => '/tickets', 'group' => 'Platform', 'pending' => 2, 'current' => $lit],
        ['label' => 'Audit', 'url' => '/audit', 'group' => 'Platform', 'pending' => null, 'current' => false],
    ];

    deskScreen($this, $pat)->assertInertia(fn (Assert $page) => $page->where('nav', $rail(true)));
    deskScreen($this, $pat, '/tickets/'.$id)->assertInertia(fn (Assert $page) => $page->where('nav', $rail(true)));
    deskScreen($this, $pat, '/messages')->assertInertia(fn (Assert $page) => $page->where('nav.2', $rail(false)[2]));
});

it('sends a visitor with no operator session from the list and from a thread to the door', function () {
    $id = deskTicket(deskBusiness('luna', 'Luna Hair Studio'), 'Moving a booking', '2026-11-16 08:00:00');

    $this->get(consoleUrl('/tickets'))->assertRedirect(consoleUrl('/login'));
    $this->get(consoleUrl('/tickets/'.$id))->assertRedirect(consoleUrl('/login'));
});

it('replies as the operator on a ticket that is {case}: the message under the operator\'s name, the status left alone, help_ticket_replied recorded with the ticket and the business', function (string $status) {
    $pat = addOperator();
    $luna = deskBusiness('luna', 'Luna Hair Studio');
    $id = deskTicket($luna, 'Moving a booking', '2026-11-16 08:00:00', 'business', $status);
    deskMessage($id, 'Eleni Owner', 'How do I move a booking?', '2026-11-16 08:00:00');

    deskPost($this, $pat, $id, 'messages', ['body' => "Drag it on the calendar.\n\nPat"])
        ->assertRedirect(consoleUrl('/tickets/'.$id))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => 'Your reply is sent.']);

    expect(deskState($id))->toBe(['status' => $status, 'last_side' => 'platform', 'last_message_at' => '2026-11-17 09:00:00'])
        ->and(deskMessages($id))->toBe([
            ['side' => 'business', 'operator_id' => null, 'author_name' => 'Eleni Owner', 'body' => 'How do I move a booking?'],
            ['side' => 'platform', 'operator_id' => $pat->id, 'author_name' => 'Pat Operator', 'body' => "Drag it on the calendar.\n\nPat"],
        ])
        ->and(deskAudit())->toBe([['action' => 'help_ticket_replied', 'operator_id' => $pat->id, 'tenant_id' => $luna->id, 'payload' => '{"ticket": '.$id.'}']])
        ->and(platformAuditOffences())->toBe([])
        ->and($this->hook->getArrayCopy())->toBe([['side' => 'platform', 'ticket' => $id]]);
})->with(['open' => ['open'], 'closed' => ['closed']]);

it('keeps the reply\'s words and the business\'s address out of the audit row', function () {
    $pat = addOperator();
    $id = deskTicket(deskBusiness('luna', 'Luna Hair Studio'), 'Moving a booking', '2026-11-16 08:00:00');

    deskPost($this, $pat, $id, 'messages', ['body' => 'Write to owner@luna.example about the move.'])->assertRedirect(consoleUrl('/tickets/'.$id));

    $payload = deskAudit()[0]['payload'];

    expect($payload)->toBe('{"ticket": '.$id.'}')
        ->and($payload)->not->toContain('owner@luna.example')
        ->and($payload)->not->toContain('Moving a booking');
});

it('takes a reply of 5,000 characters, counted as characters', function () {
    $pat = addOperator();
    $id = deskTicket(deskBusiness('luna', 'Luna Hair Studio'), 'Moving a booking', '2026-11-16 08:00:00');
    $reply = str_repeat("\u{03B1}", 5000);

    deskPost($this, $pat, $id, 'messages', ['body' => $reply])->assertRedirect(consoleUrl('/tickets/'.$id))->assertSessionHasNoErrors();

    expect(deskMessages($id)[0]['body'])->toBe($reply);
});

it('refuses a reply that is {case} on the field with its sentence, and writes nothing', function (mixed $body, string $sentence) {
    $pat = addOperator();
    $id = deskTicket(deskBusiness('luna', 'Luna Hair Studio'), 'Moving a booking', '2026-11-16 08:00:00');

    deskPost($this, $pat, $id, 'messages', $body === null ? [] : ['body' => $body])
        ->assertRedirect(consoleUrl('/tickets/'.$id))
        ->assertSessionHasErrors([ConsoleTicketReplyRequest::FIELD => $sentence]);

    expect(deskMessages($id))->toBe([])
        ->and(deskState($id))->toBe(['status' => 'open', 'last_side' => 'business', 'last_message_at' => '2026-11-16 08:00:00'])
        ->and(deskAudit())->toBe([])
        ->and($this->hook->getArrayCopy())->toBe([]);
})->with([
    'missing' => [null, ConsoleTicketReplyRequest::REQUIRED],
    'empty' => ['', ConsoleTicketReplyRequest::REQUIRED],
    'blank' => ["  \n\t ", ConsoleTicketReplyRequest::REQUIRED],
    'past 5,000 characters' => [str_repeat("\u{03B1}", 5001), ConsoleTicketReplyRequest::TOO_LONG],
    'not text' => [['a list'], ConsoleTicketReplyRequest::NOT_TEXT],
    'the byte FF, as a form decodes %FF' => ["\xFF", ConsoleTicketReplyRequest::NOT_TEXT],
    'holding a NUL' => ["Drag it\0 on the calendar.", ConsoleTicketReplyRequest::NOT_TEXT],
    'not UTF-8 inside' => ["Drag it \xC3\x28 on the calendar.", ConsoleTicketReplyRequest::NOT_TEXT],
]);

it('closes an open ticket and reopens it, the last message\'s instant left alone, each act recorded with the ticket and the business', function () {
    $pat = addOperator();
    $luna = deskBusiness('luna', 'Luna Hair Studio');
    $id = deskTicket($luna, 'Moving a booking', '2026-11-16 08:00:00');

    deskPost($this, $pat, $id, 'close')
        ->assertRedirect(consoleUrl('/tickets/'.$id))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => 'The ticket is closed.']);

    expect(deskState($id))->toBe(['status' => 'closed', 'last_side' => 'business', 'last_message_at' => '2026-11-16 08:00:00']);

    deskPost($this, $pat, $id, 'reopen')
        ->assertRedirect(consoleUrl('/tickets/'.$id))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => 'The ticket is open again.']);

    expect(deskState($id))->toBe(['status' => 'open', 'last_side' => 'business', 'last_message_at' => '2026-11-16 08:00:00'])
        ->and(deskMessages($id))->toBe([])
        ->and(deskAudit())->toBe([
            ['action' => 'help_ticket_closed', 'operator_id' => $pat->id, 'tenant_id' => $luna->id, 'payload' => '{"ticket": '.$id.'}'],
            ['action' => 'help_ticket_reopened', 'operator_id' => $pat->id, 'tenant_id' => $luna->id, 'payload' => '{"ticket": '.$id.'}'],
        ])
        ->and(platformAuditOffences())->toBe([])
        ->and($this->hook->getArrayCopy())->toBe([]);
});

it('leaves a ticket open and records nothing when the audit row of its Close cannot be written', function () {
    $pat = addOperator();
    $id = deskTicket(deskBusiness('luna', 'Luna Hair Studio'), 'Moving a booking', '2026-11-16 08:00:00');
    $nobody = $pat->id + 1;

    // The audit row names an operator no row holds, so its insert is refused. Written inside the
    // act's own transaction, the refusal takes the status with it. Written after it, the status
    // would stay moved, and the refusal would break the transaction around this test, so the
    // reads below would be refused in their turn.
    expect(DB::table('operators')->where('id', $nobody)->exists())->toBeFalse()
        ->and(fn () => app(HelpDesk::class)->close($id, $nobody))->toThrow(QueryException::class, 'platform_audit_log_operator_id_fkey');

    expect(deskState($id))->toBe(['status' => 'open', 'last_side' => 'business', 'last_message_at' => '2026-11-16 08:00:00'])
        ->and(deskAudit())->toBe([])
        ->and($this->hook->getArrayCopy())->toBe([]);
});

it('answers a second {act} on a ticket another tab already moved with its sentence, recording one act', function (string $act, string $from, string $to, string $moved, string $already) {
    $pat = addOperator();
    $kim = addOperator('kim@inbox.example', 'operator password', 'Kim Operator');
    $id = deskTicket(deskBusiness('luna', 'Luna Hair Studio'), 'Moving a booking', '2026-11-16 08:00:00', 'business', $from);

    deskPost($this, $pat, $id, $act)->assertSessionHas('flash', ['kind' => 'success', 'message' => $moved]);
    deskPost($this, $kim, $id, $act)
        ->assertRedirect(consoleUrl('/tickets/'.$id))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => $already]);

    expect(deskState($id)['status'])->toBe($to)
        ->and(array_column(deskAudit(), 'operator_id'))->toBe([$pat->id]);
})->with([
    'Close' => ['close', 'open', 'closed', 'The ticket is closed.', 'That ticket is already closed.'],
    'Reopen' => ['reopen', 'closed', 'open', 'The ticket is open again.', 'That ticket is already open.'],
]);

it('answers the console\'s 404 for {act} on an id that is {case}, writing nothing', function (string $act, string $status, string $id) {
    $pat = addOperator();
    $kept = deskTicket(deskBusiness('luna', 'Luna Hair Studio'), 'Moving a booking', '2026-11-16 08:00:00', 'business', $status);

    deskPost($this, $pat, deskAskedId($id, $kept), $act, ['body' => 'Drag it on the calendar.'])
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('console/NotFound'));

    expect(deskState($kept)['status'])->toBe($status)
        ->and(deskMessages($kept))->toBe([])
        ->and(deskAudit())->toBe([]);
})->with([
    'messages' => ['messages', 'open'],
    'close' => ['close', 'open'],
    'reopen' => ['reopen', 'closed'],
])->with([
    'held by no row' => ['unknown'],
    'nought' => ['0'],
    'not a number' => ['abc'],
    'past the largest whole number' => ['99999999999999999999'],
    'a ticket\'s own after a plus sign' => ['+{id}'],
    'a ticket\'s own after a space' => [' {id}'],
    'a ticket\'s own after a nought' => ['0{id}'],
    'past thirty-two bits and held by no row' => ['2147483648'],
]);

it('sends a visitor with no operator session from each act to the door, writing nothing', function (string $act) {
    $id = deskTicket(deskBusiness('luna', 'Luna Hair Studio'), 'Moving a booking', '2026-11-16 08:00:00');

    $this->post(consoleUrl('/tickets/'.$id.'/'.$act), ['body' => 'Drag it on the calendar.'])->assertRedirect(consoleUrl('/login'));

    expect(deskState($id)['status'])->toBe('open')
        ->and(deskMessages($id))->toBe([])
        ->and(deskAudit())->toBe([]);
})->with(['messages', 'close', 'reopen']);
