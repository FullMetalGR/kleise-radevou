<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * The whole vocabulary of the platform audit log: what an operator did, one case per action. The
 * whole set is declared together so the table's CHECK is written once; every action uses its
 * case, and the write-route walk of ConsoleSurfaceTest holds every console write route to one of
 * them.
 *
 * The migration spells these values out rather than reading them from here, since a shipped
 * migration must not change when a constant does; CentralSchemaTest holds the two together by
 * inserting every case. An action outside these is added here and to the CHECK by a migration of
 * its own, as ContactArchived was by migration 000019 and the two closing actions by 000021.
 */
enum PlatformAuditAction: string
{
    /** An operator signed in at the console's door. */
    case SignIn = 'sign_in';

    /** An operator signed out from the console. */
    case SignOut = 'sign_out';

    case TenantSuspended = 'tenant_suspended';

    case TenantResumed = 'tenant_resumed';

    case TenantCreated = 'tenant_created';

    case FeaturesChanged = 'features_changed';

    case PasswordLinkSent = 'password_link_sent';

    case SupportOpened = 'support_opened';

    case SupportEnded = 'support_ended';

    /** An operator archived a contact form message. */
    case ContactArchived = 'contact_archived';

    /**
     * An operator closed a tenant from the console, its payload the reason. Added to the CHECK
     * by migration 000021.
     */
    case TenantClosed = 'tenant_closed';

    /** An operator reopened a closing tenant from the console. */
    case TenantReopened = 'tenant_reopened';
}
