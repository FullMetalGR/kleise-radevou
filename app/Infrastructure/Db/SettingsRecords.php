<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Map\Coordinates;
use App\Infrastructure\Storage\TenantImages;
use App\Tenancy\MapState;
use App\Tenancy\Profile;
use App\Tenancy\Settings;
use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

/**
 * The writes of the Settings screen: the whole patch, which is the tenant row's currency beside
 * the settings row's own columns, landing in one transaction so a refused currency cannot leave
 * the holds half-written; the image columns, the logo's path, the cover's and the gallery's, each
 * written on its own by its upload and its removal, each answering what its caller needs to delete
 * the file the row let go or never took; the map's columns, which the patch writes where an
 * address column changes, deleting the file of a map it drops itself once it has committed, which
 * the map's job settles, settleMap answering the path the row held for the job to delete, and
 * which the map's backfill marks pending; and the link marker, written by the press of a copy
 * button.
 *
 * The currency is the tenant row's: the default every new service inherits at creation, and
 * changing it restates no existing row, which is why nothing here touches services or bookings.
 * The off list is written as the negative it is stored as, and whatever keys the body carried,
 * known to this build or not: a key this build does not know is one a later release will, or an
 * earlier one did, and refusing it would lock the tenant out of saving anything at all.
 *
 * The instants are the database's own now(), never a bound DateTimeInterface.
 */
