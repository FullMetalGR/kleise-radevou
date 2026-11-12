<?php

use App\Application\Clock;
use App\Infrastructure\Storage\TenantImages;
use App\Models\User;
use App\Retention\TenantExport;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeDisks;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;
use Tests\Support\TemporaryFiles;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * The tenant export from the Settings Danger zone, through GET /admin/settings/export: one zip
 * named {slug}-{date}.zip with the tenant's today, holding one JSON file per tenant table, bookings
 * nesting their items and intake rows, export.json describing the archive, and every image a row
 * names under its stored path; no credential, webhook token, manage token hash, sealed value or
 * serial id in any entry, read back whole; every column of every tenant table carried or withheld
 * by name; the route under manageSettings and outside the subscription gate; and no file left on
 * the server. The download is read back here through PHP's own zip reader rather than walked in a
 * browser.
 *
 * The rows are written straight to luna's tables with literal instants and distinctive serial
 * ids, so the clock is pinned first: the export's instant and the file's date are the pinned
 * clock's, 2026-11-02 08:00 in Athens, and the booking's items before the October change read
 * +03:00. The images are written to the faked public disk under luna's directory, with one more
 * gallery path the disk does not hold and one under another tenant's directory that it does.
 */

beforeEach(function () {
    $this->withoutVite();
    dropDemoSchemas();
    seedDemo();
    FakeDisks::fake(TenantImages::DISK);
    FixtureDay::pinClock();
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/** The own-mode credential the fixture's connection stores, encrypted, and the archive must never hold in any form. */
const TENANT_EXPORT_SECRET = 'sk_test_tenant_export_credential_value';

const TENANT_EXPORT_WEBHOOK = 'whk_tenant_export_webhook_token_value';

const TENANT_EXPORT_REQUIREMENT = 'tenant-export-requirement-sentence';

const TENANT_EXPORT_HASH_OF = 'tenant-export-manage-token';

const TENANT_EXPORT_SEALED = 'sealed.tenant-export-manage-token';

/** The fixture's serial ids, distinctive so that none can be met by chance in an archive. */
const TENANT_EXPORT_IDS = ['service' => 7300011, 'member' => 7300021, 'user' => 7300031, 'booking' => 7300041, 'first item' => 7300051, 'second item' => 7300052, 'trail' => 7300061, 'connection' => 7300071, 'blackout' => 7300081, 'pattern' => 7300091, 'link' => 7300101, 'operator' => 7300111, 'note' => 7300121];

/** The stamps of every row the fixture writes, the columns' default being the database's own now(). */
const TENANT_EXPORT_STAMPS = ['created_at' => '2026-10-01 06:00:00+00', 'updated_at' => '2026-10-01 06:00:00+00'];

/** Those stamps as the archive writes them, on Athens' summer clock. */
const TENANT_EXPORT_STAMPED = '2026-10-01T09:00:00+03:00';

/** The stored images, each a path under luna's directory. */
const TENANT_EXPORT_IMAGES = [
    'logo' => 'tenants/luna/logo-1111111111111111.png',
    'cover' => 'tenants/luna/cover-2222222222222222.png',
    'gallery' => 'tenants/luna/gallery-3333333333333333.png',
    'map' => 'tenants/luna/map-4444444444444444.png',
    'photo' => 'tenants/luna/staff-5555555555555555.png',
];

/** A gallery path the row names and the disk no longer holds. */
const TENANT_EXPORT_GONE = 'tenants/luna/gallery-6666666666666666.png';

/** A gallery path under another tenant's directory, whose file the disk does hold. */
const TENANT_EXPORT_FOREIGN = 'tenants/pier/gallery-7777777777777777.png';

const TENANT_EXPORT_TABLES = ['settings.json', 'payment_connections.json', 'services.json', 'staff.json', 'service_staff.json', 'shift_patterns.json', 'shift_overrides.json', 'blackouts.json', 'staff_days.json', 'bookings.json', 'customer_profiles.json', 'customer_notes.json', 'audit_log.json'];

/**
 * A luna with one of each: the five images on the disk, a second member under Elena's name
 * sharing her photo and linked to a user, a service only that member performs with its link,
 * hours and closures, a staff-day, an own-mode connection with a credential and a webhook token,
 * a booking with two items and two intake rows and its manage token's hash and sealed copy, a
 * trail row for each of the five actor shapes, and the booking's customer's profile with a note
 * by a user and one by support.
 *
 * @return array{uuid: string, ciphertext: string, owner: User}
 */
function tenantExportFixture(): array
{
    $disk = Storage::disk(TenantImages::DISK);

    foreach ([...array_values(TENANT_EXPORT_IMAGES), TENANT_EXPORT_FOREIGN] as $at => $path) {
        $disk->put($path, (string) file_get_contents(drawnPng(4, 3, 40 * $at, 200 - 30 * $at, 90)));
    }

    settingsOf('luna')->update([
        'brand_logo_path' => TENANT_EXPORT_IMAGES['logo'],
        'cover_path' => TENANT_EXPORT_IMAGES['cover'],
        'gallery_paths' => '{'.implode(',', [TENANT_EXPORT_IMAGES['gallery'], TENANT_EXPORT_GONE, TENANT_EXPORT_FOREIGN]).'}',
        'map_path' => TENANT_EXPORT_IMAGES['map'], 'map_state' => 'ready', 'lat' => 37.9755, 'lng' => 23.7348,
        'about' => "Caf\u{e9} and haircuts since 1998.",
        'link_copied_at' => '2026-10-01 06:00:00+00',
    ]);

    $tenant = LunaFixture::tenant();
    $owner = User::query()->where('email', 'owner@luna.example')->sole();
    DB::table('users')->insert(['id' => TENANT_EXPORT_IDS['user'], 'tenant_id' => $tenant->id, 'name' => 'Sam Staff', 'email' => 'sam.staff@luna.example', 'password' => bcrypt('correct horse battery staple'), 'role' => 'staff', 'created_at' => now(), 'updated_at' => now()]);

    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    DB::table('tenant_luna.staff')->where('id', $elena)->update(['photo_path' => TENANT_EXPORT_IMAGES['photo']]);
    DB::table('tenant_luna.staff')->insert(['id' => TENANT_EXPORT_IDS['member'], 'user_id' => TENANT_EXPORT_IDS['user'], 'name' => 'Elena Papadaki', 'email' => 'elena.two@luna.example', 'status' => 'active', 'sort' => 9, 'photo_path' => TENANT_EXPORT_IMAGES['photo'], 'title' => 'Junior stylist', 'colour' => 'purple', ...TENANT_EXPORT_STAMPS]);
    DB::table('tenant_luna.services')->insert(['id' => TENANT_EXPORT_IDS['service'], 'name' => 'Express cut', 'duration_min' => 20, 'price_minor' => 1500, 'currency' => 'EUR', 'payment_mode' => 'onsite', 'status' => 'active', 'sort' => 9, ...TENANT_EXPORT_STAMPS]);
    DB::table('tenant_luna.service_staff')->insert(['id' => TENANT_EXPORT_IDS['link'], 'service_id' => TENANT_EXPORT_IDS['service'], 'staff_id' => TENANT_EXPORT_IDS['member'], ...TENANT_EXPORT_STAMPS]);
    DB::table('tenant_luna.shift_patterns')->insert(['id' => TENANT_EXPORT_IDS['pattern'], 'staff_id' => TENANT_EXPORT_IDS['member'], 'starts_on' => '2026-10-05', 'cadence_weeks' => 2, 'ends_on' => '2027-03-28', 'windows' => '[{"weekday": 2, "start": "09:00", "end": "13:00"}]', ...TENANT_EXPORT_STAMPS]);
    DB::table('tenant_luna.shift_overrides')->insert(['id' => TENANT_EXPORT_IDS['pattern'] + 1, 'staff_id' => TENANT_EXPORT_IDS['member'], 'date' => '2026-10-13', 'windows' => '[{"start": "10:00", "end": "12:00"}]', ...TENANT_EXPORT_STAMPS]);
    DB::table('tenant_luna.blackouts')->insert([
        ['id' => TENANT_EXPORT_IDS['blackout'], 'staff_id' => null, 'date' => '2026-12-25', 'start_time' => null, 'end_time' => null, 'reason' => 'Christmas', ...TENANT_EXPORT_STAMPS],
        ['id' => TENANT_EXPORT_IDS['blackout'] + 1, 'staff_id' => TENANT_EXPORT_IDS['member'], 'date' => '2026-11-10', 'start_time' => '12:00', 'end_time' => '13:00', 'reason' => 'Dentist', ...TENANT_EXPORT_STAMPS],
    ]);
    DB::table('tenant_luna.staff_days')->insert(['staff_id' => TENANT_EXPORT_IDS['member'], 'day' => '2026-10-20', 'rev' => 4, ...TENANT_EXPORT_STAMPS]);

    $ciphertext = Crypt::encryptString(json_encode(['secret_key' => TENANT_EXPORT_SECRET], JSON_THROW_ON_ERROR));
    DB::table('tenant_luna.payment_connections')->insert([
        'id' => TENANT_EXPORT_IDS['connection'], 'provider' => 'stripe', 'mode' => 'own', 'state' => 'active', 'account_ref' => null,
        'credentials' => $ciphertext, 'webhook_token' => TENANT_EXPORT_WEBHOOK, 'requirements' => json_encode(['reason' => TENANT_EXPORT_REQUIREMENT], JSON_THROW_ON_ERROR),
        'is_primary' => true, 'last_checked_at' => '2026-11-01 07:00:00+00', 'last_check_ok' => true,
    ]);

    $uuid = 'b7e0c4d2-5a3f-4e61-9c8b-2d1f0a7e6c55';
    DB::table('tenant_luna.bookings')->insert([
        'id' => TENANT_EXPORT_IDS['booking'], 'uuid' => $uuid, 'status' => 'confirmed', 'source' => 'hosted', 'channel' => 'instagram', 'language' => 'el',
        'customer_name' => 'Casey Customer', 'customer_email' => 'casey@example.com', 'customer_phone' => '+30 210 7654321',
        'total_minor' => 9500, 'currency' => 'EUR', 'payment_mode' => 'online', 'requires_approval' => true, 'on_approval_timeout' => 'expire',
        'approved_at' => '2026-10-18 10:00:00+00', 'approved_by' => TENANT_EXPORT_IDS['user'], 'payment_ref' => 'cs_test_tenant_export',
        'manage_token_hash' => hash('sha256', TENANT_EXPORT_HASH_OF), 'manage_token_sealed' => TENANT_EXPORT_SEALED,
        'created_at' => '2026-10-18 09:30:00+00', 'updated_at' => '2026-10-18 10:00:00+00',
    ]);
    DB::table('tenant_luna.booking_items')->insert([
        ['id' => TENANT_EXPORT_IDS['second item'], 'booking_id' => TENANT_EXPORT_IDS['booking'], 'sort' => 1, 'service_id' => LunaFixture::id('services', 'Colour'), 'staff_id' => LunaFixture::id('staff', 'Nikos Georgiou'), 'start_utc' => '2026-10-20 08:20:00+00', 'end_utc' => '2026-10-20 09:20:00+00', 'block_start_utc' => '2026-10-20 08:20:00+00', 'block_end_utc' => '2026-10-20 09:25:00+00', 'processing_ends_utc' => '2026-10-20 09:50:00+00', 'price_minor' => 8000, 'blocks' => false, ...TENANT_EXPORT_STAMPS],
        ['id' => TENANT_EXPORT_IDS['first item'], 'booking_id' => TENANT_EXPORT_IDS['booking'], 'sort' => 0, 'service_id' => TENANT_EXPORT_IDS['service'], 'staff_id' => TENANT_EXPORT_IDS['member'], 'start_utc' => '2026-10-20 08:00:00+00', 'end_utc' => '2026-10-20 08:20:00+00', 'block_start_utc' => '2026-10-20 08:00:00+00', 'block_end_utc' => '2026-10-20 08:20:00+00', 'processing_ends_utc' => null, 'price_minor' => 1500, 'blocks' => false, ...TENANT_EXPORT_STAMPS],
    ]);
    DB::table('tenant_luna.booking_meta')->insert([
        ['booking_id' => TENANT_EXPORT_IDS['booking'], 'key' => 'note', 'value' => 'Please use the side door.'],
        ['booking_id' => TENANT_EXPORT_IDS['booking'], 'key' => 'allergies', 'value' => 'Lavender'],
    ]);
    DB::table('tenant_luna.audit_log')->insert([
        ['id' => TENANT_EXPORT_IDS['trail'], 'booking_id' => TENANT_EXPORT_IDS['booking'], 'actor' => 'customer', 'action' => 'held', 'payload' => json_encode(['status_to' => 'awaiting_approval', 'hold_class' => 'approval', 'hold_expires_at' => '2026-10-20T09:30:00.000000+00:00', 'starts_at' => '2026-10-20T08:00:00.000000+00:00'], JSON_THROW_ON_ERROR), 'created_at' => '2026-10-18 09:30:00+00'],
        ['id' => TENANT_EXPORT_IDS['trail'] + 1, 'booking_id' => TENANT_EXPORT_IDS['booking'], 'actor' => 'user:'.TENANT_EXPORT_IDS['user'], 'action' => 'approved', 'payload' => json_encode(['status_from' => 'awaiting_approval', 'status_to' => 'confirmed'], JSON_THROW_ON_ERROR), 'created_at' => '2026-10-18 10:00:00+00'],
        ['id' => TENANT_EXPORT_IDS['trail'] + 2, 'booking_id' => TENANT_EXPORT_IDS['booking'], 'actor' => 'support:'.TENANT_EXPORT_IDS['operator'], 'action' => 'rescheduled', 'payload' => json_encode(['moved_from' => '2026-10-19T08:00:00.000000+00:00', 'starts_at' => '2026-10-20T08:00:00.000000+00:00'], JSON_THROW_ON_ERROR), 'created_at' => '2026-10-18 11:00:00+00'],
        ['id' => TENANT_EXPORT_IDS['trail'] + 3, 'booking_id' => TENANT_EXPORT_IDS['booking'], 'actor' => 'job:reminders', 'action' => 'customer_exported', 'payload' => '{}', 'created_at' => '2026-10-30 12:00:00+00'],
        ['id' => TENANT_EXPORT_IDS['trail'] + 4, 'booking_id' => TENANT_EXPORT_IDS['booking'], 'actor' => 'system', 'action' => 'completed', 'payload' => '{}', 'created_at' => '2026-10-30 12:00:00+00'],
    ]);
    DB::table('tenant_luna.customer_profiles')->insert([
        'email' => 'casey@example.com', 'tags' => '{VIP,colour}', 'birthday' => '1990-04-12', 'language' => 'el',
        'blocked_at' => '2026-10-30 09:00:00+00', 'blocked_reason' => 'Two no-shows', ...TENANT_EXPORT_STAMPS,
    ]);
    DB::table('tenant_luna.customer_notes')->insert([
        ['id' => TENANT_EXPORT_IDS['note'], 'email' => 'casey@example.com', 'body' => 'Allergic to lavender.', 'author_actor' => 'user:'.TENANT_EXPORT_IDS['user'], 'author_name' => 'Sam Staff', 'created_at' => '2026-10-19 07:00:00+00'],
        ['id' => TENANT_EXPORT_IDS['note'] + 1, 'email' => 'casey@example.com', 'body' => 'Prefers mornings.', 'author_actor' => 'support:'.TENANT_EXPORT_IDS['operator'], 'author_name' => 'Support', 'created_at' => '2026-10-31 07:00:00+00'],
    ]);

    return ['uuid' => $uuid, 'ciphertext' => $ciphertext, 'owner' => $owner];
}

function tenantExportUrl(): string
{
    return tenantUrl('luna', '/admin/settings/export');
}

/**
 * The export's GET, as the user given or the owner.
 *
 * @return TestResponse<Response>
 */
function tenantExportGet(TestCase $test, ?User $as = null): TestResponse
{
    return $test->actingAs($as ?? User::query()->where('email', 'owner@luna.example')->sole())->get(tenantExportUrl());
}

/**
 * The entries of the zip a landed export streamed, read back through PHP's own zip reader with
 * its consistency check, each name with its bytes and its compression method, in the archive's
 * order.
 *
 * @param  TestResponse<Response>  $response
 * @return array{entries: array<string, string>, methods: array<string, int>}
 */
function tenantExportRead(TestResponse $response): array
{
    $path = TemporaryFiles::make('tenant-export-read', '.zip');
    file_put_contents($path, $response->assertOk()->streamedContent());
    $zip = new ZipArchive;
    expect($zip->open($path, ZipArchive::CHECKCONS | ZipArchive::RDONLY))->toBeTrue();
    $entries = [];
    $methods = [];

    for ($at = 0; $at < $zip->numFiles; $at++) {
        $stat = $zip->statIndex($at);
        $contents = $zip->getFromIndex($at);
        assert(is_array($stat) && is_string($contents));
        $entries[$stat['name']] = $contents;
        $methods[$stat['name']] = $stat['comp_method'];
    }

    $zip->close();

    return ['entries' => $entries, 'methods' => $methods];
}

/**
 * Each JSON file of a landed export, decoded, keyed by its table.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, array<mixed>>
 */
function tenantExportFiles(TestResponse $response): array
{
    $files = [];

    foreach (tenantExportRead($response)['entries'] as $name => $contents) {
        if (str_ends_with($name, '.json')) {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            $files[substr($name, 0, -5)] = is_array($decoded) ? $decoded : throw new LogicException($name.' is no JSON object or list.');
        }
    }

    return $files;
}

/**
 * Every key of a decoded file at every depth, and every scalar value with the key it sits under.
 *
 * @param  array<mixed>  $file
 * @return array{keys: list<string>, values: list<array{string, string}>}
 */
function tenantExportLeaves(array $file): array
{
    $keys = [];
    $values = [];

    $walk = function (array $node) use (&$walk, &$keys, &$values): void {
        foreach ($node as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }

            if (is_array($value)) {
                $walk($value);
            } elseif (is_scalar($value)) {
                $values[] = [(string) $key, is_bool($value) ? ($value ? 'true' : 'false') : (string) $value];
            }
        }
    };
    $walk($file);

    return ['keys' => array_values(array_unique($keys)), 'values' => $values];
}

/**
 * A row of a file by the value under a key.
 *
 * @param  array<mixed>  $rows
 * @return array<mixed>
 */
function tenantExportRow(array $rows, string $key, mixed $value): array
{
    foreach ($rows as $row) {
        if (is_array($row) && ($row[$key] ?? null) === $value) {
            return $row;
        }
    }

    throw new LogicException(sprintf('No row has %s.', $key));
}

it('downloads one zip named for the tenant and its today, a JSON file per table after export.json and every stored image under its path, each file once, read back whole by PHP\'s own reader', function () {
    tenantExportFixture();
    $images = Storage::disk(TenantImages::DISK);

    $response = tenantExportGet($this)
        ->assertOk()
        ->assertHeader('Content-Type', 'application/zip')
        ->assertHeader('Content-Disposition', 'attachment; filename=luna-2026-11-02.zip')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    ['entries' => $entries, 'methods' => $methods] = tenantExportRead($response);
    $manifest = json_decode($entries['export.json'], true, 512, JSON_THROW_ON_ERROR);

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(array_keys($entries))->toBe(['export.json', ...TENANT_EXPORT_TABLES, ...array_values(TENANT_EXPORT_IMAGES)])
        ->and(array_map(fn (string $path): string => $entries[$path], TENANT_EXPORT_IMAGES))->toBe(array_map(fn (string $path): string => (string) $images->get($path), TENANT_EXPORT_IMAGES))
        ->and($manifest)->toBe([
            'business' => 'Luna Hair Studio',
            'timezone' => 'Europe/Athens',
            'currency' => 'EUR',
            'exported_at' => '2026-11-02T08:00:00+02:00',
            'images' => array_values(TENANT_EXPORT_IMAGES),
            'missing_images' => [TENANT_EXPORT_GONE, TENANT_EXPORT_FOREIGN],
        ])
        // The JSON shrinks and goes in deflated; UTF-8 as written, never escaped.
        ->and(array_unique(array_map(fn (string $name): int => $methods[$name], ['export.json', ...TENANT_EXPORT_TABLES])))->toBe([ZipArchive::CM_DEFLATE])
        ->and($entries['settings.json'])->toContain("Caf\u{e9} and haircuts")
        ->and(mb_check_encoding($entries['settings.json'], 'UTF-8'))->toBeTrue();
});

it('nests each booking\'s items in chain order and its intake rows, names a service or a member by its number in its file with its name and a user by their address, and writes every instant on the tenant\'s clock', function () {
    ['uuid' => $uuid] = tenantExportFixture();

    $files = tenantExportFiles(tenantExportGet($this));
    $number = fn (string $table, string $name): array => array_values(array_filter($files[$table], fn (mixed $row): bool => is_array($row) && $row['name'] === $name))[0] ?? throw new LogicException($name);
    $express = $number('services', 'Express cut');
    $elenas = array_values(array_filter($files['staff'], fn (mixed $row): bool => is_array($row) && $row['name'] === 'Elena Papadaki'));
    $nikos = $number('staff', 'Nikos Georgiou');
    $colour = $number('services', 'Colour');
    $second = $elenas[1] ?? throw new LogicException('No second Elena.');
    $member = ['number' => $second['number'], 'name' => 'Elena Papadaki'];

    expect(array_column($files['services'], 'number'))->toBe(range(1, count($files['services'])))
        ->and(array_column($files['staff'], 'number'))->toBe(range(1, count($files['staff'])))
        ->and($elenas)->toHaveCount(2)
        ->and($second)->toMatchArray(['linked_user' => 'sam.staff@luna.example', 'email' => 'elena.two@luna.example', 'title' => 'Junior stylist', 'photo_path' => TENANT_EXPORT_IMAGES['photo'], 'colour' => 'purple', 'created_at' => TENANT_EXPORT_STAMPED])
        ->and(tenantExportRow($files['bookings'], 'uuid', $uuid))->toBe([
            'uuid' => $uuid, 'status' => 'confirmed', 'hold_class' => null, 'hold_expires_at' => null,
            'customer_name' => 'Casey Customer', 'customer_email' => 'casey@example.com', 'customer_phone' => '+30 210 7654321',
            'total_minor' => 9500, 'currency' => 'EUR', 'payment_mode' => 'online', 'requires_approval' => true, 'on_approval_timeout' => 'expire',
            'approved_at' => '2026-10-18T13:00:00+03:00', 'approved_by' => 'sam.staff@luna.example',
            'rejection_reason' => null, 'payment_ref' => 'cs_test_tenant_export', 'source' => 'hosted', 'channel' => 'instagram', 'language' => 'el',
            'created_at' => '2026-10-18T12:30:00+03:00', 'updated_at' => '2026-10-18T13:00:00+03:00',
            'items' => [
                ['sort' => 0, 'service' => ['number' => $express['number'], 'name' => 'Express cut'], 'staff' => $member, 'starts_at' => '2026-10-20T11:00:00+03:00', 'ends_at' => '2026-10-20T11:20:00+03:00', 'block_starts_at' => '2026-10-20T11:00:00+03:00', 'block_ends_at' => '2026-10-20T11:20:00+03:00', 'processing_ends_at' => null, 'price_minor' => 1500, 'blocks' => false, 'created_at' => TENANT_EXPORT_STAMPED, 'updated_at' => TENANT_EXPORT_STAMPED],
                ['sort' => 1, 'service' => ['number' => $colour['number'], 'name' => 'Colour'], 'staff' => ['number' => $nikos['number'], 'name' => 'Nikos Georgiou'], 'starts_at' => '2026-10-20T11:20:00+03:00', 'ends_at' => '2026-10-20T12:20:00+03:00', 'block_starts_at' => '2026-10-20T11:20:00+03:00', 'block_ends_at' => '2026-10-20T12:25:00+03:00', 'processing_ends_at' => '2026-10-20T12:50:00+03:00', 'price_minor' => 8000, 'blocks' => false, 'created_at' => TENANT_EXPORT_STAMPED, 'updated_at' => TENANT_EXPORT_STAMPED],
            ],
            'meta' => ['allergies' => 'Lavender', 'note' => 'Please use the side door.'],
        ])
        ->and(tenantExportRow($files['service_staff'], 'service', ['number' => $express['number'], 'name' => 'Express cut'])['staff'])->toBe($member)
        ->and(array_values(array_filter($files['shift_patterns'], fn (mixed $pattern): bool => is_array($pattern) && $pattern['staff'] === $member)))->toBe([['staff' => $member, 'starts_on' => '2026-10-05', 'cadence_weeks' => 2, 'ends_on' => '2027-03-28', 'windows' => [['end' => '13:00', 'start' => '09:00', 'weekday' => 2]], 'created_at' => TENANT_EXPORT_STAMPED, 'updated_at' => TENANT_EXPORT_STAMPED]])
        // The fixture's three, the implicit member's, Elena's and Nikos', and the member's above.
        ->and(count($files['shift_patterns']))->toBe(4)
        ->and($files['shift_overrides'])->toBe([['staff' => $member, 'date' => '2026-10-13', 'windows' => [['end' => '12:00', 'start' => '10:00']], 'created_at' => TENANT_EXPORT_STAMPED, 'updated_at' => TENANT_EXPORT_STAMPED]])
        // The fixture's two closures among the demo's own, in date order.
        ->and(array_values(array_filter(array_map(fn (mixed $blackout): array => is_array($blackout) ? [$blackout['staff'], $blackout['date'], $blackout['start_time'], $blackout['reason']] : [null, null, null, null], $files['blackouts']), fn (array $blackout): bool => in_array($blackout[3], ['Dentist', 'Christmas'], true) && in_array($blackout[0], [null, $member], true))))->toBe([[$member, '2026-11-10', '12:00:00', 'Dentist'], [null, '2026-12-25', null, 'Christmas']])
        ->and($files['staff_days'])->toBe([['staff' => $member, 'day' => '2026-10-20', 'rev' => 4, 'created_at' => TENANT_EXPORT_STAMPED, 'updated_at' => TENANT_EXPORT_STAMPED]])
        ->and($files['customer_profiles'])->toBe([['email' => 'casey@example.com', 'tags' => ['VIP', 'colour'], 'birthday' => '1990-04-12', 'language' => 'el', 'blocked_at' => '2026-10-30T11:00:00+02:00', 'blocked_reason' => 'Two no-shows', 'created_at' => TENANT_EXPORT_STAMPED, 'updated_at' => TENANT_EXPORT_STAMPED]])
        // A note's author as the trail names an actor: a user by their address, support by the word
        // alone.
        ->and($files['customer_notes'])->toBe([
            ['email' => 'casey@example.com', 'body' => 'Allergic to lavender.', 'author' => ['kind' => 'user', 'email' => 'sam.staff@luna.example'], 'author_name' => 'Sam Staff', 'created_at' => '2026-10-19T10:00:00+03:00'],
            ['email' => 'casey@example.com', 'body' => 'Prefers mornings.', 'author' => ['kind' => 'support'], 'author_name' => 'Support', 'created_at' => '2026-10-31T09:00:00+02:00'],
        ])
        ->and($files['payment_connections'])->toBe([['provider' => 'stripe', 'mode' => 'own', 'state' => 'active', 'account_ref' => null, 'last_checked_at' => '2026-11-01T09:00:00+02:00', 'last_check_ok' => true]])
        ->and($files['audit_log'])->toBe([
            ['booking' => $uuid, 'actor' => ['kind' => 'customer'], 'action' => 'held', 'payload' => ['starts_at' => '2026-10-20T11:00:00+03:00', 'status_to' => 'awaiting_approval', 'hold_class' => 'approval', 'hold_expires_at' => '2026-10-20T12:30:00+03:00'], 'created_at' => '2026-10-18T12:30:00+03:00'],
            ['booking' => $uuid, 'actor' => ['kind' => 'user', 'email' => 'sam.staff@luna.example'], 'action' => 'approved', 'payload' => ['status_to' => 'confirmed', 'status_from' => 'awaiting_approval'], 'created_at' => '2026-10-18T13:00:00+03:00'],
            ['booking' => $uuid, 'actor' => ['kind' => 'support'], 'action' => 'rescheduled', 'payload' => ['starts_at' => '2026-10-20T11:00:00+03:00', 'moved_from' => '2026-10-19T11:00:00+03:00'], 'created_at' => '2026-10-18T14:00:00+03:00'],
            ['booking' => $uuid, 'actor' => ['kind' => 'job', 'name' => 'reminders'], 'action' => 'customer_exported', 'payload' => [], 'created_at' => '2026-10-30T14:00:00+02:00'],
            ['booking' => $uuid, 'actor' => ['kind' => 'system'], 'action' => 'completed', 'payload' => [], 'created_at' => '2026-10-30T14:00:00+02:00'],
        ]);

    // Every instant anywhere is on Athens' clock with its offset, and only a key ending in _at,
    // or the move's start it left, holds one.
    foreach ($files as $table => $file) {
        foreach (tenantExportLeaves($file)['values'] as [$key, $value]) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $value) === 1) {
                expect($value)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+0[23]:00$/', $table.' '.$key)
                    ->and(str_ends_with($key, '_at') || $key === 'moved_from')->toBeTrue($table.' '.$key);
            }
        }
    }
});

