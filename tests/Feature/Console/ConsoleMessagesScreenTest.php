<?php

use App\Application\Clock;
use App\Http\ConsoleSession;
use App\Platform\ContactInbox;
use App\Platform\Operator;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The console's contact messages: GET /messages under operator lists the contact form's messages
 * newest first, twenty-five a page, the view open by default, the messages with no archived_at,
 * or archived on request, a malformed value reading as unset; each message carries its received
 * instant spelled on the console's clock, the zone left to the top bar, the sender's name and
 * address, the language it was sent in and the message in full. POST /messages/{id}/archive
 * writes archived_at as the clock's now where it is null and records contact_archived with the
 * message's id under message in the same transaction; a repeat writes and records nothing and
 * answers its sentence, and an id no row holds is the console's
 * 404. The rail reads Tenants, Messages, Audit.
 */

beforeEach(function () {
    $this->withoutVite();
    app()->instance(Clock::class, FixedClock::at('2026-10-20 09:00'));
});

/**
 * A contact message as the form's insert writes it, at the UTC instant given, archived where an
 * instant is named for that; answers its id.
 *
 * @param  array<string, string|null>  $overrides
 */
function inboxRow(string $utc, array $overrides = []): int
{
    return DB::table('contact_messages')->insertGetId([
        'name' => 'Maria Papadopoulou',
        'email' => 'maria@salon.example',
        'message' => 'Do you take bookings for two chairs?',
        'locale' => 'en',
        'created_at' => $utc.'+00:00',
        'archived_at' => null,
        ...$overrides,
    ]);
}

/**
 * The messages screen as the operator given asks for it, with the query given.
 *
 * @param  array<string, string>|string  $query  the query as pairs, or as the raw text after the question mark
 * @return TestResponse<Response>
 */
function messagesScreen(TestCase $test, Operator $operator, array|string $query = []): TestResponse
{
    $text = is_string($query) ? $query : http_build_query($query);

    return $test->actingAs($operator, ConsoleSession::GUARD)
        ->withSession(freshConsoleSession())
        ->get(consoleUrl('/messages'.($text === '' ? '' : '?'.$text)));
}

/**
 * The messages the screen carries, each without its id, which the database chose.
 *
 * @param  TestResponse<Response>  $response
 * @return list<mixed>
 */
function messagesScreenRows(TestResponse $response): array
{
    $page = $response->viewData('page');

    assert(is_array($page) && is_array($page['props']) && is_array($page['props']['messages']) && is_array($page['props']['messages']['rows']));

    return array_map(fn (mixed $row): mixed => is_array($row) ? array_diff_key($row, ['id' => true]) : $row, array_values($page['props']['messages']['rows']));
}

/**
 * The operator given presses Archive on the id given from the screen at the path given: the press
 * carries the screen's query, its view and page, on the archive's own address, and the screen as
 * its Referer.
 *
 * @return TestResponse<Response>
 */
function archiveMessage(TestCase $test, Operator $operator, int|string $id, string $from = '/messages'): TestResponse
{
    $query = parse_url($from, PHP_URL_QUERY);

    return $test->actingAs($operator, ConsoleSession::GUARD)
        ->withSession(freshConsoleSession())
        ->from(consoleUrl($from))
        ->post(consoleUrl('/messages/'.$id.'/archive'.(is_string($query) && $query !== '' ? '?'.$query : '')));
}

/**
 * Each message's archived_at as UTC text, by id.
 *
 * @return array<int, string|null>
 */
function inboxArchivedAt(): array
{
    $rows = [];

    foreach (DB::select("select id, to_char(archived_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as archived_at from contact_messages order by id") as $row) {
        assert($row instanceof stdClass && is_int($row->id) && ($row->archived_at === null || is_string($row->archived_at)));

        $rows[$row->id] = $row->archived_at;
    }

    return $rows;
}

/**
 * Every platform audit row in the order written: its action, operator, tenant and payload.
 *
 * @return list<array{action: string, operator_id: int, tenant_id: int|null, payload: string}>
 */
function inboxAudit(): array
{
    $rows = [];

    foreach (DB::select('select action, operator_id, tenant_id, payload::text as payload from platform_audit_log order by id') as $row) {
        assert($row instanceof stdClass && is_string($row->action) && is_int($row->operator_id) && ($row->tenant_id === null || is_int($row->tenant_id)) && is_string($row->payload));

        $rows[] = ['action' => $row->action, 'operator_id' => $row->operator_id, 'tenant_id' => $row->tenant_id, 'payload' => $row->payload];
    }

    return $rows;
}