final readonly class SettingsRecords
{
    /** The map's columns as a dropped map leaves them: no path and no point. */
    private const array NO_MAP = ['lat' => null, 'lng' => null, 'map_path' => null];

    public function __construct(private DatabaseManager $db, private TenantImages $images) {}

    /**
     * The whole patch. The address moves the map: the row's address and its map's path are read
     * under a lock before the write, and when the write changes any address column the map's state
     * is written beside them, pending where the key is set and the new address has a line to
     * geocode, else none. None drops the map the row held, its path and its point written null in
     * the same write and its file deleted once the write has committed, so the page never shows the
     * map of a former address; pending keeps it until the job settles, the page drawing it on ready
     * alone. The answer is whether a map is owed, which the caller queues once this transaction has
     * committed, so the job never reads an address the save has not landed. A save that changes no
     * address column leaves the map as it stands. The language is written where the body named one,
     * settings.language.
     *
     * @param  array{theme: string, language: string|null, checkout_ttl_min: int, approval_ttl_hours: int, payment_ttl_hours: int, reminder_lead_hours: int, start_interval_min: int, emails_off: list<string>, profile: array<string, string|null>}  $columns
     * @param  bool  $mapping  whether the map's key is set
     */
    public function update(Tenant $tenant, string $currency, array $columns, bool $mapping): bool
    {
        $connection = $this->db->connection();

        [$owed, $dropped] = $connection->transaction(function () use ($connection, $tenant, $currency, $columns, $mapping): array {
            ['address' => $address, 'path' => $path] = $this->lockedMap($tenant);
            $written = [...$address, ...array_intersect_key($columns['profile'], $address)];
            $state = null;

            if ($written !== $address) {
                $state = $mapping && Profile::of($written)->addressLines() !== [] ? MapState::Pending : MapState::None;
            }

            $connection->table('tenants')->where('id', $tenant->id)->update(['currency' => $currency, 'updated_at' => $connection->raw('now()')]);
            $this->settings($tenant)->update([
                'theme' => $columns['theme'],
                ...($columns['language'] === null ? [] : ['language' => $columns['language']]),
                'checkout_ttl_min' => $columns['checkout_ttl_min'],
                'approval_ttl_hours' => $columns['approval_ttl_hours'],
                'payment_ttl_hours' => $columns['payment_ttl_hours'],
                'reminder_lead_hours' => $columns['reminder_lead_hours'],
                'start_interval_min' => $columns['start_interval_min'],
                'emails_off' => TextArray::encode($columns['emails_off']),
                ...$columns['profile'],
                ...match ($state) {
                    null => [],
                    MapState::None => ['map_state' => $state->value, ...self::NO_MAP],
                    default => ['map_state' => $state->value],
                },
                'updated_at' => $connection->raw('now()'),
            ]);

            return [$state === MapState::Pending, $state === MapState::None ? $path : null];
        });

        if ($dropped !== null) {
            $this->images->delete($tenant, $dropped);
        }

        return $owed;
    }

    /**
     * Writes pending, under the row's lock, on a row holding an address and no ready map, and
     * answers whether it did: the row the map's backfill finds (BackfillMapsCommand). No ready map
     * is any state but ready, or ready naming no image: none, its address saved before the map
     * existed or while the key was unset; failed, a job having found no map or thrown; pending, its
     * job gone, a throw having left it before this build settled every throw, its job's re-read
     * having thrown past the queue's retries, or its failed settle having been refused too; and
     * ready with no path, which only a hand's write leaves. A row whose map stands, or which holds
     * no address, is left as it is. The caller queues the job once this has committed, as the
     * settings save does.
     */
    public function markMapPending(Tenant $tenant): bool
    {
        $connection = $this->db->connection();

        return $connection->transaction(function () use ($tenant): bool {
            $row = $this->settings($tenant)->lockForUpdate()->first(['map_state', 'map_path', ...Profile::ADDRESS]);

            if ($row === null || ($row->map_state === MapState::Ready->value && is_string($row->map_path)) || Profile::of(self::addressOf($row))->addressLines() === []) {
                return false;
            }

            $this->settings($tenant)->update(['map_state' => MapState::Pending->value]);

            return true;
        });
    }

    /**
     * Settles the map a job made, under a row lock and only while the row still waits on it:
     * pending, with the address the job geocoded. A point and a path write the pair, the path and
     * ready in one write; none writes failed and drops the map the row held, its path and its
     * point written null, the caller deleting its file. A later save having moved the state or
     * the address, nothing is written, that save's own job settling the row. The answer is
     * whether it wrote, beside the path the row held as the lock found it, so the caller can
     * delete the file the row forgot, or the one it never took.
     *
     * The map's own write records nothing the owner configured, so updated_at stays as the save
     * left it.
     *
     * @param  list<string>  $lines  the address lines the job geocoded
     * @param  array{point: Coordinates, path: string}|null  $made
     * @return array{written: bool, previous: string|null}
     */
    public function settleMap(Tenant $tenant, array $lines, ?array $made): array
    {
        $connection = $this->db->connection();

        return $connection->transaction(function () use ($tenant, $lines, $made): array {
            $row = $this->settings($tenant)->lockForUpdate()->first(['map_state', 'map_path', ...Profile::ADDRESS]);
            $previous = is_string($row?->map_path) ? $row->map_path : null;

            if ($row === null || $row->map_state !== MapState::Pending->value || Profile::of(self::addressOf($row))->addressLines() !== $lines) {
                return ['written' => false, 'previous' => $previous];
            }

            $this->settings($tenant)->update($made === null
                ? ['map_state' => MapState::Failed->value, ...self::NO_MAP]
                : ['lat' => $made['point']->lat, 'lng' => $made['point']->lng, 'map_path' => $made['path'], 'map_state' => MapState::Ready->value]);

            return ['written' => true, 'previous' => $previous];
        });
    }

    /**
     * Records the logo's path, or null for none, and answers the path it replaced so the caller
     * can delete that file. Read and written under a row lock, so two uploads racing each other
     * each learn what the other left and no file is orphaned by the row forgetting it.
     */
    public function setLogoPath(Tenant $tenant, ?string $path): ?string
    {
        return $this->swapPath($tenant, 'brand_logo_path', $path);
    }

    /** Records the cover's path, or null for none, and answers the path it replaced, as the logo's does. */
    public function setCoverPath(Tenant $tenant, ?string $path): ?string
    {
        return $this->swapPath($tenant, 'cover_path', $path);
    }

    /**
     * Appends a photo's path to the gallery, after the others, unless the gallery is full or
     * already holds that path, a photo uploaded twice storing the same bytes under the same name;
     * answers the gallery as the lock found it, so the caller knows which of the three it was.
     * Under a row lock, so two uploads racing for the last place cannot both take it.
     *
     * @return list<string>
     */
    public function addGalleryPath(Tenant $tenant, string $path): array
    {
        $connection = $this->db->connection();

        return $connection->transaction(function () use ($connection, $tenant, $path): array {
            $held = $this->lockedGallery($tenant);

            if (count($held) < Settings::GALLERY_CAPACITY && ! in_array($path, $held, true)) {
                $this->settings($tenant)->update(['gallery_paths' => TextArray::encode([...$held, $path]), 'updated_at' => $connection->raw('now()')]);
            }

            return $held;
        });
    }

    /**
     * Takes the gallery's path stored under a file name out of the gallery, the others keeping
     * their order, and answers it so the caller can delete the file; null when the gallery holds
     * no such name, and then nothing is written.
     */
    public function removeGalleryPhoto(Tenant $tenant, string $file): ?string
    {
        $connection = $this->db->connection();

        return $connection->transaction(function () use ($connection, $tenant, $file): ?string {
            $held = $this->lockedGallery($tenant);
            $kept = array_values(array_filter($held, fn (string $path): bool => TenantImages::fileOf($path) !== $file));

            if (count($kept) === count($held)) {
                return null;
            }

            $this->settings($tenant)->update(['gallery_paths' => TextArray::encode($kept), 'updated_at' => $connection->raw('now()')]);

            return array_values(array_diff($held, $kept))[0] ?? null;
        });
    }

    /**
     * Stamps the link marker: the booking link was copied from the admin. Written each time
     * and never cleared; it records a press and configures nothing, so the row's updated_at
     * stays as the last configuration left it.
     */
    public function markLinkCopied(Tenant $tenant): void
    {
        $this->settings($tenant)->update(['link_copied_at' => $this->db->connection()->raw('now()')]);
    }

    /**
     * Writes how every calendar grid colours its blocks: one word of Settings::COLOUR_BY,
     * which the column's CHECK holds as well. A configuration write, so updated_at moves
     * with it.
     */
    public function setColourBy(Tenant $tenant, string $colourBy): void
    {
        $this->settings($tenant)->update(['colour_by' => $colourBy, 'updated_at' => $this->db->connection()->raw('now()')]);
    }

    /** Writes an image column under a row lock and answers the path it held before. */
    private function swapPath(Tenant $tenant, string $column, ?string $path): ?string
    {
        $connection = $this->db->connection();

        return $connection->transaction(function () use ($connection, $tenant, $column, $path): ?string {
            $previous = $this->settings($tenant)->lockForUpdate()->value($column);
            $this->settings($tenant)->update([$column => $path, 'updated_at' => $connection->raw('now()')]);

            return is_string($previous) ? $previous : null;
        });
    }

    /**
     * The address's columns and the map's path under a row lock, inside the caller's transaction.
     *
     * @return array{address: array<string, string|null>, path: string|null}
     */
    private function lockedMap(Tenant $tenant): array
    {
        $row = $this->settings($tenant)->lockForUpdate()->first([...Profile::ADDRESS, 'map_path']);

        return [
            'address' => $row === null ? array_fill_keys(Profile::ADDRESS, null) : self::addressOf($row),
            'path' => is_string($row?->map_path) ? $row->map_path : null,
        ];
    }

    /**
     * The address's columns off a row read with them, each a string or null.
     *
     * @return array<string, string|null>
     */
    private static function addressOf(object $row): array
    {
        $address = [];

        foreach (Profile::ADDRESS as $column) {
            $value = $row->{$column} ?? null;
            $address[$column] = is_string($value) ? $value : null;
        }

        return $address;
    }

    /**
     * The gallery's paths under a row lock, inside the caller's transaction.
     *
     * @return list<string>
     */
    private function lockedGallery(Tenant $tenant): array
    {
        $literal = $this->settings($tenant)->lockForUpdate()->selectRaw('gallery_paths::text as gallery_paths')->value('gallery_paths');

        return is_string($literal) ? TextArray::decode($literal) : [];
    }

    private function settings(Tenant $tenant): Builder
    {
        return $this->db->connection()->table($tenant->schema_name.'.settings')->where('id', 1);
    }
}