it('names the members a move by the business gave segments to by their number with the name and never by a serial id, a member since deleted by null', function () {
    ['uuid' => $uuid] = tenantExportFixture();
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $gone = TENANT_EXPORT_IDS['member'] + 9;
    DB::table('tenant_luna.audit_log')->insert([
        'id' => TENANT_EXPORT_IDS['trail'] + 5, 'booking_id' => TENANT_EXPORT_IDS['booking'], 'actor' => 'user:'.TENANT_EXPORT_IDS['user'], 'action' => 'rescheduled',
        'payload' => json_encode(['starts_at' => '2026-10-20T08:00:00.000000+00:00', 'staff_changed' => [['segment' => 0, 'from' => $elena, 'to' => TENANT_EXPORT_IDS['member']], ['segment' => 1, 'from' => $gone, 'to' => $elena]], 'customer_told' => true], JSON_THROW_ON_ERROR),
        'created_at' => '2026-10-31 12:00:00+00',
    ]);

    $response = tenantExportGet($this);
    $files = tenantExportFiles($response);
    $elenas = array_values(array_filter($files['staff'], fn (mixed $row): bool => is_array($row) && $row['name'] === 'Elena Papadaki'));
    $first = ['number' => $elenas[0]['number'] ?? null, 'name' => 'Elena Papadaki'];
    $second = ['number' => $elenas[1]['number'] ?? null, 'name' => 'Elena Papadaki'];
    $moved = array_values(array_filter($files['audit_log'], fn (mixed $row): bool => is_array($row) && $row['created_at'] === '2026-10-31T14:00:00+02:00'));

    expect($moved)->toBe([[
        'booking' => $uuid, 'actor' => ['kind' => 'user', 'email' => 'sam.staff@luna.example'], 'action' => 'rescheduled',
        'payload' => ['starts_at' => '2026-10-20T11:00:00+03:00', 'customer_told' => true, 'staff_changed' => [
            ['segment' => 0, 'from' => $first, 'to' => $second],
            ['segment' => 1, 'from' => null, 'to' => $first],
        ]],
        'created_at' => '2026-10-31T14:00:00+02:00',
    ]])
        ->and(str_contains(tenantExportRead(tenantExportGet($this))['entries']['audit_log.json'], (string) TENANT_EXPORT_IDS['member']))->toBeFalse();
});

