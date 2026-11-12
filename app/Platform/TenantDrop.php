<?php

declare(strict_types=1);

namespace App\Platform;

use App\Application\Clock;
use App\Billing\BillingProvider;
use App\Billing\TrialDeadlines;
use App\Infrastructure\Db\SqlText;
use App\Infrastructure\Db\TextArray;
use App\Infrastructure\Storage\TenantImages;
use App\Retention\Cutoff;
use App\Tenancy\ExclusiveStep;
use App\Tenancy\Slug;
use App\Tenancy\TenantStatus;
use Illuminate\Container\Attributes\Config;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use stdClass;
use UnexpectedValueException;

/**
 * The drop, the retention sweep's last line: thirty days after a tenant closed, everything of it
 * goes but the central row, kept indefinitely as the accounting record.
 *
 * A tenant is due when its row is closing and its closing_at is thirty days or more before the
 * Clock port's now, the retention window ClosingWindow counts, so a row inside the window stays
 * whole. The demo tenant is never due: its nightly rebuild writes it active with no closing at
 * 04:00, before the sweep's 04:30, and the predicate names its slug as the sweep of abandoned
 * signups does, so no mistake on its row ever drops the schema the site's demo serves.
 *
 * Each due tenant is dropped in a transaction of its own, in id order. The transaction takes the
 * tenant row for update and reads it again under the predicate, so a reopening that committed
 * first leaves the row standing and the drop answers null; one waiting behind the drop finds the
 * row closed. Then, in order: the schema dropped, cascade, by its schema_name, under ExclusiveStep's
 * lock timeout, since a DROP SCHEMA takes ACCESS EXCLUSIVE on every table in it and must not queue
 * a reader's request behind it for longer; the tenant's users, payment routes and support sessions
 * deleted by tenant_id; the billing notifications naming the tenant's provider customer deleted,
 * the customer read from the subscriptions row and found in each payload where the bound billing
 * provider's own shape carries it (BillingProvider::customerPath()), a notification naming no
 * customer there being left to the ninety days of its own line; and the row written closed, its
 * closed_at the clock's now as UTC text with its offset and its slug nulled, which releases the
 * address for a new signup. The schema_name and the name are kept, and so are the subscriptions
 * row, the platform audit rows, to their own 24 months, and the payment events, with their tenant
 * id to their ninety days. The schema is the one the slug names, refused otherwise, so the
 * statement can never name another.
 *
 * After the commit the tenant's directory of uploaded images is deleted from the public disk, by
 * the slug read before the statement nulled it. A disk that refuses is logged once at error with
 * the directory to delete by hand, and the drop stands: the row is closed and no run comes back
 * to it. Nothing is mailed: the closing email told the owners the day.
 */
final readonly class TenantDrop
{
    public const string IMAGES_LOGGED = 'The drop could not delete a closed tenant\'s images; the drop stands and the directory is to be deleted by hand';

    /** How the drop names itself when a row it reads cannot be read. */
    private const string READER = 'The drop';

    /** The predicate a due row answers, over the tenants row aliased t, bound by dueBindings(). */
    private const string DUE = 't.status = ? and t.closing_at <= ?::timestamptz and t.slug <> ?';

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private BillingProvider $billing,
        private TenantImages $images,
        private LoggerInterface $log,
        #[Config('site.demo_slug')]
        private string $demoSlug,
    ) {}

    /**
     * The tenants due, by id, in id order.
     *
     * @return list<int>
     */
    public function due(): array
    {
        $ids = $this->db->connection()->select(
            'select t.id from tenants t where '.self::DUE.' order by t.id',
            $this->dueBindings(),
        );

        return array_map(
            fn (mixed $row): int => $row instanceof stdClass && is_int($row->id ?? null) ? $row->id : throw CentralRow::misshapen('id', self::READER),
            array_values($ids),
        );
    }

    /**
     * Drops the tenant of the id given and answers what went, or null where its row is no longer
     * due once locked.
     */
    public function drop(int $tenantId): ?DroppedTenant
    {
        $at = $this->clock->now();

        /** @var array{slug: Slug, dropped: DroppedTenant}|null $dropped */
        $dropped = $this->db->connection()->transaction(function (Connection $db) use ($tenantId, $at): ?array {
            $row = $db->selectOne(
                'select t.slug, t.schema_name, s.provider_customer_id
                 from tenants t left join subscriptions s on s.tenant_id = t.id
                 where t.id = ? and '.self::DUE.' for update of t',
                [$tenantId, ...$this->dueBindings()],
            );

            if (! $row instanceof stdClass) {
                return null;
            }

            $slug = Slug::of(CentralRow::text($row, 'slug', self::READER));
            $schema = CentralRow::text($row, 'schema_name', self::READER);
            $customer = CentralRow::optionalText($row, 'provider_customer_id', self::READER);

            if ($schema !== $slug->schemaName()) {
                throw new UnexpectedValueException(sprintf('The drop found tenant %d naming a schema its slug does not derive.', $tenantId));
            }

            ExclusiveStep::run($db, fn (): bool => $db->statement(sprintf('drop schema if exists "%s" cascade', $schema)));

            $dropped = new DroppedTenant(
                tenantId: $tenantId,
                schema: $schema,
                users: $db->delete('delete from users where tenant_id = ?', [$tenantId]),
                paymentRoutes: $db->delete('delete from payment_routes where tenant_id = ?', [$tenantId]),
                supportSessions: $db->delete('delete from support_sessions where tenant_id = ?', [$tenantId]),
                webhookEvents: $customer === null ? 0 : $db->delete(
                    'delete from webhook_events where payload #>> ?::text[] = ?',
                    [TextArray::encode($this->billing->customerPath()), $customer],
                ),
            );

            $db->update(
                'update tenants set status = ?, closed_at = ?::timestamptz, slug = null, updated_at = now() where id = ?',
                [TenantStatus::Closed->value, SqlText::instant($at), $tenantId],
            );

            return ['slug' => $slug, 'dropped' => $dropped];
        });

        if ($dropped === null) {
            return null;
        }

        if (! $this->images->deleteAll($dropped['slug'])) {
            $this->log->error(self::IMAGES_LOGGED, ['tenant' => $tenantId, 'slug' => $dropped['slug']->value]);
        }

        return $dropped['dropped'];
    }

    /**
     * What DUE binds, in its order.
     *
     * @return list<string>
     */
    private function dueBindings(): array
    {
        return [TenantStatus::Closing->value, Cutoff::before($this->clock, TrialDeadlines::RETENTION_WINDOW), $this->demoSlug];
    }
}
