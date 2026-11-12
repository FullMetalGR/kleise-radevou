<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Site\SiteLanguage;
use App\Tenancy\Role;

/**
 * A user of the tenant as the recipient rule reads one: who they are, what they are called,
 * where they are written to, and what they may decide. The central users row, less everything
 * the rule has no business with.
 *
 * The name is here for the messages that say who acted. A cancellation notice tells the rest of
 * the team which of them cancelled, and the trail names that person by id alone, so the id is
 * turned back into a name where the tenant's users may be read, which is here and nowhere
 * further down.
 *
 * The language is the one they chose, users.locale, null where they chose none: the business's
 * messages to them are written in it, BusinessRecipients asking the resolver for their language
 * from it, the tenant default where it is null.
 */
final readonly class TenantUser
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public Role $role,
        public ?SiteLanguage $chosenLanguage = null,
    ) {}

    /**
     * The user with this id among a tenant's users, or null where none has it. Two callers ask:
     * the decision link guard, for the user a signed link names, and the notices that say who
     * acted, for the user the trail names. Both read the same list and neither gets a second
     * idea of what "this user" means.
     *
     * @param  list<self>  $users
     */
    public static function among(array $users, int $id): ?self
    {
        foreach ($users as $user) {
            if ($user->id === $id) {
                return $user;
            }
        }

        return null;
    }
}