it('holds no credential, webhook token, requirement, manage token hash or sealed value in any form in any entry read back, no key named like one and no serial id', function () {
    ['ciphertext' => $ciphertext] = tenantExportFixture();

    $response = tenantExportGet($this);
    $entries = tenantExportRead($response)['entries'];
    $text = implode("\n", $entries);
    $files = tenantExportFiles($response);
    $forbidden = [
        'credential' => TENANT_EXPORT_SECRET, 'ciphertext' => $ciphertext, 'webhook token' => TENANT_EXPORT_WEBHOOK, 'requirement' => TENANT_EXPORT_REQUIREMENT,
        'manage token hash' => hash('sha256', TENANT_EXPORT_HASH_OF), 'sealed value' => TENANT_EXPORT_SEALED,
    ];
    $keys = array_values(array_unique(array_merge(...array_map(fn (array $file): array => tenantExportLeaves($file)['keys'], array_values($files)))));
    $ids = array_map(strval(...), [...array_values(TENANT_EXPORT_IDS), TENANT_EXPORT_IDS['blackout'] + 1, TENANT_EXPORT_IDS['trail'] + 1, TENANT_EXPORT_IDS['trail'] + 2, TENANT_EXPORT_IDS['trail'] + 3, TENANT_EXPORT_IDS['trail'] + 4]);
    $values = array_column(array_merge(...array_map(fn (array $file): array => tenantExportLeaves($file)['values'], array_values($files))), 1);

    expect(array_filter($forbidden, fn (string $value): bool => str_contains($text, $value)))->toBe([])
        ->and(array_values(array_filter($keys, fn (string $key): bool => preg_match('/(^|_)id$|hash|token|secret|credential|sealed|password|requirement|primary/i', $key) === 1)))->toBe([])
        ->and(array_values(array_filter($ids, fn (string $id): bool => str_contains($text, $id))))->toBe([])
        ->and(array_values(array_intersect($values, $ids)))->toBe([])
        // The connection is its provider, mode, state, account reference and last check alone.
        ->and(is_array($connection = $files['payment_connections'][0] ?? null) ? array_keys($connection) : [])->toBe(['provider', 'mode', 'state', 'account_ref', 'last_checked_at', 'last_check_ok'])
        // The token tables have no file.
        ->and(array_keys($files))->not->toContain('customer_links', 'customer_sessions');
});

