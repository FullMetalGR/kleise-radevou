<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Domain\Customer\CustomerSecret;
use App\Tenancy\Tenant;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use LogicException;
use SensitiveParameter;

/**
 * The rows of My bookings, customer_links and customer_sessions: the one-time link a request
 * mints, the session an opened link becomes, and the address each of them names.
 *
 * A secret is found by its hash and held nowhere else: the link's token and the session's secret
 * are presented, hashed here and looked up by the unique index. Every window is compared against
 * an instant the caller read off the Clock port and bound as UTC text with its offset, the same
 * clock that wrote it, so a link's fifteen minutes and a session's thirty days are the
 * application's to count and a test pins them with the clock.
 *
 * An address is compared as citext compares it, as bookings.customer_email is, so the page lists
 * exactly the bookings the link was sent for. Every table is named by schema and nothing is set
 * on the connection, as the booking reader does it.
 */
final readonly class CustomerAccess
{
    /** How long a session lives once a link opens it: thirty days, the cookie's life too. */
    public const string SESSION_LIFETIME = 'P30D';

    public function __construct(private DatabaseManager $db) {}

    /**
     * Whether any booking of this tenant was made under the address, as citext compares it.
     */
    public function hasBookings(Tenant $tenant, string $email): bool
    {
        return $this->db->connection()
            ->table($tenant->schema_name.'.bookings')
            ->whereRaw('customer_email = ?::citext', [$email])
            ->exists();
    }

    /**
     * Writes a minted link, the hash of its token and its window, and answers its id.
     */
    public function mintLink(Tenant $tenant, string $email, string $tokenHash, DateTimeImmutable $createdAt, DateTimeImmutable $expiresAt): int
    {
        $id = $this->db->connection()->scalar(
            sprintf('insert into %s.customer_links (email, token_hash, created_at, expires_at) values (?, ?, ?::timestamptz, ?::timestamptz) returning id', $tenant->schema_name),
            [$email, $tokenHash, SqlText::instant($createdAt), SqlText::instant($expiresAt)],
        );

        return is_int($id) ? $id : throw new LogicException('The link insert answered no id.');
    }

    /**
     * The address a link was minted for, which the job mailing it re-reads, or null where the row
     * is gone.
     */
    public function linkAddress(Tenant $tenant, int $linkId): ?string
    {
        $email = $this->db->connection()
            ->table($tenant->schema_name.'.customer_links')
            ->where('id', $linkId)
            ->selectRaw('email::text as email')
            ->value('email');

        return is_string($email) ? $email : null;
    }

    /**
     * Whether a presented token names a live, unused link: the question the link's page asks
     * before it offers its one button (P9F-L16-a), answered by a read that writes nothing, so a
     * fetch of the link, a mail scanner's among them, spends nothing. A used, lapsed or unknown
     * token answers false, as openSession answers it null.
     */
    public function linkIsLive(Tenant $tenant, #[SensitiveParameter] string $token, DateTimeImmutable $now): bool
    {
        return $this->db->connection()
            ->table($tenant->schema_name.'.customer_links')
            ->where('token_hash', CustomerSecret::presented($token)->hash())
            ->whereNull('used_at')
            ->whereRaw('expires_at > ?::timestamptz', [SqlText::instant($now)])
            ->exists();
    }

    /**
     * Opens the session a presented token's link names, once: a live, unused link is stamped
     * used and a session of thirty days is written for its address, both in one statement, and
     * the session's plaintext secret is answered for the cookie. A used, lapsed or unknown token
     * answers null and writes nothing. The link page's button is what calls it, never the link's
     * fetch.
     *
     * One statement and not two, so two presses racing on one link cannot both open a session:
     * the second update waits on the first's row lock and, at READ COMMITTED, re-reads the row
     * and finds it used (tests/Concurrency/OpenSessionTest.php).
     */
    public function openSession(Tenant $tenant, #[SensitiveParameter] string $token, DateTimeImmutable $now): ?string
    {
        $session = CustomerSecret::generate();
        $at = SqlText::instant($now);

        $opened = $this->db->connection()->scalar(
            sprintf(
                'with opened as (
                    update %1$s.customer_links set used_at = ?::timestamptz
                    where token_hash = ? and used_at is null and expires_at > ?::timestamptz
                    returning email
                )
                insert into %1$s.customer_sessions (email, session_hash, created_at, expires_at)
                select email, ?, ?::timestamptz, ?::timestamptz from opened
                returning id',
                $tenant->schema_name,
            ),
            [$at, CustomerSecret::presented($token)->hash(), $at, $session->hash(), $at, SqlText::instant($now->add(new DateInterval(self::SESSION_LIFETIME)))],
        );

        return $opened === null ? null : $session->plain;
    }

    /**
     * The address of the live session a presented secret names, or null where the session is
     * unknown, revoked or past its end: each of those reads as no session.
     */
    public function sessionAddress(Tenant $tenant, #[SensitiveParameter] string $secret, DateTimeImmutable $now): ?string
    {
        $email = $this->db->connection()
            ->table($tenant->schema_name.'.customer_sessions')
            ->where('session_hash', CustomerSecret::presented($secret)->hash())
            ->whereNull('revoked_at')
            ->whereRaw('expires_at > ?::timestamptz', [SqlText::instant($now)])
            ->selectRaw('email::text as email')
            ->value('email');

        return is_string($email) ? $email : null;
    }

    /**
     * Whether the live session a presented secret names reaches the booking that uuid names,
     * which is whether it was opened for the address the booking was made under: the manage token
     * guard's second credential. One statement joining the session to the booking on their
     * addresses, two citext columns, so the compare is citext's and a session opened for an
     * address typed in another case finds the bookings made under it, as the list does.
     *
     * Every miss is the one false and costs the one statement: a secret naming no row, a session
     * revoked or past its end, a session for another address, and a uuid naming no booking. A
     * string that is no uuid at all is answered before anything is issued, as the booking reader
     * answers it, since a uuid's syntax is no secret.
     */
    public function sessionReachesBooking(Tenant $tenant, #[SensitiveParameter] string $secret, string $uuid, DateTimeImmutable $now): bool
    {
        if (! SqlText::isUuid($uuid)) {
            return false;
        }

        $holds = $this->db->connection()->scalar(
            sprintf(
                'select exists (
                    select 1
                    from %1$s.customer_sessions s
                    join %1$s.bookings b on b.customer_email = s.email
                    where s.session_hash = ? and s.revoked_at is null and s.expires_at > ?::timestamptz and b.uuid = ?::uuid
                )',
                $tenant->schema_name,
            ),
            [CustomerSecret::presented($secret)->hash(), SqlText::instant($now), $uuid],
        );

        return $holds === true;
    }

    /**
     * Ends the session a presented secret names, where it is not ended already.
     */
    public function revokeSession(Tenant $tenant, #[SensitiveParameter] string $secret, DateTimeImmutable $now): void
    {
        $this->db->connection()
            ->table($tenant->schema_name.'.customer_sessions')
            ->where('session_hash', CustomerSecret::presented($secret)->hash())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => SqlText::instant($now)]);
    }
}
