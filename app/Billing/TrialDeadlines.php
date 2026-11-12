<?php

declare(strict_types=1);

namespace App\Billing;

use App\Application\Clock;
use App\Infrastructure\Db\SqlText;
use App\Site\SiteLanguage;
use App\Tenancy\Role;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use LogicException;

/**
 * What the platform does about a trial as its clock runs: the two notices before its end, and the
 * close thirty days after an end nobody paid for.
 *
 * Each is one statement that decides and writes at once, under the Clock port: the notice's stamp
 * where it is due and unsent, the closing where it is due and unset, so a run that crashed after
 * the write sends no second copy and two runs at once send one, the second finding what the first
 * wrote once its row lock is let go. The statement answers the tenants it moved with their owner
 * users, read in the same statement, each with the language they chose, and the caller sends after
 * it has committed. The moved tenants' models are read once after it, for the language each owner
 * is told in, which the mail asks the resolver. Nothing here sends anything, and nothing here reads
 * a tenant schema or drops one: the drop after the retention window is not done here.
 *
 * Only an active row is moved: a suspended one keeps its suspension and hears nothing, and a
 * closing one is already closing.
 */
final readonly class TrialDeadlines
{
    /** How long after a trial ended unpaid the platform closes the account. */
    public const string CLOSES_AFTER = 'P30D';

    /** The retention window a closing keeps the account for. */
    public const string RETENTION_WINDOW = 'P30D';

    private const string MISSHAPEN = 'A moved tenant row is not shaped as the schema declares.';

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
    ) {}

    /**
     * Claims the notice for every tenant in trial whose end is ahead of the clock by no more than
     * the notice's lead and that has not been sent it, stamping it with the clock's now. A notice
     * that reaches a past end (P9F-51a) drops the lower bound: a trial whose end has passed and
     * that the lapse has not yet moved to canceled is claimed too, once, by the same stamp.
     *
     * @return list<TrialAccount>
     */
    public function notice(TrialNotice $notice): array
    {
        $now = $this->now();
        $column = $notice->column();
        [$endAhead, $endAheadAt] = $notice->reachesAPastEnd()
            ? ['', []]
            : ['and trial_ends_at > ?::timestamptz', [SqlText::instant($now)]];

        return $this->moved(
            'update tenants set '.$column.' = ?::timestamptz, updated_at = now()
             where status = ? and subscription_state = '.SqlText::literal(SubscriptionState::Trial).' and '.$column.' is null
               '.$endAhead.' and trial_ends_at <= ?::timestamptz
             returning id, slug, name, timezone, trial_ends_at, closing_at',
            [SqlText::instant($now), TenantStatus::Active->value, ...$endAheadAt, SqlText::instant($now->add(new DateInterval($notice->lead())))],
        );
    }

    /**
     * Closes every active tenant whose trial ended unpaid thirty days or more before the clock's
     * now: canceled with trial_ends_at still set, which the subscription sync clears on the first
     * subscription it lands, so a tenant that once subscribed is never closed here; closing_at is
     * written as now and the status closing, where closing_at is unset.
     *
     * @return list<TrialAccount>
     */
    public function close(): array
    {
        $now = $this->now();

        return $this->moved(
            'update tenants set closing_at = ?::timestamptz, status = '.SqlText::literal(TenantStatus::Closing).', updated_at = now()
             where status = '.SqlText::literal(TenantStatus::Active).' and subscription_state = '.SqlText::literal(SubscriptionState::Canceled).'
               and closing_at is null and trial_ends_at <= ?::timestamptz
             returning id, slug, name, timezone, trial_ends_at, closing_at',
            [SqlText::instant($now), SqlText::instant($now->sub(new DateInterval(self::CLOSES_AFTER)))],
        );
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Runs the update as the head of one statement whose tail reads the moved rows' owner users,
     * and answers each moved tenant once, in id order, its owners in the order they were created.
     *
     * @param  string  $update  written from literals and enum cases, never from anything a caller was handed
     * @param  list<string>  $bindings
     * @return list<TrialAccount>
     */
    private function moved(string $update, array $bindings): array
    {
        $rows = $this->db->connection()->selectFromWriteConnection(
            'with moved as ('.$update.')
             select moved.id, moved.slug, moved.name, moved.timezone,
                    '.SqlText::instantColumn('moved.trial_ends_at').' as trial_ends_at,
                    '.SqlText::instantColumn('moved.closing_at').' as closing_at,
                    users.email as owner_address, users.name as owner_name, users.locale as owner_locale
             from moved left join users on users.tenant_id = moved.id and users.role = ?
             order by moved.id, users.id',
            [...$bindings, Role::Owner->value],
        );

        $moved = [];
        $owners = [];

        foreach ($rows as $row) {
            $row = is_object($row) ? (array) $row : [];
            $id = is_int($row['id'] ?? null) ? $row['id'] : throw new LogicException(self::MISSHAPEN);
            $moved[$id] ??= $row;
            $owners[$id] ??= [];

            if (($row['owner_address'] ?? null) !== null) {
                $locale = $row['owner_locale'] ?? null;
                $owners[$id][] = [
                    'address' => self::text($row['owner_address']),
                    'name' => self::text($row['owner_name'] ?? null),
                    'locale' => $locale === null ? null : SiteLanguage::from(self::text($locale)),
                ];
            }
        }

        if ($moved === []) {
            return [];
        }

        $tenants = Tenant::query()->whereKey(array_keys($moved))->get()->keyBy('id');

        return array_map(fn (int $id): TrialAccount => self::account($id, $moved[$id], $owners[$id], $tenants->get($id) ?? throw new LogicException(self::MISSHAPEN)), array_keys($moved));
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @param  list<array{address: string, name: string, locale: SiteLanguage|null}>  $owners
     */
    private static function account(int $id, array $row, array $owners, Tenant $tenant): TrialAccount
    {
        $closingAt = $row['closing_at'] ?? null;

        return new TrialAccount(
            $id,
            Slug::of(self::text($row['slug'] ?? null)),
            self::text($row['name'] ?? null),
            new DateTimeZone(self::text($row['timezone'] ?? null)),
            SqlText::readInstant(self::text($row['trial_ends_at'] ?? null)),
            $closingAt === null ? null : SqlText::readInstant(self::text($closingAt)),
            $owners,
            $tenant,
        );
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : throw new LogicException(self::MISSHAPEN);
    }
}
