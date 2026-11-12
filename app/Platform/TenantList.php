<?php

declare(strict_types=1);

namespace App\Platform;

use App\Billing\Plan;
use App\Billing\PlanCatalogue;
use App\Billing\SubscriptionState;
use App\Infrastructure\Db\SqlText;
use App\Tenancy\TenantStatus;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use stdClass;
use UnexpectedValueException;

/**
 * The tenant list read for the console's home: every tenant row over a left join of its
 * subscriptions row, filtered in SQL, newest signup first and the later row first within one
 * instant, twenty-five a page. Two statements a page over the one join, the count and the rows,
 * whatever the page holds.
 *
 * The search is one case-insensitive substring over the slug and the name, its wildcards literal; a
 * closed row, whose slug the drop released, is found by its name alone. The plan filter is
 * TenantPlan's derivation written as SQL, so it narrows to exactly the rows the derivation names,
 * the provisioning rows under no plan. The two dates are calendar days in the console's zone: From
 * from its midnight, To up to the next day's, so To is inclusive.
 *
 * It reads nothing else, no tenant's schema and no network, and writes nothing.
 */
final readonly class TenantList
{
    public const int PAGE_SIZE = 25;

    private const string FROM = 'from tenants tenant left join subscriptions subscription on subscription.tenant_id = tenant.id';

    public function __construct(private DatabaseManager $db, private PlanCatalogue $catalogue, private ConsoleTime $time) {}

    /**
     * One page of the list and how many rows match in all.
     *
     * @return array{records: list<ListedTenant>, total: int}
     */
    public function page(TenantListQuery $query): array
    {
        // The catalogue builds every plan afresh at each question, so the page asks it once, for
        // the plan filter and every row alike.
        $plans = TenantPlan::plansOf($this->catalogue);
        [$where, $bindings] = $this->where($query, $plans);
        $total = $this->db->connection()->scalar(sprintf('select count(*) %s where %s', self::FROM, $where), $bindings);

        $rows = $this->db->connection()->select(sprintf(
            'select tenant.id, tenant.slug, tenant.name, tenant.status, tenant.subscription_state, tenant.plan_ref,'
            .' tenant.trial_ends_at is not null as trial_end, subscription.tenant_id is not null as subscription_row,'
            .' %s as created_at %s where %s order by tenant.created_at desc, tenant.id desc limit ? offset ?',
            SqlText::instantColumn('tenant.created_at'),
            self::FROM,
            $where,
        ), [...$bindings, self::PAGE_SIZE, ($query->page - 1) * self::PAGE_SIZE]);

        return [
            'records' => array_map(fn (mixed $row): ListedTenant => self::record($row, $plans), array_values($rows)),
            'total' => is_int($total) ? $total : throw new UnexpectedValueException('The tenant list counted no integer.'),
        ];
    }

    /**
     * The query's filters as one condition and what it binds, every field left unset adding none.
     *
     * @param  list<Plan>|null  $plans
     * @return array{0: string, 1: list<string>}
     */
    private function where(TenantListQuery $query, ?array $plans): array
    {
        $conditions = ['true'];
        $bindings = [];

        if ($query->search !== null) {
            $like = '%'.addcslashes($query->search, '\\%_').'%';
            $conditions[] = '(tenant.slug ilike ? or tenant.name ilike ?)';
            array_push($bindings, $like, $like);
        }

        if ($query->status !== null) {
            $conditions[] = 'tenant.status = ?';
            $bindings[] = $query->status->value;
        }

        if ($query->state !== null) {
            $conditions[] = 'tenant.subscription_state = ?';
            $bindings[] = $query->state->value;
        }

        if ($query->plan !== null) {
            [$plan, $prices] = TenantPlan::sql($plans);
            $conditions[] = $plan.' = ?';
            array_push($bindings, ...$prices);
            $bindings[] = $query->plan->value;
        }

        if ($query->from !== null) {
            $from = $this->midnight($query->from, 0);

            if ($from !== null) {
                $conditions[] = 'tenant.created_at >= ?::timestamptz';
                $bindings[] = SqlText::instant($from);
            }
        }

        if ($query->to !== null) {
            $conditions[] = 'tenant.created_at < ?::timestamptz';
            $bindings[] = SqlText::instant($this->midnight($query->to, 1) ?? throw new UnexpectedValueException('The tenant list was handed a To before the calendar\'s first day.'));
        }

        return [implode(' and ', $conditions), $bindings];
    }

    /**
     * The first instant of a calendar day on the console's clock, the day given or one some days
     * after it, counted on the calendar rather than the clock, so a day of a clock change is the
     * day it is. Null where that midnight falls before the year 1 in UTC, which only the first day
     * of the year 1 can, east of UTC: the instant crosses to the database as UTC text with its
     * offset, and a year before 1 written that way, as the year 0 or a negative year, is no
     * timestamp PostgreSQL reads, since it spells the years before 1 as BC; it is the crossing that
     * fails, not the database's calendar. No signup precedes that midnight, so it bounds nothing.
     */
    private function midnight(string $day, int $daysAfter): ?DateTimeImmutable
    {
        [$year, $month, $date] = array_map(intval(...), explode('-', $day));
        $midnight = (new DateTimeImmutable('1970-01-01 00:00', $this->time->zone()))->setDate($year, $month, $date + $daysAfter)->setTime(0, 0);

        return (int) $midnight->setTimezone(new DateTimeZone('UTC'))->format('Y') < 1 ? null : $midnight;
    }

    /**
     * @param  list<Plan>|null  $plans
     */
    private static function record(mixed $row, ?array $plans): ListedTenant
    {
        if (! $row instanceof stdClass
            || ! is_int($row->id)
            || ! ($row->slug === null || is_string($row->slug))
            || ! is_string($row->name)
            || ! is_string($row->status)
            || ! is_string($row->subscription_state)
            || ! ($row->plan_ref === null || is_string($row->plan_ref))
            || ! is_bool($row->trial_end)
            || ! is_bool($row->subscription_row)
            || ! is_string($row->created_at)) {
            throw new UnexpectedValueException('A tenant row came back in a shape the console\'s list does not know.');
        }

        $signedUpAt = DateTimeImmutable::createFromFormat(SqlText::INSTANT, $row->created_at, new DateTimeZone('UTC'));

        if ($signedUpAt === false) {
            throw new UnexpectedValueException(sprintf('The tenant row %d holds a signup instant the console\'s list cannot read.', $row->id));
        }

        $status = TenantStatus::from($row->status);
        $state = SubscriptionState::from($row->subscription_state);

        return new ListedTenant(
            $row->id,
            $row->slug,
            $row->name,
            $status,
            $state,
            TenantPlan::among(
                status: $status,
                state: $state,
                trialEnd: $row->trial_end,
                subscriptionRow: $row->subscription_row,
                planRef: $row->plan_ref,
                plans: fn (): ?array => $plans,
            ),
            $signedUpAt,
        );
    }
}
