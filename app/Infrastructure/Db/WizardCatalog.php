<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;
use LogicException;

/**
 * What the manual booking wizard chooses from, read for the shared props: the active services
 * in catalog order, each with the duration and the processing tail the summary's end is
 * computed from and the active members who perform it; the active named members the Staff
 * select offers, the implicit row left out because it is never rendered; and how many staff
 * rows the tenant has, the rows half of the one-value rule, counted as the bookings screen
 * counts them.
 *
 * Three statements, named by schema with no search path change, the way every admin read goes.
 */
final readonly class WizardCatalog
{
    public function __construct(private DatabaseManager $db) {}

    /**
     * @return array{services: list<array{id: int, name: string, duration_min: int, processing_min: int, staff: list<int>}>, staff: list<array{id: int, name: string}>, members: int}
     */
    public function read(Tenant $tenant): array
    {
        $schema = $tenant->schema_name;
        $connection = $this->db->connection();

        $performers = [];

        foreach ($connection->table($schema.'.service_staff as link')
            ->join($schema.'.staff as member', 'member.id', '=', 'link.staff_id')
            ->where('member.status', 'active')
            ->orderBy('member.sort')->orderBy('member.id')
            ->get(['link.service_id', 'link.staff_id'])->all() as $link) {
            if (! is_int($link->service_id) || ! is_int($link->staff_id)) {
                throw new LogicException('A service link is not shaped as the schema declares.');
            }

            $performers[$link->service_id][] = $link->staff_id;
        }

        $services = [];

        foreach ($connection->table($schema.'.services')->where('status', 'active')->orderBy('sort')->orderBy('id')
            ->get(['id', 'name', 'duration_min', 'processing_gap_min'])->all() as $row) {
            if (! is_int($row->id) || ! is_string($row->name) || ! is_int($row->duration_min) || ! is_int($row->processing_gap_min)) {
                throw new LogicException('A service row is not shaped as the schema declares.');
            }

            $services[] = ['id' => $row->id, 'name' => $row->name, 'duration_min' => $row->duration_min, 'processing_min' => $row->processing_gap_min, 'staff' => $performers[$row->id] ?? []];
        }

        $staff = [];
        $members = 0;

        foreach ($connection->table($schema.'.staff')->orderBy('sort')->orderBy('id')->get(['id', 'name', 'status', 'is_implicit'])->all() as $row) {
            if (! is_int($row->id) || ! is_string($row->name)) {
                throw new LogicException('A staff row is not shaped as the schema declares.');
            }

            $members++;

            if ($row->status === 'active' && $row->is_implicit !== true) {
                $staff[] = ['id' => $row->id, 'name' => $row->name];
            }
        }

        return ['services' => $services, 'staff' => $staff, 'members' => $members];
    }
}