it('lists the open messages newest first, each with its instant on the console\'s clock, the sender, the language and the message whole', function () {
    $pat = addOperator();
    inboxRow('2026-10-19 06:00:00', ['name' => 'Nikos Georgiou', 'email' => 'nikos@clinic.example', 'locale' => 'el', 'message' => "First line\nSecond line\r\n\r\nAfter a gap"]);
    inboxRow('2026-10-19 08:15:00');
    inboxRow('2026-10-18 21:00:00', ['archived_at' => '2026-10-19 07:00:00+00:00']);

    $response = messagesScreen($this, $pat);

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('console/Messages')
            ->where('filters', ['view' => 'open'])
            ->where('messages.total', 2)
            ->where('messages.page', 1)
            ->where('messages.page_size', 25)
            ->where('zone', 'Europe/Athens'));

    expect(messagesScreenRows($response))->toBe([
        ['received' => 'Monday 19 October 2026 at 11:15', 'name' => 'Maria Papadopoulou', 'email' => 'maria@salon.example', 'language' => 'en', 'message' => 'Do you take bookings for two chairs?'],
        ['received' => 'Monday 19 October 2026 at 09:00', 'name' => 'Nikos Georgiou', 'email' => 'nikos@clinic.example', 'language' => 'el', 'message' => "First line\nSecond line\r\n\r\nAfter a gap"],
    ]);
});

it('lists the archived messages alone on the archived view, newest first by arrival', function () {
    $pat = addOperator();
    inboxRow('2026-10-19 06:00:00');
    inboxRow('2026-10-17 06:00:00', ['name' => 'Older Sender', 'archived_at' => '2026-10-19 09:00:00+00:00']);
    inboxRow('2026-10-18 06:00:00', ['name' => 'Newer Sender', 'archived_at' => '2026-10-18 07:00:00+00:00']);

    $response = messagesScreen($this, $pat, ['view' => 'archived']);

    $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('console/Messages')
        ->where('filters', ['view' => 'archived'])
        ->where('messages.total', 2));

    expect(array_column(messagesScreenRows($response), 'name'))->toBe(['Newer Sender', 'Older Sender']);
});

it('reads a view that is {case} as unset, the open messages', function (string $query) {
    $pat = addOperator();
    inboxRow('2026-10-19 06:00:00', ['name' => 'Open Sender']);
    inboxRow('2026-10-18 06:00:00', ['name' => 'Archived Sender', 'archived_at' => '2026-10-19 07:00:00+00:00']);

    $response = messagesScreen($this, $pat, $query);

    $response->assertOk()->assertInertia(fn (Assert $page) => $page->where('filters', ['view' => 'open'])->where('messages.total', 1));

    expect(array_column(messagesScreenRows($response), 'name'))->toBe(['Open Sender']);
})->with([
    'unknown' => ['view=all'],
    'in another case' => ['view=Archived'],
    'empty' => ['view='],
    'a list' => ['view%5B%5D=archived'],
]);

it('keeps the same instant\'s messages in the order they arrived, the later first', function () {
    $pat = addOperator();
    inboxRow('2026-10-19 06:00:00', ['name' => 'First In']);
    inboxRow('2026-10-19 06:00:00', ['name' => 'Second In']);

    expect(array_column(messagesScreenRows(messagesScreen($this, $pat)), 'name'))->toBe(['Second In', 'First In']);
});

it('pages twenty-five at a time, the next page carrying on where the first stopped, the view kept', function () {
    $pat = addOperator();
    $start = new DateTimeImmutable('2026-10-01 08:00:00', new DateTimeZone('UTC'));

    foreach (range(0, 25) as $minutes) {
        inboxRow($start->add(new DateInterval('PT'.$minutes.'M'))->format('Y-m-d H:i:s'), ['archived_at' => '2026-10-19 07:00:00+00:00']);
    }

    inboxRow('2026-10-19 06:00:00');

    messagesScreen($this, $pat, ['view' => 'archived'])->assertInertia(fn (Assert $page) => $page
        ->has('messages.rows', 25)
        ->where('messages.total', 26)
        ->where('messages.rows.0.received', 'Thursday 1 October 2026 at 11:25')
        ->where('messages.rows.24.received', 'Thursday 1 October 2026 at 11:01'));

    messagesScreen($this, $pat, ['view' => 'archived', 'page' => '2'])->assertInertia(fn (Assert $page) => $page
        ->where('filters', ['view' => 'archived'])
        ->has('messages.rows', 1)
        ->where('messages.page', 2)
        ->where('messages.total', 26)
        ->where('messages.rows.0.received', 'Thursday 1 October 2026 at 11:00'));
});

