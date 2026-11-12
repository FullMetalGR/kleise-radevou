<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Checkpoint;
use App\Infrastructure\Storage\ImageKind;
use App\Infrastructure\Storage\ImageRefused;
use App\Infrastructure\Storage\TenantImages;
use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use LogicException;
use Symfony\Component\HttpFoundation\File\File;

/**
 * The staff as their owner reads and writes them: every row whatever its status, the implicit
 * member among them as an ordinary row keeping its flag, each with the services it performs, its
 * repeating pattern as stored (the Staff page shows it read-only and Shifts edits it) and its
 * personal closures; and the writes. The links are replaced whole on every save. The implicit
 * member's week, Opening hours (replaceWeek), is a weekly pattern replaced whole, so an omitted
 * day is a closed day; a week holding a window stamps the settings row's hours marker in the same
 * transaction, and an empty one leaves it as it was.
 *
 * A save is one transaction: the row and the links land together or not at all. The delete guard
 * is the foreign key on booking_items.staff_id, migration 000013, read as StaffReferenced, in the
 * shape ServiceRecords::delete has: one delete statement in a transaction of its own, refused by
 * the key or waiting behind an item a hold has inserted and not committed. The implicit member is
 * refused before that statement, ImplicitStaffKept: the flag is read in the same transaction, and
 * since nothing writes the flag after provisioning the read cannot go stale between it and the
 * delete. The pattern, the overrides and the links follow the row out through their own cascading
 * keys.
 *
 * The staff photo's file lives here with its column: two members given the same photo hold one
 * file under one name, so a file is deleted only once no row names it, and that decision is the
 * one place two writers of one file can cross. Every staff photo write, an upload, a removal and
 * the delete of a member holding a photo, therefore runs in one transaction that locks the
 * member's row and then the tenant's staff photo lock, and under both stores its file, writes its
 * row, decides whether the file it let go is named by any row and deletes it if not, so no row
 * names a file the disk lacks and no upload leaves a file behind a delete. That decision rests on
 * READ COMMITTED, PostgreSQL's default, which this class neither sets nor asserts: each statement
 * reads a snapshot of its own, so the read after the lock's wait sees the commit that released the
 * lock, where REPEATABLE READ would read the transaction's first snapshot, taken before the wait,
 * and delete a file another member was just given. The file goes before the commit, since the lock
 * goes with it; a commit failing after that is the one way left to a row naming a missing file,
 * which the image route answers 404 and a re-upload heals.
 *
 * The row lock is FOR NO KEY UPDATE, which a booking item's key share on the row does not wait
 * for, and a delete takes the photo lock before its own delete statement, so no hold waits on a
 * photo write: a hold on a member being deleted waits on that delete alone, from its statement to
 * its commit, as on any delete, and the statement still waits behind an uncommitted item as above,
 * the tenant's other photo writes waiting with it when the member holds a photo. A photo write may
 * wait on a hold, never a hold on a photo write. The row lock carries a wait of its own: a
 * member's own save, deactivation and delete stand behind that member's photo upload, through the
 * image's decode.
 */
