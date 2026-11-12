<?php

declare(strict_types=1);

namespace App\Platform;

use App\Billing\Entitlements;
use App\Billing\PlanCatalogue;
use App\Billing\SubscriptionState;
use App\Domain\Enum\BookingStatus;
use App\Infrastructure\Db\PreconditionFailed;
use App\Infrastructure\Db\SqlText;
use App\Infrastructure\Db\TextArray;
use App\Tenancy\BusinessCategory;
use App\Tenancy\Role;
use App\Tenancy\SearchPath;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use stdClass;
use UnexpectedValueException;

/**
 * One tenant read whole for the console's detail.
 *
 * The central facts are two statements: the tenant row over a left join of its subscriptions row,
 * every instant handed back as UTC text with its offset, the suspension's instant and reason among
 * them (set together and cleared together) and the terms' acceptance with its version's day (set
 * together or null together), and the users rows in the order they were made. The plan is
 * TenantPlan's derivation over that row, as the list derives it.
 *
 * What the tenant's own schema holds is read in one read-only transaction with the search path set
 * locally and current_schema() asserted after it, as every tenant read is: the count of active
 * services, of active staff members with the implicit one counted, of bookings per status (an
 * inactive service is counted no more than an inactive member is), and the latest booking's
 * creation instant. The transaction ends in a rollback, since it wrote nothing: inside an
 * enclosing transaction it is a savepoint, and a release would leave the enclosing one read only
 * and on the tenant's path until its own end, where a rollback to the savepoint undoes both. A
 * provisioning row has no schema yet and is read without it, and so is a closed row, whose schema
 * the drop removed: its central row is read, its slug null, its closed_at set and its users none,
 * and never its schema; a suspended or a closing row is read as an active one.
 *
 * Nothing here writes, calls a network or binds the request's tenant: the console's host resolves
 * none, and CurrentTenant stays unbound.
 */
