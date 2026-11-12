<?php

use App\Application\Notification\MessageKey;
use App\Jobs\MessageNotSent;
use App\Jobs\SendApprovalRequest;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\RefusingTransport;
use Tests\Support\SeamLog;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * The approval request over luna's real rows: who at the business is asked to decide, what each
 * of them is told, and which of them is given a link they can act with.
 *
 * Not wrapped in a transaction: the hold enters the transaction runner, which refuses to open
 * inside another.
 *
 * The clock is the application's own, unpinned, and the booking is placed relative to today
 * rather than on a named date. Everything here turns on the approval window, which the hold
 * writes from the server's clock so that it is the TTL after created_at exactly; a test that
 * pinned the application's clock to a date weeks away would be reading a link's expiry against
 * a clock that wrote none of it. What the message says about a time is proved where it can be
 * proved exactly, in ApprovalRequestTest, and what a page says in DecisionPagesTest.
 *
 * Bridal trial is luna's one service that requires approval and is Elena's alone, so a hold on
 * it lands awaiting_approval with one item on one row.
 *
 * The seam helpers, seamHold, seamHeld, seamSent, seamTransport, seamSwitchOff, seamTextAt,
 * seamAddressSet, seamLinksIn and addTenantUser, live in tests/Pest.php, and SeamLog and
 * RefusingTransport under tests/Support. They are shared rather than copied, so that two
 * notification tests cannot acquire two ideas of what a sent message is, and this file runs
 * alone.
 */

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    seamTransport()->flush();

    // An approval hold queues three nags as well, which under the suite's sync queue run inside
    // the hold and would be counted here as three more messages to every recipient. They are
    // switched off at the tenant rather than filtered out of every assertion: emails_off is how
    // a tenant sends no nags, and the nag has its own file, ApprovalNagSeamTest. A test that
    // writes the list itself keeps the key on it.
    seamSwitchOff(['approval_nag']);
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * A request lodged with the business, taken the way the booking page takes one: Elena's
 * approval service, on the Tuesday of the first whole availability window, when she works 09:00
 * to 13:00.
 *
 * @return array{uuid: string, token: string}
 */
function askForApproval(TestCase $test): array
{
    $start = firstWindowDay(2)->setTime(9, 0)->format('Y-m-d H:i');

    [$url, $body] = seamHold('Bridal trial', $start);

    return seamHeld($test->postJson($url, $body));
}

/**
 * luna's own row in the central table.
 */
function askTenantId(): int
{
    $tenant = DB::table('tenants')->where('slug', 'luna')->value('id');

    assert(is_int($tenant));

    return $tenant;
}

it('asks everybody the recipient rule names, and gives each of them who can act their own two links', function () {
    $manager = addTenantUser('marina@luna.example', 'manager');
    $owner = DB::table('users')->where('email', 'owner@luna.example')->value('id');

    assert(is_int($owner));

    $held = askForApproval($this);

    // The customer's acknowledgement goes out on the same hold; the two listeners are
    // independent and neither knows the other exists.
    expect(seamAddressSet())->toBe([
        'marina@luna.example',
        'owner@luna.example',
        'perpetua.vandersnoot@example.test',
    ]);

    $ownersLinks = seamLinksIn(seamTextAt('owner@luna.example'));
    $managersLinks = seamLinksIn(seamTextAt('marina@luna.example'));

    expect($ownersLinks)->toHaveCount(2)
        ->and($managersLinks)->toHaveCount(2)
        // One link per user: the decision has to land as somebody, so two approvers read two
        // different messages about one booking.
        ->and($ownersLinks[0])->toContain('user='.$owner)
        ->and($ownersLinks[1])->toContain('user='.$owner)
        ->and($managersLinks[0])->toContain('user='.$manager)
        ->and($ownersLinks[0])->toContain('/decide/'.$held['uuid'].'/approve')
        ->and($ownersLinks[1])->toContain('/decide/'.$held['uuid'].'/reject')
        // On the tenant's own subdomain, which is where the pages answer.
        ->and($ownersLinks[0])->toContain('//luna.');

    // They verify, which is the whole of the authentication: each opens the page that asks
    // once more, and the other one decides the other way.
    foreach ([...$ownersLinks, ...$managersLinks] as $link) {
        $this->get($link)->assertOk();
    }

    expect($this->get($ownersLinks[0])->getContent())->toContain(decisionLine('decision.decisions.approve.verb'))
        ->and($this->get($ownersLinks[1])->getContent())->toContain(decisionLine('decision.decisions.reject.verb'));
});

