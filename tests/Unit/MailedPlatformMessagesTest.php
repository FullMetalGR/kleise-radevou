<?php

use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessageKind;
use App\Infrastructure\Notification\MailedPlatformMessages;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;

/*
 * The platform port's adapter on a mailer built by hand, no container and no database: what it
 * hands the transport for a message with an HTML part and for one without. The mailer is built on a
 * view factory that refuses every call, since the adapter renders nothing through the mailer: an
 * HTML part arrives rendered by the closure that wrote the message, and the text part is the body
 * as given, so a view named here would be a second road to the layout. The feature suite reads the
 * same adapter through the container, PlatformMessageSeamTest.
 */

/**
 * A view factory the adapter must never ask anything of.
 */
function platformMailViews(): ViewFactory
{
    return new class implements ViewFactory
    {
        public function exists($view): never
        {
            throw new LogicException('The platform adapter renders no view through the mailer.');
        }

        /**
         * @param  array<mixed>|Arrayable<array-key, mixed>  $data
         * @param  array<mixed>  $mergeData
         */
        public function file($path, $data = [], $mergeData = []): never
        {
            throw new LogicException('The platform adapter renders no view through the mailer.');
        }

        /**
         * @param  array<mixed>|Arrayable<array-key, mixed>  $data
         * @param  array<mixed>  $mergeData
         */
        public function make($view, $data = [], $mergeData = []): never
        {
            throw new LogicException('The platform adapter renders no view through the mailer.');
        }

        /**
         * @param  array<mixed>|string  $key
         */
        public function share($key, $value = null): never
        {
            throw new LogicException('The platform adapter renders no view through the mailer.');
        }

        /**
         * @param  array<mixed>|string  $views
         */
        public function composer($views, $callback): never
        {
            throw new LogicException('The platform adapter renders no view through the mailer.');
        }

        /**
         * @param  array<mixed>|string  $views
         */
        public function creator($views, $callback): never
        {
            throw new LogicException('The platform adapter renders no view through the mailer.');
        }

        /**
         * @param  array<mixed>|string  $hints
         */
        public function addNamespace($namespace, $hints): never
        {
            throw new LogicException('The platform adapter renders no view through the mailer.');
        }

        /**
         * @param  array<mixed>|string  $hints
         */
        public function replaceNamespace($namespace, $hints): never
        {
            throw new LogicException('The platform adapter renders no view through the mailer.');
        }
    };
}

/**
 * The adapter over a mailer whose transport keeps what it is handed.
 */
function platformAdapterOver(ArrayTransport $transport): MailedPlatformMessages
{
    return new MailedPlatformMessages(new Mailer('array', platformMailViews(), $transport), new NullLogger, 'hello@kleiseradevou.test', 'Example Bookings');
}

/**
 * The one message the transport was handed.
 */
function platformTransported(ArrayTransport $transport): Email
{
    $messages = $transport->messages();

    expect($messages)->toHaveCount(1);

    $sent = $messages->first();

    assert($sent instanceof SentMessage);

    $original = $sent->getOriginalMessage();

    assert($original instanceof Email);

    return $original;
}

/**
 * A message of the seam, with the HTML part given or none.
 */
function platformMailOf(?string $html): PlatformMessage
{
    return new PlatformMessage(
        kind: PlatformMessageKind::Verification,
        tenantId: 41,
        toAddress: 'perpetua.vandersnoot@example.test',
        toName: 'Perpetua Vandersnoot',
        subject: 'Confirm your email address',
        body: "Open this link to confirm your address:\nhttps://kleiseradevou.test/verify/abc\n",
        html: $html,
    );
}

it('builds a multipart from a message with an HTML part: the text part the body as given, the HTML part as given, from the platform to the one address', function () {
    $transport = new ArrayTransport;

    $message = platformMailOf('<p><a href="https://kleiseradevou.test/verify/abc">https://kleiseradevou.test/verify/abc</a></p>');
    platformAdapterOver($transport)->send($message->kind, $message->tenantId, fn (): PlatformMessage => $message);

    $sent = platformTransported($transport);

    expect($sent->getTextBody())->toBe("Open this link to confirm your address:\nhttps://kleiseradevou.test/verify/abc\n")
        ->and($sent->getHtmlBody())->toBe('<p><a href="https://kleiseradevou.test/verify/abc">https://kleiseradevou.test/verify/abc</a></p>')
        ->and($sent->getBody()->getMediaSubtype())->toBe('alternative')
        ->and(array_map(fn ($address): string => $address->toString(), $sent->getFrom()))->toBe(['"Example Bookings" <hello@kleiseradevou.test>'])
        ->and(array_map(fn ($address): string => $address->toString(), $sent->getTo()))->toBe(['"Perpetua Vandersnoot" <perpetua.vandersnoot@example.test>'])
        ->and($sent->getSubject())->toBe('Confirm your email address')
        ->and($sent->getReplyTo())->toBe([])
        ->and($sent->getAttachments())->toBe([]);
});

it('builds text alone from a message with no HTML part', function () {
    $transport = new ArrayTransport;

    $message = platformMailOf(null);
    platformAdapterOver($transport)->send($message->kind, $message->tenantId, fn (): PlatformMessage => $message);

    $sent = platformTransported($transport);

    expect($sent->getTextBody())->toBe("Open this link to confirm your address:\nhttps://kleiseradevou.test/verify/abc\n")
        ->and($sent->getHtmlBody())->toBeNull()
        ->and($sent->getBody()->getMediaSubtype())->toBe('plain');
});
