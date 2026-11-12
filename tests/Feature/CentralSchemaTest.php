<?php

use App\Platform\PlatformAuditAction;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

pest()->use(RefreshDatabase::class);

/**
 * The column's SQL type as PostgreSQL prints it, followed by "not null" where it applies.
 */
function centralColumn(string $table, string $column): mixed
{
    return DB::scalar(
        "select format_type(a.atttypid, a.atttypmod) || case when a.attnotnull then ' not null' else '' end
         from pg_attribute a
         join pg_class c on c.oid = a.attrelid
         join pg_namespace n on n.oid = c.relnamespace
         where n.nspname = current_schema() and c.relname = ? and a.attname = ? and a.attnum > 0 and not a.attisdropped",
        [$table, $column],
    );
}

/**
 * @return array<string, mixed>
 */
function tenantRow(string $slug, string $schemaName = ''): array
{
    return [
        'slug' => $slug,
        'name' => 'Tenant '.$slug,
        'schema_name' => $schemaName !== '' ? $schemaName : 'tenant_'.$slug,
        'timezone' => 'Europe/Athens',
        'currency' => 'EUR',
    ];
}

/**
 * @return array<string, mixed>
 */
function userRow(int $tenantId, string $email): array
{
    return [
        'tenant_id' => $tenantId,
        'name' => 'Someone',
        'email' => $email,
        'password' => 'secret',
        'role' => 'owner',
    ];
}

it('types tenants.{column} as {type}', function (string $column, string $type) {
    expect(centralColumn('tenants', $column))->toBe($type);
})->with([
    ['id', 'bigint not null'],
    ['slug', 'citext'],
    ['name', 'text not null'],
    ['schema_name', 'text not null'],
    ['timezone', 'text not null'],
    ['currency', 'character(3) not null'],
    ['status', 'text not null'],
    ['entitlements', 'text[] not null'],
    ['narrowed', 'text[] not null'],
    ['plan_ref', 'text'],
    ['subscription_state', 'text not null'],
    ['grace_ends_at', 'timestamp with time zone'],
    ['closing_at', 'timestamp with time zone'],
    ['closed_at', 'timestamp with time zone'],
    ['category', 'text'],
    ['trial_ends_at', 'timestamp with time zone'],
    ['trial_three_days_sent_at', 'timestamp with time zone'],
    ['trial_ends_today_sent_at', 'timestamp with time zone'],
    ['suspended_at', 'timestamp with time zone'],
    ['suspension_reason', 'text'],
    ['locale', 'text not null'],
    ['terms_accepted_at', 'timestamp with time zone'],
    ['terms_version', 'date'],
    ['created_at', 'timestamp with time zone not null'],
    ['updated_at', 'timestamp with time zone not null'],
]);

it('types users.{column} as {type}', function (string $column, string $type) {
    expect(centralColumn('users', $column))->toBe($type);
})->with([
    ['id', 'bigint not null'],
    ['tenant_id', 'bigint not null'],
    ['name', 'text not null'],
    ['email', 'citext not null'],
    ['password', 'text not null'],
    ['role', 'text not null'],
    ['remember_token', 'character varying(100)'],
    ['email_verified_at', 'timestamp with time zone'],
    ['verification_token_hash', 'text'],
    ['password_link_hash', 'text'],
    ['password_link_expires_at', 'timestamp with time zone'],
    ['locale', 'text'],
    ['created_at', 'timestamp with time zone not null'],
    ['updated_at', 'timestamp with time zone not null'],
]);

it('defaults a new tenant to active, on the floor, with nothing narrowed', function () {
    DB::table('tenants')->insert(tenantRow('acme'));

    $row = DB::table('tenants')->where('slug', 'acme')->first();

    expect($row)->not->toBeNull()
        ->and($row?->status)->toBe('active')
        ->and($row?->entitlements)->toBe('{}')
        ->and($row?->narrowed)->toBe('{}')
        ->and($row?->subscription_state)->toBe('active')
        ->and($row?->plan_ref)->toBeNull()
        ->and($row?->grace_ends_at)->toBeNull()
        ->and($row?->closing_at)->toBeNull()
        ->and($row?->closed_at)->toBeNull()
        ->and($row?->category)->toBeNull()
        ->and($row?->trial_ends_at)->toBeNull()
        ->and($row?->trial_three_days_sent_at)->toBeNull()
        ->and($row?->trial_ends_today_sent_at)->toBeNull()
        ->and($row?->suspended_at)->toBeNull()
        ->and($row?->suspension_reason)->toBeNull()
        ->and($row?->locale)->toBe('en')
        ->and($row?->terms_accepted_at)->toBeNull()
        ->and($row?->terms_version)->toBeNull();
});