it('carries or withholds by name every column of every tenant table the live schema holds, the two token tables whole', function () {
    tenantExportFixture();
    $files = tenantExportFiles(tenantExportGet($this));

    // Per table: the columns no file carries, the columns carried under another key, the keys
    // the archive adds of its own, and the rows the keys are read off.
    $items = array_merge(...array_map(fn (mixed $booking): array => is_array($booking) && is_array($booking['items']) ? $booking['items'] : [], $files['bookings']));
    $tables = [
        'settings' => [['id'], [], [], [$files['settings']]],
        'payment_connections' => [['id', 'credentials', 'webhook_token', 'requirements', 'is_primary', 'created_at', 'updated_at'], [], [], $files['payment_connections']],
        'services' => [['id'], [], ['number'], $files['services']],
        'staff' => [['id'], ['user_id' => 'linked_user'], ['number'], $files['staff']],
        'service_staff' => [['id'], ['service_id' => 'service', 'staff_id' => 'staff'], [], $files['service_staff']],
        'shift_patterns' => [['id'], ['staff_id' => 'staff'], [], $files['shift_patterns']],
        'shift_overrides' => [['id'], ['staff_id' => 'staff'], [], $files['shift_overrides']],
        'blackouts' => [['id'], ['staff_id' => 'staff'], [], $files['blackouts']],
        'staff_days' => [[], ['staff_id' => 'staff'], [], $files['staff_days']],
        'bookings' => [['id', 'manage_token_hash', 'manage_token_sealed'], [], ['items', 'meta'], $files['bookings']],
        // An item and an intake row are their booking's, nested in it: booking_id is the nesting.
        'booking_items' => [['id', 'booking_id', 'block_span'], ['service_id' => 'service', 'staff_id' => 'staff', 'start_utc' => 'starts_at', 'end_utc' => 'ends_at', 'block_start_utc' => 'block_starts_at', 'block_end_utc' => 'block_ends_at', 'processing_ends_utc' => 'processing_ends_at'], [], $items],
        'booking_meta' => [['booking_id', 'key', 'value'], [], [], []],
        'customer_profiles' => [[], [], [], $files['customer_profiles']],
        'customer_notes' => [['id'], ['author_actor' => 'author'], [], $files['customer_notes']],
        'audit_log' => [['id'], ['booking_id' => 'booking'], [], $files['audit_log']],
        'customer_links' => [['id', 'email', 'token_hash', 'created_at', 'expires_at', 'used_at'], [], [], []],
        'customer_sessions' => [['id', 'email', 'session_hash', 'created_at', 'expires_at', 'revoked_at'], [], [], []],
    ];

    $live = [];

    foreach (DB::select("select table_name::text as table_name, column_name::text as column_name from information_schema.columns where table_schema = 'tenant_luna' order by table_name, ordinal_position") as $column) {
        assert($column instanceof stdClass && is_string($column->table_name) && is_string($column->column_name));
        $live[$column->table_name][] = $column->column_name;
    }

    unset($live['migrations']);

    expect(array_keys($live))->toEqualCanonicalizing(array_keys($tables));

    foreach ($tables as $table => [$withheld, $renamed, $added, $rows]) {
        $carried = array_values(array_diff($live[$table], $withheld));
        $keys = [...array_map(fn (string $column): string => $renamed[$column] ?? $column, $carried), ...$added];

        expect(array_values(array_diff($withheld, $live[$table])))->toBe([], $table.' withholds a column it does not have');

        foreach ($rows as $row) {
            expect(is_array($row) ? array_keys($row) : [])->toEqualCanonicalizing($keys, $table);
        }
    }

    // The token tables carry nothing at all.
    expect(array_values(array_diff($live['customer_links'], $tables['customer_links'][0])))->toBe([])
        ->and(array_values(array_diff($live['customer_sessions'], $tables['customer_sessions'][0])))->toBe([]);

    expect($files['bookings'])->not->toBe([])
        ->and($items)->not->toBe([])
        ->and($files['blackouts'])->not->toBe([])
        ->and($files['customer_profiles'])->not->toBe([])
        ->and($files['customer_notes'])->not->toBe([]);
});

