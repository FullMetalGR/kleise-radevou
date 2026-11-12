<?php

use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use App\Notifications\BrandedMails;
use App\Notifications\VerificationLink;
use App\Site\Brand;
use App\Tenancy\Languages;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\FileViewFinder;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\RefusingTransport;
use Tests\Support\SeamLog;

pest()->use(RefreshDatabase::class);

/*
 * The platform's own mail seam, beside the tenant's: a message sent in the platform's name to one
 * address, with no tenant switch, no booking and no reply-to but the one a message names, the
 * contact form's sender, as text alone or as a multipart where the message carries an HTML part,
 * and a promise never to throw. Nothing here enters the transaction runner, so the file is
 * wrapped, and the tenant the switch test provisions rolls back with the test.
 */

beforeEach(function () {
    config(['mail.from.address' => 'hello@kleiseradevou.test', 'mail.from.name' => siteName()]);
    seamTransport()->flush();
});

function platformMessage(?int $tenantId, PlatformMessageKind $kind = PlatformMessageKind::Verification): PlatformMessage
{
    return new PlatformMessage(
        kind: $kind,
        tenantId: $tenantId,
        toAddress: 'perpetua.vandersnoot@example.test',
        toName: 'Perpetua Vandersnoot',
        subject: 'Confirm your email address',
        body: "Open this link to confirm your address:\nhttps://kleiseradevou.test/verify/abc\n",
    );
}

/**
 * Hands the message to the seam under its own kind and tenant, written by a closure that answers
 * it as given.
 */
function platformSend(PlatformMessage $message): void
{
    app(PlatformMessenger::class)->send($message->kind, $message->tenantId, fn (): PlatformMessage => $message);
}

it('sends one message in the platform\'s name, from the platform\'s address, with no reply-to, to the one address given, with the subject and the body as given', function () {
    platformSend(platformMessage(41));

    $message = seamOnly();

    expect(array_map(fn ($address): string => $address->getAddress(), $message->getFrom()))->toBe(['hello@kleiseradevou.test'])
        ->and(array_map(fn ($address): string => $address->getName(), $message->getFrom()))->toBe([siteName()])
        ->and($message->getReplyTo())->toBe([])
        ->and(array_map(fn ($address): string => $address->toString(), $message->getTo()))->toBe(['"Perpetua Vandersnoot" <perpetua.vandersnoot@example.test>'])
        ->and($message->getCc())->toBe([])
        ->and($message->getBcc())->toBe([])
        ->and($message->getSubject())->toBe('Confirm your email address')
        ->and($message->getTextBody())->toBe("Open this link to confirm your address:\nhttps://kleiseradevou.test/verify/abc\n")
        ->and($message->getHtmlBody())->toBeNull()
        ->and($message->getAttachments())->toBe([]);
});

it('sends to a tenant that switched every key off, its own kind spelled on the list too, since the platform\'s messages have no switch', function () {
    $tenant = provisionTenant('acme')->tenant;
    $off = [...seamEveryKeyBut(), ...array_map(fn (PlatformMessageKind $kind): string => $kind->value, PlatformMessageKind::cases())];
    DB::statement('update tenant_acme.settings set emails_off = ?::text[] where id = 1', ['{'.implode(',', $off).'}']);

    foreach (PlatformMessageKind::cases() as $kind) {
        platformSend(platformMessage($tenant->id, $kind));
    }

    expect(seamSent())->toHaveCount(count(PlatformMessageKind::cases()))
        ->and(array_map(fn ($message): ?string => $message->getSubject(), seamSent()))->each->toBe('Confirm your email address');
});

it('answers nothing when the transport throws, and logs one error naming the tenant id and the kind and never the address', function () {
    $log = new SeamLog;
    Log::swap($log);
    $transport = new RefusingTransport;
    seamMailer()->setSymfonyTransport($transport);

    platformSend(platformMessage(41, PlatformMessageKind::TrialNotice));

    $errors = $log->errors();

    expect($transport->refused)->toHaveCount(1)
        ->and($log->records)->toHaveCount(1)
        ->and($errors)->toHaveCount(1)
        ->and($errors[0]['context'])->toBe([
            'tenant' => 41,
            'kind' => 'trial_notice',
            'exception' => TransportException::class,
        ])
        ->and($log->text())->not->toContain('perpetua.vandersnoot')
        ->and($log->text())->not->toContain('Perpetua Vandersnoot')
        ->and($log->text())->not->toContain('https://kleiseradevou.test/verify/abc');
});

