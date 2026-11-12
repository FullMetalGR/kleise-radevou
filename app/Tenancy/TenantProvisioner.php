<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Application\Checkpoint;
use App\Application\Clock;
use App\Billing\Entitlements;
use App\Billing\SubscriptionState;
use App\Domain\Lifetime;
use App\Infrastructure\Db\SqlText;
use App\Infrastructure\Db\WeeklyPattern;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use DateInterval;
use DateTimeZone;
use Illuminate\Container\Attributes\Config;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;
use Symfony\Component\Console\Output\OutputInterface;
use UnexpectedValueException;

/**
 * Turns a signup into a working tenant: the central row, the schema named from the slug, every
 * tenant migration inside it, the settings row, the implicit staff member with its default
 * working week, and the owner user.
 *
 * Two paths. The verified signup reserves the slug with reserve(), which writes the row, its owner
 * and the owner's link hash and no schema; the link's click builds the row with
 * provisionClaimedTrial(), the row written in trial on the full feature set until seven days from
 * the clock, and no billing-provider object; sweepAbandoned() gives a reservation nobody verified
 * back a day after it was made. Trial is written by that build and nowhere else. The console's
 * provision() below bypasses billing, keeps out of the trial and refuses a row a signup holds. A
 * third path, rebuildDemo(), is the demo tenant's alone: the one reserved label a tenant is built
 * under, dropped and built again nightly.
 *
 * provision() takes two transactions. The first claims the slug with a row in state provisioning
 * and commits, so a failure later leaves the slug held and the row visibly unfinished. The second
 * does everything else and activates the tenant in the same commit, so the schema exists only for
 * an active tenant and a failure anywhere inside it rolls the schema back with it: PostgreSQL DDL
 * is transactional.
 *
 * Idempotent. A second run finds the active row and returns it. A run after a failure finds the
 * provisioning row and finishes the job, unless told to finish only a row it creates, the free
 * tenant's: the schema is created if absent, only pending migrations run, and each row is inserted
 * only where missing. Provisioned says which of the three a run did. The default week is inserted
 * only where the implicit member holds no shift pattern at all, so hours a tenant has edited are
 * never restored.
 */