it('holds a suspension\'s instant and reason together: {case}', function (array $columns) {
    DB::table('tenants')->insert([...tenantRow('acme'), 'status' => 'suspended', ...$columns]);

    expect(DB::table('tenants')->where('slug', 'acme')->value('status'))->toBe('suspended');
})->with([
    'both set' => [fn (): array => ['suspended_at' => DB::raw('now()'), 'suspension_reason' => 'Chargebacks under review.']],
    'both null' => [['suspended_at' => null, 'suspension_reason' => null]],
]);

it('refuses a suspension\'s {case}', function (array $columns) {
    expect(fn () => DB::table('tenants')->insert([...tenantRow('acme'), ...$columns]))
        ->toThrow(QueryException::class, 'tenants_suspension_check');
})->with([
    'instant with no reason' => [fn (): array => ['suspended_at' => DB::raw('now()')]],
    'reason with no instant' => [['suspension_reason' => 'Chargebacks under review.']],
]);

it('accepts the tenant status {status}', function (string $status) {
    DB::table('tenants')->insert([...tenantRow('acme'), 'status' => $status]);

    expect(DB::table('tenants')->where('slug', 'acme')->value('status'))->toBe($status);
})->with(['provisioning', 'active', 'suspended', 'closing']);

it('accepts the tenant status closed on a row the drop wrote, a closed_at set and the slug released', function () {
    $id = DB::table('tenants')->insertGetId([...tenantRow('acme'), 'slug' => null, 'status' => 'closed', 'closed_at' => DB::raw('now()')]);

    expect(DB::table('tenants')->where('id', $id)->value('status'))->toBe('closed');
});

it('holds a closed row, and only a closed row, to a closed_at and no slug: {case}', function (array $columns) {
    expect(fn () => DB::table('tenants')->insert([...tenantRow('acme'), ...$columns]))
        ->toThrow(QueryException::class, 'tenants_closed_check');
})->with([
    'closed keeping its slug' => [fn (): array => ['status' => 'closed', 'closed_at' => DB::raw('now()')]],
    'closed with no instant' => [['status' => 'closed', 'slug' => null]],
    'active with no slug' => [['slug' => null]],
    'closing with a closed_at' => [fn (): array => ['status' => 'closing', 'closing_at' => DB::raw('now()'), 'closed_at' => DB::raw('now()')]],
]);

it('admits any number of released slugs, the unique index admitting many nulls', function () {
    DB::table('tenants')->insert([...tenantRow('acme'), 'slug' => null, 'status' => 'closed', 'closed_at' => DB::raw('now()')]);
    DB::table('tenants')->insert([...tenantRow('bravo'), 'slug' => null, 'status' => 'closed', 'closed_at' => DB::raw('now()')]);

    expect(DB::table('tenants')->whereNull('slug')->count())->toBe(2);
});

it('lets a new signup of a released slug take the schema name the closed row keeps, and no two rows not closed share one', function () {
    DB::table('tenants')->insert([...tenantRow('acme'), 'slug' => null, 'status' => 'closed', 'closed_at' => DB::raw('now()')]);
    DB::table('tenants')->insert([...tenantRow('acme'), 'status' => 'provisioning']);

    expect(DB::table('tenants')->where('schema_name', 'tenant_acme')->count())->toBe(2)
        ->and(fn () => DB::table('tenants')->insert(tenantRow('acme-two', 'tenant_acme')))->toThrow(UniqueConstraintViolationException::class);
});

it('refuses a sixth tenant status', function () {
    expect(fn () => DB::table('tenants')->insert([...tenantRow('acme'), 'status' => 'deleted']))
        ->toThrow(QueryException::class, '23514');
});

it('accepts the subscription state {state}', function (string $state) {
    DB::table('tenants')->insert([...tenantRow('acme'), 'subscription_state' => $state, 'trial_ends_at' => $state === 'trial' ? DB::raw("now() + interval '7 days'") : null]);

    expect(DB::table('tenants')->where('slug', 'acme')->value('subscription_state'))->toBe($state);
})->with(['active', 'grace', 'past_due', 'canceled', 'incomplete', 'trial']);

it('refuses a seventh subscription state', function () {
    expect(fn () => DB::table('tenants')->insert([...tenantRow('acme'), 'subscription_state' => 'trialing']))
        ->toThrow(QueryException::class, '23514');
});

