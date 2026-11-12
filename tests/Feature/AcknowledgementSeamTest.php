<?php

use App\Application\Clock;
use App\Application\Notification\Attachment;
use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Infrastructure\Notification\Messengers;
use App\Jobs\MessageNotSent;
use App\Site\SiteLanguage;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Part\DataPart;
use Tests\Support\FixedClock;
use Tests\Support\LunaFixture;
use Tests\Support\RefusingTransport;
use Tests\Support\SeamLog;

pest()->use(DatabaseTruncation::class);

/*
 * The acknowledgement seam end to end, over luna's real rows: which holds get an
 * acknowledgement and which do not, what the message says and where it goes, what a
 * switched-off key does, what a broken transport does, and what a queue row is allowed to hold.
 *
 * Not wrapped in a transaction: the hold enters the transaction runner, which refuses to open
 * inside another.
 *
 * The seam helpers every notification suite reads, seamHold, seamHeld, seamSent, seamOnly and
 * the rest, live in tests/Pest.php, and SeamLog and RefusingTransport under tests/Support, so
 * that any one of these files runs alone and no two of them hold two ideas of what a sent
 * message is.
 *
 * luna is in Europe/Athens, UTC+2 in November. 2026-11-03 is a Tuesday, when Elena works 09:00
 * to 13:00. Bridal trial is her two-hour service and the one that requires approval; Haircut is
 * the ordinary pay-on-site one. The clock the planner reads stands on the Monday before.
 */

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00:00'));
    seamTransport()->flush();

    // An approval hold now produces messages beside this one: the request to the business, and
    // the three nags, which under the suite's sync queue run inside the hold. They are
    // independent listeners and none knows the others exist. What this file counts is the
    // acknowledgement, so the others are switched off at the tenant rather than filtered out
    // of every assertion; each has its own file, and one of the tests below turns the request
    // back on to prove the switch is per key.
    settingsOf('luna')->update(['emails_off' => DB::raw("'{approval_request,approval_nag}'::text[]")]);
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

it('acknowledges an approval hold once, to the address on the booking, with a manage link that works', function () {
    [$url, $body] = seamHold();
    $held = seamHeld($this->postJson($url, $body));

    [$otherUrl, $otherBody] = seamHold('Haircut', '2026-11-04 10:00');
    $other = seamHeld($this->postJson($otherUrl, $otherBody));

    $message = seamOnly();
    $text = (string) $message->getTextBody();
    // Under the prefix of the booking's language, English here.
    $link = app(TenantUrls::class)->manageLink(Slug::of('luna'), $held['uuid'], $held['token'], SiteLanguage::English);

    expect(array_map(fn ($address): string => $address->getAddress(), $message->getTo()))->toBe(['perpetua.vandersnoot@example.test'])
        ->and($message->getSubject())->toBe(customerMailLine('customer-mail.acknowledged.subject', ['business' => 'Luna Hair Studio']))
        ->and($text)->toContain(customerMailLine('customer-mail.acknowledged.manage')."\n\n    ".$link)
        // The link is on luna's own origin, which is where the manage page answers.
        ->and($text)->toContain('//luna.')
        ->and($message->getAttachments())->toBe([])
        // One booking and no other: the uuid appears once, in the link, and the second hold's
        // does not appear at all.
        ->and(substr_count($text, $held['uuid']))->toBe(1)
        ->and($text)->not->toContain($other['uuid']);

    $this->getJson(tenantUrl('luna', '/api/v1/bookings/'.$held['uuid'].'?token='.$held['token']))
        ->assertOk()
        ->assertJsonPath('uuid', $held['uuid']);
});

it('sends nothing for a pay-on-site chain that needs nobody to say yes, because that customer is still in the booking flow', function () {
    [$url, $body] = seamHold('Haircut');
    seamHeld($this->postJson($url, $body));

    expect(seamSent())->toBe([]);
});

it('sends nothing when the tenant has switched the key off, and still hands the customer their token', function () {
    settingsOf('luna')->update(['emails_off' => DB::raw("'{acknowledged,approval_request,approval_nag}'::text[]")]);

    [$url, $body] = seamHold();
    $held = seamHeld($this->postJson($url, $body));

    expect(seamSent())->toBe([])
        ->and($held['token'])->not->toBe('');

    $this->getJson(tenantUrl('luna', '/api/v1/bookings/'.$held['uuid'].'?token='.$held['token']))->assertOk();
});

it('goes on sending a message whose key is not the one switched off', function () {
    // The business's request is switched back on here, which is what makes the point: two keys
    // are off, two audiences hear about this hold, and neither of the two switches is the
    // other's. The nag stays off, as it is in every test of this file.
    settingsOf('luna')->update(['emails_off' => DB::raw("'{confirmed,reminder,approval_nag}'::text[]")]);

    [$url, $body] = seamHold();
    seamHeld($this->postJson($url, $body));

    expect(seamSent())->toHaveCount(2);
});

