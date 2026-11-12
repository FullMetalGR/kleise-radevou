<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Messenger;
use App\Infrastructure\Db\SettingsReader;
use App\Models\User;
use App\Tenancy\Role;
use App\Tenancy\Tenant;
use Closure;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message as MailMessage;
use LogicException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The port's one adapter: the framework's mailer, one tenant's settings, and a promise never to
 * throw.
 *
 * Three things happen here and nowhere above. The tenant's off switches are applied, and a message
 * whose key is on emails_off is not sent; the list is stored as the negative, so a key this build
 * has never heard of is simply not on it and a message added later sends by default. Whatever
 * files the message carries are attached. And the message goes out from the platform's own address
 * with the tenant's owner as its reply-to: the platform sends, so signup needs no DNS work and
 * deliverability is one thing to debug rather than one per tenant, and a customer pressing reply
 * still reaches the salon.
 *
 * **It never throws.** By the time anything reaches here the transition has committed: there is
 * nothing to roll back, nobody to answer with an error, and a broken transport must not turn a
 * booking that was taken into a booking that failed. Every failure is one logged error.
 *
 * **The render is one of them**. The message arrives unwritten, as its key, its booking and the
 * closure that writes it; the switch is asked first, so a key the tenant switched off is never
 * written, and the closure runs here, inside the catch. A render that throws is its own line,
 * "could not be rendered", and nothing is sent; a message the closure answers under another key or
 * about another booking than the ones it was handed under is refused the same way, since the
 * switch asked was the one for the key named.
 *
 * **That line names the tenant, the message and the booking, and never the address.** Not even
 * the transport's own message, which is where an address usually leaks: a failed SMTP command
 * is reported with the recipient in it, and a log is not a place to put a customer. The
 * exception's class is kept because it separates a refused mailbox from a network that was not
 * there; the booking uuid is what an operator follows to find out who was not written to,
 * through a store that is meant to hold that. A render's line keeps to the same rule, the class
 * and never the message, which may quote a value the render was handed.
 */
final readonly class MailedMessages implements Messenger
{
    public function __construct(
        private Mailer $mailer,
        private LoggerInterface $log,
        private SettingsReader $settings,
        private Tenant $tenant,
        private string $platformAddress,
        private ?string $platformName,
    ) {}

    /**
     * @param  Closure(): Message  $render
     */
    public function send(MessageKey $key, ?string $bookingUuid, Closure $render): void
    {
        try {
            if (! $this->sends($key)) {
                return;
            }
        } catch (Throwable $failure) {
            $this->failed('A message could not be sent', $key, $bookingUuid, $failure);

            return;
        }

        try {
            $message = $render();

            if ($message->key !== $key || $message->bookingUuid !== $bookingUuid) {
                throw new LogicException('A message was written under another key or about another booking than it was handed under.');
            }
        } catch (Throwable $failure) {
            $this->failed('A message could not be rendered', $key, $bookingUuid, $failure);

            return;
        }

        try {
            $replyTo = $this->replyTo($message);

            $this->mailer->raw($message->body, function (MailMessage $mail) use ($message, $replyTo): void {
                $mail->from($this->platformAddress, $this->platformName)
                    ->to($message->to->address)
                    ->subject($message->subject);

                if ($replyTo !== null) {
                    $mail->replyTo($replyTo);
                }

                foreach ($message->attachments as $file) {
                    $mail->attachData($file->contents, $file->name, ['mime' => $file->contentType]);
                }
            });
        } catch (Throwable $failure) {
            $this->failed('A message could not be sent', $key, $bookingUuid, $failure);
        }
    }

    /**
     * Whether a message of this key would go out at all, which is the drop above asked as a
     * question. A message whose words point at another message reads it before it prints the
     * sentence, and it reads it here so that a template can never acquire a second idea of
     * what the tenant switched off.
     *
     * It is the tenant's list as it stands now. A tenant who switched a key off after that
     * message went out, or on after it did not, is the one case the row cannot answer for, and
     * nothing records per booking which messages were sent.
     */
    public function sends(MessageKey $key): bool
    {
        return $this->settings->read($this->tenant)->sends($key);
    }

    /**
     * The one error line a failure leaves: the tenant, the key, the booking and the exception's
     * class, and never the address or the exception's own message.
     */
    private function failed(string $line, MessageKey $key, ?string $bookingUuid, Throwable $failure): void
    {
        $this->log->error($line, [
            'tenant' => $this->tenant->slug,
            'key' => $key->value,
            'booking' => $bookingUuid,
            'exception' => $failure::class,
        ]);
    }

    /**
     * Where a reply goes: the tenant's owner. Read by role rather than kept on the tenant row,
     * because the owner is a user and a tenant whose owner changed must not go on answering to
     * the person who left. The lowest id wins where a tenant somehow has two, so the address is
     * stable rather than whichever the planner happened to return.
     *
     * **A tenant with no owner row still gets its message, with no reply-to on it.** That is a
     * fault provisioning cannot make and a hand-edited database can, and the two ways to answer
     * it are to send without a reply-to or not to send. Not sending is the worse of them by
     * some distance: this message is the customer's only durable copy of their magic link, and
     * withholding it over the sender's own bookkeeping punishes the wrong person. A reply then
     * goes to the from address, which is the platform, which is somebody.
     *
     * It is not silent either, because a business whose customers cannot reach them is worth
     * finding before they notice. One warning, naming the tenant, the message and the booking,
     * and never the address, the way every line this seam writes does.
     */
    private function replyTo(Message $message): ?string
    {
        $email = User::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('role', Role::Owner->value)
            ->orderBy('id')
            ->value('email');

        if (is_string($email)) {
            return $email;
        }

        $this->log->warning('A tenant has no owner for a message to be replied to', [
            'tenant' => $this->tenant->slug,
            'key' => $message->key->value,
            'booking' => $message->bookingUuid,
        ]);

        return null;
    }
}
