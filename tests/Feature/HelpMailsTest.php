<?php

declare(strict_types=1);

use App\Application\Clock;
use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use App\Help\HelpAuthor;
use App\Help\HelpDesk;
use App\Help\HelpLedger;
use App\Help\HelpTickets;
use App\Models\User;
use App\Notifications\HelpMessageReceived;
use App\Site\SiteLanguage;
use App\Tenancy\TenantUrls;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Psr\Log\LoggerInterface;
use Tests\Support\FixedClock;
use Tests\Support\RecordingPlatformMessages;
use Tests\Support\SeamLog;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The two mails a help ticket sends, as the platform's seam is handed them. A business's message,
 * the one that opens a ticket and every later one, goes to the support mailbox as plain English
 * text naming the business, the writer with their role, the subject and the message, with a link
 * to the thread on the console's host. An operator's reply goes to each user of the business who
 * wrote in the thread and is one still, once each, in their own language, with the reply as it was
 * written and a link to the thread in the admin. A user of another business is sent nothing,
 * whatever the thread's rows name. A subject or a name typed with line breaks stands on one line
 * wherever a mail prints it as a one-line value, and the message alone keeps its breaks. Closing
 * and reopening send nothing, a write that rolled back sends nothing, every mail goes out after the
 * write has committed, and a mail that fails leaves the write standing with a log line that names
 * the ticket and the message alone.
 *
 * The clock is pinned and the seam records what it is handed; the business is luna, its owner
 * writing in English.
 */

beforeEach(function () {
    $this->withoutVite();
    app()->instance(Clock::class, FixedClock::at('2026-11-17 09:00'));
    app()->instance(RecordingPlatformMessages::class, new RecordingPlatformMessages);
    app()->instance(PlatformMessenger::class, app(RecordingPlatformMessages::class));
    freshRouteControllers();
    provisionTenant('luna', ['name' => 'Luna Hair Studio', 'ownerEmail' => 'owner@luna.example'], ['staff']);
    DB::table('users')->where('email', 'owner@luna.example')->update(['locale' => 'en']);
    helpMailForget();
});

/**
 * What the recording seam was handed since it was last emptied.
 *
 * @return list<PlatformMessage>
 */
function helpMailSent(): array
{
    return app(RecordingPlatformMessages::class)->sent;
}

function helpMailForget(): void
{
    app(RecordingPlatformMessages::class)->sent = [];
}

function helpMailUser(string $email = 'owner@luna.example'): User
{
    return User::query()->where('email', $email)->sole();
}

function helpMailTenant(): int
{
    $id = DB::table('tenants')->where('slug', 'luna')->value('id');
    assert(is_int($id));

    return $id;
}

/**
 * The ids of a ticket's messages, oldest first.
 *
 * @return list<int>
 */
function helpMailMessages(int $ticket): array
{
    return array_values(array_map(function (mixed $id): int {
        assert(is_int($id));

        return $id;
    }, DB::table('help_messages')->where('ticket_id', $ticket)->orderBy('id')->pluck('id')->all()));
}

function helpMailTicket(string $uuid): int
{
    $id = DB::table('help_tickets')->where('uuid', $uuid)->value('id');
    assert(is_int($id));

    return $id;
}

/**
 * One write of the admin's help, as the user named, from the Calendar and back to it.
 *
 * @param  array<string, string>  $data
 */
function helpMailWrite(TestCase $test, string $email, string $path, array $data): void
{
    freshGuards();
    Inertia::flushShared();

    $test->actingAs(helpMailUser($email))->from(tenantUrl('luna', '/admin/calendar'))
        ->post(tenantUrl('luna', $path), $data)
        ->assertRedirect(tenantUrl('luna', '/admin/calendar'));
}

/**
 * Opens a ticket as the user named and answers its uuid.
 */
function helpMailOpen(TestCase $test, string $email, string $subject, string $body): string
{
    helpMailWrite($test, $email, '/admin/help', ['subject' => $subject, 'body' => $body]);

    $uuid = DB::table('help_tickets')->where('subject', $subject)->orderByDesc('id')->value('uuid');
    assert(is_string($uuid));

    return $uuid;
}