it('takes a file two rows name once, skips a path the disk does not hold or that lies outside the tenant\'s directory and names it in export.json, and exports a tenant with no images at all', function () {
    $response = tenantExportGet($this);
    ['entries' => $bare] = tenantExportRead($response);

    expect(array_keys($bare))->toBe(['export.json', ...TENANT_EXPORT_TABLES])
        ->and(json_decode($bare['export.json'], true, 512, JSON_THROW_ON_ERROR))->toMatchArray(['images' => [], 'missing_images' => []]);

    tenantExportFixture();
    Storage::disk(TenantImages::DISK)->delete(TENANT_EXPORT_IMAGES['cover']);
    $entries = tenantExportRead(tenantExportGet($this))['entries'];
    $manifest = json_decode($entries['export.json'], true, 512, JSON_THROW_ON_ERROR);

    expect(array_values(array_filter(array_keys($entries), fn (string $name): bool => ! str_ends_with($name, '.json'))))->toBe([TENANT_EXPORT_IMAGES['logo'], TENANT_EXPORT_IMAGES['gallery'], TENANT_EXPORT_IMAGES['map'], TENANT_EXPORT_IMAGES['photo']])
        ->and($manifest)->toMatchArray(['missing_images' => [TENANT_EXPORT_IMAGES['cover'], TENANT_EXPORT_GONE, TENANT_EXPORT_FOREIGN]])
        ->and(implode('', $entries))->not->toContain((string) Storage::disk(TenantImages::DISK)->get(TENANT_EXPORT_FOREIGN));
});

