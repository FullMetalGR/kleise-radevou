<?php

use App\Application\Clock;
use App\Application\Notification\PlatformMessenger;
use App\Http\ConsoleSession;
use App\Http\Middleware\RequiresOperator;
use App\Platform\PlatformAudit;
use App\Platform\PlatformAuditAction;
use App\Platform\PlatformAuditEntry;
use App\Tenancy\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\FixedClock;
use Tests\Support\RecordingPlatformMessages;

pest()->use(RefreshDatabase::class);

/*
 * The platform audit log: one writer, PlatformAudit, inserting one row on the connection it is
 * handed, so an action's transaction carries its row; the door's sign-in writes sign_in once the
 * session is regenerated and the sign-out writes sign_out before the session ends, each naming
 * the operator by id and no tenant; a refused sign-in writes nothing. Beside them, the net every
 * test of the three suites runs, platformAuditNet, shown failing on what it exists to catch.
 */

beforeEach(function () {
    $this->withoutVite();
    app()->instance(Clock::class, FixedClock::at('2026-10-14 09:00'));
});

/**
 * Every platform audit row in the order written: its action, operator, tenant and payload.
 *
 * @return list<array{action: string, operator_id: int, tenant_id: int|null, payload: string}>
 */
function platformAuditRows(): array
{
    $rows = [];

    foreach (DB::select('select action, operator_id, tenant_id, payload::text as payload from platform_audit_log order by id') as $row) {
        assert($row instanceof stdClass && is_string($row->action) && is_int($row->operator_id) && ($row->tenant_id === null || is_int($row->tenant_id)) && is_string($row->payload));

        $rows[] = ['action' => $row->action, 'operator_id' => $row->operator_id, 'tenant_id' => $row->tenant_id, 'payload' => $row->payload];
    }

    return $rows;
}

/**
 * Who held the console session, and under which id, when each platform audit row was written:
 * read by a listener on the insert itself, so the order of the write against the session's
 * regeneration or its end is observed rather than inferred.
 *
 * @return ArrayObject<int, array{session: string, operator: int|string|null}>
 */
function platformAuditWrites(): ArrayObject
{
    /** @var ArrayObject<int, array{session: string, operator: int|string|null}> $writes */
    $writes = new ArrayObject;

    DB::listen(function (QueryExecuted $query) use ($writes): void {
        if (str_starts_with($query->sql, 'insert into platform_audit_log')) {
            $writes->append(['session' => session()->getId(), 'operator' => Auth::guard(ConsoleSession::GUARD)->id()]);
        }
    });

    return $writes;
}

it('writes one row on the connection it is handed, inside the transaction that connection runs', function () {
    $operator = addOperator();
    $tenant = Tenant::factory()->create(['slug' => 'acme']);
    $entry = new PlatformAuditEntry(PlatformAuditAction::TenantSuspended, ['reason' => 'Unpaid since March']);

    try {
        DB::transaction(function (Connection $db) use ($operator, $entry, $tenant): void {
            PlatformAudit::record($db, $operator->id, $entry, $tenant->id);

            throw new RuntimeException('The action failed after its row was written.');
        });
    } catch (RuntimeException) {
    }

    expect(platformAuditRows())->toBe([]);

    DB::transaction(fn (Connection $db) => PlatformAudit::record($db, $operator->id, $entry, $tenant->id));
    DB::transaction(fn (Connection $db) => PlatformAudit::record($db, $operator->id, new PlatformAuditEntry(PlatformAuditAction::FeaturesChanged, ['features' => ['staff']]), $tenant->id));
    PlatformAudit::record(DB::connection(), $operator->id, new PlatformAuditEntry(PlatformAuditAction::SignIn), null);

    expect(platformAuditRows())->toBe([
        ['action' => 'tenant_suspended', 'operator_id' => $operator->id, 'tenant_id' => $tenant->id, 'payload' => '{"reason": "Unpaid since March"}'],
        ['action' => 'features_changed', 'operator_id' => $operator->id, 'tenant_id' => $tenant->id, 'payload' => '{"features": ["staff"]}'],
        ['action' => 'sign_in', 'operator_id' => $operator->id, 'tenant_id' => null, 'payload' => '{}'],
    ]);
});

it('records a sign-in at the door as sign_in, naming the operator by id and no tenant, once the session is regenerated', function () {
    $operator = addOperator();
    $writes = platformAuditWrites();

    $this->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'operator password'])
        ->assertRedirect(consoleUrl('/'));

    expect(platformAuditRows())->toBe([['action' => 'sign_in', 'operator_id' => $operator->id, 'tenant_id' => null, 'payload' => '{}']])
        ->and($writes->getArrayCopy())->toBe([['session' => session()->getId(), 'operator' => $operator->id]]);
});