it('reads a malformed page as the first: {page}', function (string $page) {
    $pat = addOperator();
    inboxRow('2026-10-19 06:00:00');

    messagesScreen($this, $pat, ['page' => $page])->assertOk()->assertInertia(fn (Assert $page) => $page->where('messages.page', 1)->has('messages.rows', 1));
})->with(['0', '-1', 'two', '1.5', '9999999999']);

it('answers a page past the end with no messages and the total', function () {
    $pat = addOperator();
    inboxRow('2026-10-19 06:00:00');

    messagesScreen($this, $pat, ['page' => '3'])->assertOk()->assertInertia(fn (Assert $page) => $page->where('messages.page', 3)->where('messages.total', 1)->has('messages.rows', 0));
});

it('spells each instant in the zone the configuration names', function () {
    config(['console.timezone' => 'America/New_York']);
    $pat = addOperator();
    inboxRow('2026-10-19 06:00:00');

    messagesScreen($this, $pat)->assertInertia(fn (Assert $page) => $page->where('messages.rows.0.received', 'Monday 19 October 2026 at 02:00')->where('zone', 'America/New_York'));
});

it('reads a page in two statements, the count and the messages, whatever it holds', function () {
    $pat = addOperator();

    foreach (range(1, 6) as $minute) {
        inboxRow(sprintf('2026-10-19 06:%02d:00', $minute));
    }

    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        if (str_contains($query->sql, 'contact_messages')) {
            $statements[] = $query->sql;
        }
    });

    messagesScreen($this, $pat)->assertOk()->assertInertia(fn (Assert $page) => $page->has('messages.rows', 6));

    expect($statements)->toHaveCount(2);
});

it('hands the console\'s rail as Tenants, Messages, Audit, Messages lit on the messages screen', function () {
    $pat = addOperator();
    $tenants = ['label' => 'Tenants', 'url' => '/', 'group' => 'Platform', 'pending' => null];
    $messages = ['label' => 'Messages', 'url' => '/messages', 'group' => 'Platform', 'pending' => null];
    $audit = ['label' => 'Audit', 'url' => '/audit', 'group' => 'Platform', 'pending' => null];

    messagesScreen($this, $pat)->assertInertia(fn (Assert $page) => $page->where('nav', [
        [...$tenants, 'current' => false],
        [...$messages, 'current' => true],
        [...$audit, 'current' => false],
    ]));
});

it('sends a visitor with no operator session from the messages screen to the door', function () {
    inboxRow('2026-10-19 06:00:00');

    $this->get(consoleUrl('/messages'))->assertRedirect(consoleUrl('/login'));
});

it('archives an open message: archived_at the clock\'s now and contact_archived with the message\'s id and no tenant, then back to the screen with a sentence', function () {
    $pat = addOperator();
    $kept = inboxRow('2026-10-19 05:00:00');
    $id = inboxRow('2026-10-19 06:00:00');

    archiveMessage($this, $pat, $id)
        ->assertRedirect(consoleUrl('/messages'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => 'The message is archived.']);

    expect(inboxArchivedAt())->toBe([$kept => null, $id => '2026-10-20 09:00:00'])
        ->and(inboxAudit())->toBe([['action' => 'contact_archived', 'operator_id' => $pat->id, 'tenant_id' => null, 'payload' => '{"message": '.$id.'}']])
        ->and(platformAuditOffences())->toBe([]);

    messagesScreen($this, $pat)->assertInertia(fn (Assert $page) => $page->where('messages.total', 1)->where('messages.rows.0.id', $kept));
    messagesScreen($this, $pat, ['view' => 'archived'])->assertInertia(fn (Assert $page) => $page->where('messages.total', 1)->where('messages.rows.0.id', $id));
});

it('answers the screen when the press carries no page to go back to', function () {
    $pat = addOperator();
    $id = inboxRow('2026-10-19 06:00:00');

    $this->actingAs($pat, ConsoleSession::GUARD)
        ->withSession(freshConsoleSession())
        ->post(consoleUrl('/messages/'.$id.'/archive'))
        ->assertRedirect(consoleUrl('/messages'));
});

it('goes back to the view and the page the press carried, the page kept while it still holds a message and clamped to the last one that does', function () {
    $pat = addOperator();
    $start = new DateTimeImmutable('2026-10-18 06:00:00', new DateTimeZone('UTC'));
    $ids = [];

    foreach (range(1, ContactInbox::PAGE_SIZE + 2) as $minutes) {
        $ids[] = inboxRow($start->add(new DateInterval('PT'.$minutes.'M'))->format('Y-m-d H:i:s'));
    }

    archiveMessage($this, $pat, $ids[0], '/messages?page=2')->assertRedirect(consoleUrl('/messages?page=2'));
    archiveMessage($this, $pat, $ids[1], '/messages?page=2')->assertRedirect(consoleUrl('/messages'));
    archiveMessage($this, $pat, $ids[2], '/messages?page=9')->assertRedirect(consoleUrl('/messages'));
    archiveMessage($this, $pat, $ids[3], '/messages?view=archived&page=2')->assertRedirect(consoleUrl('/messages?view=archived'));
});

