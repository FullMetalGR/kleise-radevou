<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Infrastructure\Db\SqlState;
use App\Models\User;
use App\Notifications\SetPasswordLink;
use App\Platform\PasswordLinks;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use stdClass;

/**
 * The people who sign in to one business's admin: the users rows of the tenant, read and written
 * for the Team screen.
 *
 * A member's staff row is the tenant's staff row whose user_id names them, the one link the
 * Calendar's Me and the approver's scope read; the first in the roster's order where two name the
 * same user. An invitation is out while a set-password link was sent and the address is still
 * unproven: email_verified_at is stamped by the link's use (PasswordLinks::set), and an invited
 * row is born without it and with a link. A row with neither, an owner a seed or a pending signup
 * made, carries no invitation.
 *
 * An invitation creates the row with the role chosen and a password that is a secret nobody
 * knows, as the console's free tenant's owner is created, so the row can never be signed in to
 * until the link is used; mints the set-password link the console mails (PasswordLinks::mint) in
 * the same transaction; and once that has committed mails it through SetPasswordLink, the
 * console's message, read for a first password. A link sent again is the same mint and the same
 * message, read for a first password while the invitation is out and for a reset once it is not.
 *
 * The guards run inside the write's transaction, the tenant's owner rows locked first, so two
 * owners demoting each other at once cannot leave the business with none: the last owner is
 * neither demoted nor removed, and nobody removes themself. A removal unlinks the user's staff
 * row, which keeps its hours and its bookings, and deletes the user's sessions rows, signing them
 * out everywhere, in the same transaction as the row's delete.
 */
final readonly class Team
{
    /** The bytes of the secret an invited row's password is, as the free tenant's owner's is. */
    private const int SECRET_BYTES = 32;

    public function __construct(
        private DatabaseManager $db,
        private PasswordLinks $links,
        private SetPasswordLink $mail,
    ) {}

    /**
     * Every user of the tenant, the viewer first and the rest in the order they joined, as the
     * screen reads them.
     *
     * @param  int|null  $viewer  the signed-in user's id, null for support
     * @return list<array{id: int, name: string, email: string, role: string, you: bool, invited: bool, staff: string|null}>
     */
    public function members(Tenant $tenant, ?int $viewer): array
    {
        $rows = $this->connection()->select(
            'select u.id, u.name, u.email::text as email, u.role, u.email_verified_at is null and u.password_link_hash is not null as invited,
               (select s.name from '.$tenant->schema_name.'.staff s where s.user_id = u.id order by s.sort, s.id limit 1) as staff
             from users u where u.tenant_id = ? order by u.id',
            [$tenant->id],
        );
        $members = [];

        foreach ($rows as $row) {
            if (! $row instanceof stdClass) {
                continue;
            }

            $id = is_numeric($row->id) ? (int) $row->id : 0;
            $members[] = [
                'id' => $id,
                'name' => is_string($row->name) ? $row->name : '',
                'email' => is_string($row->email) ? $row->email : '',
                'role' => is_string($row->role) ? $row->role : '',
                'you' => $viewer === $id,
                'invited' => $row->invited === true,
                'staff' => is_string($row->staff) ? $row->staff : null,
            ];
        }

        // The viewer first, the rest in the order they joined.
        usort($members, fn (array $a, array $b): int => $b['you'] <=> $a['you']);

        return $members;
    }

    /**
     * Invites someone: the row, the link and, after the commit, the mail.
     *
     * @throws TeamRefused where a user of the tenant took the address since the form's check
     */
    public function invite(Tenant $tenant, string $name, string $email, Role $role): User
    {
        try {
            ['user' => $user, 'token' => $token] = $this->connection()->transaction(function () use ($tenant, $name, $email, $role): array {
                $user = User::query()->create([
                    'tenant_id' => $tenant->id,
                    'name' => $name,
                    'email' => $email,
                    'password' => bin2hex(random_bytes(self::SECRET_BYTES)),
                    'role' => $role,
                ]);

                return ['user' => $user, 'token' => $this->links->mint($user)];
            });
        } catch (QueryException $exception) {
            throw SqlState::of($exception) === SqlState::UniqueViolation ? TeamRefused::emailTaken() : $exception;
        }

        $this->mail->send($tenant, $user, $token, firstPassword: true);

        return $user;
    }

    /**
     * Mails a member the set-password link again, a new link replacing the one there was; null
     * where the tenant holds no user of that id, sending nothing.
     */
    public function sendLink(Tenant $tenant, int $userId): ?User
    {
        $sent = $this->connection()->transaction(function () use ($tenant, $userId): ?array {
            $user = $this->userOf($tenant, $userId);

            return $user === null ? null : ['user' => $user, 'token' => $this->links->mint($user)];
        });

        if ($sent === null) {
            return null;
        }

        $this->mail->send($tenant, $sent['user'], $sent['token'], firstPassword: $sent['user']->email_verified_at === null);

        return $sent['user'];
    }

    /**
     * Changes a member's role; null where the tenant holds no user of that id.
     *
     * @throws TeamRefused where the member is the last owner and the role is not owner
     */
    public function changeRole(Tenant $tenant, int $userId, Role $role): ?User
    {
        return $this->connection()->transaction(function (Connection $db) use ($tenant, $userId, $role): ?User {
            $owners = $this->lockOwners($db, $tenant);
            $user = $this->userOf($tenant, $userId);

            if ($user === null) {
                return null;
            }

            if ($user->role === Role::Owner && $role !== Role::Owner && $owners === [$user->id]) {
                throw TeamRefused::lastOwner();
            }

            $user->role = $role;
            $user->save();

            return $user;
        });
    }

    /**
     * Removes a member, unlinking their staff row and ending their sessions; null where the
     * tenant holds no user of that id.
     *
     * @param  int|null  $actor  the signed-in user's id, null for support
     *
     * @throws TeamRefused where the member is the actor, or the last owner
     */
    public function remove(Tenant $tenant, int $userId, ?int $actor): ?User
    {
        return $this->connection()->transaction(function (Connection $db) use ($tenant, $userId, $actor): ?User {
            $owners = $this->lockOwners($db, $tenant);
            $user = $this->userOf($tenant, $userId);

            if ($user === null) {
                return null;
            }

            if ($user->id === $actor) {
                throw TeamRefused::removingSelf();
            }

            if ($owners === [$user->id]) {
                throw TeamRefused::lastOwner();
            }

            $db->update('update '.$tenant->schema_name.'.staff set user_id = null, updated_at = now() where user_id = ?', [$user->id]);
            $db->delete('delete from sessions where user_id = ?', [$user->id]);
            $user->delete();

            return $user;
        });
    }

    /**
     * The tenant's owner rows locked for the write's transaction, their ids in order.
     *
     * @return list<int>
     */
    private function lockOwners(Connection $db, Tenant $tenant): array
    {
        $ids = $db->table('users')->where('tenant_id', $tenant->id)->where('role', Role::Owner->value)->orderBy('id')->lockForUpdate()->pluck('id')->all();

        return array_values(array_map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids));
    }

    private function userOf(Tenant $tenant, int $userId): ?User
    {
        return User::query()->where('tenant_id', $tenant->id)->whereKey($userId)->lockForUpdate()->first();
    }

    private function connection(): Connection
    {
        return $this->db->connection();
    }
}