it('logs a failed message about no tenant against the kind alone, the tenant null', function () {
    $log = new SeamLog;
    Log::swap($log);
    seamMailer()->setSymfonyTransport(new RefusingTransport);

    platformSend(platformMessage(null, PlatformMessageKind::OperatorSignIn));

    expect($log->errors())->toHaveCount(1)
        ->and($log->errors()[0]['context'])->toBe([
            'tenant' => null,
            'kind' => 'operator_sign_in',
            'exception' => TransportException::class,
        ])
        ->and($log->text())->not->toContain('perpetua.vandersnoot');
});

it('sends a message about no tenant as it sends any other, to the one address given', function () {
    platformSend(platformMessage(null, PlatformMessageKind::OperatorSignIn));

    expect(array_map(fn ($address): string => $address->getAddress(), seamOnly()->getTo()))->toBe(['perpetua.vandersnoot@example.test']);
});

it('names the platform\'s messages and nothing else: the verification link, the trial notice, the closing email, the operator\'s sign-in notice, the suspension\'s two, the set-password link, the contact form\'s message, the welcome email and the console\'s reopen', function () {
    expect(array_map(fn (PlatformMessageKind $kind): string => $kind->value, PlatformMessageKind::cases()))
        ->toBe(['verification', 'trial_notice', 'closing', 'operator_sign_in', 'suspension', 'resumption', 'set_password', 'contact_message', 'welcome', 'reopened']);
});

it('sets the reply-to a message names, the contact form\'s sender, and nothing else of the message changes', function () {
    $message = platformMessage(null, PlatformMessageKind::ContactMessage);

    platformSend(new PlatformMessage(
        kind: $message->kind,
        tenantId: null,
        toAddress: 'support@contact.example',
        toName: '',
        subject: $message->subject,
        body: $message->body,
        replyTo: 'perpetua.vandersnoot@example.test',
    ));

    $sent = seamOnly();

    expect(array_map(fn ($address): string => $address->toString(), $sent->getReplyTo()))->toBe(['perpetua.vandersnoot@example.test'])
        ->and(array_map(fn ($address): string => $address->toString(), $sent->getTo()))->toBe(['support@contact.example'])
        ->and(array_map(fn ($address): string => $address->getAddress(), $sent->getFrom()))->toBe(['hello@kleiseradevou.test'])
        ->and($sent->getTextBody())->toBe($message->body);
});

it('carries no reply-to on a message that names none, and refuses a blank one', function () {
    expect(platformMessage(41)->replyTo)->toBeNull()
        ->and(fn () => new PlatformMessage(PlatformMessageKind::ContactMessage, null, 'support@contact.example', '', 'Subject', 'Body', ' '))
        ->toThrow(InvalidArgumentException::class);
});

it('sends a multipart where a message carries an HTML part, the text part the body as given and the HTML part as given, and nothing else of the message changes', function () {
    $message = platformMessage(41);

    platformSend(new PlatformMessage(
        kind: $message->kind,
        tenantId: 41,
        toAddress: $message->toAddress,
        toName: $message->toName,
        subject: $message->subject,
        body: $message->body,
        html: '<p>Open this link to confirm your address.</p>',
    ));

    $sent = seamOnly();

    expect($sent->getTextBody())->toBe($message->body)
        ->and($sent->getHtmlBody())->toBe('<p>Open this link to confirm your address.</p>')
        ->and(array_map(fn ($address): string => $address->toString(), $sent->getTo()))->toBe(['"Perpetua Vandersnoot" <perpetua.vandersnoot@example.test>'])
        ->and(array_map(fn ($address): string => $address->getAddress(), $sent->getFrom()))->toBe(['hello@kleiseradevou.test'])
        ->and($sent->getReplyTo())->toBe([])
        ->and($sent->getSubject())->toBe('Confirm your email address')
        ->and($sent->getAttachments())->toBe([]);
});