it('leaves a session no request can use where the sign_in row cannot be written: no instant and no fingerprint in it, no notice mailed, and the next request on it refused as run out (04c)', function () {
    $seam = new RecordingPlatformMessages;
    app()->instance(PlatformMessenger::class, $seam);
    addOperator();
    DB::unprepared(<<<'SQL'
        create function platform_audit_refuse_insert() returns trigger language plpgsql as $$ begin raise exception 'the platform audit insert is refused'; end $$;
        create trigger platform_audit_refuse_insert before insert on platform_audit_log for each row execute function platform_audit_refuse_insert();
        SQL);

    // The insert runs in no transaction of its own, so its failure aborts the one the test runs
    // in; a savepoint taken before the request is rolled back to after it, as the next request's
    // own connection would start clean.
    DB::statement('savepoint before_the_sign_in');
    $this->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'operator password'])->assertStatus(500);
    DB::statement('rollback to savepoint before_the_sign_in');

    expect(session()->has(ConsoleSession::SIGNED_IN_AT))->toBeFalse()
        ->and(session()->has(ConsoleSession::LAST_SEEN_AT))->toBeFalse()
        ->and(session()->has(ConsoleSession::PASSWORD))->toBeFalse()
        ->and($seam->sent)->toBe([])
        ->and(platformAuditRows())->toBe([]);

    $this->get(consoleUrl('/'))
        ->assertRedirect(consoleUrl('/login'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => RequiresOperator::IDLE]);
    $this->assertGuest(ConsoleSession::GUARD);
});

it('records a sign-out as sign_out, naming the operator by id and no tenant, before the session ends', function () {
    $operator = addOperator();
    $writes = platformAuditWrites();

    $this->actingAs($operator, ConsoleSession::GUARD)
        ->withSession(freshConsoleSession())
        ->post(consoleUrl('/logout'))
        ->assertRedirect(consoleUrl('/login'));

    $this->assertGuest(ConsoleSession::GUARD);
    $written = $writes->getArrayCopy();

    expect(platformAuditRows())->toBe([['action' => 'sign_out', 'operator_id' => $operator->id, 'tenant_id' => null, 'payload' => '{}']])
        ->and($written)->toHaveCount(1)
        ->and($written[0]['operator'] ?? null)->toBe($operator->id)
        ->and($written[0]['session'] ?? null)->not->toBe(session()->getId());
});

it('records the two of a session in order, the sign-in and then the sign-out', function () {
    $operator = addOperator();

    $this->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'operator password'])->assertRedirect(consoleUrl('/'));
    $this->post(consoleUrl('/logout'))->assertRedirect(consoleUrl('/login'));

    expect(array_column(platformAuditRows(), 'action'))->toBe(['sign_in', 'sign_out'])
        ->and(array_unique(array_column(platformAuditRows(), 'operator_id')))->toBe([$operator->id]);
});

it('records nothing for a refused sign-in: {case}', function (array $form) {
    addOperator();
    $tenant = Tenant::factory()->create(['slug' => 'acme']);
    DB::table('users')->insert(['tenant_id' => $tenant->id, 'name' => 'Alex Owner', 'email' => 'owner@acme.example', 'password' => bcrypt('owner password'), 'role' => 'owner']);

    $this->post(consoleUrl('/login'), $form)->assertSessionHasErrors('email');

    expect(platformAuditRows())->toBe([]);
})->with([
    'a wrong password' => [['email' => 'pat@inbox.example', 'password' => 'not the password']],
    'an email no operator holds' => [['email' => 'nobody@inbox.example', 'password' => 'operator password']],
    'a tenant user\'s own pair' => [['email' => 'owner@acme.example', 'password' => 'owner password']],
    'an empty form' => [[]],
]);

it('records nothing past the door\'s limit, the right pair included', function () {
    addOperator();

    foreach (range(1, 10) as $attempt) {
        $this->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'not the password'])->assertRedirect();
    }

    $this->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'operator password'])->assertStatus(429);

    expect(platformAuditRows())->toBe([]);
});

it('records nothing for a sign-out nobody signed in posts, there being no operator to name', function () {
    $this->post(consoleUrl('/logout'))->assertRedirect(consoleUrl('/login'));

    expect(platformAuditRows())->toBe([]);
});