it('sends the support mailbox a plain mail for a new ticket and for each later message of the business, naming the business, the writer and their role, and linking to the thread in the console', function () {
    addTenantUser('lin@luna.example', 'manager');
    $owner = helpMailUser();
    $uuid = helpMailOpen($this, 'owner@luna.example', 'Moving a booking', "How do I move a booking?\nThanks.");
    $id = helpMailTicket($uuid);
    helpMailWrite($this, 'lin@luna.example', '/admin/help/'.$uuid.'/messages', ['body' => 'The same question from me.']);

    $sent = helpMailSent();
    $link = app(TenantUrls::class)->console('/tickets/'.$id);
    $envelope = [PlatformMessageKind::HelpMessage, helpMailTenant(), config('site.contact_address'), '', null, null];

    expect(array_map(fn (PlatformMessage $message): array => [$message->kind, $message->tenantId, $message->toAddress, $message->toName, $message->replyTo, $message->html], $sent))->toBe([$envelope, $envelope])
        ->and($sent[0]->subject)->toBe(sprintf(HelpMessageReceived::OPENED, 'Luna Hair Studio'))
        ->and($sent[0]->body)->toBe(implode("\n", [
            'Luna Hair Studio opened a help ticket.',
            '',
            'Business: Luna Hair Studio',
            'From: '.$owner->name.', Owner',
            'Subject: Moving a booking',
            '',
            "How do I move a booking?\nThanks.",
            '',
            'Answer it in the console:',
            $link,
        ]))
        ->and($sent[1]->subject)->toBe(sprintf(HelpMessageReceived::ADDED, 'Luna Hair Studio'))
        ->and($sent[1]->body)->toBe(implode("\n", [
            'Luna Hair Studio wrote again on a help ticket.',
            '',
            'Business: Luna Hair Studio',
            'From: lin@luna.example, Manager',
            'Subject: Moving a booking',
            '',
            'The same question from me.',
            '',
            'Answer it in the console:',
            $link,
        ]))
        ->and($link)->toStartWith('http')->toContain('://console.')->toEndWith('/tickets/'.$id);
});

it('sends an operator\'s reply to each user who wrote in the thread, once each however often they wrote, in their own language, with the reply and a link to the thread in the admin', function () {
    $owner = helpMailUser();
    $lin = addTenantUser('lin@luna.example', 'manager');
    addTenantUser('max@luna.example', 'staff');
    DB::table('users')->where('id', $lin)->update(['locale' => 'el']);
    $uuid = helpMailOpen($this, 'owner@luna.example', 'Moving a booking', 'How do I move a booking?');
    helpMailWrite($this, 'lin@luna.example', '/admin/help/'.$uuid.'/messages', ['body' => 'The same question from me.']);
    helpMailWrite($this, 'owner@luna.example', '/admin/help/'.$uuid.'/messages', ['body' => 'Any news?']);
    helpMailForget();

    expect(app(HelpDesk::class)->reply(helpMailTicket($uuid), addOperator(), "Open the booking.\nThen drag it to its new time."))->toBeTrue();

    $sent = helpMailSent();
    $link = tenantUrl('luna', '/admin/help/'.$uuid);
    $replied = fn (SiteLanguage $language): string => siteSentence('mail.help_reply.replied', $language, ['business' => 'Luna Hair Studio']);

    expect(array_map(fn (PlatformMessage $message): array => [$message->kind, $message->tenantId, $message->toAddress, $message->toName, $message->replyTo], $sent))->toBe([
        [PlatformMessageKind::HelpReply, helpMailTenant(), 'owner@luna.example', $owner->name, null],
        [PlatformMessageKind::HelpReply, helpMailTenant(), 'lin@luna.example', 'lin@luna.example', null],
    ])
        ->and($sent[0]->subject)->toBe(siteSentence('mail.help_reply.subject', SiteLanguage::English, ['subject' => 'Moving a booking']))
        ->and($sent[0]->body)->toBe(implode("\n\n", [
            'Hello '.$owner->name.',',
            siteName().' Support replied to a help ticket of Luna Hair Studio.',
            'Subject: Moving a booking',
            "Open the booking.\nThen drag it to its new time.",
            'Open the ticket in your admin to read the whole thread and answer:',
            $link,
        ]))
        ->and($replied(SiteLanguage::English))->toBe(siteName().' Support replied to a help ticket of Luna Hair Studio.')
        ->and($sent[0]->html)->toContain('<html lang="en">')->toContain(e($replied(SiteLanguage::English)))->toContain('href="'.$link.'"')
        ->and($sent[1]->subject)->toBe(siteSentence('mail.help_reply.subject', SiteLanguage::Greek, ['subject' => 'Moving a booking']))
        ->and($sent[1]->body)->toBe(implode("\n\n", [
            siteSentence('mail.greeting', SiteLanguage::Greek, ['owner' => 'lin@luna.example']),
            $replied(SiteLanguage::Greek),
            siteSentence('mail.help_reply.about', SiteLanguage::Greek, ['subject' => 'Moving a booking']),
            "Open the booking.\nThen drag it to its new time.",
            siteSentence('mail.help_reply.open', SiteLanguage::Greek),
            $link,
        ]))
        ->and($sent[1]->html)->toContain('<html lang="el">')->toContain(e($replied(SiteLanguage::Greek)))->toContain('href="'.$link.'"')
        ->toContain(e(siteSentence('mail.help_reply.reason', SiteLanguage::Greek, ['business' => 'Luna Hair Studio'])));
});