it('keeps the booking when the transport refuses, and logs one error naming the tenant, the key and the booking and never the address', function () {
    $log = new SeamLog;
    Log::swap($log);
    seamMailer()->setSymfonyTransport(new RefusingTransport);

    [$url, $body] = seamHold();
    $held = seamHeld($this->postJson($url, $body));

    $errors = $log->errors();

    expect($errors)->toHaveCount(1)
        ->and($errors[0]['context'])->toBe([
            'tenant' => 'luna',
            'key' => 'acknowledged',
            'booking' => $held['uuid'],
            'exception' => TransportException::class,
        ])
        ->and($log->text())->not->toContain('perpetua.vandersnoot@example.test')
        ->and($log->text())->not->toContain($held['token']);

    expect(array_map(fn (array $row): mixed => $row['action'], LunaFixture::trail()))->toBe(['held']);
});

it('keeps the plaintext token out of every log line under a transport that works too', function () {
    $log = new SeamLog;
    Log::swap($log);

    [$url, $body] = seamHold();
    $held = seamHeld($this->postJson($url, $body));

    expect($log->errors())->toBe([])
        ->and($log->text())->not->toContain($held['token']);
});

it('sends from the platform address with the tenant owner as the reply-to', function () {
    [$url, $body] = seamHold();
    seamHeld($this->postJson($url, $body));

    $message = seamOnly();
    $from = config('mail.from.address');

    assert(is_string($from));

    expect(array_map(fn ($address): string => $address->getAddress(), $message->getFrom()))->toBe([$from])
        // luna's owner user, which the demo seeder creates and the provisioner links to the
        // tenant. Named literally rather than read back, so a tenant that lost its owner fails
        // here instead of agreeing with itself.
        ->and(array_map(fn ($address): string => $address->getAddress(), $message->getReplyTo()))->toBe(['owner@luna.example'])
        ->and(DB::table('users')->where('email', 'owner@luna.example')->where('role', 'owner')->count())->toBe(1);
});

it('sends with no reply-to, and says so once, for a tenant that has no owner to reply to', function () {
    $log = new SeamLog;
    Log::swap($log);

    // The one arrangement provisioning cannot produce: a tenant whose users are all there and
    // none of them is the owner. Demoting the row rather than deleting it keeps the tenant's
    // people intact, so what is proved is the missing role and not a missing tenant.
    DB::table('users')->where('email', 'owner@luna.example')->update(['role' => 'manager']);

    [$url, $body] = seamHold();
    $held = seamHeld($this->postJson($url, $body));

    $warnings = array_values(array_filter($log->records, fn (array $record): bool => $record['level'] === 'warning'));

    expect(seamOnly()->getReplyTo())->toBe([])
        ->and($log->errors())->toBe([])
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0]['context'])->toBe([
            'tenant' => 'luna',
            'key' => 'acknowledged',
            'booking' => $held['uuid'],
        ])
        ->and($log->text())->not->toContain('perpetua.vandersnoot@example.test')
        ->and($log->text())->not->toContain($held['token']);
});

it('queues the send with nothing readable in the row, and sends the link when the worker gets to it', function () {
    config(['queue.default' => 'database']);

    [$url, $body] = seamHold();
    $held = seamHeld($this->postJson($url, $body));

    // Five rows: this message; the request to the business, which is switched off at the seam
    // rather than at the dispatch and is therefore queued like any other; and the three nags,
    // delayed to instants inside the window and therefore left where they are by the worker
    // below. The one on trial here is the encrypted one, which is the row that cannot be read
    // at all.
    $queued = DB::table('jobs')->get();

    expect($queued)->toHaveCount(5)
        ->and(seamSent())->toBe([]);

    $rows = $queued->filter(function (stdClass $row): bool {
        assert(is_string($row->payload));

        return ! str_contains($row->payload, 'SendApprovalRequest') && ! str_contains($row->payload, 'SendApprovalNag');
    });

    expect($rows)->toHaveCount(1);

    $row = $rows->first();

    assert($row instanceof stdClass && is_string($row->payload));

    $payload = $row->payload;

    expect($payload)->not->toContain($held['token'])
        ->and($payload)->not->toContain('perpetua.vandersnoot@example.test')
        ->and($payload)->not->toContain($held['uuid']);

    // No memory ceiling for the worker: its check reads the memory of the whole test process,
    // which a full run grows past the default 128 MB by the time this file runs, and a worker
    // over it stops after the first job and leaves the other due ones queued.
    Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--memory' => 0, '--no-interaction' => true]);

    // The link and not the token inside it. What the customer needs is the address they can open,
    // and a body carrying the secret somewhere other than the query string would satisfy a
    // substring and not the criterion. Under the prefix of the booking's language, English here.
    $link = app(TenantUrls::class)->manageLink(Slug::of('luna'), $held['uuid'], $held['token'], SiteLanguage::English);

    // The three rows left are the nags, whose instants the worker has not reached.
    expect(DB::table('jobs')->count())->toBe(3)
        ->and((string) seamOnly()->getTextBody())->toContain($link);
});

