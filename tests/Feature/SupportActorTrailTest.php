<?php

use App\Domain\Audit\Actor;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Enum\BookingStatus;
use App\Http\BookingTrail;
use App\Infrastructure\Db\AuditRow;
use App\Models\User;
use App\Tenancy\SearchPath;
use App\Tenancy\Tenant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

pest()->use(RefreshDatabase::class);

/*
 * The fifth actor shape on the trail: a change made in a support session is recorded as
 * support:{operator id}, written through the one writer of the trail, and read back by the
 * booking dialog's trail as kind support with the operator's name, resolved from the central
 * operators table as a user's is from users. The row keeps the id alone, so an operator since
 * removed reads as support with no name, and the trail carries no name and no address whoever
 * acted.
 */

beforeEach(function () {
    provisionTenant('acme');
});

/**
 * A booking of acme with one cancellation on its trail, written by the actor given through
 * AuditRow, the writer every transition uses, inside the tenant's schema.
 */
function supportTrailBooking(Actor ...$actors): string
{
    $row = bookingRow();
    DB::table('tenant_acme.bookings')->insert($row);
    $uuid = $row['uuid'];
    assert(is_string($uuid));

    $connection = DB::connection();
    $central = $connection->scalar("select current_setting('search_path')");
    assert(is_string($central));
    $connection->statement('set search_path to '.SearchPath::forSchema('tenant_acme'));

    foreach ($actors as $actor) {
        AuditRow::write($connection, new AuditEntry($uuid, AuditAction::Cancelled, $actor, [
            'status_from' => BookingStatus::Confirmed,
            'status_to' => BookingStatus::Cancelled,
        ]));
    }

    $connection->statement('set search_path to '.$central);

    return $uuid;
}

/**
 * @return list<array{action: string, actor: string, kind: string, name: string|null, at: string, facts: array<mixed>}>
 */
function supportTrailOf(string $uuid): array
{
    return app(BookingTrail::class)->of(Tenant::query()->where('slug', 'acme')->sole(), $uuid);
}

it('reads a row written by support back as kind support, named by the operator\'s name from the central operators table', function () {
    $operator = addOperator(name: 'Pat Operator');
    $uuid = supportTrailBooking(Actor::support($operator->id));

    expect(DB::table('tenant_acme.audit_log')->value('actor'))->toBe('support:'.$operator->id);

    $trail = supportTrailOf($uuid);

    expect($trail)->toHaveCount(1)
        ->and($trail[0]['actor'])->toBe('support:'.$operator->id)
        ->and($trail[0]['kind'])->toBe('support')
        ->and($trail[0]['name'])->toBe('Pat Operator')
        ->and($trail[0]['facts'])->toEqual(['status_from' => 'confirmed', 'status_to' => 'cancelled']);
});

it('reads the row as support with no name once the operator is removed, the trail keeping the id alone', function () {
    $operator = addOperator(name: 'Pat Operator');
    $uuid = supportTrailBooking(Actor::support($operator->id));
    $operator->delete();

    $trail = supportTrailOf($uuid);

    expect($trail[0]['actor'])->toBe('support:'.$operator->id)
        ->and($trail[0]['kind'])->toBe('support')
        ->and($trail[0]['name'])->toBeNull();
});

it('resolves every operator of a trail in one read of operators beside the one of users, and reads operators not at all for a trail support never touched', function () {
    $pat = addOperator(name: 'Pat Operator');
    $sam = addOperator('sam@inbox.example', name: 'Sam Operator');
    $owner = User::query()->where('tenant_id', Tenant::query()->where('slug', 'acme')->sole()->id)->firstOrFail();
    $mixed = supportTrailBooking(Actor::support($pat->id), Actor::user($owner->id), Actor::support($sam->id), Actor::support($pat->id));
    $untouched = supportTrailBooking(Actor::user($owner->id), Actor::system());

    $reads = [];
    DB::listen(function (QueryExecuted $query) use (&$reads): void {
        $reads[] = $query->sql;
    });

    // A statement reads a table when it names it as a word, quoted or not, so a read rewritten as
    // raw SQL is still counted and the "not at all" below cannot pass for want of a match.
    $naming = fn (string $table): Closure => fn (string $sql): bool => preg_match('/(?<![a-z0-9_])"?'.$table.'"?(?![a-z0-9_])/i', $sql) === 1;

    $trail = supportTrailOf($mixed);
    $operatorReads = count(array_filter($reads, $naming('operators')));
    $userReads = count(array_filter($reads, $naming('users')));

    expect(array_map(fn (array $entry): array => [$entry['kind'], $entry['name']], $trail))->toBe([
        ['support', 'Pat Operator'],
        ['user', $owner->name],
        ['support', 'Sam Operator'],
        ['support', 'Pat Operator'],
    ])
        ->and($operatorReads)->toBe(1)
        ->and($userReads)->toBe(1);

    $reads = [];
    supportTrailOf($untouched);

    expect(array_filter($reads, $naming('operators')))->toBe([])
        ->and($naming('operators')('select id, name from operators where id in (1)'))->toBeTrue()
        ->and($naming('operators')('select "id", "name" from "operators" where "id" in (?)'))->toBeTrue()
        ->and($naming('operators')('select * from "operators_archive"'))->toBeFalse();
});