it('accepts the business category {category}', function (string $category) {
    DB::table('tenants')->insert([...tenantRow('acme'), 'category' => $category]);

    expect(DB::table('tenants')->where('slug', 'acme')->value('category'))->toBe($category);
})->with(['hair_salon', 'barber', 'nails', 'beauty', 'massage', 'physiotherapy', 'other']);

it('refuses a trial with no end', function () {
    expect(fn () => DB::table('tenants')->insert([...tenantRow('acme'), 'subscription_state' => 'trial']))
        ->toThrow(QueryException::class, 'tenants_trial_ends_check');
});

it('keeps an end on a row past its trial, the implication running one way', function () {
    DB::table('tenants')->insert([...tenantRow('acme'), 'subscription_state' => 'canceled', 'trial_ends_at' => DB::raw("now() + interval '7 days'")]);

    expect(DB::table('tenants')->where('slug', 'acme')->value('subscription_state'))->toBe('canceled');
});

it('holds one owner to one verification hash', function () {
    $tenantId = DB::table('tenants')->insertGetId(tenantRow('acme'));
    DB::table('users')->insert([...userRow($tenantId, 'a@example.com'), 'verification_token_hash' => str_repeat('a', 64)]);
    DB::table('users')->insert([...userRow($tenantId, 'b@example.com'), 'verification_token_hash' => null]);
    DB::table('users')->insert([...userRow($tenantId, 'c@example.com'), 'verification_token_hash' => null]);

    expect(fn () => DB::table('users')->insert([...userRow($tenantId, 'd@example.com'), 'verification_token_hash' => str_repeat('a', 64)]))
        ->toThrow(QueryException::class, 'users_verification_token_hash_unique');
});

it('holds one set-password link to one user', function () {
    $tenantId = DB::table('tenants')->insertGetId(tenantRow('acme'));
    $link = fn (string $hash): array => ['password_link_hash' => $hash, 'password_link_expires_at' => DB::raw("now() + interval '72 hours'")];
    DB::table('users')->insert([...userRow($tenantId, 'a@example.com'), ...$link(str_repeat('a', 64))]);
    DB::table('users')->insert([...userRow($tenantId, 'b@example.com'), 'password_link_hash' => null, 'password_link_expires_at' => null]);
    DB::table('users')->insert([...userRow($tenantId, 'c@example.com'), 'password_link_hash' => null, 'password_link_expires_at' => null]);

    expect(fn () => DB::table('users')->insert([...userRow($tenantId, 'd@example.com'), ...$link(str_repeat('a', 64))]))
        ->toThrow(QueryException::class, 'users_password_link_hash_unique');
});

it('holds a set-password link\'s hash and expiry together: {case}', function (array $columns) {
    $tenantId = DB::table('tenants')->insertGetId(tenantRow('acme'));

    DB::table('users')->insert([...userRow($tenantId, 'a@example.com'), ...$columns]);

    expect(DB::table('users')->where('tenant_id', $tenantId)->count())->toBe(1);
})->with([
    'both set' => [fn (): array => ['password_link_hash' => str_repeat('a', 64), 'password_link_expires_at' => DB::raw("now() + interval '72 hours'")]],
    'both null' => [['password_link_hash' => null, 'password_link_expires_at' => null]],
]);

it('refuses a set-password link\'s {case}', function (array $columns) {
    $tenantId = DB::table('tenants')->insertGetId(tenantRow('acme'));

    expect(fn () => DB::table('users')->insert([...userRow($tenantId, 'a@example.com'), ...$columns]))
        ->toThrow(QueryException::class, 'users_password_link_check');
})->with([
    'hash with no expiry' => [['password_link_hash' => str_repeat('a', 64)]],
    'expiry with no hash' => [fn (): array => ['password_link_expires_at' => DB::raw("now() + interval '72 hours'")]],
]);

it('refuses a category outside the seven', function () {
    expect(fn () => DB::table('tenants')->insert([...tenantRow('acme'), 'category' => 'spa']))
        ->toThrow(QueryException::class, '23514');
});

it('holds a tenant\'s language to the site\'s two: {locale}', function (string $locale) {
    DB::table('tenants')->insert([...tenantRow('acme'), 'locale' => $locale]);

    expect(DB::table('tenants')->where('slug', 'acme')->value('locale'))->toBe($locale);
})->with(['en', 'el']);

it('refuses a tenant\'s language outside the site\'s two', function () {
    expect(fn () => DB::table('tenants')->insert([...tenantRow('acme'), 'locale' => 'de']))
        ->toThrow(QueryException::class, 'tenants_locale_check');
});