final readonly class StaffRecords
{
    /**
     * Reached by a staff photo write once its file and row writes are done and its orphan decided,
     * before its transaction commits: where a second write of the tenant's staff photos stands
     * behind the first, tests/Concurrency/StaffPhotoRaceTest.php.
     */
    public const string PHOTO_SETTLED = 'staff.photo.settled';

    /**
     * The class half of the staff photo lock's advisory key, apart from the sweeper's and the
     * concurrency harness's classes; the other half is the tenant's central id, so nothing is
     * hashed and no two tenants share one (PostgresSweepBatch says why both halves are plain
     * integers).
     */
    public const int PHOTO_LOCK_CLASS = 3400;

    /**
     * The columns a save of the implicit member leaves as stored, a value saved before the rule
     * included; its photo stands apart, the photo's routes answering not-found for it (takesPhoto).
     */
    private const array FACELESS = ['title', 'bio'];

    /**
     * The member row's lock that every photo write and every delete take, the delete of a member
     * with no photo included: one a booking item's key share does not wait on.
     */
    private const string ROW_LOCK = 'for no key update';

    public function __construct(
        private DatabaseManager $db,
        private ServiceRecords $services,
        private BlackoutRecords $blackouts,
        private TenantImages $images,
    ) {}

    /**
     * Every member in the roster's order, in the wire's shape but for the stored photo_path,
     * which StaffController::withPhoto turns into the photo the wire carries.
     *
     * @return list<array{id: int, name: string, email: string|null, status: string, online: bool, is_implicit: bool, user_id: int|null, title: string|null, bio: string|null, photo_path: string|null, service_ids: list<int>, pattern: array{cadence_weeks: int, starts_on: string, ends_on: string|null, windows: list<array{weekday: int, start: string, end: string}>}|null, blackouts: list<array{date: string, start: string|null, end: string|null, reason: string|null}>, colour: string|null}>
     */
    public function all(Tenant $tenant): array
    {
        $links = [];

        foreach ($this->table($tenant, 'service_staff')->orderBy('service_id')->get(['staff_id', 'service_id']) as $link) {
            $links[BlackoutRecords::int($link->staff_id)][] = BlackoutRecords::int($link->service_id);
        }

        $patterns = WeeklyPattern::stored($this->db->connection(), $tenant->schema_name.'.');
        $closures = $this->blackouts->perMember($tenant);
        $members = [];

        foreach ($this->table($tenant, 'staff')->orderBy('sort')->orderBy('id')->get(['id', 'name', 'email', 'status', 'online', 'is_implicit', 'user_id', 'title', 'bio', 'photo_path', 'colour']) as $member) {
            $id = BlackoutRecords::int($member->id);
            $members[] = [
                'id' => $id,
                'name' => BlackoutRecords::string($member->name),
                'email' => is_string($member->email) ? $member->email : null,
                'status' => BlackoutRecords::string($member->status),
                'online' => (bool) $member->online,
                'is_implicit' => (bool) $member->is_implicit,
                'user_id' => $member->user_id === null ? null : BlackoutRecords::int($member->user_id),
                'title' => is_string($member->title) ? $member->title : null,
                'bio' => is_string($member->bio) ? $member->bio : null,
                'photo_path' => is_string($member->photo_path) ? $member->photo_path : null,
                'service_ids' => $links[$id] ?? [],
                'pattern' => $patterns[$id] ?? null,
                'blackouts' => $closures[$id] ?? [],
                'colour' => is_string($member->colour) ? $member->colour : null,
            ];
        }

        return $members;
    }

    /**
     * Writes a member's own calendar colour, one word of StaffColour or null for Automatic. False
     * where no such member exists.
     */
    public function setColour(Tenant $tenant, int $id, ?string $colour): bool
    {
        return $this->table($tenant, 'staff')->where('id', $id)->update(['colour' => $colour, 'updated_at' => $this->db->connection()->raw('now()')]) === 1;
    }

    /**
     * Every member who picked a colour, by id, Automatic's left out: what the Calendar and the
     * Shifts screen draw a member's hue from beside the spread.
     *
     * @return list<array{id: int, colour: string}>
     */
    public function colours(Tenant $tenant): array
    {
        return array_values(array_map(
            fn (object $row): array => ['id' => BlackoutRecords::int($row->id ?? null), 'colour' => BlackoutRecords::string($row->colour ?? null)],
            $this->table($tenant, 'staff')->whereNotNull('colour')->orderBy('id')->get(['id', 'colour'])->all(),
        ));
    }

    /**
     * How many members are active, the implicit one among them while it is, which is the count
     * the downgrade refusal names.
     */
    public function activeCount(Tenant $tenant): int
    {
        return $this->table($tenant, 'staff')->where('status', 'active')->count();
    }

    public function exists(Tenant $tenant, int $id): bool
    {
        return $this->table($tenant, 'staff')->where('id', $id)->exists();
    }

    /**
     * Inserts a member after the last one in the roster's order, with their links, and answers
     * the id. The member starts with no pattern, their hours set on Shifts.
     *
     * @param  array<string, mixed>  $columns
     * @param  list<int>  $serviceIds
     */
    public function insert(Tenant $tenant, array $columns, array $serviceIds): int
    {
        return $this->db->connection()->transaction(function () use ($tenant, $columns, $serviceIds): int {
            $sort = $this->table($tenant, 'staff')->max('sort');
            $id = $this->table($tenant, 'staff')->insertGetId([...$columns, 'sort' => (is_int($sort) ? $sort : 0) + 1]);
            $this->services->replaceMemberServices($tenant, $id, $serviceIds);

            return $id;
        });
    }

    /**
     * Writes the columns given onto the row, replaces the links where a list was given, and
     * answers whether the row existed. The implicit member's title and bio are
     * ignored, where its photo's routes answer not-found (takesPhoto): that row stands for the
     * business, which shows neither anywhere, so a body naming them writes the rest and leaves
     * both as stored (P9F-L35-b). The flag is read in the same transaction; nothing writes it
     * after provisioning.
     *
     * @param  array<string, mixed>  $columns
     * @param  list<int>|null  $serviceIds
     */
    public function update(Tenant $tenant, int $id, array $columns, ?array $serviceIds): bool
    {
        return $this->db->connection()->transaction(function () use ($tenant, $id, $columns, $serviceIds): bool {
            $implicit = $this->table($tenant, 'staff')->where('id', $id)->value('is_implicit');

            if ($implicit === null) {
                return false;
            }

            if ((bool) $implicit) {
                $columns = array_diff_key($columns, array_flip(self::FACELESS));
            }

            if ($columns !== []) {
                $this->table($tenant, 'staff')->where('id', $id)->update([...$columns, 'updated_at' => $this->db->connection()->raw('now()')]);
            }

            if ($serviceIds !== null) {
                $this->services->replaceMemberServices($tenant, $id, $serviceIds);
            }

            return true;
        });
    }

    /**
     * Deletes the row and answers whether it existed; refuses, with ImplicitStaffKept, the
     * implicit member, and with StaffReferenced a member any booking item names, on the terms
     * the class comment gives. The member's photo goes with them unless another row holds the
     * same file: its path is read under the row's lock in the delete's own transaction, so an
     * upload cannot land between the read and the delete, and the staff photo lock is taken
     * before the delete statement, so the file is let go under it as every photo write's is and a
     * hold's item meets this delete alone, never a photo write it would wait behind.
     *
     * @throws ImplicitStaffKept
     * @throws StaffReferenced
     */
    public function delete(Tenant $tenant, int $id): bool
    {
        try {
            return $this->db->connection()->transaction(function () use ($tenant, $id): bool {
                $row = $this->table($tenant, 'staff')->where('id', $id)->lock(self::ROW_LOCK)->first(['is_implicit', 'photo_path']);

                if ($row === null) {
                    return false;
                }

                if ((bool) $row->is_implicit) {
                    throw new ImplicitStaffKept($id);
                }

                $photo = is_string($row->photo_path) ? $row->photo_path : null;

                if ($photo !== null) {
                    $this->lockPhotos($tenant);
                }

                $deleted = $this->table($tenant, 'staff')->where('id', $id)->delete() === 1;

                if ($deleted && $photo !== null) {
                    $this->forgetPhoto($tenant, $photo);
                    Checkpoint::reach(self::PHOTO_SETTLED);
                }

                return $deleted;
            });
        } catch (QueryException $exception) {
            if (SqlState::codeOf($exception) === StaffReferenced::SQLSTATE) {
                throw new StaffReferenced($id, $exception);
            }

            throw $exception;
        }
    }

    /**
     * Whether the id names a member who can have a photo: any row but the implicit one, which
     * stands for the business and has no face.
     */
    public function takesPhoto(Tenant $tenant, int $id): bool
    {
        return $this->table($tenant, 'staff')->where('id', $id)->where('is_implicit', false)->exists();
    }

    /**
     * Stores the photo given for a member other than the implicit one, through the tenant image
     * store at the staff photo's own edge, and writes its path onto the row, or clears the photo
     * with null; the file the row named before goes unless another row holds it. Answers whether
     * the member was found: for none, nothing is stored. All of it under the row's lock and the
     * staff photo lock, the class comment's terms.
     *
     * @throws ImageRefused when the bytes are not an image the store takes; nothing is written
     */
    public function swapPhoto(Tenant $tenant, int $id, ?File $photo): bool
    {
        $connection = $this->db->connection();

        return $connection->transaction(function () use ($connection, $tenant, $id, $photo): bool {
            $row = $this->table($tenant, 'staff')->where('id', $id)->where('is_implicit', false)->lock(self::ROW_LOCK)->first(['photo_path']);

            if ($row === null) {
                return false;
            }

            $this->lockPhotos($tenant);
            $path = $photo === null ? null : $this->images->store($tenant, ImageKind::StaffPhoto, $photo);
            $this->table($tenant, 'staff')->where('id', $id)->update(['photo_path' => $path, 'updated_at' => $connection->raw('now()')]);

            if (is_string($row->photo_path) && $row->photo_path !== $path) {
                $this->forgetPhoto($tenant, $row->photo_path);
            }

            Checkpoint::reach(self::PHOTO_SETTLED);

            return true;
        });
    }

    /**
     * Every photo path a row holds, the reader GET /images/{file} asks for the staff photo's
     * kind: every member's, whatever their status or flag, since the admin shows an inactive
     * member's photo too.
     *
     * @return list<string>
     */
    public function photoPaths(Tenant $tenant): array
    {
        return array_values(array_filter(
            $this->table($tenant, 'staff')->whereNotNull('photo_path')->distinct()->pluck('photo_path')->all(),
            is_string(...),
        ));
    }

    /**
     * The implicit member's usual week, the windows of their pattern: the week Opening hours edits
     * while the staff feature is absent.
     *
     * @return list<array{weekday: int, start: string, end: string}>
     */
    public function week(Tenant $tenant): array
    {
        $implicitId = $this->implicitId($tenant);

        return WeeklyPattern::stored($this->db->connection(), $tenant->schema_name.'.', [$implicitId])[$implicitId]['windows'] ?? [];
    }

    /**
     * Replace-all over the implicit member's week, PUT /admin/opening-hours: a weekly pattern of
     * the windows given from the one start (WeeklyPattern::replace), or none for an empty week.
     * The route names no member, so the row is found by its flag inside the same transaction that
     * writes it. A week holding a window stamps the hours marker in the same transaction: every
     * write of a week with hours confirms the opening hours, each time and never cleared. An
     * empty week stamps nothing, so the setup checklist's hours item stays open until a week with
     * hours is saved (P9F-L29-b). The instant is the database's own now(). The overrides are left
     * as they are.
     *
     * @param  list<array{weekday: int, start: string, end: string}>  $windows
     */
    public function replaceWeek(Tenant $tenant, array $windows): void
    {
        $this->db->connection()->transaction(function () use ($tenant, $windows): void {
            WeeklyPattern::replace($this->db->connection(), $tenant->schema_name.'.', $this->implicitId($tenant), $windows);
            WeeklyPattern::confirmHours($this->db->connection(), $tenant->schema_name.'.', $windows !== []);
        });
    }

    /**
     * Takes the tenant's staff photo lock for the rest of the transaction: transaction-scoped,
     * never session-scoped, so it goes with the commit or the rollback and cannot outlive the
     * write that took it on a pooled connection.
     */
    private function lockPhotos(Tenant $tenant): void
    {
        $this->db->connection()->statement('select pg_advisory_xact_lock(?, ?)', [self::PHOTO_LOCK_CLASS, $tenant->id]);
    }

    /**
     * Deletes a staff photo's file once no row names it, under the staff photo lock the caller
     * holds: an upload naming the same file either committed before this read, which then sees
     * it, or waits on the lock and stores the file again after this delete. The read sees that
     * commit because READ COMMITTED gives each statement a snapshot of its own (the class comment).
     */
    private function forgetPhoto(Tenant $tenant, string $path): void
    {
        if (! $this->table($tenant, 'staff')->where('photo_path', $path)->exists()) {
            $this->images->delete($tenant, $path);
        }
    }

    /**
     * The one row that stands for the business, which provisioning writes and nothing deletes.
     */
    private function implicitId(Tenant $tenant): int
    {
        $id = $this->table($tenant, 'staff')->where('is_implicit', true)->orderBy('id')->value('id');

        if ($id === null) {
            throw new LogicException(sprintf('Tenant "%s" has no implicit staff member; provisioning inserts one.', $tenant->slug));
        }

        return BlackoutRecords::int($id);
    }

    private function table(Tenant $tenant, string $name): Builder
    {
        return $this->db->connection()->table($tenant->schema_name.'.'.$name);
    }
}
