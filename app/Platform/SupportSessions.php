<?php

declare(strict_types=1);

namespace App\Platform;

use App\Application\Clock;
use App\Infrastructure\Db\SqlText;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use stdClass;
use UnexpectedValueException;

/**
 * The support session: the operator reads and acts in a tenant's own admin as support, and in no
 * other way.
 *
 * The press writes a support_sessions row naming the operator and the tenant, the sha256 of a
 * token of 32 random bytes as hex, and the instants from the Clock port as UTC text with the
 * offset: created_at the clock's now and expires_at sixty minutes on, which the session ends at
 * whatever happens after. support_opened is recorded on the same connection in the same
 * transaction, so the row and its record commit or roll back together. The tenant row is read for
 * share in that transaction, so a suspension racing the press is decided on the row as it stands:
 * an active or a closing row takes the press, a suspended one, whose host answers 503 everywhere,
 * and a provisioning one, which has no host yet, are refused with their sentences, writing
 * nothing. The clear token is answered to the caller once and lives nowhere else: nothing here
 * logs it, stores it or hands it on.
 *
 * The claim is one statement: on the row whose hash matches, at this tenant, unopened and
 * unended, whose expiry is ahead of the clock's now and whose press was five minutes or less
 * before it, opened_at is written and the hash cleared, so a link opens once, at its own tenant,
 * inside the five minutes the link is good for; two opens racing on one link find the row opened
 * by whichever won. A claim that moves no row opens nothing.
 *
 * The live read is the principal's on every request under the admin prefix: the row the session
 * key names at this tenant, opened and unended, its expiry ahead of the clock's now, read with its
 * operator's name in one statement. The console's detail reads the same rows for a tenant, its
 * open sessions. Every comparison is against the Clock port, never the database's now(), so the
 * sixty minutes and the five are read by moving the clock alone.
 *
 * The end: an operator's act, by the admin's Sign out or End session, by a sign-in or a second
 * support link displacing the session in the browser, or by the console's End. One statement
 * writes ended_at as the clock's now on a row still unended and unexpired, and support_ended is
 * recorded with the row's tenant in the same transaction, so the end and its record commit or roll
 * back together. A row ended already, or lapsed at its expiry, moves nothing and records nothing:
 * the sixty minutes end a session by themselves, and expiry is no operator's act.
 */