final readonly class TenantProvisioner
{
    /**
     * Where reserve() has written the row and its owner and not yet committed, for a concurrency
     * test.
     */
    public const string RESERVED = 'provisioner.reserved';

    /**
     * Where provisionClaimedTrial() holds the row's lock and has built nothing yet, for a
     * concurrency test.
     */
    public const string BUILD_LOCKED = 'provisioner.build.locked';

    /**
     * Where the claim of provision() has judged the slug free and not yet written its row, for a
     * concurrency test.
     */
    public const string CLAIMING = 'provisioner.claiming';

    /**
     * Where the build of provision() holds the schema and the owner it wrote, uncommitted, for a
     * concurrency test.
     */
    public const string BUILDING = 'provisioner.building';

    /**
     * How long a signup's row may stay provisioning before the sweep gives its slug back.
     */
    public const string ABANDONED_AFTER = 'PT24H';

    /**
     * How long a trial runs from its build: seven whole days, counted in UTC.
     */
    public const string TRIAL_LENGTH = 'P7D';

    /**
     * How many whole days the trial runs, TRIAL_LENGTH as Lifetime reads an interval. The
     * verification link's mail names the trial's length from it.
     */
    public static function trialDays(): int
    {
        return Lifetime::days(self::TRIAL_LENGTH);
    }

    public function __construct(
        private SlugRules $slugRules,
        private TenantMigrator $migrator,
        private DatabaseManager $db,
        private Clock $clock,
        #[Config('site.demo_slug')]
        private string $demoSlug,
    ) {}

    /**
     * The console's path, tenant:provision's and the free tenant's. A row this run finds
     * provisioning is finished, as a retried tenant:provision finishes its own, unless
     * $finishFound is false: the free tenant's create passes false, since the form refuses every
     * row of the slug before the build, so a provisioning row the build meets is another create's
     * build in flight, which a second build would hand a second owner or answer the schema's name
     * with a 500. That row is handed back unbuilt, created false.
     *
     * @throws InvalidSlug when the slug is malformed, reserved, or belongs to nobody yet fails the
     *                     rules, or another run wrote its row between the rules' check and this
     *                     run's insert
     * @throws ProvisioningRefused when the slug belongs to a suspended or closing tenant, or to a
     *                             signup's reservation, a provisioning row with a users row or a
     *                             subscriptions row beside it, which its verification link builds
     */
    public function provision(Signup $signup, ?OutputInterface $output = null, bool $finishFound = true): Provisioned
    {
        [$tenant, $created] = $this->claim($signup);

        return $this->build($tenant, $created, $finishFound, $signup, $output);
    }

    /**
     * The trial's shape on a row, written before it is saved: state trial, its end seven days
     * from the clock's now and the full feature set whatever the signup named; the hourly lapse
     * writes canceled once that end has passed.
     */
    private function shapeTrial(Tenant $tenant): void
    {
        $tenant->subscription_state = SubscriptionState::Trial->value;
        $tenant->entitlements = Entitlements::all()->toSlugs();
        $tenant->trial_ends_at = CarbonImmutable::instance($this->clock->now()->setTimezone(new DateTimeZone('UTC'))->add(new DateInterval(self::TRIAL_LENGTH)));
    }

    private function build(Tenant $tenant, bool $created, bool $finishFound, Signup $signup, ?OutputInterface $output): Provisioned
    {
        if ($tenant->status === TenantStatus::Active) {
            return new Provisioned($tenant, created: false, alreadyActive: true);
        }

        if (! $created && ! $finishFound) {
            return new Provisioned($tenant, created: false, alreadyActive: false);
        }

        $this->connection()->transaction(function () use ($tenant, $signup, $output): void {
            $this->buildSchema($tenant, $output);
            $this->createOwner($tenant, $signup);
            Checkpoint::reach(self::BUILDING);

            $tenant->status = TenantStatus::Active;
            $tenant->save();
        });

        return new Provisioned($tenant, created: $created, alreadyActive: false);
    }

    /**
     * Builds a verified signup's reserved row as a trial: the schema, every tenant migration, the
     * settings row and the implicit member with its week, the owner being there since reserve(),
     * with the trial's shape carried onto the reserved row, the status, the state, its end and
     * the full set written in the build's commit; $verified writes the owner's side of the click
     * in that same commit. One transaction that takes the row for update and re-reads its status
     * first, so a second click at the same moment waits for the first and then finds nothing to
     * do. Answers the row's status as the lock found it, null when the row is gone: provisioning
     * is the row just built, any other the row left as it was.
     *
     * @param  Closure(Connection): void  $verified
     */
    public function provisionClaimedTrial(int $tenantId, Closure $verified): ?TenantStatus
    {
        return $this->connection()->transaction(function (Connection $db) use ($tenantId, $verified): ?TenantStatus {
            $tenant = Tenant::query()->lockForUpdate()->find($tenantId);
            Checkpoint::reach(self::BUILD_LOCKED);

            if (! $tenant instanceof Tenant) {
                return null;
            }

            if ($tenant->status !== TenantStatus::Provisioning) {
                return $tenant->status;
            }

            $this->buildSchema($tenant, null);
            $this->shapeTrial($tenant);
            $tenant->status = TenantStatus::Active;
            $tenant->setUpdatedAt($this->clock->now());
            $tenant->save();
            $verified($db);

            return TenantStatus::Provisioning;
        });
    }

    /**
     * The demo tenant's rebuild: the one path that takes the reserved demo label, which SlugRules
     * refuses to every other. The label is the configured one and nothing else: a signup naming any
     * other slug is a programming error, refused before anything is read, so no caller can aim a
     * schema drop at a tenant by naming it.
     *
     * It builds from nothing whether or not the demo exists, in two transactions. The first takes
     * the row for update, or makes it, and writes it provisioning in the free shape the console's
     * path gives a tenant: the signup's name, zone, currency, category, language and feature set,
     * the subscription state active, no trial end, no grace, no closing, no suspension; and it
     * deletes the central rows the old schema's contents pointed at, the users (the owner among
     * them, so its random password is drawn afresh each night), any subscriptions row and the
     * payment routes and events of connections the drop takes with it. The platform audit log and
     * the support sessions keep naming the tenant by its id, which does not change: an audit row is
     * never rewritten. Once that commits, a request reaching the demo answers as for any
     * provisioning row. The second transaction drops the schema, builds it as provision() builds
     * one, writes the owner, runs the seed inside the same transaction so nothing serves a
     * half-seeded salon, and turns the row active in that commit; a failure anywhere rolls the drop
     * back with it, PostgreSQL DDL being transactional, and leaves the row provisioning for the
     * next run.
     *
     * @param  Closure(Tenant, Connection): void  $seed  what fills the fresh schema, run inside the
     *                                                   build's transaction on its connection
     *
     * @throws LogicException when the signup names any slug but the configured demo label
     */
    public function rebuildDemo(Signup $signup, Closure $seed): Tenant
    {
        $slug = Slug::of($signup->slug);

        if ($slug->value !== $this->demoSlug) {
            throw new LogicException(sprintf('The rebuild is the demo tenant\'s alone, the label "%s"; "%s" is not it.', $this->demoSlug, $slug->value));
        }

        $tenant = $this->connection()->transaction(function (Connection $db) use ($slug, $signup): Tenant {
            $tenant = Tenant::query()->where('slug', $slug->value)->lockForUpdate()->first();
            $now = $this->clock->now();

            if (! $tenant instanceof Tenant) {
                $tenant = new Tenant(['slug' => $slug]);
                $tenant->setCreatedAt($now);
            }

            $tenant->name = $signup->name;
            $tenant->timezone = $signup->timezone->getName();
            $tenant->currency = $signup->currency;
            $tenant->status = TenantStatus::Provisioning;
            $tenant->category = $signup->category;
            $tenant->locale = $signup->locale;
            $tenant->entitlements = ($signup->entitlements ?? Entitlements::none())->toSlugs();
            $tenant->narrowed = [];
            $tenant->plan_ref = null;
            $tenant->subscription_state = SubscriptionState::Active->value;
            $tenant->grace_ends_at = null;
            $tenant->closing_at = null;
            $tenant->trial_ends_at = null;
            $tenant->trial_three_days_sent_at = null;
            $tenant->trial_ends_today_sent_at = null;
            $tenant->suspended_at = null;
            $tenant->suspension_reason = null;
            $tenant->terms_accepted_at = null;
            $tenant->terms_version = null;
            $tenant->setUpdatedAt($now);
            $tenant->save();

            foreach (['payment_events', 'payment_routes', 'subscriptions', 'users'] as $table) {
                $db->table($table)->where('tenant_id', $tenant->id)->delete();
            }

            return $tenant;
        });

        $this->connection()->transaction(function (Connection $db) use ($tenant, $signup, $seed): void {
            $db->statement(sprintf('drop schema if exists "%s" cascade', $tenant->schema_name));
            $this->buildSchema($tenant, null);
            $this->createOwner($tenant, $signup);
            $seed($tenant, $db);

            $tenant->status = TenantStatus::Active;
            $tenant->setUpdatedAt($this->clock->now());
            $tenant->save();
        });

        return $tenant;
    }

    /**
     * The verified signup's first step: a new row in state provisioning holding the slug, with
     * the owner user under it, in one transaction, and no schema. The row starts at
     * subscription_state incomplete until the click's build writes the trial; the console path
     * keeps the table's active. The slug must be free: a row in any state holds it, and a second
     * signup racing for it at the same moment meets the unique index and is refused as held by a
     * signup in progress, the sentence the same person reads on resubmitting a second later: the
     * only writers that can win the index are another reserve() and the console's claim, and both
     * write a provisioning row.
     *
     * A verified signup names the sha256 of its owner's link, written on the owner in the same
     * commit, so no reserved row exists whose owner has no link to click.
     *
     * @throws InvalidSlug when the slug is malformed, reserved or held
     */
    public function reserve(Signup $signup, ?string $verificationHash = null): Tenant
    {
        try {
            return $this->connection()->transaction(function () use ($signup, $verificationHash): Tenant {
                $tenant = $this->newRow($signup);
                $tenant->subscription_state = SubscriptionState::Incomplete->value;
                $tenant->save();
                $this->createOwner($tenant, $signup);

                if ($verificationHash !== null) {
                    $this->connection()->table('users')->where('tenant_id', $tenant->id)->update(['verification_token_hash' => $verificationHash]);
                }

                Checkpoint::reach(self::RESERVED);

                return $tenant;
            });
        } catch (UniqueConstraintViolationException) {
            throw InvalidSlug::because(SlugProblem::HeldBySignup);
        }
    }

    /**
     * Gives a reserved slug back: the row, its users and any subscriptions row, while it is still
     * provisioning. The sweep gives back every signup abandoned a day ago through it. The row is
     * taken with a plain lock, waiting for a click building it and then finding it active: SKIP
     * LOCKED is kept for the holds sweeper's batch alone. Answers whether it deleted.
     */
    public function release(Tenant $tenant): bool
    {
        return $this->releaseRow($tenant->id);
    }

    private function releaseRow(int $tenantId): bool
    {
        return $this->connection()->transaction(function (Connection $db) use ($tenantId): bool {
            $held = $db->selectOne('select t.id from tenants t where t.id = ? and t.status = ? for update', [$tenantId, TenantStatus::Provisioning->value]);

            if ($held === null) {
                return false;
            }

            $db->table('subscriptions')->where('tenant_id', $tenantId)->delete();
            $db->table('users')->where('tenant_id', $tenantId)->delete();
            $db->table('tenants')->where('id', $tenantId)->delete();

            return true;
        });
    }

    /**
     * Sweeps the abandoned signups: every row still provisioning a day after it was created, its
     * owner unverified, is given back as release() gives it, which frees its slug. A verified
     * owner's row is never swept: the click that verifies builds in the same commit. Answers how
     * many went.
     */
    public function sweepAbandoned(): int
    {
        $before = SqlText::instant($this->clock->now()->sub(new DateInterval(self::ABANDONED_AFTER)));
        // The demo's row is provisioning while its nightly rebuild runs and is no signup's:
        // rebuildDemo() deletes its owner before the build writes a fresh one, so the predicate
        // below would read it as abandoned on any morning the two overlapped.
        $ids = $this->connection()->table('tenants as t')
            ->where('t.status', TenantStatus::Provisioning->value)
            ->where('t.slug', '<>', $this->demoSlug)
            ->whereRaw('t.created_at < ?::timestamptz', [$before])
            ->whereNotExists(fn (Builder $verified): Builder => $verified->from('users')->whereColumn('users.tenant_id', 't.id')->whereNotNull('users.email_verified_at'))
            ->orderBy('t.id')
            ->pluck('t.id');

        $swept = 0;

        foreach ($ids as $id) {
            if (! is_int($id)) {
                throw new UnexpectedValueException(sprintf('The sweep of abandoned signups read a tenant id that is a %s, where the schema declares a bigint.', get_debug_type($id)));
            }

            $swept += $this->releaseRow($id) ? 1 : 0;
        }

        return $swept;
    }

    /**
     * The row for this slug and whether this call created it: the existing one when it is active
     * or a half-built row of this path's own, or a new one in state provisioning. A provisioning
     * row with a users row or a subscriptions row beside it is a signup's reservation, whose
     * verification link builds it, and is refused: reserve() writes the owner in the row's own
     * transaction, while this path's owner rolls back with its DDL, so its own half-built row
     * never has one. The new row is the one path a slug is judged on, through SlugRules, which
     * is the only place the reserved list and uniqueness are checked.
     *
     * Another run can write the slug's row between the rules' check and this insert: a second
     * console create, a signup's reserve() or another tenant:provision. The unique index refuses
     * the insert and the refusal is the slug's own, as reserve() maps it, taken rather than held
     * by a signup, since the row that won is whichever writer's and the slug is no longer this
     * run's to take.
     *
     * @return array{0: Tenant, 1: bool}
     *
     * @throws InvalidSlug
     * @throws ProvisioningRefused
     */
    private function claim(Signup $signup): array
    {
        $existing = Tenant::query()->where('slug', Slug::of($signup->slug)->value)->first();

        if ($existing instanceof Tenant) {
            if ($existing->status === TenantStatus::Active) {
                return [$existing, false];
            }

            if ($existing->status !== TenantStatus::Provisioning) {
                throw ProvisioningRefused::because($existing);
            }

            if ($this->heldBySignup($existing)) {
                throw ProvisioningRefused::heldBySignup($existing);
            }

            return [$existing, false];
        }

        $tenant = $this->newRow($signup);
        Checkpoint::reach(self::CLAIMING);

        try {
            $tenant->save();
        } catch (UniqueConstraintViolationException) {
            throw InvalidSlug::because(SlugProblem::Taken);
        }

        return [$tenant, true];
    }

    private function heldBySignup(Tenant $tenant): bool
    {
        $db = $this->connection();

        return $db->table('users')->where('tenant_id', $tenant->id)->exists()
            || $db->table('subscriptions')->where('tenant_id', $tenant->id)->exists();
    }

    /**
     * An unsaved row in state provisioning for a slug SlugRules has judged free, created and
     * updated at one reading of the application's clock. Where the signup carries the owner's
     * acceptance of the terms, which the signup form's alone does, the row records it at that
     * same reading with the version accepted; every other row leaves both null.
     *
     * @throws InvalidSlug
     */
    private function newRow(Signup $signup): Tenant
    {
        $tenant = new Tenant([
            'slug' => $this->slugRules->check($signup->slug),
            'name' => $signup->name,
            'timezone' => $signup->timezone->getName(),
            'currency' => $signup->currency,
        ]);
        $tenant->status = TenantStatus::Provisioning;
        $tenant->category = $signup->category;
        $tenant->locale = $signup->locale;
        $tenant->entitlements = ($signup->entitlements ?? Entitlements::none())->toSlugs();
        $now = $this->clock->now();
        $tenant->setCreatedAt($now)->setUpdatedAt($now);

        if ($signup->terms !== null) {
            $tenant->terms_accepted_at = CarbonImmutable::instance($now);
            $tenant->terms_version = $signup->terms->version;
        }

        return $tenant;
    }

    private function buildSchema(Tenant $tenant, ?OutputInterface $output): void
    {
        $connection = $this->connection();
        $schemaName = $tenant->schema_name;

        $connection->statement(sprintf('create schema if not exists "%s"', $schemaName));

        SchemaSwitch::within($connection, $schemaName, function () use ($connection, $tenant, $schemaName, $output): void {
            $this->migrator->migrate($schemaName, $output);

            $connection->table('settings')->insertOrIgnore(['id' => 1]);

            $implicitId = $connection->table('staff')->where('is_implicit', true)->value('id');

            if (! is_int($implicitId)) {
                $implicitId = $connection->table('staff')->insertGetId([
                    'name' => $tenant->name,
                    'status' => 'active',
                    'is_implicit' => true,
                    'sort' => 0,
                ]);
            }

            if (! $connection->table('shift_patterns')->where('staff_id', $implicitId)->exists()) {
                WeeklyPattern::replace($connection, '', $implicitId, DefaultWorkingWeek::windows());
            }
        });
    }

    private function createOwner(Tenant $tenant, Signup $signup): void
    {
        User::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'email' => $signup->ownerEmail],
            ['name' => $signup->ownerName, 'password' => $signup->ownerPassword, 'role' => Role::Owner],
        );
    }

    private function connection(): Connection
    {
        return $this->db->connection();
    }
}
