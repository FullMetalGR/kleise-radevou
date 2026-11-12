<?php

declare(strict_types=1);

namespace App\Retention;

use App\Application\Clock;
use App\Domain\Audit\Actor;
use App\Domain\Audit\AuditKey;
use App\Infrastructure\Db\RowShape;
use App\Infrastructure\Db\SqlText;
use App\Infrastructure\Storage\TenantImages;
use App\Tenancy\Tenant;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;

/**
 * The tenant's export of everything it holds: the archive a business takes away when it leaves,
 * or keeps.
 *
 * One statement reads every file, so the archive is one snapshot of the tables as they stand, and
 * nothing is written: the tenant export leaves no trail row. The archive is one JSON file per
 * tenant table: settings, payment_connections, services, staff, service_staff, shift_patterns,
 * shift_overrides (a pattern's and an override's windows as stored), blackouts, staff_days,
 * bookings, each booking with its items in chain order and its intake rows nested as
 * booking_items and booking_meta are its own, customer_profiles and customer_notes (the profile
 * and the staff's notes the business keeps on an address, a note's author named as the trail's
 * actor is), and audit_log; beside them export.json, naming the business, its zone and currency
 * (the central row's, which the General section edits), the instant of the export, the images
 * taken and any a row names that the disk no longer holds; and the uploaded images under their
 * stored paths, the logo, the cover, the gallery, the map and the staff photos, each file once
 * however many rows name it.
 *
 * Every column of a table is carried under its own name but these exclusions: no serial id
 * anywhere, so a row of services or staff carries its number, its place in its own file, and a
 * reference to one is that number with the name (neither table has a unique name); a booking is
 * referenced by its uuid; a user, whose id is the platform's, is carried as the address the users
 * table holds for them, a support operator as the word alone. Never the manage token's hash or
 * its sealed copy, never a payment connection's credentials or webhook token: the connection is
 * its provider, mode, state and account reference with its last check, when it ran and whether it
 * passed, the requirements the provider last listed left out with the primary flag.
 * customer_links and customer_sessions are token tables and get no file. An item's block_span is
 * generated from the two block columns beside it and is not repeated.
 *
 * Every instant is on the tenant's clock with its offset, the trail payload's three included,
 * the server writing each out as UTC text and this class reading it in the tenant's zone; the
 * instant columns are renamed where their name says UTC, start_utc reading starts_at. Dates and
 * wall-clock times carry no instant and are written as stored. The images are read before the
 * response begins, so a failure of the database or the disk answers an error and never a broken
 * download; a row naming a file the disk no longer has, or a path outside the tenant's own
 * directory, is skipped and named in export.json rather than failing the export.
 *
 * Every table is named by the tenant's schema and the users table by the central search path;
 * nothing of a row leaves this class but the archive, and a misshapen row is refused naming the
 * tenant and never a value.
 */