it('gives a staff user their own links where the booking is on their row', function () {
    $elena = addTenantUser('elena@luna.example', 'staff', 'Elena Papadaki');
    $held = askForApproval($this);

    $links = seamLinksIn(seamTextAt('elena@luna.example'));

    expect(seamAddressSet())->toContain('elena@luna.example')
        ->and($links)->toHaveCount(2)
        ->and($links[0])->toContain('user='.$elena)
        ->and($links[0])->toContain('/decide/'.$held['uuid'].'/approve');

    $this->get($links[0])->assertOk();
});

it('sends a staff row with an address and nobody behind it the request with no link in it', function () {
    // A person who holds hours and never signs in, a staff row. There is no user for a link to
    // decide as, so there is no link.
    DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->update(['email' => 'chair@luna.example']);

    askForApproval($this);

    $chair = seamTextAt('chair@luna.example');

    expect(seamAddressSet())->toContain('chair@luna.example')
        ->and(seamLinksIn($chair))->toBe([])
        // The sentence true of this copy, which BusinessMailKeysTest holds to its key.
        ->and($chair)->toContain(businessMailLine('business-mail.links.none'))
        ->and($chair)->toContain('Bridal trial')
        // A mailbox with nobody behind it holds no permission, so it is told whose booking it
        // is and nothing about how to reach them.
        ->and($chair)->toContain('Perpetua Vandersnoot')
        ->and($chair)->not->toContain('perpetua.vandersnoot@example.test');
});

it('announces a request somebody answered before the job ran, and offers nobody a way to decide it', function () {
    $held = askForApproval($this);

    // Settled between the hold and the queue reaching the job. The announcement still goes out,
    // because what it says is that a request was made; what it cannot carry is a link, since
    // the expiry a link signs is the hold's own and this booking has none. The row is written
    // straight, because what is on trial here is the message rather than the transition that
    // settled it.
    DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])
        ->update(['status' => 'rejected', 'hold_class' => null, 'hold_expires_at' => null]);

    seamTransport()->flush();

    SendApprovalRequest::dispatch(askTenantId(), $held['uuid']);

    $owner = seamTextAt('owner@luna.example');

    expect(seamLinksIn($owner))->toBe([])
        ->and($owner)->toContain(businessMailLine('business-mail.approval_request.settled.nothing_left'))
        ->and(seamAt('owner@luna.example')->getSubject())->toBe(businessMailLine('business-mail.approval_request.settled.subject', ['business' => 'Luna Hair Studio']))
        // The sentence the linkless copy carries for a mailbox with nobody behind it is false
        // of this one: nobody can approve or reject a request already answered, from any copy.
        ->and($owner)->not->toContain(businessMailLine('business-mail.links.none'))
        ->and($owner)->toContain('Bridal trial');
});

it('asks nobody about a hold nobody has to decide, because a checkout hold is a customer still in the flow', function () {
    [$url, $body] = seamHold('Haircut', firstWindowDay(2)->setTime(10, 0)->format('Y-m-d H:i'));
    seamHeld($this->postJson($url, $body));

    expect(seamSent())->toBe([]);
});

it('sends the request under its own key and under no other, with every other key in the catalog switched off', function () {
    seamSwitchOff(seamEveryKeyBut(MessageKey::ApprovalRequest));

    askForApproval($this);

    expect(seamAddressSet())->toBe(['owner@luna.example']);
});

it('stops asking the business when the tenant switches the key off, and still acknowledges the customer', function () {
    seamSwitchOff(['approval_request', 'approval_nag']);

    askForApproval($this);

    expect(seamAddressSet())->toBe(['perpetua.vandersnoot@example.test']);
});

it('keeps the link out of every log line, because a signed link is a credential like the token beside it', function () {
    $log = new SeamLog;
    Log::swap($log);

    $held = askForApproval($this);

    expect($log->errors())->toBe([])
        ->and($log->text())->not->toContain('signature=')
        ->and($log->text())->not->toContain('/decide/')
        ->and($log->text())->not->toContain($held['token']);
});

