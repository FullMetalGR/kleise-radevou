<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Application\Notification\Recipients;
use App\Site\SiteLanguage;
use App\Tenancy\Languages;
use App\Tenancy\Role;
use App\Tenancy\Tenant;
use Closure;

/**
 * Who at the business hears about a booking, and, under a key the catalog says goes to the
 * owners alone, who owns it: under() reads the key, MessageKey::ownersAlone, and is the one road
 * to the owners' limb, so a key is sent to the owners exactly when its checkbox's tag says so.
 *
 * Three limbs, in this order:
 *
 * 1. Every user whose role is owner or manager. They run the whole diary, so every booking is
 *    theirs to know about whoever it is on.
 * 2. A user whose role is staff, where the booking has an item on the staff row they are linked
 *    to. A staff user hears about their own bookings and no others, which is the same scoping
 *    their calendar and their approval permission use.
 * 3. The address on a staff row with an item on the booking, where the row has one and is not the
 *    implicit row. That is how a person who holds hours and never signs in is told, which is what
 *    the column is for. The implicit row is left out because it stands for the business itself
 *    rather than for a person, and the business is already being told through limb 1.
 *
 * The order is the de-duplication. A mailbox named by two limbs is kept from the first, so an
 * address a user holds keeps the identity that lets a decision link be signed for it, rather than
 * being demoted to an address with nobody to act as.
 *
 * Each recipient is told apart by what they may be shown: a role's manageBookings is read here,
 * where a role may be read at all, and travels with the address so that no template has to ask
 * again. Limb 1 holds it, limb 2 does not because a staff role never does, and limb 3 is nobody at
 * all and therefore has nothing.
 *
 * So does the language a message to each of them is written in: a user reads their own, the
 * language they chose, users.locale, else the tenant default, and a staff row's own address, which
 * no user holds, the tenant default; each the resolver's answer, Languages::ofUser, asked here
 * with what the user chose, null for the row. So two recipients of one booking may read two
 * languages, one message each.
 *
 * It lives beside the listeners rather than under Application because a role is a tenancy concept
 * and Application depends on Domain and on ports and on nothing else. What crosses into
 * Application is the answer, which is addresses.
 */
final readonly class BusinessRecipients
{
    /**
     * @param  Closure(?SiteLanguage): SiteLanguage  $languageOf  the language a recipient reads,
     *                                                            from the language a user chose,
     *                                                            null for none and for a staff
     *                                                            row's address
     */
    public function __construct(private BusinessDirectory $directory, private Closure $languageOf) {}

    /**
     * The rule over one tenant's directory, each recipient's language the resolver's answer for
     * that tenant.
     */
    public static function of(BusinessDirectory $directory, Languages $languages, Tenant $tenant): self
    {
        return new self($directory, fn (?SiteLanguage $chosen): SiteLanguage => $languages->ofUser($tenant, $chosen));
    }

    /**
     * The recipients of a business message under its key: the owners alone where the key says
     * so, MessageKey::ownersAlone, and the booking's party otherwise. The late payment's job
     * reaches the owners through here and nowhere else, so the Emails section's "to the owners"
     * tag and the send read one answer.
     */
    public function under(MessageKey $key, string $uuid): Recipients
    {
        return $key->ownersAlone() ? $this->owners() : $this->forBooking($uuid);
    }

    public function forBooking(string $uuid): Recipients
    {
        $users = $this->directory->users();
        $staff = $this->directory->staffOn($uuid);

        $byId = [];

        foreach ($users as $user) {
            $byId[$user->id] = $user;
        }

        $named = [];

        foreach ($users as $user) {
            if ($user->role === Role::Owner || $user->role === Role::Manager) {
                $named[] = $this->mailbox($user);
            }
        }

        foreach ($staff as $member) {
            $user = $member->userId === null ? null : ($byId[$member->userId] ?? null);

            if ($user !== null && $user->role === Role::Staff) {
                $named[] = $this->mailbox($user);
            }
        }

        foreach ($staff as $member) {
            if ($member->email !== null && ! $member->isImplicit) {
                $named[] = new Recipient($member->email, null, false, $this->codeFor(null));
            }
        }

        return Recipients::of(...$named);
    }

    /**
     * The owners alone, every user whose role is owner and nobody else: the recipients of a
     * message about the business's money rather than about a booking's diary, which a manager
     * and a staff member run but which only an owner decides, the late payment's notice,
     * reached through under() alone. No staff row is read, so whoever the booking is on makes
     * no difference, and a tenant with no owner row names nobody.
     */
    private function owners(): Recipients
    {
        $named = [];

        foreach ($this->directory->users() as $user) {
            if ($user->role === Role::Owner) {
                $named[] = $this->mailbox($user);
            }
        }

        return Recipients::of(...$named);
    }

    /**
     * A user as a place to write to: the address, the identity that can act, the one question
     * asked of a recipient, answered from the role rather than from which limb named them, and
     * the language the user reads.
     */
    private function mailbox(TenantUser $user): Recipient
    {
        return new Recipient($user->email, $user->id, $user->role->mayManageBookings(), $this->codeFor($user->chosenLanguage));
    }

    /**
     * The code of the language a recipient reads, from what a user chose, null for none.
     */
    private function codeFor(?SiteLanguage $chosen): string
    {
        return ($this->languageOf)($chosen)->value;
    }
}