it('records no sign-out for a session the idle timeout ends, which the operator did not do', function () {
    $operator = addOperator();

    $this->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'operator password'])->assertRedirect(consoleUrl('/'));
    app()->instance(Clock::class, FixedClock::at('2026-10-14 09:31'));
    $this->get(consoleUrl('/'))->assertRedirect(consoleUrl('/login'));

    $this->assertGuest(ConsoleSession::GUARD);

    expect(platformAuditRows())->toBe([['action' => 'sign_in', 'operator_id' => $operator->id, 'tenant_id' => null, 'payload' => '{}']]);
});

it('passes the net over the rows a session writes', function () {
    addOperator();

    $this->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'operator password'])->assertRedirect(consoleUrl('/'));
    $this->post(consoleUrl('/logout'))->assertRedirect(consoleUrl('/login'));

    expect(platformAuditRows())->toHaveCount(2)
        ->and(platformAuditOffences())->toBe([]);
});

it('fails the net on a payload key outside the declared list, and on an operator\'s, a user\'s or a contact message\'s sender\'s email in an action or a payload', function () {
    $operator = addOperator();
    $tenant = Tenant::factory()->create(['slug' => 'acme']);
    DB::table('users')->insert(['tenant_id' => $tenant->id, 'name' => 'Alex Owner', 'email' => 'owner@acme.example', 'password' => bcrypt('owner password'), 'role' => 'owner']);
    $message = DB::table('contact_messages')->insertGetId(['name' => 'Maria Papadopoulou', 'email' => 'maria@salon.example', 'message' => 'Two chairs?', 'locale' => 'en', 'created_at' => '2026-10-14 06:00:00+00:00']);

    // Written past the writer, which would refuse the first, to prove the net reads what reached
    // the table whoever wrote it.
    DB::table('platform_audit_log')->insert([
        ['operator_id' => $operator->id, 'action' => 'sign_in', 'tenant_id' => null, 'payload' => '{"email": "x"}'],
        ['operator_id' => $operator->id, 'action' => 'tenant_suspended', 'tenant_id' => $tenant->id, 'payload' => '{"reason": "Asked by OWNER@acme.example"}'],
        ['operator_id' => $operator->id, 'action' => 'sign_out', 'tenant_id' => null, 'payload' => '{"user": "pat@inbox.example"}'],
        ['operator_id' => $operator->id, 'action' => 'contact_archived', 'tenant_id' => null, 'payload' => '{"message": "'.$message.' from maria@salon.example"}'],
    ]);

    $offences = platformAuditOffences();

    // Removed before the net over every test of this suite reads them, since they were written to
    // prove the net catches them and not to be left lying there.
    DB::table('platform_audit_log')->delete();

    expect($offences)->toHaveCount(4)
        ->and($offences[0] ?? '')->toContain('action sign_in: the payload key "email" is outside the declared list')
        ->and($offences[1] ?? '')->toContain('action tenant_suspended: the row names an operator, a user or a contact message\'s sender, "owner@acme.example"')
        ->and($offences[2] ?? '')->toContain('action sign_out: the row names an operator, a user or a contact message\'s sender, "pat@inbox.example"')
        ->and($offences[3] ?? '')->toContain('action contact_archived: the row names an operator, a user or a contact message\'s sender, "maria@salon.example"')
        ->and(platformAuditOffences())->toBe([]);
});

it('reads nothing where the table is gone or the test\'s transaction was aborted by a constraint it tripped, there being nothing to read: {case}', function (Closure $arrange) {
    $arrange();

    expect(platformAuditOffences())->toBe([]);
})->with([
    'the table dropped' => [fn () => DB::statement('drop table platform_audit_log')],
    'the transaction aborted' => [function (): void {
        try {
            DB::table('platform_audit_log')->insert(['operator_id' => 424242, 'action' => 'sign_in']);
        } catch (QueryException) {
            // The foreign key refuses the row, and every statement after it fails with 25P02.
        }
    }],
]);

it('throws a read that fails any other way, rather than passing the net over rows it never read (04d)', function () {
    // A savepoint before the read, rolled back to after it, so the failed read leaves the test's
    // transaction usable for putting the column back before the net over every test reads it.
    DB::statement('savepoint before_the_read');
    DB::statement('alter table platform_audit_log rename column operator_id to actor_id');

    try {
        expect(fn () => platformAuditOffences())->toThrow(QueryException::class, '42703');
    } finally {
        DB::statement('rollback to savepoint before_the_read');
    }
});

it('costs one query on a test that wrote no row', function () {
    addOperator();
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    platformAuditNet();

    expect($queries)->toBe(1);
});
