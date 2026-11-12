<?php

use App\Application\Notification\Recipient;
use App\Infrastructure\Notification\PostgresBusinessDirectory;
use App\Notifications\BusinessRecipients;
use App\Notifications\TenantUser;
use App\Site\SiteLanguage;
use App\Tenancy\Languages;
use App\Tenancy\Role;
use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FixtureDay;

pest()->use(DatabaseTruncation::class);

/*
 * The recipient rule over the rows it actually reads, which sit in two schemas: users are
 * central, staff rows are the tenant's, related by id and never by a foreign key across them.
 *
 * luna's demo tenant has one user, the owner, and two staff members who do not sign in, plus
 * the implicit row standing for the business. Everything else here is seeded by the test,
 * because the arrangement being proved - a manager, staff users, a chair with an address of
 * its own - is one no demo tenant has.
 */

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

function crewTenant(): Tenant
{
    return Tenant::query()->where('slug', 'luna')->sole();
}

/**
 * The id of a row of luna's catalog by its name.
 */
function crewId(string $table, string $name): int
{
    $id = DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');

    assert(is_int($id));

    return $id;
}

function crewUser(string $email, Role $role): int
{
    return (int) DB::table('users')->insertGetId([
        'tenant_id' => crewTenant()->id,
        'name' => ucfirst(strstr($email, '@', true) ?: $email),
        'email' => $email,
        'password' => bcrypt('correct horse battery staple'),
        'role' => $role->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function crewStaff(string $name, ?string $email = null, ?int $userId = null): int
{
    return (int) DB::table('tenant_luna.staff')->insertGetId([
        'name' => $name,
        'email' => $email,
        'user_id' => $userId,
        'status' => 'active',
    ]);
}

function crewLink(string $name, ?int $userId, ?string $email = null): int
{
    $id = crewId('staff', $name);

    DB::table('tenant_luna.staff')->where('id', $id)->update(['user_id' => $userId, 'email' => $email]);

    return $id;
}

/**
 * A booking of luna with one item per staff row named, written straight to the rows: the rule
 * reads the staff of a booking and nothing else about it.
 */
function crewBooking(int ...$staffIds): string
{
    $uuid = (string) Str::uuid();

    $bookingId = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed',
        'uuid' => $uuid,
        'status' => 'confirmed',
        'customer_name' => 'Casey Customer',
        'customer_email' => 'casey@example.test',
        'total_minor' => 3500,
        'currency' => 'EUR',
        'payment_mode' => 'onsite',
        'requires_approval' => false,
        'on_approval_timeout' => 'expire',
        'manage_token_hash' => str_repeat('c', 64),
    ]);

    foreach (array_values($staffIds) as $sort => $staffId) {
        $from = FixtureDay::start()->add(new DateInterval(sprintf('PT%dH', $sort)))->setTimezone(new DateTimeZone('UTC'));
        $start = $from->format('Y-m-d H:i:s.uP');
        $end = $from->add(new DateInterval('PT45M'))->format('Y-m-d H:i:s.uP');

        DB::table('tenant_luna.booking_items')->insert([
            'booking_id' => $bookingId,
            'sort' => $sort,
            'service_id' => crewId('services', 'Haircut'),
            'staff_id' => $staffId,
            'start_utc' => $start,
            'end_utc' => $end,
            'block_start_utc' => $start,
            'block_end_utc' => $end,
            'price_minor' => 3500,
        ]);
    }

    return $uuid;
}

/**
 * @return list<array{string, int|null}>
 */
function crewFor(string $uuid): array
{
    $rule = BusinessRecipients::of(new PostgresBusinessDirectory(app(DatabaseManager::class), crewTenant()), app(Languages::class), crewTenant());

    return array_map(fn (Recipient $to): array => [$to->address, $to->userId], $rule->forBooking($uuid)->all);
}

it('names the owner, the manager, the staff user whose row the booking is on, and the address of a row with no user, once each', function () {
    $owner = DB::table('users')->where('email', 'owner@luna.example')->value('id');
    $manager = crewUser('manager@luna.example', Role::Manager);
    $elenaUser = crewUser('elena@luna.example', Role::Staff);
    crewUser('nikos@luna.example', Role::Staff);

    $elena = crewLink('Elena Papadaki', $elenaUser);
    $chair = crewStaff('Chair two', 'chair@luna.example');

    expect(crewFor(crewBooking($elena, $chair)))->toBe([
        ['owner@luna.example', $owner],
        ['manager@luna.example', $manager],
        ['elena@luna.example', $elenaUser],
        ['chair@luna.example', null],
    ]);
});

it('names the other staff user, and only them, for a booking on their row alone', function () {
    $owner = DB::table('users')->where('email', 'owner@luna.example')->value('id');
    $manager = crewUser('manager@luna.example', Role::Manager);
    $elenaUser = crewUser('elena@luna.example', Role::Staff);
    $nikosUser = crewUser('nikos@luna.example', Role::Staff);

    crewLink('Elena Papadaki', $elenaUser);
    $nikos = crewLink('Nikos Georgiou', $nikosUser);

    expect(crewFor(crewBooking($nikos)))->toBe([
        ['owner@luna.example', $owner],
        ['manager@luna.example', $manager],
        ['nikos@luna.example', $nikosUser],
    ]);
});

it('never names the implicit row by its own address, whatever address that row carries', function () {
    $owner = DB::table('users')->where('email', 'owner@luna.example')->value('id');
    $implicit = implicitStaffId('tenant_luna');

    DB::table('tenant_luna.staff')->where('id', $implicit)->update(['email' => 'business@luna.example']);

    expect(crewFor(crewBooking($implicit)))->toBe([['owner@luna.example', $owner]]);
});

it('mails an address once when a staff row carries the manager own address', function () {
    $owner = DB::table('users')->where('email', 'owner@luna.example')->value('id');
    $manager = crewUser('manager@luna.example', Role::Manager);
    $chair = crewStaff('Chair two', 'MANAGER@luna.example');

    expect(crewFor(crewBooking($chair)))->toBe([
        ['owner@luna.example', $owner],
        ['manager@luna.example', $manager],
    ]);
});

it('names the owner and nobody else for a booking whose rows have neither a user nor an address, which is every demo booking', function () {
    $owner = DB::table('users')->where('email', 'owner@luna.example')->value('id');

    expect(crewFor(crewBooking(crewId('staff', 'Elena Papadaki'))))->toBe([['owner@luna.example', $owner]]);
});

it('counts a staff row once however many items of the booking sit on it', function () {
    $owner = DB::table('users')->where('email', 'owner@luna.example')->value('id');
    $chair = crewStaff('Chair two', 'chair@luna.example');

    expect(crewFor(crewBooking($chair, $chair)))->toBe([
        ['owner@luna.example', $owner],
        ['chair@luna.example', null],
    ]);
});

it('reads no staff row for an unknown booking or an identifier that is no uuid, and still names the people who run the diary', function () {
    // The rule answers who at the business hears about a booking, not whether the booking is
    // there to hear about: the caller re-reads it before it builds a message, and a message
    // for a booking that is gone is skipped there. What is proved here is the other half:
    // a string that is no uuid reads no staff rows rather than reaching the server as a
    // malformed literal, and an unknown one reads none either.
    $owner = DB::table('users')->where('email', 'owner@luna.example')->value('id');
    $chair = crewStaff('Chair two', 'chair@luna.example');
    crewBooking($chair);

    expect(crewFor('7b7f0e5a-0000-4000-8000-0000000000ff'))->toBe([['owner@luna.example', $owner]])
        ->and(crewFor('not-a-uuid'))->toBe([['owner@luna.example', $owner]]);
});

it('refuses a users row whose role this build has no case for, rather than raising from behind the guard', function () {
    // The check constraint is what keeps the column to the three roles, so the only way to
    // write a fourth is to take the constraint off, which is what a later migration adding a
    // role and an older deploy reading the rows amounts to. The guard has to be what answers
    // that, and not the enum behind it.
    DB::statement('alter table users drop constraint users_role_check');

    try {
        crewUser('accountant@luna.example', Role::Owner);
        DB::table('users')->where('email', 'accountant@luna.example')->update(['role' => 'accountant']);

        expect(fn (): array => crewFor('7b7f0e5a-0000-4000-8000-0000000000ff'))
            ->toThrow(LogicException::class, 'A users row of tenant "luna" is not shaped as the schema declares.');
    } finally {
        DB::table('users')->where('email', 'accountant@luna.example')->delete();
        DB::statement("alter table users add constraint users_role_check check (role in ('owner', 'manager', 'staff'))");
    }
});

it('reads the language each user chose off the users row, none where they chose none, and gives each recipient theirs, the staff row\'s address the tenant default', function () {
    settingsOf('luna')->update(['language' => 'el']);
    $manager = crewUser('manager@luna.example', Role::Manager);
    DB::table('users')->where('id', $manager)->update(['locale' => 'en']);
    $uuid = crewBooking(crewStaff('Chair two', 'chair@luna.example'));
    $directory = new PostgresBusinessDirectory(app(DatabaseManager::class), crewTenant());

    expect(array_map(fn (TenantUser $user): array => [$user->email, $user->chosenLanguage], $directory->users()))->toBe([
        ['owner@luna.example', null],
        ['manager@luna.example', SiteLanguage::English],
    ])->and(array_map(fn (Recipient $to): array => [$to->address, $to->language], BusinessRecipients::of($directory, app(Languages::class), crewTenant())->forBooking($uuid)->all))->toBe([
        ['owner@luna.example', 'el'],
        ['manager@luna.example', 'en'],
        ['chair@luna.example', 'el'],
    ]);
});

it('refuses a users row whose language this build has no case for, rather than reading it as none', function () {
    // The check constraint keeps the column to the two languages, so only a row written with it
    // taken off can hold a third; the guard answers that, as it answers a role it has no case for.
    DB::statement('alter table users drop constraint users_locale_check');

    try {
        DB::table('users')->where('email', 'owner@luna.example')->update(['locale' => 'fr']);

        expect(fn (): array => crewFor('7b7f0e5a-0000-4000-8000-0000000000ff'))
            ->toThrow(LogicException::class, 'A users row of tenant "luna" is not shaped as the schema declares.');
    } finally {
        DB::table('users')->where('email', 'owner@luna.example')->update(['locale' => null]);
        DB::statement("alter table users add constraint users_locale_check check (locale in ('en', 'el'))");
    }
});