it('sends a reply to no writer who has left the business since, reading the team when the reply is sent', function () {
    $lin = addTenantUser('lin@luna.example', 'manager');
    $uuid = helpMailOpen($this, 'owner@luna.example', 'Moving a booking', 'How do I move a booking?');
    helpMailWrite($this, 'lin@luna.example', '/admin/help/'.$uuid.'/messages', ['body' => 'The same question from me.']);
    DB::table('users')->where('id', $lin)->delete();
    helpMailForget();

    expect(app(HelpDesk::class)->reply(helpMailTicket($uuid), addOperator(), 'Open the booking.'))->toBeTrue()
        ->and(array_map(fn (PlatformMessage $message): string => $message->toAddress, helpMailSent()))->toBe(['owner@luna.example'])
        ->and(DB::table('help_messages')->where('ticket_id', helpMailTicket($uuid))->where('author_name', 'lin@luna.example')->count())->toBe(1);
});

it('sends a reply to users of the ticket\'s own business alone: a message on the thread that names a user of another business mails them nothing', function () {
    provisionTenant('pier', ['name' => 'Pier Barbers', 'ownerEmail' => 'owner@pier.example']);
    $stranger = DB::table('users')->where('email', 'owner@pier.example')->value('id');
    $id = helpMailTicket(helpMailOpen($this, 'owner@luna.example', 'Moving a booking', 'How do I move a booking?'));
    DB::table('help_messages')->insert(['ticket_id' => $id, 'side' => 'business', 'user_id' => $stranger, 'operator_id' => null, 'author_name' => 'Pat Pier', 'body' => 'A message from elsewhere.', 'created_at' => '2026-11-17 09:00:00+00']);
    helpMailForget();

    expect($stranger)->toBeInt()
        ->and(app(HelpDesk::class)->reply($id, addOperator(), 'Open the booking.'))->toBeTrue()
        ->and(array_map(fn (PlatformMessage $message): string => $message->toAddress, helpMailSent()))->toBe(['owner@luna.example'])
        ->and(DB::table('help_messages')->where('ticket_id', $id)->where('user_id', $stranger)->count())->toBe(1);
});

it('sends nothing when an operator closes a ticket or reopens it', function () {
    $operator = addOperator();
    $id = helpMailTicket(helpMailOpen($this, 'owner@luna.example', 'Moving a booking', 'How do I move a booking?'));
    helpMailForget();
    $desk = app(HelpDesk::class);

    expect($desk->close($id, $operator->id))->toBeTrue()
        ->and($desk->reopen($id, $operator->id))->toBeTrue()
        ->and(helpMailSent())->toBe([]);
});