it('carries no HTML part on a message that names none, and refuses a blank one', function () {
    expect(platformMessage(41)->html)->toBeNull()
        ->and(fn () => new PlatformMessage(PlatformMessageKind::Verification, 41, 'perpetua.vandersnoot@example.test', '', 'Subject', 'Body', null, ' '))
        ->toThrow(InvalidArgumentException::class);
});

it('logs a failed multipart as it logs a failed text, the tenant id and the kind and nothing the HTML part says', function () {
    $log = new SeamLog;
    Log::swap($log);
    seamMailer()->setSymfonyTransport(new RefusingTransport);
    $message = platformMessage(41);

    platformSend(new PlatformMessage(
        kind: $message->kind,
        tenantId: 41,
        toAddress: $message->toAddress,
        toName: $message->toName,
        subject: $message->subject,
        body: $message->body,
        html: '<p><a href="https://kleiseradevou.test/verify/abc">https://kleiseradevou.test/verify/abc</a></p>',
    ));

    expect($log->errors())->toHaveCount(1)
        ->and($log->errors()[0]['context'])->toBe([
            'tenant' => 41,
            'kind' => 'verification',
            'exception' => TransportException::class,
        ])
        ->and($log->text())->not->toContain('perpetua.vandersnoot')
        ->and($log->text())->not->toContain('verify/abc');
});

it('writes the message inside its promise: a render that throws is one logged error naming the tenant id, the kind and the class, and nothing is sent', function () {
    $log = new SeamLog;
    Log::swap($log);

    app(PlatformMessenger::class)->send(PlatformMessageKind::TrialNotice, 41, fn (): PlatformMessage => throw new RuntimeException('perpetua.vandersnoot@example.test'));

    expect(seamSent())->toBe([])
        ->and($log->records)->toBe([[
            'level' => 'error',
            'message' => 'A platform message could not be rendered',
            'context' => ['tenant' => 41, 'kind' => 'trial_notice', 'exception' => RuntimeException::class],
        ]])
        ->and($log->text())->not->toContain('perpetua.vandersnoot');
});

it('refuses a message written under another kind or about another tenant than it was handed under, as a render that failed', function (PlatformMessage $written) {
    $log = new SeamLog;
    Log::swap($log);

    app(PlatformMessenger::class)->send(PlatformMessageKind::Verification, 41, fn (): PlatformMessage => $written);

    expect(seamSent())->toBe([])
        ->and($log->errors())->toBe([[
            'level' => 'error',
            'message' => 'A platform message could not be rendered',
            'context' => ['tenant' => 41, 'kind' => 'verification', 'exception' => LogicException::class],
        ]]);
})->with([
    'another kind' => fn (): PlatformMessage => platformMessage(41, PlatformMessageKind::Welcome),
    'another tenant' => fn (): PlatformMessage => platformMessage(42),
    'about no tenant' => fn (): PlatformMessage => platformMessage(null),
]);

it('answers the caller of a mail whose layout cannot be rendered as it answers a sent one, the failure logged by the seam and nothing sent', function () {
    $log = new SeamLog;
    Log::swap($log);
    $tenant = provisionTenant('acme')->tenant;
    $noViews = new ViewFactory(app('view.engine.resolver'), new FileViewFinder(app('files'), [base_path('tests/no-such-views')]), app('events'));
    app()->instance(BrandedMails::class, new BrandedMails(app(Translator::class), $noViews, app(Brand::class), app(Languages::class)));

    app(VerificationLink::class)->send($tenant, 'perpetua.vandersnoot@example.test', 'Perpetua Vandersnoot', str_repeat('a', 64));

    expect(seamSent())->toBe([])
        ->and($log->errors())->toBe([[
            'level' => 'error',
            'message' => 'A platform message could not be rendered',
            'context' => ['tenant' => $tenant->id, 'kind' => 'verification', 'exception' => InvalidArgumentException::class],
        ]])
        ->and($log->text())->not->toContain('perpetua.vandersnoot');
});