final readonly class TenantReadout
{
    /** How the detail names itself when a row it reads cannot be read. */
    private const string READER = 'The console\'s detail';

    public function __construct(private DatabaseManager $db, private PlanCatalogue $catalogue) {}

    public function of(Tenant $tenant): TenantFacts
    {
        $row = $this->central($tenant);
        $status = TenantStatus::from(CentralRow::text($row, 'status', self::READER));
        $state = SubscriptionState::from(CentralRow::text($row, 'subscription_state', self::READER));
        $trialEndsAt = self::instant($row, 'trial_ends_at');
        $subscription = self::subscription($row);

        return new TenantFacts(
            slug: CentralRow::optionalText($row, 'slug', self::READER),
            name: CentralRow::text($row, 'name', self::READER),
            status: $status,
            timezone: CentralRow::text($row, 'timezone', self::READER),
            currency: CentralRow::text($row, 'currency', self::READER),
            category: BusinessCategory::tryFrom(CentralRow::optionalText($row, 'category', self::READER) ?? ''),
            signedUpAt: self::instant($row, 'created_at') ?? throw CentralRow::misshapen('created_at', self::READER),
            trialEndsAt: $trialEndsAt,
            graceEndsAt: self::instant($row, 'grace_ends_at'),
            closingAt: self::instant($row, 'closing_at'),
            closedAt: self::instant($row, 'closed_at'),
            suspension: self::suspension($row),
            terms: self::terms($row),
            state: $state,
            plan: TenantPlan::of(
                status: $status,
                state: $state,
                trialEnd: $trialEndsAt !== null,
                subscriptionRow: $subscription !== null,
                planRef: CentralRow::optionalText($row, 'plan_ref', self::READER),
                catalogue: $this->catalogue,
            ),
            entitlements: Entitlements::fromSlugs(TextArray::decode(CentralRow::text($row, 'entitlements', self::READER))),
            subscription: $subscription,
            users: $this->users($tenant),
            activity: in_array($status, TenantStatus::schemaless(), true) ? null : $this->activity(CentralRow::text($row, 'schema_name', self::READER)),
        );
    }

    private function central(Tenant $tenant): stdClass
    {
        $row = $this->connection()->selectOne(sprintf(
            'select tenant.slug, tenant.name, tenant.status, tenant.timezone, tenant.currency, tenant.category,'
            .' tenant.schema_name, tenant.subscription_state, tenant.plan_ref, tenant.entitlements,'
            .' %s as created_at, %s as trial_ends_at, %s as grace_ends_at, %s as closing_at, %s as closed_at, %s as suspended_at, tenant.suspension_reason,'
            .' %s as terms_accepted_at, tenant.terms_version::text as terms_version,'
            .' subscription.tenant_id is not null as subscription_row, subscription.provider_customer_id,'
            .' subscription.status as provider_status, %s as current_period_end'
            .' from tenants tenant left join subscriptions subscription on subscription.tenant_id = tenant.id'
            .' where tenant.id = ?',
            SqlText::instantColumn('tenant.created_at'),
            SqlText::instantColumn('tenant.trial_ends_at'),
            SqlText::instantColumn('tenant.grace_ends_at'),
            SqlText::instantColumn('tenant.closing_at'),
            SqlText::instantColumn('tenant.closed_at'),
            SqlText::instantColumn('tenant.suspended_at'),
            SqlText::instantColumn('tenant.terms_accepted_at'),
            SqlText::instantColumn('subscription.current_period_end'),
        ), [$tenant->id]);

        return $row instanceof stdClass ? $row : throw CentralRow::gone($tenant);
    }

    /**
     * Every users row of the tenant in the order they were made: the id, the name, the email, the
     * role and whether the email was verified, and no other column.
     *
     * @return list<TenantPerson>
     */
    private function users(Tenant $tenant): array
    {
        $people = [];

        foreach ($this->connection()->select('select id, name, email, role, email_verified_at is not null as verified from users where tenant_id = ? order by id', [$tenant->id]) as $user) {
            if (! $user instanceof stdClass || ! is_int($user->id ?? null) || ! is_bool($user->verified ?? null)) {
                throw CentralRow::misshapen('users', self::READER);
            }

            $people[] = new TenantPerson($user->id, CentralRow::text($user, 'name', self::READER), CentralRow::text($user, 'email', self::READER), Role::from(CentralRow::text($user, 'role', self::READER)), $user->verified);
        }

        return $people;
    }

    /**
     * The schema's counts and last activity, in one read-only transaction on the tenant's path,
     * ended in a rollback whatever happened inside it.
     */
    private function activity(string $schemaName): TenantActivity
    {
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $connection->statement('set transaction read only');
            $connection->statement('set local search_path to '.SearchPath::forSchema($schemaName));

            $schema = $connection->scalar('select current_schema()');

            if ($schema !== $schemaName) {
                throw PreconditionFailed::schema($schemaName, $schema);
            }

            $counts = $connection->selectOne(sprintf(
                "select (select count(*) from services where status = 'active') as services, (select count(*) from staff where status = 'active') as active_staff, (select %s from bookings) as last_activity",
                SqlText::instantColumn('max(created_at)'),
            ));
            $byStatus = $connection->select('select status, count(*) as count from bookings group by status');
        } finally {
            $connection->rollBack();
        }

        if (! $counts instanceof stdClass || ! is_int($counts->services) || ! is_int($counts->active_staff)) {
            throw new UnexpectedValueException(sprintf('The schema "%s" counted no integers.', $schemaName));
        }

        $bookings = [];

        foreach (BookingStatus::cases() as $status) {
            $bookings[$status->value] = 0;
        }

        foreach ($byStatus as $group) {
            if (! $group instanceof stdClass || ! is_string($group->status) || ! array_key_exists($group->status, $bookings) || ! is_int($group->count)) {
                throw new UnexpectedValueException(sprintf('The schema "%s" counted its bookings in a shape the console does not know.', $schemaName));
            }

            $bookings[$group->status] = $group->count;
        }

        return new TenantActivity($counts->services, $counts->active_staff, $bookings, self::instant($counts, 'last_activity'));
    }

    private static function subscription(stdClass $row): ?TenantSubscription
    {
        if (($row->subscription_row ?? null) !== true) {
            return null;
        }

        return new TenantSubscription(
            CentralRow::text($row, 'provider_customer_id', self::READER),
            CentralRow::optionalText($row, 'provider_status', self::READER),
            self::instant($row, 'current_period_end'),
        );
    }

    private static function suspension(stdClass $row): ?TenantSuspension
    {
        $at = self::instant($row, 'suspended_at');
        $reason = CentralRow::optionalText($row, 'suspension_reason', self::READER);

        return $at === null || $reason === null ? null : new TenantSuspension($at, $reason);
    }

    private static function terms(stdClass $row): ?TenantTerms
    {
        $at = self::instant($row, 'terms_accepted_at');
        $version = CentralRow::optionalText($row, 'terms_version', self::READER);

        return $at === null || $version === null ? null : new TenantTerms($at, $version);
    }

    /**
     * An instant the statement handed back as UTC text with its offset, read as UTC, or null.
     */
    private static function instant(stdClass $row, string $column): ?DateTimeImmutable
    {
        $text = CentralRow::optionalText($row, $column, self::READER);

        if ($text === null) {
            return null;
        }

        $instant = DateTimeImmutable::createFromFormat(SqlText::INSTANT, $text, new DateTimeZone('UTC'));

        return $instant === false ? throw CentralRow::misshapen($column, self::READER) : $instant;
    }

    private function connection(): Connection
    {
        return $this->db->connection();
    }
}
