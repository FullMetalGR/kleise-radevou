<?php

use App\Http\BookingPayload;
use App\Http\LinkKind;
use App\Http\Middleware\HandleInertiaRequests;
use App\Infrastructure\Db\SqlText;

/*
 * The spellings two or more readers shared, each read from its one home. Every case reads the
 * repository's own sources, so a second copy written later beside the home fails here by its path,
 * and each names the home it holds the readers to: the manifest's hash, a uuid's pattern, the
 * database's instant read back, the API's instant written out, a booking's first start and its
 * last segment end, the hosted page's two query keys on the server, and a business category's
 * English label, read by the console alone. The secret type's pin is CustomerSecretTest's.
 */

/**
 * Every PHP file under a directory of the repository, by its path from the root, in name order.
 *
 * @return list<string>
 */
function sharedHomeSources(string $directory): array
{
    $root = dirname(__DIR__, 2);
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }

    sort($files);

    return $files;
}

/**
 * The files under the directories given whose source matches the pattern, by their path from the
 * root, in name order.
 *
 * @param  list<string>  $directories
 * @return list<string>
 */
function sharedHomeSpellers(array $directories, string $pattern): array
{
    $spellers = [];

    foreach ($directories as $directory) {
        foreach (sharedHomeSources($directory) as $path) {
            if (preg_match($pattern, (string) file_get_contents(dirname(__DIR__, 2).'/'.$path)) === 1) {
                $spellers[] = $path;
            }
        }
    }

    sort($spellers);

    return $spellers;
}

it('reads the admin build\'s manifest in HandleInertiaRequests::manifestHash alone, the three hosts\' adapters versioning their pages by it', function () {
    $adapters = ['HandleInertiaRequests', 'HandleCentralInertiaRequests', 'HandleConsoleInertiaRequests'];

    expect(sharedHomeSpellers(['app'], '/manifest\.json/'))->toBe(['app/Http/Middleware/HandleInertiaRequests.php'])
        ->and(sharedHomeSpellers(['app'], '/hash_file\(/'))->toBe(['app/Http/Middleware/HandleInertiaRequests.php'])
        ->and(array_map(fn (string $adapter): bool => str_contains(
            (string) file_get_contents(dirname(__DIR__, 2).'/app/Http/Middleware/'.$adapter.'.php'),
            $adapter === 'HandleInertiaRequests' ? 'self::manifestHash()' : 'HandleInertiaRequests::manifestHash()',
        ), $adapters))->toBe([true, true, true])
        ->and(HandleInertiaRequests::BUILD_DIRECTORY)->toBe('build/admin');
});

it('spells a uuid\'s pattern once, SqlText::UUID, anchored at both ends, so a trailing newline is no uuid and never reaches the server as a malformed literal', function () {
    expect(sharedHomeSpellers(['app'], '/\[0-9a-f\]\{8\}-/'))->toBe(['app/Infrastructure/Db/SqlText.php'])
        ->and(SqlText::UUID)->toStartWith('/\A')->toEndWith('\z/i')
        ->and(SqlText::isUuid('5f0c2c9e-8d1b-4c3a-9e2f-0a1b2c3d4e5f'))->toBeTrue()
        ->and(SqlText::isUuid('5F0C2C9E-8D1B-4C3A-9E2F-0A1B2C3D4E5F'))->toBeTrue()
        ->and(SqlText::isUuid("5f0c2c9e-8d1b-4c3a-9e2f-0a1b2c3d4e5f\n"))->toBeFalse()
        ->and(SqlText::isUuid('not-a-uuid'))->toBeFalse();
});

it('reads an instant the server wrote out back through SqlText::readInstant alone, in UTC with the parsing zone named', function () {
    $read = SqlText::readInstant('2026-11-03 08:00:00.250000+02:00');

    // The idiom by its shape, whatever the zone is called: a text parsed in a zone, held as a
    // variable or spelled inline, then set to the same zone. The jobs' queued instants read
    // through it as well as the rows' columns, so the scan is app/ whole.
    expect(sharedHomeSpellers(['app'], '/\(new DateTimeImmutable\(\$[^,()]+, (\$\w+|new DateTimeZone\(\'UTC\'\))\)\)->setTimezone\(\1\)/'))
        ->toBe(['app/Infrastructure/Db/SqlText.php'])
        ->and($read->getTimezone()->getName())->toBe('UTC')
        ->and($read->format(SqlText::INSTANT))->toBe('2026-11-03 06:00:00.250000+00:00');
});