it('names the file by the tenant\'s today and stamps the export on the tenant\'s clock, half an hour past midnight in Athens while UTC still reads the day before', function () {
    app()->instance(Clock::class, FixedClock::at('2026-11-02 22:30'));

    $response = tenantExportGet($this)->assertHeader('Content-Disposition', 'attachment; filename=luna-2026-11-03.zip');
    $manifest = json_decode(tenantExportRead($response)['entries']['export.json'], true, 512, JSON_THROW_ON_ERROR);

    expect(is_array($manifest) ? $manifest['exported_at'] : null)->toBe('2026-11-03T00:30:00+02:00');
});

it('refuses a viewer without manageSettings the export, 403, answers a signed-out JSON caller 401 rather than the sign-in page, and sends no archive', function () {
    $staff = User::query()->findOrFail(addTenantUser('staff@luna.example', 'staff'));

    $this->getJson(tenantExportUrl())->assertUnauthorized();
    $this->get(tenantExportUrl())->assertRedirect(tenantUrl('luna', '/admin/login'));
    $refused = tenantExportGet($this, $staff)->assertForbidden();

    expect($refused->headers->get('Content-Disposition'))->toBeNull()
        ->and($refused->headers->get('Content-Type'))->not->toContain('zip');
});

it('exports the archive of a tenant whose subscription has lapsed, a closing one included, the export being outside the subscription gate', function (string $state, ?string $closingAt = null) {
    tenantExportFixture();
    billingSet(['subscription_state' => $state, 'grace_ends_at' => null, ...($closingAt === null ? [] : ['status' => 'closing', 'closing_at' => $closingAt])]);

    expect(array_keys(tenantExportRead(tenantExportGet($this))['entries']))->toBe(['export.json', ...TENANT_EXPORT_TABLES, ...array_values(TENANT_EXPORT_IMAGES)]);
})->with([
    'canceled' => ['canceled'],
    'past_due after grace' => ['past_due'],
    'closing' => ['canceled', '2026-11-01 08:00:00+00'],
]);

