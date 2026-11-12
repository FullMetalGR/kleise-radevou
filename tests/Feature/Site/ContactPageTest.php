<?php

use App\Application\Clock;
use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use App\Application\Turnstile\TurnstileCheck;
use App\Http\Requests\ContactRequest;
use App\Http\TurnstileGate;
use App\Notifications\ContactMessageReceived;
use App\Site\SiteLanguage;
use App\Site\SitePages;
use App\Tenancy\TenantUrls;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\FakeTurnstileCheck;
use Tests\Support\FixedClock;
use Tests\Support\RecordingPlatformMessages;
use Tests\Support\RefusingTransport;
use Tests\Support\SeamLog;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The contact page and its POST, as served and read with no script run. GET /contact and
 * /el/contact show the name, the email address and the message, the trap a person never fills,
 * and Turnstile's widget while both its keys are set, the page keeping the session for the
 * cross-site token, the flash and the errors. The POST runs in this order: the limit, five an
 * hour per address; the trap, a filled one answered as a good message with nothing stored, sent
 * or logged; the fields, each refusal a sentence of contact.php in the page's language; the
 * anti-spam check once every other field has passed; the row, with the page's language; the mail
 * to the support mailbox through the platform's seam, the sender its reply-to; and the thank-you,
 * a redirect with one sentence in the flash.
 *
 * The seam is the recorder, so nothing is mailed, but where a case names the real adapter over a
 * refusing transport; the check is the fake, so nothing reaches Cloudflare. Nothing here enters
 * the transaction runner, so the file is wrapped. The page's head and its h1 are
 * CentralSurfaceTest's walk, which lists the page with no edit to it. A Greek sentence is read
 * through the translator with the locale named, never spelled here.
 */

const CONTACT_ADDRESS = 'help@contact.example';

const CONTACT_SITE_KEY = '0x4AAAAAAAContactSiteKey';

const CONTACT_PAGE_SCRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

beforeEach(function () {
    $this->withoutVite();
    config(['site.contact_address' => CONTACT_ADDRESS]);
    app()->instance(Clock::class, FixedClock::at('2026-10-20 09:30:00.250000'));
});

/**
 * A message as a person fills the form, with the fields given in place of its own.
 *
 * @param  array<mixed>  $overrides
 * @return array<mixed>
 */
function contactForm(array $overrides = []): array
{
    return [
        'name' => 'Perpetua Vandersnoot',
        'email' => 'perpetua.vandersnoot@example.test',
        'message' => "Do you take bookings for a mobile salon?\nWe visit people at home.",
        ...$overrides,
    ];
}

/**
 * An address of the length given, its local part and each label of its domain inside the lengths
 * an address may have, so only the whole length is in question.
 */
function contactAddressOf(int $length): string
{
    $address = 'perpetua@'.str_repeat('a', 60).'.'.str_repeat('b', 60).'.'.str_repeat('c', 60).'.';

    return $address.str_repeat('d', $length - strlen($address) - 5).'.test';
}

/**
 * The platform's seam as the recorder, bound for the page's POST.
 */
function contactSeam(): RecordingPlatformMessages
{
    $seam = new RecordingPlatformMessages;
    app()->instance(PlatformMessenger::class, $seam);

    return $seam;
}

/**
 * Sets both of Turnstile's keys and binds the fake check scripted as asked.
 */
function contactTurnstile(bool $passing): FakeTurnstileCheck
{
    config(['services.turnstile.site_key' => CONTACT_SITE_KEY, 'services.turnstile.secret_key' => '0x4AAAAAAAContactSecretKey']);
    $check = new FakeTurnstileCheck($passing);
    app()->instance(TurnstileCheck::class, $check);

    return $check;
}

/**
 * The contact page's address in a language.
 */
function contactUrl(SiteLanguage $language): string
{
    return app(TenantUrls::class)->central($language->uri('/contact'));
}

/**
 * The contact page in a language as served.
 */
function contactDocument(TestCase $test, SiteLanguage $language): HTMLDocument
{
    $response = $test->get(contactUrl($language));

    $response->assertOk()->assertViewIs('site.contact');

    return siteDocument($response);
}

/**
 * Every contact message stored, oldest first, its instant as UTC text.
 *
 * @return list<array<mixed>>
 */