it('holds a user\'s language to the two: {locale}', function (?string $locale) {
    $tenantId = DB::table('tenants')->insertGetId(tenantRow('acme'));
    DB::table('users')->insert([...userRow($tenantId, 'a@example.com'), 'locale' => $locale]);

    expect(DB::table('users')->where('tenant_id', $tenantId)->value('locale'))->toBe($locale);
})->with(['English' => ['en'], 'Greek' => ['el'], 'no choice' => [null]]);

it('leaves a new user\'s language null, so it reads as the tenant default, nothing copied', function () {
    $tenantId = DB::table('tenants')->insertGetId([...tenantRow('acme'), 'locale' => 'el']);
    DB::table('users')->insert(userRow($tenantId, 'a@example.com'));

    expect(DB::table('users')->where('tenant_id', $tenantId)->value('locale'))->toBeNull();
});

it('refuses a user\'s language outside the two', function () {
    $tenantId = DB::table('tenants')->insertGetId(tenantRow('acme'));

    expect(fn () => DB::table('users')->insert([...userRow($tenantId, 'a@example.com'), 'locale' => 'de']))
        ->toThrow(QueryException::class, 'users_locale_check');
});

it('accepts the user role {role}', function (string $role) {
    $tenantId = DB::table('tenants')->insertGetId(tenantRow('acme'));

    DB::table('users')->insert([...userRow($tenantId, 'a@example.com'), 'role' => $role]);

    expect(DB::table('users')->where('tenant_id', $tenantId)->value('role'))->toBe($role);
})->with(['owner', 'manager', 'staff']);

it('refuses a fourth user role', function () {
    $tenantId = DB::table('tenants')->insertGetId(tenantRow('acme'));

    expect(fn () => DB::table('users')->insert([...userRow($tenantId, 'a@example.com'), 'role' => 'admin']))
        ->toThrow(QueryException::class, '23514');
});

