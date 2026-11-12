<?php

declare(strict_types=1);

namespace App\Application\Notification;

/**
 * Which of the platform's own messages a message is: the ones sent in the platform's name, about a
 * tenant's account or about none, outside the tenant's catalog and beyond its switches.
 *
 * Not a MessageKey, and kept apart from it on purpose. A key is a switch the tenant holds and a
 * checkbox in the Emails section; nothing here is either, and a kind that turned up in that list
 * would be a switch on a message the tenant is not the sender of. The value is what a failed send
 * is logged against, beside the tenant's id, since the address may never be.
 *
 * The signup verification, the trial notice and the closing email, the console's sign-in notice
 * beside them, the two a suspension sends the owners, the set-password link the console sends a
 * user, the contact form's message to the support mailbox, the welcome email the building click
 * sends the owner, and the reopen the console tells the owners of.
 */
enum PlatformMessageKind: string
{
    /** The link that confirms the owner's address at signup. */
    case Verification = 'verification';

    /** A trial's notice, three days before its end and on the day it ends. */
    case TrialNotice = 'trial_notice';

    /**
     * The closing email, thirty days after an unpaid lapse, and in its other openings the owner's
     * own close and the console's.
     */
    case Closing = 'closing';

    /** The notice to an operator of every sign-in at the console, about no tenant. */
    case OperatorSignIn = 'operator_sign_in';

    /** The notice to a tenant's owners that the console suspended the account, with the reason. */
    case Suspension = 'suspension';

    /** The notice to a tenant's owners that the console ended the suspension. */
    case Resumption = 'resumption';

    /** The link the console sends a tenant's user to set their own password. */
    case SetPassword = 'set_password';

    /**
     * A message the site's contact form let through, to the support mailbox, about no tenant,
     * with the sender's address as its reply-to.
     */
    case ContactMessage = 'contact_message';

    /** The welcome email to the owner, sent once by the verification click that built the trial. */
    case Welcome = 'welcome';

    /**
     * The notice to a tenant's owners that the console reopened the closing account, so it stays
     * open and nothing in it is deleted.
     */
    case Reopened = 'reopened';
}