function contactRows(): array
{
    $rows = [];

    foreach (DB::table('contact_messages')->orderBy('id')->get(['name', 'email', 'message', 'locale', DB::raw("to_char(created_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') as created_at"), 'archived_at']) as $row) {
        $rows[] = get_object_vars($row);
    }

    return $rows;
}

it('shows the form in each language: the three fields with their labels, the trap no person fills, the button, posting to its own address, and keeps the session', function (SiteLanguage $language) {
    $response = $this->get(contactUrl($language));
    $document = siteDocument($response);
    $form = $document->querySelector('form.site-contact');

    expect($form?->getAttribute('method'))->toBe('post')
        ->and($form?->getAttribute('action'))->toBe(contactUrl($language))
        ->and($form?->querySelector('input[name="_token"]')?->getAttribute('type'))->toBe('hidden')
        ->and($document->querySelector('#contact-name')?->getAttribute('name'))->toBe('name')
        ->and($document->querySelector('#contact-name')?->getAttribute('maxlength'))->toBe('200')
        ->and($document->querySelector('#contact-email')?->getAttribute('type'))->toBe('email')
        ->and($document->querySelector('#contact-email')?->getAttribute('maxlength'))->toBe('254')
        ->and($document->querySelector('textarea#contact-message')?->getAttribute('name'))->toBe('message')
        ->and($document->querySelector('textarea#contact-message')?->getAttribute('maxlength'))->toBe('5000')
        ->and(siteTexts($document, 'form.site-contact .site-contact-label'))->toBe([
            siteSentence('contact.fields.name', $language),
            siteSentence('contact.fields.email', $language),
            siteSentence('contact.fields.message', $language),
        ])
        ->and(trim((string) $document->querySelector('button.site-contact-send')?->textContent))->toBe(siteSentence('contact.send', $language))
        ->and(siteTexts($document, '.site-contact-lead'))->toBe([siteSentence('contact.lead', $language)])
        ->and($document->querySelector('.site-contact-notice'))->toBeNull()
        ->and($response->headers->getCookies())->not->toBe([]);

    // The trap: hidden by style and from assistive technology, out of the tab order, never
    // filled by the browser, inside the form so a machine filling every field fills it too.
    $trap = $form?->querySelector('.site-contact-trap');
    $input = $trap?->querySelector('input[name="'.ContactRequest::TRAP.'"]');

    expect($trap?->getAttribute('aria-hidden'))->toBe('true')
        ->and(explode(' ', (string) $trap?->getAttribute('class')))->toContain('absolute', '-left-[10000px]', 'overflow-hidden')
        ->and($input?->getAttribute('tabindex'))->toBe('-1')
        ->and($input?->getAttribute('autocomplete'))->toBe('off')
        ->and($input?->getAttribute('value'))->toBe('')
        ->and(trim((string) $trap?->querySelector('label')?->textContent))->toBe(siteSentence('contact.trap', $language));
})->with([
    'English' => SiteLanguage::English,
    'Greek' => SiteLanguage::Greek,
]);

it('carries no widget and loads no script of Cloudflare\'s while either key is unset', function (?string $site, ?string $secret) {
    config(['services.turnstile.site_key' => $site, 'services.turnstile.secret_key' => $secret]);

    $response = $this->get(contactUrl(SiteLanguage::English));

    expect($response->getContent())->not->toContain('challenges.cloudflare.com')
        ->and(siteDocument($response)->querySelector('.cf-turnstile'))->toBeNull();
})->with([
    'both keys unset' => [null, null],
    'the site key alone unset' => [null, '0x4AAAAAAAContactSecretKey'],
    'the secret key alone unset' => [CONTACT_SITE_KEY, null],
]);

it('draws the widget from the markup inside the form with both keys set, its token posted in the signup\'s field, the page\'s script the one Cloudflare draws it with', function (SiteLanguage $language) {
    contactTurnstile(true);

    $document = contactDocument($this, $language);
    $widget = $document->querySelector('form.site-contact .cf-turnstile');
    $scripts = [];

    foreach ($document->querySelectorAll('script[src*="challenges.cloudflare.com"]') as $script) {
        $scripts[] = $script->getAttribute('src');
    }

    expect($widget?->getAttribute('data-sitekey'))->toBe(CONTACT_SITE_KEY)
        ->and($widget?->getAttribute('data-response-field-name'))->toBe(TurnstileGate::FIELD)
        ->and($widget?->getAttribute('data-language'))->toBe($language->value)
        ->and($scripts)->toBe([CONTACT_PAGE_SCRIPT]);
})->with([
    'English' => SiteLanguage::English,
    'Greek' => SiteLanguage::Greek,
]);