it('gives a message as it was written: escaped in the reply\'s HTML part with its line breaks kept and nothing in it made a link, and whole in every text part', function () {
    $body = "<b>Bold</b> & <script>alert(1)</script>\nSee https://example.test/help for more.";
    $uuid = helpMailOpen($this, 'owner@luna.example', 'Hours & <breaks>', $body);
    helpMailForget();

    app(HelpDesk::class)->reply(helpMailTicket($uuid), addOperator(), $body);

    $reply = helpMailSent()[0];
    $html = $reply->html ?? '';

    expect($reply->body)->toContain("\n\n".$body."\n\n")
        ->and($html)->toContain("&lt;b&gt;Bold&lt;/b&gt; &amp; &lt;script&gt;alert(1)&lt;/script&gt;<br>\nSee https://example.test/help for more.")
        ->and($html)->toContain('Subject: Hours &amp; &lt;breaks&gt;')
        ->not->toContain('<b>Bold</b>')
        ->not->toContain('<script>')
        ->not->toContain('<breaks>')
        ->not->toContain('href="https://example.test')
        ->and(substr_count($html, 'href='))->toBe(1)
        ->and($reply->subject)->toBe(siteSentence('mail.help_reply.subject', SiteLanguage::English, ['subject' => 'Hours & <breaks>']));
});

it('prints a subject, a business\'s name and a writer\'s name typed with line breaks on one line wherever a mail names them as a one-line value: all three in the mail to the support mailbox, the subject in both parts of the reply\'s mail, where the reply alone is drawn with breaks', function () {
    $subject = "Hours\r\n\r\nYour account is closed.\n\tPay at evil.example";
    $folded = 'Hours Your account is closed. Pay at evil.example';
    DB::table('tenants')->where('slug', 'luna')->update(['name' => "Luna Hair Studio\n\nFrom: the platform"]);
    DB::table('users')->where('email', 'owner@luna.example')->update(['name' => "Ada Owner\r\nSubject: all is well"]);
    $uuid = helpMailOpen($this, 'owner@luna.example', $subject, "How do I move a booking?\nThanks.");
    $id = helpMailTicket($uuid);
    helpMailWrite($this, 'owner@luna.example', '/admin/help/'.$uuid.'/messages', ['body' => 'Any news?']);
    [$opened, $added] = helpMailSent();
    helpMailForget();

    app(HelpDesk::class)->reply($id, addOperator(), "Open the booking.\nThen drag it to its new time.");

    $reply = helpMailSent()[0];
    $html = $reply->html ?? '';
    $paragraph = fn (string $text): string => '<p style="margin:0 0 16px 0;">'.$text.'</p>';

    expect(DB::table('help_tickets')->where('id', $id)->value('subject'))->toBe($subject)
        ->and($opened->subject)->toBe(sprintf(HelpMessageReceived::OPENED, 'Luna Hair Studio From: the platform'))
        ->and($opened->body)->toBe(implode("\n", [
            'Luna Hair Studio From: the platform opened a help ticket.',
            '',
            'Business: Luna Hair Studio From: the platform',
            'From: Ada Owner Subject: all is well, Owner',
            'Subject: '.$folded,
            '',
            "How do I move a booking?\nThanks.",
            '',
            'Answer it in the console:',
            app(TenantUrls::class)->console('/tickets/'.$id),
        ]))
        ->and($added->subject)->toBe(sprintf(HelpMessageReceived::ADDED, 'Luna Hair Studio From: the platform'))
        ->and($added->body)->toStartWith(implode("\n", [
            'Luna Hair Studio From: the platform wrote again on a help ticket.',
            '',
            'Business: Luna Hair Studio From: the platform',
            'From: Ada Owner Subject: all is well, Owner',
            'Subject: '.$folded,
            '',
            'Any news?',
            '',
        ]))
        ->and($reply->subject)->toBe(siteSentence('mail.help_reply.subject', SiteLanguage::English, ['subject' => $folded]))
        ->and($reply->body)->toContain("\n\nSubject: ".$folded."\n\nOpen the booking.\nThen drag it to its new time.\n\n")
        ->and($html)->toContain('<title>'.e($reply->subject).'</title>')
        ->and($html)->toContain($paragraph('Subject: '.$folded))
        ->and($html)->toContain($paragraph("Hello Ada Owner\r\nSubject: all is well,"))
        ->and($html)->toContain($paragraph("Open the booking.<br>\nThen drag it to its new time."))
        ->and(substr_count($html, '<br'))->toBe(1);
});