it('attaches whatever files a message carries, which is how the calendar file travels', function () {
    app(Messengers::class)->for(Tenant::query()->where('slug', 'luna')->sole())->send(MessageKey::Confirmed, '7b7f0e5a-0000-4000-8000-000000000001', fn (): Message => new Message(
        MessageKey::Confirmed,
        new Recipient('casey@example.test'),
        'A subject',
        'A body.',
        '7b7f0e5a-0000-4000-8000-000000000001',
        [new Attachment('booking.ics', 'text/calendar', "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n")],
    ));

    $attachments = seamOnly()->getAttachments();

    expect(array_map(fn (DataPart $part): ?string => $part->getFilename(), $attachments))->toBe(['booking.ics'])
        ->and(array_map(fn (DataPart $part): string => $part->getMediaType().'/'.$part->getMediaSubtype(), $attachments))->toBe(['text/calendar'])
        ->and(array_map(fn (DataPart $part): string => $part->getBody(), $attachments))->toBe(["BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n"]);
});

it('logs and returns where the rows it would read are gone, because the seam never throws whatever is wrong', function () {
    $tenant = Tenant::query()->where('slug', 'luna')->sole();
    $log = new SeamLog;
    Log::swap($log);

    DB::statement('drop schema tenant_luna cascade');

    app(Messengers::class)->for($tenant)->send(MessageKey::Confirmed, '7b7f0e5a-0000-4000-8000-000000000001', fn (): Message => new Message(
        MessageKey::Confirmed,
        new Recipient('casey@example.test'),
        'A subject',
        'A body.',
        '7b7f0e5a-0000-4000-8000-000000000001',
    ));

    $errors = $log->errors();

    expect($errors)->toHaveCount(1)
        ->and($errors[0]['context']['tenant'] ?? null)->toBe('luna')
        ->and($errors[0]['context']['key'] ?? null)->toBe('confirmed')
        ->and($errors[0]['context']['booking'] ?? null)->toBe('7b7f0e5a-0000-4000-8000-000000000001')
        ->and($log->text())->not->toContain('casey@example.test')
        ->and(seamSent())->toBe([]);
});

it('fails into failed_jobs carrying nothing from below the job, because a failed row is a queue row too', function () {
    config(['queue.default' => 'database']);

    // Raised past the fifteen characters the engine prints by default, so what passes is the
    // job's own guard and not a truncation a php.ini can undo.
    $was = ini_get('zend.exception_string_param_max_len');
    ini_set('zend.exception_string_param_max_len', '1000');

    try {
        // The seam catches everything, so the only way a throw leaves it is for the one thing
        // it does with a failure to fail: the transport refuses, and the log refuses the line
        // the refusal writes. That throw happens above the frames that hold the link as an
        // argument, which are inside the seam's own try, so the frame-argument hazard is not
        // reached here and cannot be from outside the seam; MessageNotSentTest reaches it and
        // is the proof. What this proves is the wrapper on a real failed row: the row carries
        // the class, the line and the booking, nothing of the original exception, not even
        // its message, and nothing readable in its payload.
        $log = new SeamLog;
        $log->refuses = 'A message could not be sent';
        Log::swap($log);
        seamMailer()->setSymfonyTransport(new RefusingTransport);

        [$url, $body] = seamHold();
        $held = seamHeld($this->postJson($url, $body));

        // Both due jobs are worked: this one fails, and the request to the business finds its
        // key switched off and returns; the three nags are not due and stay. Stopping when the
        // queue is empty rather than after one job is what keeps the count of rows below about
        // the failure rather than about the order they were pushed in.
        Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--tries' => 1, '--memory' => 0, '--no-interaction' => true]);

        $failed = DB::table('failed_jobs')->get();

        expect($failed)->toHaveCount(1)
            ->and(DB::table('jobs')->count())->toBe(3);

        $row = $failed->first();

        assert($row instanceof stdClass && is_string($row->exception) && is_string($row->payload));

        expect($row->exception)->toContain(MessageNotSent::class)
            ->and($row->exception)->toContain($held['uuid'])
            ->and($row->exception)->toContain(RuntimeException::class)
            ->and($row->exception)->not->toContain($held['token'])
            ->and($row->exception)->not->toContain('perpetua.vandersnoot@example.test')
            ->and($row->exception)->not->toContain('the log is not there either')
            ->and($row->payload)->not->toContain($held['token'])
            ->and($row->payload)->not->toContain('perpetua.vandersnoot@example.test');
    } finally {
        ini_set('zend.exception_string_param_max_len', is_string($was) ? $was : '15');
    }
});
