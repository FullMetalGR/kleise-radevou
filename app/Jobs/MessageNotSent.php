<?php

declare(strict_types=1);

namespace App\Jobs;

use RuntimeException;
use Throwable;

/**
 * What a job carrying a credential fails with, so that failing leaks nothing.
 *
 * A failed job lands in failed_jobs, and failed_jobs is a queue row. Its payload is whatever
 * the queue held, which for an encrypted job is ciphertext; its exception column is the
 * stringified throwable, trace included, written by the framework into a central table that
 * nothing encrypts.
 *
 * **A stringified trace prints the arguments of every frame in it.** The engine renders them
 * unless a php.ini turns zend.exception_ignore_args on, which nothing here does, and truncates
 * each string argument to zend.exception_string_param_max_len, which defaults to fifteen
 * characters. Fifteen characters of a manage token is not the token, so the default hides this
 * by accident - and a setting is not a guarantee, because the same setting takes any value up
 * to a thousand and an operator raising it to read a stack trace would turn every throw below
 * the line that builds the customer's magic link into a credential written out in the clear.
 * Only the token's hash is stored, and a queue row nothing encrypts is a row.
 *
 * So a job that holds a token answers for what it fails with. This is made at the job's own top
 * frame, where the only arguments are the services the container injected. It names the fault
 * by class and by where it happened, which is what an operator follows, and the booking, which
 * is the handle the seam's own log lines already use. It carries no previous exception, because
 * a previous exception is rendered with the trace this exists to refuse, and it repeats no
 * message from below, because a message from below may name an address and a log is not a place
 * to put a customer, the rule MailedMessages keeps for the same reason.
 *
 * What is lost is the original trace. That is the price, and it is the one the seam already
 * pays one layer down: a failed send is logged by exception class alone. An operator gets the
 * class, the file, the line and the booking, which is enough to find the code and enough to
 * find the customer through a store meant to hold one.
 */
final class MessageNotSent extends RuntimeException
{
    /** What a message is about where it is a booking's: every message but one. */
    public const string ABOUT_BOOKING = 'booking';

    /** What the My bookings link is about: the link, by its id, since it names no booking. */
    public const string ABOUT_LINK = 'link';

    /**
     * @param  string  $subject  the booking's uuid, or the link's id for the bookings link
     */
    public static function after(Throwable $failure, string $subject, string $about = self::ABOUT_BOOKING): self
    {
        return new self(sprintf(
            '%s was thrown at %s line %d while sending a message about %s %s.',
            $failure::class,
            $failure->getFile(),
            $failure->getLine(),
            $about,
            $subject,
        ));
    }
}
