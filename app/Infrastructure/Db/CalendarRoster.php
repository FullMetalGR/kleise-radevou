<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Domain\Enum\BookingStatus;
use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;

/**
 * What the Calendar's filter card and legend are built from, the members Who resolves to, and the
 * staff row a viewer's Me stands for, the row a viewer holding viewOwnCalendar alone is pinned
 * to.
 *
 * The staff offered are the active members alone, the ones Who can pick and a new booking's
 * prefill can name; the implicit row is counted toward the one-value rule and never offered,
 * since it stands for the business, though Scheduled and All draw its bookings (implicitId). The
 * services are the full catalog with whether each is active,
 * because this cut narrows history. The count of every staff row is the rows half of the
 * one-value rule. The categories are the catalogue's distinct ones, sorted, a blank category none
 * of them: Colour by's category hue is its place in that list, so a category keeps its hue
 * whatever window is read.
 */
final readonly class CalendarRoster
{
    public function __construct(private DatabaseManager $db) {}

    /**
     * @return array{statuses: list<string>, staff: list<array{id: int, name: string}>, services: list<array{id: int, name: string, active: bool}>, categories: list<string>, members: int}
     */
    public function options(Tenant $tenant): array
    {
        $staff = $this->db->connection()->table($tenant->schema_name.'.staff')
            ->orderBy('sort')->orderBy('id')->get(['id', 'name', 'status', 'is_implicit'])->all();

        $members = RowShape::of('staff', $tenant);
        $active = [];

        foreach ($staff as $member) {
            if ($member->is_implicit !== true && $member->status === 'active') {
                $active[] = ['id' => $members->int($member->id), 'name' => $members->string($member->name)];
            }
        }

        return $this->assembled($tenant, $active, count($staff));
    }

    /**
     * The options of a viewer pinned to their own row: the staff offered are that row alone, or
     * none where no row is linked, and nobody else's name is read; the count of every row is the
     * one-value rule's as for anybody.
     *
     * @param  array{id: int, name: string}|null  $own
     * @return array{statuses: list<string>, staff: list<array{id: int, name: string}>, services: list<array{id: int, name: string, active: bool}>, categories: list<string>, members: int}
     */
    public function pinnedOptions(Tenant $tenant, ?array $own): array
    {
        return $this->assembled($tenant, $own === null ? [] : [$own], $this->members($tenant));
    }

    /**
     * @param  list<array{id: int, name: string}>  $staff
     * @return array{statuses: list<string>, staff: list<array{id: int, name: string}>, services: list<array{id: int, name: string, active: bool}>, categories: list<string>, members: int}
     */
    private function assembled(Tenant $tenant, array $staff, int $members): array
    {
        $services = $this->db->connection()->table($tenant->schema_name.'.services')
            ->orderBy('sort')->orderBy('id')->get(['id', 'name', 'status', 'category'])->all();
        $offered = RowShape::of('services', $tenant);
        $catalog = [];
        $categories = [];

        foreach ($services as $row) {
            $catalog[] = ['id' => $offered->int($row->id), 'name' => $offered->string($row->name), 'active' => $row->status === 'active'];

            if ($row->category !== null) {
                $categories[] = $offered->string($row->category);
            }
        }

        $categories = array_values(array_unique($categories));
        sort($categories, SORT_STRING);

        return [
            'statuses' => array_map(fn (BookingStatus $status): string => $status->value, BookingStatus::cases()),
            'staff' => $staff,
            'services' => $catalog,
            'categories' => $categories,
            'members' => $members,
        ];
    }

    /**
     * Every active member but the implicit row, in roster order: All staff, and the members
     * Scheduled staff is resolved among.
     *
     * @return list<array{id: int, name: string}>
     */
    public function active(Tenant $tenant): array
    {
        return $this->named($tenant, null);
    }

    /**
     * The members of the ids picked that exist, the implicit row never among them, in roster
     * order; with null, the active members alone.
     *
     * @param  list<int>|null  $ids
     * @return list<array{id: int, name: string}>
     */
    public function named(Tenant $tenant, ?array $ids): array
    {
        $query = $this->db->connection()->table($tenant->schema_name.'.staff')->where('is_implicit', false);
        $query = $ids === null ? $query->where('status', 'active') : $query->whereRaw('id = any(?::bigint[])', [SqlText::bigintArray($ids)]);
        $shape = RowShape::of('staff', $tenant);

        return array_values(array_map(
            fn (object $row): array => ['id' => $shape->int($row->id), 'name' => $shape->string($row->name)],
            $query->orderBy('sort')->orderBy('id')->get(['id', 'name'])->all(),
        ));
    }

    /**
     * The staff row a user of this tenant is linked to, the row whose user_id is theirs, or null
     * where none is: the row Me stands for. A viewer with no user behind them, support acting in
     * a session, has no row and reads nothing.
     *
     * @return array{id: int, name: string}|null
     */
    public function ownRow(Tenant $tenant, ?int $userId): ?array
    {
        if ($userId === null) {
            return null;
        }

        $row = $this->db->connection()->table($tenant->schema_name.'.staff')
            ->where('user_id', $userId)
            ->orderBy('id')
            ->first(['id', 'name']);

        $shape = RowShape::of('staff', $tenant);

        return $row === null ? null : ['id' => $shape->int($row->id), 'name' => $shape->string($row->name)];
    }

    /**
     * The implicit row's id, the member standing for the business, whose bookings Scheduled staff
     * and All staff draw although it is never offered or listed; null where the tenant has none.
     */
    public function implicitId(Tenant $tenant): ?int
    {
        $id = $this->db->connection()->table($tenant->schema_name.'.staff')->where('is_implicit', true)->orderBy('id')->value('id');

        return $id === null ? null : RowShape::of('staff', $tenant)->int($id);
    }

    /**
     * Each service's category by its id, null where it has none: what the Calendar's items carry
     * beside the service's name for Colour by.
     *
     * @return array<int, string|null>
     */
    public function categories(Tenant $tenant): array
    {
        $shape = RowShape::of('services', $tenant);
        $categories = [];

        foreach ($this->db->connection()->table($tenant->schema_name.'.services')->get(['id', 'category'])->all() as $row) {
            $categories[$shape->int($row->id)] = $row->category === null ? null : $shape->string($row->category);
        }

        return $categories;
    }

    /** The rows every staff row of the tenant, active or not, the count the one-value rule reads. */
    public function members(Tenant $tenant): int
    {
        return $this->db->connection()->table($tenant->schema_name.'.staff')->count();
    }
}