it('refuses a second slug that differs only in case', function () {
    DB::table('tenants')->insert(tenantRow('acme'));

    expect(fn () => DB::table('tenants')->insert(tenantRow('ACME', 'tenant_acme_2')))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('refuses a second tenant on the same schema name', function () {
    DB::table('tenants')->insert(tenantRow('acme'));

    expect(fn () => DB::table('tenants')->insert(tenantRow('acme-two', 'tenant_acme')))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows the same email under two tenants', function () {
    $first = DB::table('tenants')->insertGetId(tenantRow('first'));
    $second = DB::table('tenants')->insertGetId(tenantRow('second'));

    DB::table('users')->insert(userRow($first, 'owner@example.com'));
    DB::table('users')->insert(userRow($second, 'owner@example.com'));

    expect(DB::table('users')->where('email', 'owner@example.com')->count())->toBe(2);
});

it('refuses the same email twice under one tenant, whatever its case', function () {
    $tenantId = DB::table('tenants')->insertGetId(tenantRow('acme'));

    DB::table('users')->insert(userRow($tenantId, 'owner@example.com'));

    expect(fn () => DB::table('users')->insert(userRow($tenantId, 'Owner@Example.com')))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('refuses a user whose tenant does not exist', function () {
    expect(fn () => DB::table('users')->insert(userRow(424242, 'owner@example.com')))
        ->toThrow(QueryException::class, '23503');
});

it('types operators.{column} as {type}', function (string $column, string $type) {
    expect(centralColumn('operators', $column))->toBe($type);
})->with([
    ['id', 'bigint not null'],
    ['name', 'text not null'],
    ['email', 'citext not null'],
    ['password', 'text not null'],
    ['created_at', 'timestamp with time zone not null'],
    ['updated_at', 'timestamp with time zone not null'],
]);

it('keeps an operator to the platform: no tenant, no role and no remember token', function () {
    $columns = DB::table('information_schema.columns')
        ->where('table_schema', DB::raw('current_schema()'))
        ->where('table_name', 'operators')
        ->orderBy('ordinal_position')
        ->pluck('column_name')
        ->all();

    expect($columns)->toBe(['id', 'name', 'email', 'password', 'created_at', 'updated_at']);
});

it('refuses a second operator on the same email, whatever its case', function () {
    DB::table('operators')->insert(['name' => 'Pat Operator', 'email' => 'pat@inbox.example', 'password' => 'secret']);

    expect(fn () => DB::table('operators')->insert(['name' => 'Twin', 'email' => 'Pat@Inbox.example', 'password' => 'secret']))
        ->toThrow(UniqueConstraintViolationException::class);
});

/**
 * An operator row to hang a platform audit row on, by id.
 */
function auditOperatorId(): int
{
    return DB::table('operators')->insertGetId(['name' => 'Pat Operator', 'email' => 'pat@inbox.example', 'password' => 'secret']);
}

it('types platform_audit_log.{column} as {type}', function (string $column, string $type) {
    expect(centralColumn('platform_audit_log', $column))->toBe($type);
})->with([
    ['id', 'bigint not null'],
    ['operator_id', 'bigint not null'],
    ['action', 'text not null'],
    ['tenant_id', 'bigint'],
    ['payload', 'jsonb not null'],
    ['created_at', 'timestamp with time zone not null'],
]);

it('keeps a platform audit row as written: no updated_at, since a row is never rewritten', function () {
    $columns = DB::table('information_schema.columns')
        ->where('table_schema', DB::raw('current_schema()'))
        ->where('table_name', 'platform_audit_log')
        ->orderBy('ordinal_position')
        ->pluck('column_name')
        ->all();

    expect($columns)->toBe(['id', 'operator_id', 'action', 'tenant_id', 'payload', 'created_at']);
});

it('defaults a platform audit row to an empty payload, no tenant and the instant it was written', function () {
    $id = DB::table('platform_audit_log')->insertGetId(['operator_id' => auditOperatorId(), 'action' => 'sign_in']);

    $row = DB::table('platform_audit_log')->selectRaw('payload::text as payload, tenant_id, created_at = now() as stamped')->where('id', $id)->first();

    expect($row)->not->toBeNull()
        ->and($row?->payload)->toBe('{}')
        ->and($row?->tenant_id)->toBeNull()
        ->and($row?->stamped)->toBeTrue();
});

it('accepts the platform audit action {action}, each case of the vocabulary', function (string $action) {
    DB::table('platform_audit_log')->insert(['operator_id' => auditOperatorId(), 'action' => $action]);

    expect(DB::table('platform_audit_log')->value('action'))->toBe($action);
})->with(fn (): array => array_map(fn (PlatformAuditAction $action): string => $action->value, PlatformAuditAction::cases()));

it('spells in the action CHECK the vocabulary\'s cases and nothing else, a literal the enum lacks being a row the Audit screen could not read (04b)', function () {
    $definition = DB::scalar("select pg_get_constraintdef(oid) from pg_constraint where conname = 'platform_audit_log_action_check' and conrelid = 'platform_audit_log'::regclass");
    assert(is_string($definition));
    preg_match_all("/'([^']*)'/", $definition, $literals);
    $vocabulary = array_map(fn (PlatformAuditAction $action): string => $action->value, PlatformAuditAction::cases());

    sort($literals[1]);
    sort($vocabulary);

    expect($literals[1])->toBe($vocabulary)
        ->and($vocabulary)->toHaveCount(12);
});

it('refuses an action outside the vocabulary\'s twelve', function () {
    expect(fn () => DB::table('platform_audit_log')->insert(['operator_id' => auditOperatorId(), 'action' => 'tenant_deleted']))
        ->toThrow(QueryException::class, 'platform_audit_log_action_check');
});

it('refuses a platform audit row naming {case} that does not exist', function (string $case) {
    $operatorId = auditOperatorId();
    $row = $case === 'an operator'
        ? ['operator_id' => $operatorId + 1, 'action' => 'sign_in']
        : ['operator_id' => $operatorId, 'action' => 'tenant_suspended', 'tenant_id' => 424242];

    expect(fn () => DB::table('platform_audit_log')->insert($row))->toThrow(QueryException::class, '23503');
})->with(['an operator', 'a tenant']);

it('keeps a tenant\'s row while its platform audit rows name it, nothing cascading', function () {
    $tenantId = DB::table('tenants')->insertGetId(tenantRow('acme'));
    DB::table('platform_audit_log')->insert(['operator_id' => auditOperatorId(), 'action' => 'tenant_suspended', 'tenant_id' => $tenantId]);

    expect(fn () => DB::table('tenants')->where('id', $tenantId)->delete())->toThrow(QueryException::class, '23503');
});

it('indexes the platform audit log by tenant and instant for the narrowed list, and by instant for the screen', function () {
    $definitions = DB::table('pg_indexes')
        ->where('schemaname', DB::raw('current_schema()'))
        ->where('tablename', 'platform_audit_log')
        ->where('indexname', '!=', 'platform_audit_log_pkey')
        ->orderBy('indexname')
        ->pluck('indexdef')
        ->all();

    expect($definitions)->toBe([
        'CREATE INDEX platform_audit_log_created ON public.platform_audit_log USING btree (created_at)',
        'CREATE INDEX platform_audit_log_tenant ON public.platform_audit_log USING btree (tenant_id, created_at)',
    ]);
});

/**
 * A support_sessions row's columns for the operator and tenant given, unopened, its link's hash
 * set and its expiry an hour on, with whatever the case overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function supportSessionRow(int $operatorId, int $tenantId, array $overrides = []): array
{
    return [
        'operator_id' => $operatorId,
        'tenant_id' => $tenantId,
        'token_hash' => str_repeat('a', 64),
        'expires_at' => DB::raw("now() + interval '60 minutes'"),
        ...$overrides,
    ];
}

it('types support_sessions.{column} as {type}', function (string $column, string $type) {
    expect(centralColumn('support_sessions', $column))->toBe($type);
})->with([
    ['id', 'bigint not null'],
    ['operator_id', 'bigint not null'],
    ['tenant_id', 'bigint not null'],
    ['token_hash', 'text'],
    ['opened_at', 'timestamp with time zone'],
    ['expires_at', 'timestamp with time zone not null'],
    ['ended_at', 'timestamp with time zone'],
    ['created_at', 'timestamp with time zone not null'],
]);

it('keeps a support session to references and instants, no name and no address', function () {
    $columns = DB::table('information_schema.columns')
        ->where('table_schema', DB::raw('current_schema()'))
        ->where('table_name', 'support_sessions')
        ->orderBy('ordinal_position')
        ->pluck('column_name')
        ->all();

    expect($columns)->toBe(['id', 'operator_id', 'tenant_id', 'token_hash', 'opened_at', 'expires_at', 'ended_at', 'created_at']);
});

it('defaults a support session to unopened and unended, stamped the instant it was written', function () {
    $id = DB::table('support_sessions')->insertGetId(supportSessionRow(auditOperatorId(), DB::table('tenants')->insertGetId(tenantRow('acme'))));

    $row = DB::table('support_sessions')->selectRaw('opened_at, ended_at, created_at = now() as stamped')->where('id', $id)->first();

    expect($row?->opened_at)->toBeNull()
        ->and($row?->ended_at)->toBeNull()
        ->and($row?->stamped)->toBeTrue();
});

it('holds one support link to one session, nulls apart', function () {
    $operatorId = auditOperatorId();
    $tenantId = DB::table('tenants')->insertGetId(tenantRow('acme'));
    $opened = ['token_hash' => null, 'opened_at' => DB::raw('now()')];
    DB::table('support_sessions')->insert(supportSessionRow($operatorId, $tenantId));
    DB::table('support_sessions')->insert(supportSessionRow($operatorId, $tenantId, $opened));
    DB::table('support_sessions')->insert(supportSessionRow($operatorId, $tenantId, $opened));

    expect(fn () => DB::table('support_sessions')->insert(supportSessionRow($operatorId, $tenantId)))
        ->toThrow(QueryException::class, 'support_sessions_token_hash_unique');
});

it('holds a support link\'s hash to an unopened session, the open clearing it: {case}', function (array $columns) {
    DB::table('support_sessions')->insert([...supportSessionRow(auditOperatorId(), DB::table('tenants')->insertGetId(tenantRow('acme'))), ...$columns]);

    expect(DB::table('support_sessions')->count())->toBe(1);
})->with([
    'unopened with its hash' => [[]],
    'opened with the hash cleared' => [fn (): array => ['token_hash' => null, 'opened_at' => DB::raw('now()')]],
    'ended with neither' => [fn (): array => ['token_hash' => null, 'opened_at' => DB::raw('now()'), 'ended_at' => DB::raw('now()')]],
]);

it('refuses a support session opened while its hash is still set', function () {
    expect(fn () => DB::table('support_sessions')->insert(supportSessionRow(auditOperatorId(), DB::table('tenants')->insertGetId(tenantRow('acme')), ['opened_at' => DB::raw('now()')])))
        ->toThrow(QueryException::class, 'support_sessions_token_check');
});

it('refuses a support session naming {case} that does not exist', function (string $case) {
    $operatorId = auditOperatorId();
    $tenantId = DB::table('tenants')->insertGetId(tenantRow('acme'));
    $row = $case === 'an operator' ? supportSessionRow($operatorId + 1, $tenantId) : supportSessionRow($operatorId, $tenantId + 1);

    expect(fn () => DB::table('support_sessions')->insert($row))->toThrow(QueryException::class, '23503');
})->with(['an operator', 'a tenant']);

it('refuses a support session with no expiry', function () {
    $row = supportSessionRow(auditOperatorId(), DB::table('tenants')->insertGetId(tenantRow('acme')));
    unset($row['expires_at']);

    expect(fn () => DB::table('support_sessions')->insert($row))->toThrow(QueryException::class, '23502');
});

/**
 * A contact message as the form writes it, with the columns given in place of its own.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function contactMessageRow(array $overrides = []): array
{
    return [
        'name' => 'Perpetua Vandersnoot',
        'email' => 'perpetua.vandersnoot@example.test',
        'message' => 'Do you take bookings for a mobile salon?',
        'locale' => 'en',
        'created_at' => DB::raw('now()'),
        ...$overrides,
    ];
}

it('types contact_messages.{column} as {type}', function (string $column, string $type) {
    expect(centralColumn('contact_messages', $column))->toBe($type);
})->with([
    ['id', 'bigint not null'],
    ['name', 'text not null'],
    ['email', 'text not null'],
    ['message', 'text not null'],
    ['locale', 'text not null'],
    ['created_at', 'timestamp with time zone not null'],
    ['archived_at', 'timestamp with time zone'],
]);

it('keeps a contact message to the person\'s name, address and message, its language and its two instants, and no address of the sender\'s machine', function () {
    $columns = DB::table('information_schema.columns')
        ->where('table_schema', DB::raw('current_schema()'))
        ->where('table_name', 'contact_messages')
        ->orderBy('ordinal_position')
        ->pluck('column_name')
        ->all();

    expect($columns)->toBe(['id', 'name', 'email', 'message', 'locale', 'created_at', 'archived_at']);
});

it('holds a contact message\'s language to the site\'s two, unarchived until the console archives it', function () {
    DB::table('contact_messages')->insert(contactMessageRow());
    DB::table('contact_messages')->insert(contactMessageRow(['locale' => 'el']));

    expect(DB::table('contact_messages')->orderBy('id')->pluck('locale')->all())->toBe(['en', 'el'])
        ->and(DB::table('contact_messages')->whereNotNull('archived_at')->count())->toBe(0)
        ->and(fn () => DB::table('contact_messages')->insert(contactMessageRow(['locale' => 'de'])))
        ->toThrow(QueryException::class, 'contact_messages_locale_check');
});

it('refuses a contact message with no {column}', function (string $column) {
    $row = contactMessageRow();
    unset($row[$column]);

    expect(fn () => DB::table('contact_messages')->insert($row))->toThrow(QueryException::class, '23502');
})->with(['name', 'email', 'message', 'locale', 'created_at']);

it('types subscriptions.{column} as {type}', function (string $column, string $type) {
    expect(centralColumn('subscriptions', $column))->toBe($type);
})->with([
    ['id', 'bigint not null'],
    ['tenant_id', 'bigint not null'],
    ['provider_customer_id', 'text not null'],
    ['provider_subscription_id', 'text'],
    ['status', 'text'],
    ['current_period_end', 'timestamp with time zone'],
    ['items', 'jsonb not null'],
    ['revision', 'bigint not null'],
    ['created_at', 'timestamp with time zone not null'],
    ['updated_at', 'timestamp with time zone not null'],
]);

it('types webhook_events.{column} as {type}', function (string $column, string $type) {
    expect(centralColumn('webhook_events', $column))->toBe($type);
})->with([
    ['id', 'bigint not null'],
    ['provider_event_id', 'text not null'],
    ['type', 'text not null'],
    ['payload', 'jsonb not null'],
    ['processed_at', 'timestamp with time zone'],
    ['created_at', 'timestamp with time zone not null'],
    ['updated_at', 'timestamp with time zone not null'],
]);

it('types payment_routes.{column} as {type}, holding no credential', function (string $column, string $type) {
    expect(centralColumn('payment_routes', $column))->toBe($type);
})->with([
    ['id', 'bigint not null'],
    ['tenant_id', 'bigint not null'],
    ['provider', 'text not null'],
    ['webhook_token', 'text'],
    ['account_ref', 'text'],
    ['created_at', 'timestamp with time zone not null'],
    ['updated_at', 'timestamp with time zone not null'],
]);

it('routes one token and one account per provider, each row naming exactly one of the two', function (array $row) {
    $tenant = DB::table('tenants')->insertGetId(tenantRow('acme'));
    DB::table('payment_routes')->insert(['tenant_id' => $tenant, 'provider' => 'paydesk', 'webhook_token' => 'tok_1']);
    DB::table('payment_routes')->insert(['tenant_id' => $tenant, 'provider' => 'paydesk', 'account_ref' => 'acct_1']);

    expect(fn () => DB::table('payment_routes')->insert(['tenant_id' => $tenant, 'provider' => 'paydesk', ...$row]))
        ->toThrow(QueryException::class);
})->with([
    'the same token' => [['webhook_token' => 'tok_1']],
    'the same account' => [['account_ref' => 'acct_1']],
    'neither' => [[]],
    'both' => [['webhook_token' => 'tok_2', 'account_ref' => 'acct_2']],
]);

it('types payment_events.{column} as {type}, holding no payload', function (string $column, string $type) {
    expect(centralColumn('payment_events', $column))->toBe($type);
})->with([
    ['id', 'bigint not null'],
    ['provider', 'text not null'],
    ['route', 'text not null'],
    ['tenant_id', 'bigint'],
    ['event_id', 'text'],
    ['type', 'text'],
    ['refusal', 'text'],
    ['processed_at', 'timestamp with time zone'],
    ['created_at', 'timestamp with time zone not null'],
    ['updated_at', 'timestamp with time zone not null'],
]);

it('records a payment event once per provider, and a row either verified with its id and type or refused with neither', function (array $row) {
    // The table is central, and a file that truncates rather than rolls back may have left a row
    // there, evt_1 among them, before this one ran; the case starts from its own rows alone, and
    // the delete rolls back with them.
    DB::table('payment_events')->delete();
    DB::table('payment_events')->insert(['provider' => 'paydesk', 'route' => 'own', 'event_id' => 'evt_1', 'type' => 'payment.settled']);
    DB::table('payment_events')->insert(['provider' => 'stripe', 'route' => 'own', 'event_id' => 'evt_1', 'type' => 'payment.settled']);
    DB::table('payment_events')->insert(['provider' => 'paydesk', 'route' => 'own', 'refusal' => 'signature']);

    expect(fn () => DB::table('payment_events')->insert(['provider' => 'paydesk', 'route' => 'own', ...$row]))
        ->toThrow(QueryException::class);
})->with([
    'the same event' => [['event_id' => 'evt_1', 'type' => 'payment.settled']],
    'refused with an event id' => [['event_id' => 'evt_2', 'type' => 'payment.settled', 'refusal' => 'signature']],
    'neither verified nor refused' => [[]],
    'an id with no type' => [['event_id' => 'evt_3']],
    'a refusal it does not know' => [['refusal' => 'tired']],
    'a refused row processed' => [['refusal' => 'signature', 'processed_at' => 'now']],
    'a route it does not know' => [['route' => 'tenant', 'event_id' => 'evt_4', 'type' => 'x']],
]);

it('records an event id once', function () {
    DB::table('webhook_events')->insert(['provider_event_id' => 'evt_1', 'type' => 'fake.other', 'payload' => '{}']);

    expect(fn () => DB::table('webhook_events')->insert(['provider_event_id' => 'evt_1', 'type' => 'fake.other', 'payload' => '{}']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('keeps one subscriptions row per tenant, each customer and each subscription naming one tenant', function (string $tenant, array $row) {
    $first = DB::table('tenants')->insertGetId(tenantRow('acme'));
    $other = DB::table('tenants')->insertGetId(tenantRow('bold'));
    DB::table('subscriptions')->insert(['tenant_id' => $first, 'provider_customer_id' => 'cus_1', 'provider_subscription_id' => 'sub_1']);

    expect(fn () => DB::table('subscriptions')->insert(['tenant_id' => $tenant === 'same' ? $first : $other, ...$row]))
        ->toThrow(UniqueConstraintViolationException::class);
})->with([
    'a second row for the tenant' => ['same', ['provider_customer_id' => 'cus_2']],
    'a second tenant for the customer' => ['other', ['provider_customer_id' => 'cus_1']],
    'a second tenant for the subscription' => ['other', ['provider_customer_id' => 'cus_2', 'provider_subscription_id' => 'sub_1']],
]);

it('lets a customer wait for its subscription, which no snapshot has named yet', function () {
    $tenantId = DB::table('tenants')->insertGetId(tenantRow('acme'));
    DB::table('subscriptions')->insert(['tenant_id' => $tenantId, 'provider_customer_id' => 'cus_1']);
    DB::table('subscriptions')->insert(['tenant_id' => DB::table('tenants')->insertGetId(tenantRow('bold')), 'provider_customer_id' => 'cus_2']);

    expect(DB::table('subscriptions')->whereIn('provider_customer_id', ['cus_1', 'cus_2'])->whereNull('provider_subscription_id')->count())->toBe(2)
        ->and(DB::table('subscriptions')->where('tenant_id', $tenantId)->value('items'))->toBe('[]');
});
