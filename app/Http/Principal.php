<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Audit\Actor;
use App\Models\User;
use App\Site\SiteLanguage;
use App\Tenancy\Permission;
use App\Tenancy\Role;
use DateTimeImmutable;

/**
 * Who is acting in the admin of the tenant this request resolved: a signed-in user of the tenant,
 * with the permissions of their role, or the platform's operator in an open support session, with
 * every owner permission but manageBilling and viewOwnCalendar. AdminSession answers it, and the
 * permission middleware, the shared props, the rail and every controller that stamps the trail read
 * it rather than a user, so a user is answered exactly as before and support is answered by the
 * same code.
 *
 * The support set is the owner's row less two permissions named here, WITHHELD, read off
 * Role::Owner and never written out: a seventh permission reaches support unless somebody withholds
 * it here on purpose, the way the two are withheld. manageBilling, because billing and payments are
 * the tenant's own business with the platform and support acts for the business inside its bookings
 * and settings; viewOwnCalendar, because the own calendar is a staff row's and support has none: it
 * opens the Calendar through viewBookings as an owner does, with no Me preset, since Me is the
 * viewer's own row.
 *
 * The principal carries an actor rather than an id, so the trail is stamped from here and no
 * controller spells a shape: Actor::user for a user, Actor::support for the operator, 10's
 * shape. Support has no user of the tenant behind it, so it has no staff row of its own either,
 * and the approver's scope never narrows it because it holds manageBookings, which admits before
 * any row is read.
 *
 * A user's principal also carries the language they chose, users.locale, which the locale
 * middleware and the shared props hand the resolver for the user's language; support carries none,
 * having no user row, and so reads the tenant default.
 */
final readonly class Principal
{
    public const string USER = 'user';

    /** The two owner permissions support is withheld, each with its reason above. */
    private const array WITHHELD = [Permission::ManageBilling, Permission::ViewOwnCalendar];

    public const string SUPPORT = 'support';

    /**
     * @param  self::USER|self::SUPPORT  $kind
     * @param  string  $name  the user's name, or the operator's
     * @param  string  $role  the slug auth.user carries: the user's role, or support
     * @param  list<Permission>  $permissions
     */
    private function __construct(
        public string $kind,
        public string $name,
        public string $role,
        public array $permissions,
        private Actor $actor,
        private ?int $userId,
        private ?int $sessionId,
        private ?DateTimeImmutable $endsAt,
        private ?SiteLanguage $chosen,
    ) {}

    /**
     * A signed-in user of the tenant, with the permissions of their role.
     */
    public static function user(User $user): self
    {
        return new self(self::USER, $user->name, $user->role->value, $user->role->permissions(), Actor::user($user->id), $user->id, null, null, $user->locale);
    }

    /**
     * The operator of an open support session, with every owner permission but manageBilling
     * and viewOwnCalendar, and the instant the session ends.
     */
    public static function support(int $sessionId, int $operatorId, string $operatorName, DateTimeImmutable $endsAt): self
    {
        $permissions = array_values(array_filter(
            Role::Owner->permissions(),
            fn (Permission $permission): bool => ! in_array($permission, self::WITHHELD, true),
        ));

        return new self(self::SUPPORT, $operatorName, self::SUPPORT, $permissions, Actor::support($operatorId), null, $sessionId, $endsAt, null);
    }

    /**
     * Who the trail names for what this principal does.
     */
    public function actor(): Actor
    {
        return $this->actor;
    }

    /**
     * The user whose staff row is this principal's own, the row the Calendar's Me and the
     * approver's scope read: the user's id, and null for support, which has none.
     */
    public function ownStaffUserId(): ?int
    {
        return $this->userId;
    }

    /**
     * The support session this principal acts in, and null for a user.
     */
    public function supportSessionId(): ?int
    {
        return $this->sessionId;
    }

    /**
     * When the support session this principal acts in ends, sixty minutes from the press, and
     * null for a user.
     */
    public function supportEndsAt(): ?DateTimeImmutable
    {
        return $this->endsAt;
    }

    /**
     * The language the user chose for the admin and their mails, users.locale: null for a user who
     * chose none, and for support, which has no user row.
     */
    public function chosenLanguage(): ?SiteLanguage
    {
        return $this->chosen;
    }

    public function holds(Permission $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}