it('builds the way back from the press alone, never from the Referer, a malformed view or page reading as unset', function (string $referer) {
    $pat = addOperator();
    inboxRow('2026-10-19 05:00:00', ['archived_at' => '2026-10-19 07:00:00+00:00']);
    $id = inboxRow('2026-10-19 06:00:00');

    $this->actingAs($pat, ConsoleSession::GUARD)
        ->withSession(freshConsoleSession())
        ->from($referer)
        ->post(consoleUrl('/messages/'.$id.'/archive?view=archived&page=3'))
        ->assertRedirect(consoleUrl('/messages?view=archived'));

    $other = inboxRow('2026-10-19 06:30:00');

    $this->actingAs($pat, ConsoleSession::GUARD)
        ->withSession(freshConsoleSession())
        ->from($referer)
        ->post(consoleUrl('/messages/'.$other.'/archive?view=everything&page=two'))
        ->assertRedirect(consoleUrl('/messages'));
})->with([
    'another host' => ['https://elsewhere.example/messages?page=4'],
    'the console\'s own later page' => [fn (): string => consoleUrl('/messages?page=4')],
]);

it('keeps the sender, the address and the message out of the audit row', function () {
    $pat = addOperator();
    $id = inboxRow('2026-10-19 06:00:00');

    archiveMessage($this, $pat, $id)->assertRedirect();

    $payload = inboxAudit()[0]['payload'] ?? '';

    expect($payload)->not->toContain('maria@salon.example')
        ->and($payload)->not->toContain('Maria')
        ->and($payload)->not->toContain('chairs');
});

it('writes and records nothing for a message already archived, answering its sentence', function () {
    $pat = addOperator();
    $id = inboxRow('2026-10-19 06:00:00', ['archived_at' => '2026-10-19 07:30:00+00:00']);

    archiveMessage($this, $pat, $id, '/messages?view=archived')
        ->assertRedirect(consoleUrl('/messages?view=archived'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => 'That message is already archived.']);

    expect(inboxArchivedAt())->toBe([$id => '2026-10-19 07:30:00'])
        ->and(inboxAudit())->toBe([]);
});

it('records one archive when the same message is pressed twice, the second press answering its sentence', function () {
    $pat = addOperator();
    $id = inboxRow('2026-10-19 06:00:00');

    archiveMessage($this, $pat, $id)->assertSessionHas('flash', ['kind' => 'success', 'message' => 'The message is archived.']);

    app()->instance(Clock::class, FixedClock::at('2026-10-20 09:05'));

    archiveMessage($this, $pat, $id)->assertSessionHas('flash', ['kind' => 'error', 'message' => 'That message is already archived.']);

    expect(inboxArchivedAt())->toBe([$id => '2026-10-20 09:00:00'])
        ->and(array_column(inboxAudit(), 'action'))->toBe(['contact_archived']);
});

it('answers the console\'s 404 for an id that is {case}, writing nothing', function (string $id) {
    $pat = addOperator();
    $kept = inboxRow('2026-10-19 06:00:00');

    archiveMessage($this, $pat, $id === 'unknown' ? (string) ($kept + 1) : $id)
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('console/NotFound'));

    expect(inboxArchivedAt())->toBe([$kept => null])
        ->and(inboxAudit())->toBe([]);
})->with([
    'held by no row' => ['unknown'],
    'nought' => ['0'],
    'negative' => ['-1'],
    'not a number' => ['abc'],
    'a fraction' => ['1.5'],
    'past the largest whole number' => ['99999999999999999999'],
]);

it('sends a visitor with no operator session from Archive to the door, writing nothing', function () {
    $id = inboxRow('2026-10-19 06:00:00');

    $this->post(consoleUrl('/messages/'.$id.'/archive'))->assertRedirect(consoleUrl('/login'));

    expect(inboxArchivedAt())->toBe([$id => null])
        ->and(inboxAudit())->toBe([]);
});

it('archives nothing when the audit row cannot be written, the write and its record sharing one transaction', function () {
    $id = inboxRow('2026-10-19 06:00:00');

    expect(fn () => app(ContactInbox::class)->archive($id, 999999))->toThrow(QueryException::class);

    expect(inboxArchivedAt())->toBe([$id => null])
        ->and(inboxAudit())->toBe([]);
});