it('leaves no file under storage/ or the system\'s temporary directory, the archive written to the response and nowhere else', function () {
    tenantExportFixture();

    // Every file, read under the fake disks' lock so no worker's disk directory comes or goes
    // meanwhile.
    $listing = fn (): array => FakeDisks::locked(function (): array {
        $files = glob(sys_get_temp_dir().'/*') ?: [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(storage_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return array_values(array_filter($files, is_file(...)));
    });

    // Whether a file holds a zip or a line of this archive. A file gone before it is read was
    // another worker's, removed after its own test.
    $holds = function (string $path): bool {
        try {
            $contents = (new SplFileObject($path))->fread(1 << 22);
        } catch (RuntimeException) {
            return false;
        }

        return is_string($contents) && (str_contains($contents, "PK\x03\x04") || str_contains($contents, 'Please use the side door.'));
    };
    $before = $listing();

    $body = tenantExportGet($this)->assertOk()->streamedContent();
    $left = array_values(array_diff($listing(), $before));

    expect(str_starts_with($body, "PK\x03\x04"))->toBeTrue()
        ->and(array_values(array_filter($left, $holds)))->toBe([]);
});

it('lists the table files in the order the archive writes them', function () {
    expect(array_map(fn (string $table): string => $table.'.json', TenantExport::TABLES))->toBe(TENANT_EXPORT_TABLES);
});

it('carries the settings row\'s Colour by in the settings file, as the Calendar\'s filter card wrote it', function () {
    settingsOf('luna')->update(['colour_by' => 'category']);

    $files = tenantExportFiles(tenantExportGet($this));

    expect($files['settings'])->toHaveKey('colour_by', 'category');
});

it('carries the settings row\'s theme in the settings file', function () {
    settingsOf('luna')->update(['theme' => 'rose']);

    $files = tenantExportFiles(tenantExportGet($this));

    expect($files['settings'])->toHaveKey('theme', 'rose');
});

it('carries no accent in the settings file, the theme the one colour a tenant chooses', function () {
    $files = tenantExportFiles(tenantExportGet($this));

    expect($files['settings'])->not->toHaveKey('brand_accent');
});