final readonly class SupportSessions
{
    /** The link's token as the tenant host's route admits it: 32 bytes as lowercase hex. */
    public const string TOKEN = '[0-9a-f]{64}';

    /** How long a session lasts from the press, as elapsed time. */
    public const string LIFETIME = 'PT60M';

    /** How long the link opens the session for from the press, as elapsed time. */
    public const string CLAIM_WINDOW = 'PT5M';

    public const string SUSPENDED = 'A suspended account cannot be opened as support. Resume it first.';

    public const string PROVISIONING = 'An account still being set up cannot be opened as support.';

    public const string CLOSED = 'A closed account cannot be opened as support.';

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
    ) {}

    /**
     * The press: writes the row and its record in one transaction and answers the clear token for
     * the link.
     *
     * @throws SupportRefused where the tenant's row is neither active nor closing
     */
    public function open(Operator $operator, Tenant $tenant): string
    {
        $token = bin2hex(random_bytes(32));
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));

        $this->connection()->transaction(function (Connection $db) use ($operator, $tenant, $token, $now): void {
            $status = $db->scalar('select status from tenants where id = ? for share', [$tenant->id]);
            $refusal = match (is_string($status) ? TenantStatus::tryFrom($status) : null) {
                TenantStatus::Active, TenantStatus::Closing => null,
                TenantStatus::Suspended => self::SUSPENDED,
                TenantStatus::Provisioning => self::PROVISIONING,
                TenantStatus::Closed => self::CLOSED,
                null => throw CentralRow::gone($tenant),
            };

            if ($refusal !== null) {
                throw SupportRefused::because($refusal);
            }

            $db->insert(
                'insert into support_sessions (operator_id, tenant_id, token_hash, expires_at, created_at) values (?, ?, ?, ?::timestamptz, ?::timestamptz)',
                [$operator->id, $tenant->id, self::hashOf($token), SqlText::instant($now->add(new DateInterval(self::LIFETIME))), SqlText::instant($now)],
            );
            PlatformAudit::record($db, $operator->id, new PlatformAuditEntry(PlatformAuditAction::SupportOpened), $tenant->id);
        });

        return $token;
    }

    /**
     * The open: claims the row the token names at the tenant in one statement and answers its id,
     * or null where no row moved, a link opened, ended, lapsed, past its five minutes, unknown,
     * tampered, of the wrong shape or another tenant's, and nothing has changed.
     */
    public function claim(Tenant $tenant, string $token): ?int
    {
        if (preg_match('/\A'.self::TOKEN.'\z/', $token) !== 1) {
            return null;
        }

        $now = $this->clock->now();
        $id = $this->connection()->scalar(
            'update support_sessions set opened_at = ?::timestamptz, token_hash = null
             where token_hash = ? and tenant_id = ? and opened_at is null and ended_at is null
               and expires_at > ?::timestamptz and created_at >= ?::timestamptz
             returning id',
            [SqlText::instant($now), self::hashOf($token), $tenant->id, SqlText::instant($now), SqlText::instant($now->sub(new DateInterval(self::CLAIM_WINDOW)))],
            false,
        );

        return is_int($id) ? $id : null;
    }

    /**
     * The open session the id names at the tenant, with its operator, or null where the row is
     * another tenant's, unopened, ended or expired by the clock's now, or there is none.
     */
    public function live(Tenant $tenant, int $id): ?SupportSession
    {
        $row = $this->connection()->selectOne(
            self::openSessions().' and session.id = ? and session.tenant_id = ?',
            [SqlText::instant($this->clock->now()), $id, $tenant->id],
        );

        return $row === null ? null : self::sessionOf($row);
    }

    /**
     * The tenant's open sessions for its detail on the console: opened, unended and unexpired by
     * the clock's now, each with its operator, the earliest opened first. A row pressed and
     * never opened is no session anybody is in, and is not among them.
     *
     * @return list<SupportSession>
     */
    public function openAt(Tenant $tenant): array
    {
        $rows = $this->connection()->select(
            self::openSessions().' and session.tenant_id = ? order by session.opened_at, session.id',
            [SqlText::instant($this->clock->now()), $tenant->id],
        );

        return array_values(array_map(self::sessionOf(...), $rows));
    }

    /**
     * The slug of the tenant the row the id names belongs to, ended or not, or null where no row
     * holds the id: where the console's End sends the operator back to.
     */
    public function tenantSlugOf(int $id): ?string
    {
        $slug = $this->connection()->scalar(
            'select tenant.slug from support_sessions session join tenants tenant on tenant.id = session.tenant_id where session.id = ?',
            [$id],
        );

        return is_string($slug) ? $slug : null;
    }

    /**
     * The end: writes ended_at as the clock's now on the row the id names where it is unended
     * and its expiry ahead of that now, and records support_ended in the name of the operator
     * given with the row's tenant, in one transaction. Answers whether the row moved: false for a
     * row ended already, lapsed or never written, which records nothing.
     */
    public function end(int $id, int $operatorId): bool
    {
        $now = SqlText::instant($this->clock->now());

        return $this->connection()->transaction(function (Connection $db) use ($id, $operatorId, $now): bool {
            $tenant = $db->scalar(
                'update support_sessions set ended_at = ?::timestamptz where id = ? and ended_at is null and expires_at > ?::timestamptz returning tenant_id',
                [$now, $id, $now],
                false,
            );

            if (! is_int($tenant)) {
                return false;
            }

            PlatformAudit::record($db, $operatorId, new PlatformAuditEntry(PlatformAuditAction::SupportEnded), $tenant);

            return true;
        });
    }

    /**
     * The open sessions with their operators, the clock's now bound first: opened, unended and
     * ahead of their expiry, each instant handed back as UTC text with its offset. Each read
     * narrows it further.
     */
    private static function openSessions(): string
    {
        return 'select session.id, session.operator_id, operator.name as operator_name, '
            .SqlText::instantColumn('session.opened_at').' as opened_at, '.SqlText::instantColumn('session.expires_at').' as expires_at
             from support_sessions session join operators operator on operator.id = session.operator_id
             where session.opened_at is not null and session.ended_at is null and session.expires_at > ?::timestamptz';
    }

    /**
     * One open session's row as the live read and the detail's list read it.
     */
    private static function sessionOf(mixed $row): SupportSession
    {
        if (! $row instanceof stdClass || ! is_int($row->id ?? null) || ! is_int($row->operator_id ?? null) || ! is_string($row->operator_name ?? null) || ! is_string($row->opened_at ?? null) || ! is_string($row->expires_at ?? null)) {
            throw new UnexpectedValueException('A support_sessions row came back in a shape the read cannot read.');
        }

        return new SupportSession($row->id, $row->operator_id, $row->operator_name, self::instantOf($row->opened_at), self::instantOf($row->expires_at));
    }

    private static function instantOf(string $text): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        $instant = DateTimeImmutable::createFromFormat(SqlText::INSTANT, $text, $utc);

        if ($instant === false) {
            throw new UnexpectedValueException('A support_sessions row came back with an instant the read cannot read.');
        }

        return $instant->setTimezone($utc);
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