it('sends every mail after the write has committed, never inside its transaction', function () {
    /** @var ArrayObject<int, array{0: PlatformMessageKind, 1: int}> $depths */
    $depths = new ArrayObject;
    app()->instance(PlatformMessenger::class, new readonly class($depths) implements PlatformMessenger
    {
        /**
         * @param  ArrayObject<int, array{0: PlatformMessageKind, 1: int}>  $depths
         */
        public function __construct(private ArrayObject $depths) {}

        public function send(PlatformMessageKind $kind, ?int $tenantId, Closure $render): void
        {
            $this->depths->append([$kind, DB::transactionLevel()]);
        }
    });
    freshRouteControllers();
    $depth = DB::transactionLevel();

    $uuid = helpMailOpen($this, 'owner@luna.example', 'Moving a booking', 'How do I move a booking?');
    helpMailWrite($this, 'owner@luna.example', '/admin/help/'.$uuid.'/messages', ['body' => 'Any news?']);
    app(HelpDesk::class)->reply(helpMailTicket($uuid), addOperator(), 'Open the booking.');

    expect($depths->getArrayCopy())->toBe([
        [PlatformMessageKind::HelpMessage, $depth],
        [PlatformMessageKind::HelpMessage, $depth],
        [PlatformMessageKind::HelpReply, $depth],
    ]);
});

it('sends nothing for a write that rolled back, on either side', function () {
    $owner = helpMailUser();
    $author = new HelpAuthor($owner->id, $owner->name, $owner->role);
    $tickets = app(HelpTickets::class);
    $tooLong = str_repeat('x', 5001);

    expect(fn () => $tickets->open(helpMailTenant(), $author, 'Too long', $tooLong))->toThrow(QueryException::class)
        ->and(DB::table('help_tickets')->count())->toBe(0)
        ->and(DB::table('help_messages')->count())->toBe(0);

    $uuid = $tickets->open(helpMailTenant(), $author, 'Moving a booking', 'How do I move a booking?');
    $id = helpMailTicket($uuid);
    helpMailForget();

    expect(fn () => $tickets->reply(helpMailTenant(), $author, $uuid, $tooLong))->toThrow(QueryException::class)
        ->and(fn () => app(HelpDesk::class)->reply($id, addOperator(), $tooLong))->toThrow(QueryException::class)
        ->and(DB::table('help_messages')->where('ticket_id', $id)->count())->toBe(1)
        ->and(helpMailSent())->toBe([]);
});

it('keeps a failing mail from the write: the message stands, the answer is the same, and the log names the ticket and the message alone', function () {
    app()->instance(PlatformMessenger::class, new class implements PlatformMessenger
    {
        public function send(PlatformMessageKind $kind, ?int $tenantId, Closure $render): void
        {
            throw new RuntimeException('the mail failed');
        }
    });
    $log = new SeamLog;
    app()->instance(LoggerInterface::class, $log);
    freshRouteControllers();

    $uuid = helpMailOpen($this, 'owner@luna.example', 'A private subject', 'A private question');
    $id = helpMailTicket($uuid);

    expect(app(HelpDesk::class)->reply($id, addOperator(), 'A private answer'))->toBeTrue();

    $messages = helpMailMessages($id);
    $failed = fn (int $message): array => ['level' => 'error', 'message' => HelpLedger::HOOK_FAILED, 'context' => ['ticket' => $id, 'message' => $message, 'exception' => RuntimeException::class]];

    expect($messages)->toHaveCount(2)
        ->and($log->errors())->toBe([$failed($messages[0]), $failed($messages[1])])
        ->and($log->text())->not->toContain('private')
        ->not->toContain('owner@luna.example')
        ->not->toContain(helpMailUser()->name)
        ->not->toContain('Luna Hair Studio');
});