it('writes an instant in the API\'s spelling through BookingPayload::instant alone, RFC 3339 in UTC with the offset written out', function () {
    expect(sharedHomeSpellers(['app/Infrastructure', 'app/Http'], '/format\(DateTimeImmutable::RFC3339\)|Y-m-d\\\\TH:i:sP/'))->toBe(['app/Http/BookingPayload.php'])
        ->and(BookingPayload::instant(new DateTimeImmutable('2026-11-03 08:00', new DateTimeZone('Europe/Athens'))))->toBe('2026-11-03T06:00:00+00:00');
});

it('spells a booking\'s first start once, SqlText::firstStarts, the subquery the Bookings list, the Customers screen and the reports join a booking to', function () {
    expect(sharedHomeSpellers(['app'], '/min\(start_utc\) as starts_at/'))->toBe(['app/Infrastructure/Db/SqlText.php'])
        ->and(SqlText::firstStarts('tenant_luna'))->toBe('select booking_id, min(start_utc) as starts_at from tenant_luna.booking_items group by booking_id')
        ->and(SqlText::bookingsByFirstStart('tenant_luna'))->toBe('tenant_luna.bookings booking join ('.SqlText::firstStarts('tenant_luna').') first on first.booking_id = booking.id');
});

it('spells a booking\'s last segment end once, SqlText::lastEnds, the subquery the retention lines of the 24 months and the 30 days and erasure\'s primitive count from', function () {
    expect(sharedHomeSpellers(['app'], '/max\(end_utc\)/'))->toBe(['app/Infrastructure/Db/SqlText.php'])
        ->and(SqlText::lastEnds('tenant_luna'))->toBe('select booking_id, max(end_utc) as ends_at from tenant_luna.booking_items group by booking_id')
        ->and(sharedHomeSpellers(['app/Retention'], '/SqlText::lastEnds\(/'))->toBe(['app/Retention/BookingMetaLine.php', 'app/Retention/Erasure.php', 'app/Retention/ManageTokensLine.php']);
});

it('reads the hosted page\'s two query keys off LinkKind on the server, in every reader and writer of a booking link', function () {
    $links = sharedHomeSpellers(['app'], '/bookingLink\(|linkMount\(/');
    $literal = array_filter($links, fn (string $path): bool => preg_match('/\[\'(service|staff)\'\]|\[\'(service|staff)\' =>/', (string) file_get_contents(dirname(__DIR__, 2).'/'.$path)) === 1);

    expect($links)->toContain('app/Http/BookingPage.php', 'app/Http/BusinessPage.php', 'app/Notifications/BookAgainLine.php', 'app/Http/BookingLinks.php')
        ->and(array_values($literal))->toBe([])
        ->and(array_map(fn (LinkKind $kind): string => $kind->value, LinkKind::cases()))->toBe(['service', 'staff']);
});

it('spells the hosted page\'s two query keys once on the client, myBookings.ts\'s two constants, holding LinkKind\'s values', function () {
    $root = dirname(__DIR__, 2);
    $writers = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/resources/js', FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = $file instanceof SplFileInfo ? $file->getPathname() : '';

        if (preg_match('/\.tsx?$/', $path) === 1 && preg_match('/\.test\.tsx?$/', $path) !== 1 && preg_match('/\.set\([\'"](service|staff)[\'"]/', (string) file_get_contents($path)) === 1) {
            $writers[] = substr($path, strlen($root) + 1);
        }
    }

    $source = (string) file_get_contents($root.'/resources/js/booking/myBookings.ts');
    preg_match_all('/^export const (SERVICE|STAFF)_QUERY = \'([a-z]+)\';$/m', $source, $constants);

    expect($writers)->toBe([])
        ->and($constants[2])->toBe(array_map(fn (LinkKind $kind): string => $kind->value, LinkKind::cases()))
        ->and(substr_count($source, 'query.set(SERVICE_QUERY,'))->toBe(1)
        ->and(substr_count($source, 'query.set(STAFF_QUERY,'))->toBe(1);
});

it('reads a queued job\'s tenant through JobTenant::find alone, so a job on a closed or gone tenant ends at one line wherever it runs', function () {
    expect(sharedHomeSpellers(['app/Jobs'], '/Tenant::query\(\)/'))->toBe(['app/Jobs/JobTenant.php'])
        ->and(count(sharedHomeSpellers(['app/Jobs'], '/JobTenant::find\(\$this->tenantId, self::class, \$log\)/')))->toBe(21);
});