it('loads Cloudflare\'s script on no other page of the site with both keys set: every page in both languages, the 404 and the signup\'s pending page', function () {
    contactTurnstile(true);
    contactSeam();
    $pages = app(SitePages::class);
    $urls = [centralUrl('/no-such-page'), centralUrl('/el/no-such-page')];

    foreach ($pages->names() as $page) {
        foreach ($pages->instances($page) as $parameters) {
            foreach (SiteLanguage::cases() as $language) {
                if ($page !== 'site.contact') {
                    $urls[] = $pages->url($page, $language, $parameters);
                }
            }
        }
    }

    $this->post(centralUrl('/signup'), signupForm([TurnstileGate::FIELD => 'XXXX.DUMMY.TOKEN']))->assertRedirect(centralUrl('/signup/luna/pending'));
    $urls[] = centralUrl('/signup/luna/pending');

    expect($pages->names())->toContain('site.contact')
        ->and(count($urls))->toBeGreaterThan(20);

    foreach ($urls as $url) {
        expect((string) $this->get($url)->getContent())->not->toContain('challenges.cloudflare.com', $url)
            ->not->toContain(CONTACT_SITE_KEY, $url);
    }
});

it('stores a good message with its page\'s language and the clock\'s now, mails it once to the support mailbox with the sender as its reply-to, and thanks the sender in the page\'s language', function (SiteLanguage $language) {
    $seam = contactSeam();

    $this->post(contactUrl($language), contactForm())
        ->assertRedirect(contactUrl($language))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => siteSentence('contact.sent', $language)])
        ->assertSessionHasNoErrors();

    expect(contactRows())->toBe([[
        'name' => 'Perpetua Vandersnoot',
        'email' => 'perpetua.vandersnoot@example.test',
        'message' => "Do you take bookings for a mobile salon?\nWe visit people at home.",
        'locale' => $language->value,
        'created_at' => '2026-10-20 09:30:00.250000',
        'archived_at' => null,
    ]])
        ->and($seam->sent)->toHaveCount(1);

    $message = $seam->sent[0];

    expect($message->kind)->toBe(PlatformMessageKind::ContactMessage)
        ->and($message->tenantId)->toBeNull()
        ->and($message->toAddress)->toBe(CONTACT_ADDRESS)
        ->and($message->replyTo)->toBe('perpetua.vandersnoot@example.test')
        ->and($message->html)->toBeNull()
        ->and($message->subject)->toBe(sprintf(ContactMessageReceived::SUBJECT, siteName()))
        ->and($message->body)->toBe(implode("\n", [
            sprintf('A message came in through the contact form of %s.', siteName()),
            '',
            'Name: Perpetua Vandersnoot',
            'Email: perpetua.vandersnoot@example.test',
            'Language: '.($language === SiteLanguage::English ? 'English' : 'Greek'),
            '',
            'Do you take bookings for a mobile salon?',
            'We visit people at home.',
            '',
            'Reply to this email to answer Perpetua Vandersnoot.',
        ]));

    // The thank-you is the page's one notice on the visit the redirect makes.
    $document = contactDocument($this, $language);

    expect(siteTexts($document, '.site-contact-notice'))->toBe([siteSentence('contact.sent', $language)])
        ->and($document->querySelector('.site-contact-notice')?->getAttribute('role'))->toBe('status');
})->with([
    'English' => SiteLanguage::English,
    'Greek' => SiteLanguage::Greek,
]);

it('mails the message once its row is written, never before', function () {
    $seam = new class implements PlatformMessenger
    {
        /** @var list<int> */
        public array $rowsAtSend = [];

        public function send(PlatformMessageKind $kind, ?int $tenantId, Closure $render): void
        {
            $this->rowsAtSend[] = DB::table('contact_messages')->count();
        }
    };
    app()->instance(PlatformMessenger::class, $seam);

    $this->post(contactUrl(SiteLanguage::English), contactForm())->assertRedirect(contactUrl(SiteLanguage::English));

    expect($seam->rowsAtSend)->toBe([1]);
});

