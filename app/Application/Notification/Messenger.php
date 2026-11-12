<?php

declare(strict_types=1);

namespace App\Application\Notification;

use Closure;

/**
 * The seam every message passes through, bound to one tenant by whoever built it.
 *
 * It applies the tenant's per-message off switches, renders the message, attaches whatever the
 * message carries, and never throws. A broken transport degrades to a logged error and never to a
 * failed booking: by the time anything calls this the transition has committed, so there is
 * nothing left to roll back and nobody to answer with an error.
 *
 * **The render is inside the promise**. A caller hands over the message not yet written: its key,
 * the booking it is about, and the closure that writes it. The switch is asked first, so a
 * message the tenant switched off is never written at all; the closure runs inside the seam's own
 * catch, so words that cannot be written, a line the translator cannot read or a formatter that
 * refuses an instant, are one logged error and no message, as a refused mailbox is, and never the
 * job's failure. What a caller reads before it hands the closure over, the tenant, the re-read
 * and the links it mints, is the caller's to fail on, as it always was.
 *
 * Nothing above this port knows how mail is sent, which address it is sent from, or that it is
 * mail at all. What a caller holds is a message and a promise that handing it over cannot fail.
 *
 * It answers one question as well as taking messages, and the question is the same rule read
 * out loud. A message that points at another message - "the link is in the one we sent you when
 * your request arrived" - is false for a tenant who switched that other key off, and the switches
 * are this seam's to know. Asking here rather than reading the settings row again is what keeps
 * one idea of what "switched off" means: the answer this gives and the drop the send performs are
 * the same line of code.
 */
interface Messenger
{
    /**
     * Sends the message of this key about this booking, as the closure writes it, where the
     * tenant has the key on. The key and the booking are named beside the closure because they
     * are what the switch is asked and what a failure is logged against before anything is
     * written; the message the closure answers carries the same two, or it is not sent.
     *
     * @param  string|null  $bookingUuid  the booking the message is about, null for the bookings link alone, as Message holds it
     * @param  Closure(): Message  $render  writes the message; called once, after the switch, inside the seam's catch
     */
    public function send(MessageKey $key, ?string $bookingUuid, Closure $render): void;

    /**
     * Whether a message of this key would go out for the tenant this seam was built for.
     *
     * Unlike send(), this may throw: it is a read of the tenant's settings and a caller asking
     * it is inside a job that already turns a failure into one that carries nothing, rather
     * than after a commit with nothing left to answer.
     */
    public function sends(MessageKey $key): bool;
}