final readonly class TenantExport
{
    /** How an instant is spelled in the archive: RFC 3339 on the tenant's clock, its offset written out. */
    public const string INSTANT = DateTimeInterface::RFC3339;

    /** The table files in the archive, in the schema's order. */
    public const array TABLES = ['settings', 'payment_connections', 'services', 'staff', 'service_staff', 'shift_patterns', 'shift_overrides', 'blackouts', 'staff_days', 'bookings', 'customer_profiles', 'customer_notes', 'audit_log'];

    private const int JSON = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** Every table's two stamps. */
    private const array STAMPS = ['created_at', 'updated_at'];

    /** The keys of each file holding an instant, the two stamps beside them where a table has both. */
    private const array INSTANTS = [
        'settings' => ['hours_confirmed_at', 'link_copied_at', ...self::STAMPS],
        'payment_connections' => ['last_checked_at'],
        'services' => self::STAMPS,
        'staff' => self::STAMPS,
        'service_staff' => self::STAMPS,
        'shift_patterns' => self::STAMPS,
        'shift_overrides' => self::STAMPS,
        'blackouts' => self::STAMPS,
        'staff_days' => self::STAMPS,
        'bookings' => ['hold_expires_at', 'approved_at', ...self::STAMPS],
        'customer_profiles' => ['blocked_at', ...self::STAMPS],
        'customer_notes' => ['created_at'],
        'audit_log' => ['created_at'],
    ];

    /** A booking item's instants. */
    private const array ITEM_INSTANTS = ['starts_at', 'ends_at', 'block_starts_at', 'block_ends_at', 'processing_ends_at', ...self::STAMPS];

    /** A trail payload's instants, AuditEntry's spelling of each. */
    private const array PAYLOAD_INSTANTS = [AuditKey::HoldExpiresAt, AuditKey::StartsAt, AuditKey::MovedFrom];

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private TenantImages $images,
    ) {}

    /**
     * The archive's entries, each name with its bytes, the name it is downloaded under,
     * {slug}-{date}.zip with the tenant's today on the Clock port, and the export's instant on the
     * tenant's clock, read once for export.json, the name and the entries' time.
     *
     * @return array{file: string, at: DateTimeImmutable, entries: array<string, string>}
     */
    public function archive(Tenant $tenant): array
    {
        $zone = $tenant->zone();
        $now = $this->clock->now()->setTimezone($zone);
        $shape = RowShape::of('tenant export', $tenant);
        $row = $shape->row($this->db->connection()->selectOne(self::statement($tenant->schema_name), [$tenant->id]));

        $files = [];
        $members = self::decoded($row->members, $shape);

        foreach (self::TABLES as $table) {
            $value = $row->{$table};
            $files[$table] = match ($table) {
                'settings' => self::onClock(self::decoded($value, $shape), self::INSTANTS[$table], $zone, $shape),
                'bookings' => array_map(fn (array $booking): array => self::booking($booking, $zone, $shape), self::rows($value, $shape)),
                'audit_log' => array_map(fn (array $entry): array => self::entry($entry, $members, $zone, $shape), self::rows($value, $shape)),
                default => array_map(fn (array $one): array => self::onClock($one, self::INSTANTS[$table], $zone, $shape), self::rows($value, $shape)),
            };
        }

        $images = $this->images($tenant, self::imagePaths($files['settings'], $files['staff'], $shape));
        $entries = ['export.json' => json_encode([
            'business' => $tenant->name,
            'timezone' => $zone->getName(),
            'currency' => $tenant->currency,
            'exported_at' => $now->format(self::INSTANT),
            'images' => array_keys($images['found']),
            'missing_images' => $images['missing'],
        ], self::JSON)];

        foreach ($files as $table => $contents) {
            $entries[$table.'.json'] = json_encode($contents, self::JSON);
        }

        return ['file' => sprintf('%s-%s.zip', $tenant->slug, $now->format('Y-m-d')), 'at' => $now, 'entries' => [...$entries, ...$images['found']]];
    }

    /**
     * The one read: each file as a JSON value the server builds, every key named here and every
     * instant written out as UTC text. Services and staff are numbered by their place in their
     * files, sort and then insertion, and every reference to one is that number with the name; a
     * user is read from the central users table by the tenant's own rows alone.
     */
    private static function statement(string $schema): string
    {
        $service = self::object(['number' => 'service.number', 'name' => 'service.name']);
        $member = self::object(['number' => 'member.number', 'name' => 'member.name']);
        $items = self::object([
            'sort' => 'item.sort', 'service' => $service, 'staff' => $member,
            'starts_at' => self::instant('item.start_utc'), 'ends_at' => self::instant('item.end_utc'),
            'block_starts_at' => self::instant('item.block_start_utc'), 'block_ends_at' => self::instant('item.block_end_utc'),
            'processing_ends_at' => self::instant('item.processing_ends_utc'),
            'price_minor' => 'item.price_minor', 'blocks' => 'item.blocks',
            ...self::stamps('item'),
        ]);

        $files = [
            'settings' => '(select '.self::object([
                ...self::same(['checkout_ttl_min', 'approval_ttl_hours', 'payment_ttl_hours', 'reminder_lead_hours', 'granularity_min', 'start_interval_min', 'start_of_week', 'date_format', 'time_format', 'emails_off']),
                ...self::same(['brand_logo_path', 'about', 'address_line1', 'address_line2', 'city', 'postal_code', 'country', 'phone', 'public_email', 'website', 'instagram', 'facebook', 'google_reviews_url', 'important_info']),
                ...self::same(['cover_path', 'gallery_paths', 'lat', 'lng', 'map_path', 'map_state', 'language', 'colour_by', 'theme']),
                'hours_confirmed_at' => self::instant('r.hours_confirmed_at'), 'link_copied_at' => self::instant('r.link_copied_at'),
                ...self::stamps('r'),
            ]).' from '.$schema.'.settings r)',
            'payment_connections' => self::list(
                [...self::same(['provider', 'mode', 'state', 'account_ref']), 'last_checked_at' => self::instant('r.last_checked_at'), 'last_check_ok' => 'r.last_check_ok'],
                $schema.'.payment_connections r',
                'r.is_primary desc, r.id',
            ),
            'services' => self::list(
                ['number' => 'service.number', ...self::same(['name', 'description', 'category', 'duration_min', 'processing_gap_min', 'buffer_before_min', 'buffer_after_min', 'price_minor', 'currency', 'payment_mode', 'requires_approval', 'approval_hold_hours', 'on_approval_timeout', 'cancel_window_hours', 'reschedule_window_hours', 'lead_time_min', 'horizon_days', 'status', 'online', 'sort']), ...self::stamps('r')],
                $schema.'.services r join service_number service on service.id = r.id',
                'service.number',
            ),
            'staff' => self::list(
                ['number' => 'member.number', ...self::same(['name', 'email']), 'linked_user' => 'linked.email', ...self::same(['status', 'is_implicit', 'online', 'sort', 'title', 'bio', 'photo_path', 'colour']), ...self::stamps('r')],
                $schema.'.staff r join staff_number member on member.id = r.id left join tenant_user linked on linked.id = r.user_id',
                'member.number',
            ),
            'service_staff' => self::list(
                ['service' => $service, 'staff' => $member, ...self::stamps('r')],
                $schema.'.service_staff r join service_number service on service.id = r.service_id join staff_number member on member.id = r.staff_id',
                'service.number, member.number',
            ),
            'shift_patterns' => self::list(
                ['staff' => $member, ...self::same(['starts_on', 'cadence_weeks', 'ends_on', 'windows']), ...self::stamps('r')],
                $schema.'.shift_patterns r join staff_number member on member.id = r.staff_id',
                'member.number',
            ),
            'shift_overrides' => self::list(
                ['staff' => $member, ...self::same(['date', 'windows']), ...self::stamps('r')],
                $schema.'.shift_overrides r join staff_number member on member.id = r.staff_id',
                'member.number, r.date',
            ),
            'blackouts' => self::list(
                ['staff' => 'case when member.id is null then null else '.$member.' end', ...self::same(['date', 'start_time', 'end_time', 'reason']), ...self::stamps('r')],
                $schema.'.blackouts r left join staff_number member on member.id = r.staff_id',
                'r.date, member.number nulls first, r.start_time nulls first, r.id',
            ),
            'staff_days' => self::list(
                ['staff' => $member, ...self::same(['day', 'rev']), ...self::stamps('r')],
                $schema.'.staff_days r join staff_number member on member.id = r.staff_id',
                'member.number, r.day',
            ),
            'bookings' => self::list(
                [
                    'uuid' => 'r.uuid::text', ...self::same(['status', 'hold_class']), 'hold_expires_at' => self::instant('r.hold_expires_at'),
                    ...self::same(['customer_name', 'customer_email', 'customer_phone', 'total_minor', 'currency', 'payment_mode', 'requires_approval', 'on_approval_timeout']),
                    'approved_at' => self::instant('r.approved_at'), 'approved_by' => 'approver.email',
                    ...self::same(['rejection_reason', 'payment_ref', 'source', 'channel', 'language']), ...self::stamps('r'),
                    'items' => 'coalesce((select json_agg('.$items.' order by item.sort) from '.$schema.'.booking_items item'
                        .' join service_number service on service.id = item.service_id join staff_number member on member.id = item.staff_id'
                        .' where item.booking_id = r.id), \'[]\'::json)',
                    'meta' => 'coalesce((select json_object_agg(meta.key, meta.value order by meta.key) from '.$schema.'.booking_meta meta where meta.booking_id = r.id), \'{}\'::json)',
                ],
                $schema.'.bookings r left join tenant_user approver on approver.id = r.approved_by',
                'r.created_at, r.id',
            ),
            'customer_profiles' => self::list(
                [...self::same(['email', 'tags', 'birthday', 'language']), 'blocked_at' => self::instant('r.blocked_at'), 'blocked_reason' => 'r.blocked_reason', ...self::stamps('r')],
                $schema.'.customer_profiles r',
                'r.email',
            ),
            'customer_notes' => self::list(
                [...self::same(['email', 'body']), 'author' => self::actor('r.author_actor', 'author_user'), 'author_name' => 'r.author_name', 'created_at' => self::instant('r.created_at')],
                $schema.'.customer_notes r left join tenant_user author_user on r.author_actor = \'user:\' || author_user.id',
                'r.created_at, r.id',
            ),
            'audit_log' => self::list(
                ['booking' => 'booking.uuid::text', 'actor' => self::actor('r.actor', 'actor_user'), ...self::same(['action', 'payload']), 'created_at' => self::instant('r.created_at')],
                $schema.'.audit_log r left join '.$schema.'.bookings booking on booking.id = r.booking_id left join tenant_user actor_user on r.actor = \'user:\' || actor_user.id',
                'r.created_at, r.id',
            ),
        ];

        return 'with service_number as (select id, name, row_number() over (order by sort, id) as number from '.$schema.'.services),'
            .' staff_number as (select id, name, row_number() over (order by sort, id) as number from '.$schema.'.staff),'
            .' tenant_user as (select id, email::text as email from users where tenant_id = ?)'
            .' select '.implode(', ', array_map(fn (string $file, string $value): string => $value.'::text as '.$file, array_keys($files), $files))
            .', (select coalesce(json_object_agg(member.id, '.$member.'), \'{}\'::json) from staff_number member)::text as members';
    }

    /**
     * An actor as the archive names one: a user by the address the users table holds for them
     * (the joined alias given), support by the word alone, a job by its name, and any other actor
     * by its kind.
     */
    private static function actor(string $column, string $user): string
    {
        return sprintf(
            "case when %s ~ '%s' then %s when %s ~ '%s' then %s when %s ~ '%s' then %s else %s end",
            $column, Actor::USER_SHAPE, self::object(['kind' => "'user'", 'email' => $user.'.email']),
            $column, Actor::SUPPORT_SHAPE, self::object(['kind' => "'support'"]),
            $column, Actor::JOB_SHAPE, self::object(['kind' => "'job'", 'name' => 'substr('.$column.', 5)']),
            self::object(['kind' => $column]),
        );
    }

    /**
     * A file of rows: each row the object of its keys, in the order given, an empty table an
     * empty list.
     *
     * @param  array<string, string>  $pairs
     */
    private static function list(array $pairs, string $from, string $order): string
    {
        return '(select coalesce(json_agg('.self::object($pairs).' order by '.$order.'), \'[]\'::json) from '.$from.')';
    }

    /**
     * An object of the keys given, each with its expression.
     *
     * @param  array<string, string>  $pairs
     */
    private static function object(array $pairs): string
    {
        return 'json_build_object('.implode(', ', array_map(fn (string $key, string $value): string => "'".$key."', ".$value, array_keys($pairs), $pairs)).')';
    }

    /**
     * Columns of the row carried under their own names.
     *
     * @param  list<string>  $columns
     * @return array<string, string>
     */
    private static function same(array $columns): array
    {
        return array_combine($columns, array_map(fn (string $column): string => 'r.'.$column, $columns));
    }

    /**
     * A table's two stamps.
     *
     * @return array<string, string>
     */
    private static function stamps(string $alias): array
    {
        return ['created_at' => self::instant($alias.'.created_at'), 'updated_at' => self::instant($alias.'.updated_at')];
    }

    private static function instant(string $column): string
    {
        return SqlText::instantColumn($column);
    }

    /**
     * A booking as its file holds it, its instants and its items' on the tenant's clock and its intake an
     * object, an empty one where it has none.
     *
     * @param  array<mixed>  $booking
     * @return array<mixed>
     */
    private static function booking(array $booking, DateTimeZone $zone, RowShape $shape): array
    {
        $booking = self::onClock($booking, self::INSTANTS['bookings'], $zone, $shape);
        $booking['items'] = array_map(fn (array $item): array => self::onClock($item, self::ITEM_INSTANTS, $zone, $shape), self::rows($booking['items'] ?? null, $shape));
        $booking['meta'] = (object) self::decoded($booking['meta'] ?? null, $shape);

        return $booking;
    }

    /**
     * A trail row as its file holds it, its instant and its payload's on the tenant's clock, the
     * payload an object. A move by the business names the members it gave segments to by serial
     * id, staff_changed; each is carried as the member's number with the name, as every other
     * reference to a member is, and a member since deleted as null.
     *
     * @param  array<mixed>  $entry
     * @param  array<mixed>  $members  each member's reference keyed by its serial id
     * @return array<mixed>
     */
    private static function entry(array $entry, array $members, DateTimeZone $zone, RowShape $shape): array
    {
        $entry = self::onClock($entry, self::INSTANTS['audit_log'], $zone, $shape);
        $payload = self::onClock(self::decoded($entry['payload'] ?? null, $shape), array_map(fn (AuditKey $key): string => $key->value, self::PAYLOAD_INSTANTS), $zone, $shape);
        $changed = $payload[AuditKey::StaffChanged->value] ?? null;

        if (is_array($changed)) {
            $payload[AuditKey::StaffChanged->value] = array_map(fn (mixed $change): array => is_array($change) ? [
                'segment' => $change['segment'] ?? null,
                'from' => $members[$shape->int($change['from'] ?? null)] ?? null,
                'to' => $members[$shape->int($change['to'] ?? null)] ?? null,
            ] : throw $shape->misshapen(), $changed);
        }

        $entry['payload'] = (object) $payload;

        return $entry;
    }

    /**
     * Every stored path the rows name, in the order the archive takes them: the logo, the cover,
     * the gallery, the map, then each member's photo.
     *
     * @param  array<mixed>  $settings
     * @param  list<array<mixed>>  $staff
     * @return list<string>
     */
    private static function imagePaths(array $settings, array $staff, RowShape $shape): array
    {
        $paths = [$settings['brand_logo_path'] ?? null, $settings['cover_path'] ?? null];
        $gallery = $settings['gallery_paths'] ?? [];

        foreach (is_array($gallery) ? $gallery : throw $shape->misshapen() as $path) {
            $paths[] = $path;
        }

        $paths[] = $settings['map_path'] ?? null;

        foreach ($staff as $member) {
            $paths[] = $member['photo_path'] ?? null;
        }

        return array_values(array_unique(array_map(fn (mixed $path): string => $shape->string($path), array_filter($paths, fn (mixed $path): bool => $path !== null))));
    }

    /**
     * The bytes of every image the tenant's rows name and the disk holds, keyed by its stored
     * path, and the paths it does not hold or that are no file of the tenant's own directory.
     *
     * @param  list<string>  $paths
     * @return array{found: array<string, string>, missing: list<string>}
     */
    private function images(Tenant $tenant, array $paths): array
    {
        $found = [];
        $missing = [];

        foreach ($paths as $path) {
            $image = TenantImages::isOwn($tenant, $path) ? $this->images->read($tenant, $path) : null;

            if ($image === null) {
                $missing[] = $path;
            } else {
                $found[$path] = $image['contents'];
            }
        }

        return ['found' => $found, 'missing' => $missing];
    }

    /**
     * The row with each instant named read in the tenant's zone, a null one left null.
     *
     * @param  array<mixed>  $row
     * @param  list<string>  $keys
     * @return array<mixed>
     */
    private static function onClock(array $row, array $keys, DateTimeZone $zone, RowShape $shape): array
    {
        foreach ($keys as $key) {
            if (isset($row[$key])) {
                $row[$key] = SqlText::readInstant($shape->string($row[$key]))->setTimezone($zone)->format(self::INSTANT);
            }
        }

        return $row;
    }

    /**
     * A file's rows, each an object of the statement's.
     *
     * @return list<array<mixed>>
     */
    private static function rows(mixed $value, RowShape $shape): array
    {
        $rows = is_string($value) ? self::decoded($value, $shape) : $value;

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw $shape->misshapen();
        }

        return array_map(fn (mixed $row): array => is_array($row) ? $row : throw $shape->misshapen(), $rows);
    }

    /**
     * A JSON value the server built, or an object already decoded with the file it sits in.
     *
     * @return array<mixed>
     */
    private static function decoded(mixed $value, RowShape $shape): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? $decoded : throw $shape->misshapen();
    }
}