it('answers a filled trap exactly as a good message, the thank-you, storing nothing, sending nothing, asking nothing and logging nothing, even with every other field refused', function (array $form) {
    $seam = contactSeam();
    $check = contactTurnstile(false);
    $log = new SeamLog;
    Log::swap($log);

    $this->post(contactUrl(SiteLanguage::Greek), [...$form, ContactRequest::TRAP => 'https://spam.example'])
        ->assertRedirect(contactUrl(SiteLanguage::Greek))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => siteSentence('contact.sent', SiteLanguage::Greek)])
        ->assertSessionHasNoErrors();

    expect(contactRows())->toBe([])
        ->and($seam->sent)->toBe([])
        ->and($check->asked)->toBe([])
        ->and($log->records)->toBe([])
        ->and(session()->getOldInput())->toBe([]);
})->with([
    'a whole message' => [contactForm()],
    'every field empty' => [['name' => '', 'email' => '', 'message' => '']],
]);

it('refuses {case} with its sentence of contact.php under its field, the input flashed back, nothing stored or sent', function (array $overrides, string $field, string $key) {
    $seam = contactSeam();
    $check = contactTurnstile(true);

    $this->from(contactUrl(SiteLanguage::English))
        ->post(contactUrl(SiteLanguage::English), [...contactForm($overrides), TurnstileGate::FIELD => 'XXXX.DUMMY.TOKEN'])
        ->assertRedirect(contactUrl(SiteLanguage::English))
        ->assertSessionHasErrors([$field => siteSentence($key, SiteLanguage::English)]);

    // The fields that passed are flashed back as they were posted.
    foreach (array_diff_key(contactForm(), [$field => true]) as $kept => $value) {
        expect(session()->getOldInput($kept))->toBe($value);
    }

    // The fields come before the anti-spam check, so a refused field spends no token.
    $errors = session('errors');

    assert($errors instanceof ViewErrorBag);

    expect($errors->getBag('default')->keys())->toBe([$field])
        ->and(session()->getOldInput(TurnstileGate::FIELD))->toBeNull()
        ->and($check->asked)->toBe([])
        ->and(contactRows())->toBe([])
        ->and($seam->sent)->toBe([]);
})->with([
    'no name' => [['name' => ''], 'name', 'contact.errors.name_required'],
    'a name of blanks' => [['name' => '   '], 'name', 'contact.errors.name_required'],
    'a name over 200 characters' => [['name' => str_repeat('a', 201)], 'name', 'contact.errors.name_long'],
    'no email' => [['email' => ''], 'email', 'contact.errors.email_required'],
    'an email that is no address' => [['email' => 'perpetua at example'], 'email', 'contact.errors.email_invalid'],
    'an email over 254 characters' => [['email' => contactAddressOf(255)], 'email', 'contact.errors.email_long'],
    'no message' => [['message' => ''], 'message', 'contact.errors.message_required'],
    'a message over 5000 characters' => [['message' => str_repeat('a', 5001)], 'message', 'contact.errors.message_long'],
]);

it('takes a message at each limit: a name of 200 characters, an email of 254 and a message of 5000', function () {
    $seam = contactSeam();
    $form = contactForm(['name' => str_repeat('a', 200), 'email' => contactAddressOf(254), 'message' => str_repeat('a', 5000)]);

    expect(strlen(contactAddressOf(254)))->toBe(254)
        ->and(strlen(contactAddressOf(255)))->toBe(255);

    $this->post(contactUrl(SiteLanguage::English), $form)->assertSessionHasNoErrors()->assertRedirect(contactUrl(SiteLanguage::English));

    expect(contactRows())->toHaveCount(1)
        ->and($seam->sent)->toHaveCount(1);
});

