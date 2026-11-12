<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use Closure;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message as MailMessage;
use Illuminate\Support\HtmlString;
use LogicException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The platform port's one adapter: the framework's mailer, the deployment's own address, and a
 * promise never to throw.
 *
 * Plain text from config's mail address and name, as the tenant's seam sends, to the one address
 * the message names with the name behind it, and with no reply-to unless the message names one:
 * the platform is the sender and a reply reaches the from address, which is the platform, but for
 * the contact form's message, whose reply-to is the person who wrote it, so the support mailbox
 * answers them by replying. Where the message carries an HTML part, the send is a multipart, the
 * text part the body as given and the HTML part as given: the closure that writes the message
 * renders the layout, so no view is named to the mailer here and the layout has one road, the
 * mail that composed it. No tenant's settings are read, because the platform's messages sit
 * outside the catalog and no switch reaches them.
 *
 * **It never throws, and the render is inside the promise.** The message arrives unwritten, as
 * its kind, its tenant and the closure that writes it, and the closure runs here, inside the
 * catch. A render that throws is its own line, "could not be rendered", and nothing is sent; a
 * message the closure answers under another kind or about another tenant than it was handed under
 * is refused the same way, since those two are what the failure lines name. A failed send is the
 * line "could not be sent". Each line names the tenant's id, null for a message about no tenant,
 * and the kind, and never the address or the exception's own message, which is where an address
 * usually leaks; the exception's class is kept because it separates a refused mailbox from a
 * network that was not there, the same line the tenant's seam writes.
 */
final readonly class MailedPlatformMessages implements PlatformMessenger
{
    public function __construct(
        private Mailer $mailer,
        private LoggerInterface $log,
        #[Config('mail.from.address')] private string $platformAddress,
        #[Config('mail.from.name')] private ?string $platformName = null,
    ) {}

    /**
     * @param  Closure(): PlatformMessage  $render
     */
    public function send(PlatformMessageKind $kind, ?int $tenantId, Closure $render): void
    {
        try {
            $message = $render();

            if ($message->kind !== $kind || $message->tenantId !== $tenantId) {
                throw new LogicException('A platform message was written under another kind or about another tenant than it was handed under.');
            }
        } catch (Throwable $failure) {
            $this->failed('A platform message could not be rendered', $kind, $tenantId, $failure);

            return;
        }

        try {
            $parts = $message->html === null
                ? ['raw' => $message->body]
                : ['raw' => $message->body, 'html' => new HtmlString($message->html)];

            $this->mailer->send($parts, [], function (MailMessage $mail) use ($message): void {
                $mail->from($this->platformAddress, $this->platformName)
                    ->to($message->toAddress, trim($message->toName) === '' ? null : $message->toName)
                    ->subject($message->subject);

                if ($message->replyTo !== null) {
                    $mail->replyTo($message->replyTo);
                }
            });
        } catch (Throwable $failure) {
            $this->failed('A platform message could not be sent', $kind, $tenantId, $failure);
        }
    }

    /**
     * The one error line a failure leaves: the tenant's id, the kind and the exception's class,
     * and never the address or the exception's own message.
     */
    private function failed(string $line, PlatformMessageKind $kind, ?int $tenantId, Throwable $failure): void
    {
        $this->log->error($line, [
            'tenant' => $tenantId,
            'kind' => $kind->value,
            'exception' => $failure::class,
        ]);
    }
}
