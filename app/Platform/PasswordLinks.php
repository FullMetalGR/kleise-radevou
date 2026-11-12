<?php

declare(strict_types=1);

namespace App\Platform;

use App\Application\Clock;
use App\Domain\Lifetime;
use App\Infrastructure\Db\SqlText;
use App\Models\User;
use App\Notifications\SetPasswordLink;
use App\Tenancy\Tenant;
use DateInterval;
use DateTimeZone;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use UnexpectedValueException;

/**
 * The set-password link: the console never types or learns a password, so it sends a user a link
 * and the user sets their own under the signup's one rule.
 *
 * The link is 32 random bytes as hex. Its sha256 sits on the user's row with the instant it lapses,
 * 72 hours from the clock's now, and the clear token lives in the link alone: nothing here logs it,
 * stores it or hands it to anything but the caller that puts it in the message. A new link replaces
 * the one there was in one statement, so the old link names no row from then on. The user's old
 * password keeps working until the link is used: sending one locks nobody out.
 *
 * The set writes the new password's hash and clears the link in one statement, guarded by the hash,
 * the tenant and the expiry against the clock's now, so a link works once, inside its 72 hours, at
 * its own tenant alone; a set that moves no row changes nothing. The same statement stamps the
 * address verified where it was not, a used link proving it, and the same transaction ends the
 * user's sessions: the framework's sessions rows, deleted by the user_id the web guard writes on
 * them and never by reading a payload, so a password set through a link signs every other device
 * out. The caller regenerates the request's own session, which then survives under a new id. Every
 * instant crosses to the database as UTC text with its offset, from the Clock port and never from
 * the database's now().
 */
final readonly class PasswordLinks
{
    /** The link's token as the tenant host's route admits it: 32 bytes as lowercase hex. */
    public const string TOKEN = '[0-9a-f]{64}';

    /** How long a link works from its mint, as elapsed time. */
    public const string LIFETIME = 'PT72H';

    /**
     * How many whole hours LIFETIME spans, as Lifetime reads an interval.
     */
    public static function hours(): int
    {
        return Lifetime::hours(self::LIFETIME);
    }

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private Hasher $hasher,
        private SetPasswordLink $mail,
    ) {}

    /**
     * Mints a new link for the user in one statement, replacing whatever link the row held, and
     * answers the clear token for the message.
     *
     * @throws UnexpectedValueException where no row holds the user's id
     */
    public function mint(User $user): string
    {
        $token = bin2hex(random_bytes(32));
        $expires = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->add(new DateInterval(self::LIFETIME));
        $moved = $this->connection()->update(
            'update users set password_link_hash = ?, password_link_expires_at = ?::timestamptz, updated_at = now() where id = ?',
            [self::hashOf($token), SqlText::instant($expires), $user->id],
        );

        return $moved === 1 ? $token : throw new UnexpectedValueException(sprintf('No users row holds the id %d a link was minted for.', $user->id));
    }

    /**
     * The console's send: in one transaction, the tenant's user the id names, a new link minted for
     * them, and the platform audit row password_link_sent naming the user by id; once that has
     * committed, the link mailed to the user through the platform's seam. Answers the user the link
     * went to, or null where the tenant holds no user of that id, writing nothing and sending
     * nothing.
     */
    public function send(Tenant $tenant, Operator $operator, int $userId): ?User
    {
        $sent = $this->connection()->transaction(function (Connection $db) use ($tenant, $operator, $userId): ?array {
            $user = User::query()->where('tenant_id', $tenant->id)->whereKey($userId)->first();

            if ($user === null) {
                return null;
            }

            $token = $this->mint($user);
            PlatformAudit::record($db, $operator->id, new PlatformAuditEntry(PlatformAuditAction::PasswordLinkSent, [PlatformAuditKey::User->value => $user->id]), $tenant->id);

            return ['user' => $user, 'token' => $token];
        });

        if ($sent === null) {
            return null;
        }

        $this->mail->send($tenant, $sent['user'], $sent['token']);

        return $sent['user'];
    }

    /**
     * Whether the token opens a link at the tenant: of the link's shape, its hash naming a user of
     * this tenant, and its expiry ahead of the clock's now.
     */
    public function live(Tenant $tenant, string $token): bool
    {
        if (! self::shaped($token)) {
            return false;
        }

        return $this->connection()->scalar(
            'select exists (select 1 from users where password_link_hash = ? and tenant_id = ? and password_link_expires_at > ?::timestamptz)',
            [self::hashOf($token), $tenant->id, SqlText::instant($this->clock->now())],
        ) === true;
    }

    /**
     * Uses the link, in one transaction: the password's hash written, the link's hash and expiry
     * cleared and the address stamped verified where it was not, in one statement on the row the
     * token's hash names at this tenant while its expiry is ahead of the clock's now, and then every
     * sessions row of that user deleted. Answers whether a row moved; none moved is a link used,
     * lapsed, replaced, unknown, tampered, of the wrong shape or another tenant's, and nothing has
     * changed.
     *
     * The password is hashed only once live()'s cheap read has found the link, so a dead link costs
     * no bcrypt on a POST no limit stands in front of; the guarded statement still decides, so of
     * two sets racing on one link one moves the row and the other answers false.
     *
     * @param  string  $password  the person's choice, already judged by PasswordRule at the boundary
     */
    public function set(Tenant $tenant, string $token, string $password): bool
    {
        if (! $this->live($tenant, $token)) {
            return false;
        }

        $hash = $this->hasher->make($password);
        $now = SqlText::instant($this->clock->now());

        return $this->connection()->transaction(function (Connection $db) use ($tenant, $token, $hash, $now): bool {
            $userId = $db->scalar(
                'update users set password = ?, password_link_hash = null, password_link_expires_at = null,
                   email_verified_at = coalesce(email_verified_at, ?::timestamptz), updated_at = now()
                 where password_link_hash = ? and tenant_id = ? and password_link_expires_at > ?::timestamptz
                 returning id',
                [$hash, $now, self::hashOf($token), $tenant->id, $now],
                false,
            );

            if (! is_int($userId)) {
                return false;
            }

            $db->delete('delete from sessions where user_id = ?', [$userId]);

            return true;
        });
    }

    private static function shaped(string $token): bool
    {
        return preg_match('/\A'.self::TOKEN.'\z/', $token) === 1;
    }

    private static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }

    private function connection(): Connection
    {
        return $this->db->connection();
    }
}