it('reads every loop over the tenants\' schemas from Tenant::built() alone, so no loop opens a closed tenant\'s schema', function () {
    expect(sharedHomeSpellers(['app'], '/Tenant::built\(\)/'))->toBe([
        'app/Console/Commands/BackfillMapsCommand.php',
        'app/Console/Commands/MigrateTenantsCommand.php',
        'app/Console/Commands/RetentionSweepCommand.php',
        'app/Console/Commands/SweepHoldsCommand.php',
    ])
        ->and(sharedHomeSpellers(['app'], "/where(Not)?\\('status', ('!=', )?TenantStatus::Provisioning->value\\)/"))->toBe([]);
});

it('reads and writes no weekday rule anywhere but the migrations, a member\'s week being their pattern, and writes a test\'s week straight into the schema through WeeklyHours alone', function () {
    expect(sharedHomeSpellers(['app', 'database/seeders', 'tests'], '/table\\([^)]*availability_rules/'))->toBe([])
        // A write through the query builder, its table named literally, joined or built by
        // sprintf, and a write in raw SQL, insert into, update or delete from.
        ->and(sharedHomeSpellers(['tests'], '/shift_patterns[\'"]?(?:, [^)]*)?\\)+(?:->where[^;]*?)?->(?:insert|insertGetId|update|upsert|delete)\\(|(?:insert\\s+into|update|delete\\s+from)\\s+[^\\s(]*shift_patterns\\b/is'))->toBe([
            'tests/Feature/Admin/TenantExportTest.php',
            'tests/Feature/TenantSchemaTest.php',
            'tests/Support/WeeklyHours.php',
        ])
        ->and(sharedHomeSpellers(['tests'], '/WeeklyHours::(?:add|week|clear|reshape|pattern)\\(/'))->toContain(
            'tests/Concurrency/DeleteStaffTest.php',
            'tests/Concurrency/HoldBookingTest.php',
            'tests/Feature/Admin/StoredEntitlementsTest.php',
            'tests/Feature/AvailabilityEndpointTest.php',
            'tests/Feature/AvailabilityRepositoryTest.php',
            'tests/Feature/BusinessPageTest.php',
            'tests/Feature/DemoHoursSeederTest.php',
            'tests/Feature/MoveAvailabilityEndpointTest.php',
        );
});

it('spells the one start every weekly pattern has once, WeeklyPattern::fromTheStart, and the start a pattern write stores once, WeeklyPattern::storedStart, which put() writes and Shifts\' pattern request judges an end against, so a save is never judged against a start it does not store', function () {
    expect(sharedHomeSpellers(['app', 'database', 'tests'], '/weekStart\\([^;]*(?:BEGINNING|2000-01-01)/'))->toBe(['app/Infrastructure/Db/WeeklyPattern.php'])
        ->and(sharedHomeSpellers(['app', 'database'], '/[\'"]2000-01-01[\'"]/'))->toBe(['app/Infrastructure/Db/WeeklyPattern.php'])
        ->and(sharedHomeSpellers(['app/Http'], '/ShiftRecords::class\\)->storedStart\\(/'))->toBe(['app/Http/Requests/ShiftPatternRequest.php'])
        ->and(preg_match('/function put\\([^{]*\\{[^}]*\'starts_on\' => self::storedStart\\(\\$connection, \\$prefix, \\$cadenceWeeks, \\$startsOn\\)/s', (string) file_get_contents(dirname(__DIR__, 2).'/app/Infrastructure/Db/WeeklyPattern.php')))->toBe(1);
});

/*
 * A business category's label is the console's English: the signup names its categories in
 * signup.php's words and no admin page shows one, so a third reader, an admin prop among them,
 * fails here by its path. The pattern goes by the name `category` and the enum's name, not by type:
 * a label read off a variable or property called category, or straight off BusinessCategory by a
 * case, from() or tryFrom().
 */
it('reads a business category\'s label in the console alone: the new tenant form and a tenant\'s detail, no admin prop and no signup', function () {
    expect(sharedHomeSpellers(['app'], '/category\??->label\(\)|BusinessCategory::(?:[A-Z]\w*|(?:try)?[fF]rom\(.*?\))\??->label\(\)/'))->toBe([
        'app/Http/Controllers/Console/NewTenantController.php',
        'app/Http/Controllers/Console/TenantController.php',
    ]);
});
