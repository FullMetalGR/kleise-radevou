<?php

declare(strict_types=1);

namespace App\Application\Notification;

use Closure;

/**
 * The seam the platform's own messages pass through, beside the tenant's Messenger and apart
 * from it.
 *
 * Where a Messenger is built for one tenant, reads that tenant's switches and answers to its
 * owner, this one belongs to the deployment: a message goes out in the platform's name, to the
 * one address it names, through no switch, and with no reply-to but the one a message names, the
 * contact form's sender, as text alone or, where the message carries its words as HTML too, as a
 * multipart. What it shares with the tenant's seam is the promise. It never throws: a broken
 * transport is one logged error naming the tenant and the kind and never the address, and
 * whatever handed the message over has done its own work already and is not answered with a
 * failure.
 *
 * The message's writing is inside that promise. A caller hands over the kind and the tenant the
 * message is about with the closure that writes it, and the seam runs the closure inside its
 * catch, so words or a layout that cannot be rendered (a template or a style file missing from a
 * deploy) are a logged error and nothing is sent, never an exception in the caller's request.
 *
 * Nothing above this port knows how mail is sent or which address it is sent from.
 */
interface PlatformMessenger
{
    /**
     * Sends the message the closure writes, of this kind and about this tenant. The two are named
     * beside the closure because they are what a failure is logged against before anything is
     * written; the message the closure answers carries the same two, or it is not sent.
     *
     * @param  int|null  $tenantId  the tenant the message is about, null for a message about none
     * @param  Closure(): PlatformMessage  $render  writes the message; called at most once, inside the seam's catch
     */
    public function send(PlatformMessageKind $kind, ?int $tenantId, Closure $render): void;
}