it('reads each language\'s refusals from its own file, the Greek page\'s from lang/el', function () {
    contactSeam();
    // A Greek line is laid over the loaded Greek file to tell the test's line from the file's,
    // whatever the file holds.
    __('contact.title', [], 'el');
    app('translator')->addLines(['contact.errors.name_required' => 'GREEK NAME REFUSAL'], 'el');

    $this->post(contactUrl(SiteLanguage::Greek), contactForm(['name' => '']))
        ->assertRedirect(contactUrl(SiteLanguage::Greek))
        ->assertSessionHasErrors(['name' => 'GREEK NAME REFUSAL']);
    $this->post(contactUrl(SiteLanguage::English), contactForm(['name' => '']))
        ->assertRedirect(contactUrl(SiteLanguage::English))
        ->assertSessionHasErrors(['name' => siteSentence('contact.errors.name_required', SiteLanguage::English)]);

    expect(siteSentence('contact.errors.name_required', SiteLanguage::English))->not->toBe('GREEK NAME REFUSAL');
});

it('shows a refusal under its field on the page the refusal sends back to, the input in its field', function () {
    contactSeam();

    $this->post(contactUrl(SiteLanguage::English), contactForm(['email' => 'perpetua at example']))->assertRedirect(contactUrl(SiteLanguage::English));

    $document = contactDocument($this, SiteLanguage::English);

    expect(siteTexts($document, '#contact-email-error'))->toBe([siteSentence('contact.errors.email_invalid', SiteLanguage::English)])
        ->and($document->querySelector('#contact-email')?->getAttribute('aria-invalid'))->toBe('true')
        ->and($document->querySelector('#contact-email')?->getAttribute('aria-describedby'))->toBe('contact-email-error')
        ->and($document->querySelector('#contact-email')?->getAttribute('value'))->toBe('perpetua at example')
        ->and($document->querySelector('#contact-name')?->getAttribute('value'))->toBe('Perpetua Vandersnoot')
        ->and(trim((string) $document->querySelector('#contact-message')?->textContent))->toBe("Do you take bookings for a mobile salon?\nWe visit people at home.")
        ->and($document->querySelector('#contact-name')?->getAttribute('aria-invalid'))->toBeNull();
});

it('refuses {case} with the check\'s sentence on the widget once every other field has passed, nothing stored, nothing sent and no token flashed back', function (?string $token, int $asked) {
    $check = contactTurnstile(false);
    $seam = contactSeam();

    $this->post(contactUrl(SiteLanguage::English), contactForm($token === null ? [] : [TurnstileGate::FIELD => $token]))
        ->assertRedirect(contactUrl(SiteLanguage::English))
        ->assertSessionHasErrors([TurnstileGate::FIELD => siteSentence('contact.errors.check', SiteLanguage::English)])
        ->assertSessionHasInput('message', "Do you take bookings for a mobile salon?\nWe visit people at home.");

    expect($check->asked)->toHaveCount($asked)
        ->and(session()->getOldInput(TurnstileGate::FIELD))->toBeNull()
        ->and(contactRows())->toBe([])
        ->and($seam->sent)->toBe([]);
})->with([
    'a token the check fails' => ['XXXX.DUMMY.TOKEN', 1],
    'no token' => [null, 0],
    'an empty token' => ['', 0],
    'a token longer than any Cloudflare issues' => [str_repeat('a', 2049), 0],
]);

it('asks the check once about the token and the posting address, then stores and sends', function () {
    $seam = contactSeam();
    $check = contactTurnstile(true);

    $this->post(contactUrl(SiteLanguage::English), contactForm([TurnstileGate::FIELD => 'XXXX.DUMMY.TOKEN']))
        ->assertRedirect(contactUrl(SiteLanguage::English))
        ->assertSessionHasNoErrors();

    expect($check->asked)->toBe([['token' => 'XXXX.DUMMY.TOKEN', 'remoteAddress' => '127.0.0.1']])
        ->and(contactRows())->toHaveCount(1)
        ->and($seam->sent)->toHaveCount(1);
});

it('shows the check\'s refusal under the widget on the page it sends back to, the widget drawn anew', function () {
    contactTurnstile(false);
    contactSeam();

    $this->post(contactUrl(SiteLanguage::English), contactForm([TurnstileGate::FIELD => 'XXXX.DUMMY.TOKEN']));

    $document = contactDocument($this, SiteLanguage::English);

    expect(siteTexts($document, '#contact-turnstile-error'))->toBe([siteSentence('contact.errors.check', SiteLanguage::English)])
        ->and($document->querySelector('form.site-contact .cf-turnstile'))->not->toBeNull();
});