it('queues the ask with no link in the row, because the link is minted where it is sent', function () {
    config(['queue.default' => 'database']);

    $held = askForApproval($this);

    /** @var list<stdClass> $queued */
    $queued = DB::table('jobs')->orderBy('id')->get()->all();

    // Five jobs: the customer's acknowledgement, this one, and the three nags, delayed to
    // instants inside the window and therefore left where they are by the worker below.
    expect($queued)->toHaveCount(5)
        ->and(seamSent())->toBe([]);

    $asks = array_values(array_filter($queued, function (stdClass $row): bool {
        assert(is_string($row->payload));

        return str_contains($row->payload, class_basename(SendApprovalRequest::class));
    }));

    expect($asks)->toHaveCount(1);

    $payload = $asks[0]->payload;

    assert(is_string($payload));

    // The row carries the tenant and the booking and nothing anybody could decide with: the
    // links are minted inside the job, after the re-read, so no queue row ever holds one.
    expect($payload)->not->toContain('signature=')
        ->not->toContain('/decide/')
        ->not->toContain($held['token']);

    // No memory ceiling for the worker: its check reads the memory of the whole test process,
    // which a full run grows past the default 128 MB by the time this file runs, and a worker
    // over it stops after the first job and leaves the other due ones queued.
    Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--memory' => 0, '--no-interaction' => true]);

    // The three rows left are the nags, whose instants the worker has not reached.
    expect(DB::table('jobs')->count())->toBe(3)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(seamLinksIn(seamTextAt('owner@luna.example')))->toHaveCount(2);
});

it('asks the business although the customer acknowledgement failed before it, because one listener is not the other', function () {
    $log = new SeamLog;
    Log::swap($log);

    // The acknowledgement's listener is registered first and its job runs inside the dispatch
    // under the sync driver. What it cannot survive is having nowhere to write its own failure.
    $log->refuses = 'A message could not be sent';
    seamMailer()->setSymfonyTransport(new RefusingTransport);

    askForApproval($this);

    // Both listeners ran and each failed on its own: the transport refused the send, the log
    // refused the line the seam writes about it, and the throw that got out of the seam was
    // caught by the listener's own hand-over rather than by the dispatcher. Two of those lines
    // is two listeners; one would be the first having silenced the second.
    $failures = array_filter(
        $log->errors(),
        fn (array $record): bool => $record['message'] === 'A message could not be queued',
    );

    expect($failures)->toHaveCount(2)
        ->and($log->text())->not->toContain('signature=');
});

it('fails into failed_jobs carrying no link, because a link is an argument of the call that writes it into the words', function () {
    config(['queue.default' => 'database']);

    // Raised past the fifteen characters the engine prints by default, so what passes is the
    // job's own guard and not a truncation a php.ini can undo.
    $was = ini_get('zend.exception_string_param_max_len');
    ini_set('zend.exception_string_param_max_len', '1000');

    try {
        // The seam catches everything, so the only way a throw leaves it is for the one thing
        // it does with a failure to fail: the transport refuses, and the log refuses the line
        // the refusal writes. That throw happens above the frames that hold the links as
        // arguments, which are inside the seam's own try, so the frame-argument hazard is not
        // reached here; MessageNotSentTest reaches it and is the proof, and what this proves
        // is the wrapper on a real failed row. The customer's acknowledgement is switched off,
        // so the only job that can fail here is this one, and the nag stays off with it, as
        // every test of this file that writes the list itself keeps it.
        seamSwitchOff(['acknowledged', 'approval_nag']);

        $log = new SeamLog;
        $log->refuses = 'A message could not be sent';
        Log::swap($log);
        seamMailer()->setSymfonyTransport(new RefusingTransport);

        $held = askForApproval($this);

        Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--tries' => 1, '--memory' => 0, '--no-interaction' => true]);

        $failed = DB::table('failed_jobs')->get();

        // One failure, and three rows left: the nags, not due and untouched.
        expect($failed)->toHaveCount(1)
            ->and(DB::table('jobs')->count())->toBe(3);

        $row = $failed->first();

        assert($row instanceof stdClass && is_string($row->exception) && is_string($row->payload));

        // The class, the file, the line and the booking: what an operator follows, and nothing
        // from the frames below, where the link and the addresses are arguments.
        expect($row->exception)->toContain(MessageNotSent::class)
            ->and($row->exception)->toContain($held['uuid'])
            ->and($row->exception)->not->toContain('signature=')
            ->and($row->exception)->not->toContain('/decide/')
            ->and($row->exception)->not->toContain('owner@luna.example')
            ->and($row->payload)->not->toContain('signature=');
    } finally {
        ini_set('zend.exception_string_param_max_len', is_string($was) ? $was : '15');
    }
});