it('answers the sixth post in an hour from one address at 429 with the page and its refusal above the form, both languages on one count, the trap\'s posts counted too, nothing stored or sent past it', function () {
    $seam = contactSeam();

    $this->post(contactUrl(SiteLanguage::English), contactForm())->assertRedirect();
    $this->post(contactUrl(SiteLanguage::Greek), contactForm())->assertRedirect();
    $this->post(contactUrl(SiteLanguage::English), contactForm(['name' => '']))->assertRedirect();
    $this->post(contactUrl(SiteLanguage::Greek), [...contactForm(), ContactRequest::TRAP => 'filled'])->assertRedirect();
    $this->post(contactUrl(SiteLanguage::English), contactForm())->assertRedirect();

    $response = $this->post(contactUrl(SiteLanguage::Greek), contactForm());

    $response->assertStatus(429)->assertViewIs('site.contact')->assertHeader('Retry-After');
    $document = siteDocument($response);
    $notice = $document->querySelector('.site-contact-notice');

    expect(trim((string) $notice?->textContent))->toBe(siteSentence('contact.too_many', SiteLanguage::Greek))
        ->and($notice?->getAttribute('role'))->toBe('status')
        ->and($document->documentElement?->getAttribute('lang'))->toBe('el')
        ->and($document->title)->toBe(siteSentence('contact.title', SiteLanguage::Greek))
        ->and($document->querySelector('link[rel="canonical"]')?->getAttribute('href'))->toBe(contactUrl(SiteLanguage::Greek))
        ->and($document->querySelector('meta[name="robots"]'))->toBeNull()
        ->and($document->querySelector('form.site-contact')?->getAttribute('action'))->toBe(contactUrl(SiteLanguage::Greek))
        ->and($document->querySelector('#contact-name')?->getAttribute('value'))->toBe('Perpetua Vandersnoot')
        ->and(contactRows())->toHaveCount(3)
        ->and($seam->sent)->toHaveCount(3);

    $this->post(contactUrl(SiteLanguage::English), contactForm())->assertStatus(429);

    expect(contactRows())->toHaveCount(3);
});

it('counts another address apart', function () {
    contactSeam();

    for ($i = 0; $i < 5; $i++) {
        $this->post(contactUrl(SiteLanguage::English), contactForm())->assertRedirect();
    }

    $this->post(contactUrl(SiteLanguage::English), contactForm())->assertStatus(429);
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->post(contactUrl(SiteLanguage::English), contactForm())->assertRedirect();

    expect(contactRows())->toHaveCount(6);
});

it('keeps the row when the transport fails, the thank-you as before, and logs one error naming the kind alone, never the address, the sender or the message', function () {
    config(['mail.from.address' => 'hello@kleiseradevou.test']);
    $log = new SeamLog;
    Log::swap($log);
    $transport = new RefusingTransport;
    seamMailer()->setSymfonyTransport($transport);

    $this->post(contactUrl(SiteLanguage::English), contactForm())
        ->assertRedirect(contactUrl(SiteLanguage::English))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => siteSentence('contact.sent', SiteLanguage::English)]);

    expect($transport->refused)->toHaveCount(1)
        ->and(contactRows())->toHaveCount(1)
        ->and($log->records)->toHaveCount(1)
        ->and($log->errors()[0]['context'])->toBe([
            'tenant' => null,
            'kind' => 'contact_message',
            'exception' => TransportException::class,
        ]);

    foreach ([CONTACT_ADDRESS, 'perpetua', 'Perpetua', 'Vandersnoot', 'mobile salon', 'at home'] as $secret) {
        expect($log->text())->not->toContain($secret);
    }
});

it('logs no part of the message when the row cannot be written, the statement\'s values kept out of the error', function () {
    $seam = contactSeam();
    $log = new SeamLog;
    Log::swap($log);
    DB::statement('drop table contact_messages');

    $this->post(contactUrl(SiteLanguage::English), contactForm())->assertStatus(500);

    expect($log->errors())->not->toBe([])
        ->and($seam->sent)->toBe([]);

    foreach (['perpetua', 'Perpetua', 'Vandersnoot', 'mobile salon', 'at home'] as $secret) {
        expect($log->text())->not->toContain($secret);
    }
});
