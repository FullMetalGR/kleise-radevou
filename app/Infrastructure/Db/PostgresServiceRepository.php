<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Dto\BookableService;
use App\Application\Repository\ServiceRepository;
use App\Domain\Availability\SegmentShape;
use App\Domain\Enum\ApprovalTimeout;
use App\Domain\Enum\PaymentMode;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use LogicException;

/**
 * The service repository of one tenant, for a read that opens no transaction: the engine's
 * view of the active services among some ids, with the active staff members assigned to each,
 * the implicit member among them, since the engine is entitlement-blind and the implicit row
 * holds the tenant's opening hours, and the columns the hold copies onto a booking. Two
 * statements for the whole list, never one per service.
 * Built per tenant rather than resolved from the container, so a use case holding one can
 * never read another tenant's rows, and per pool, so the public road reads only the services
 * and members offered online and every other caller the whole catalogue (Pool).
 *
 * Every table is named by schema and nothing is set on the connection, the way the catalog
 * reader honours the pooling rule.
 */
final readonly class PostgresServiceRepository implements ServiceRepository
{
    public function __construct(
        private DatabaseManager $db,
        private Tenant $tenant,
        private Pool $pool,
    ) {}

    public function bookable(array $serviceIds): array
    {
        if ($serviceIds === []) {
            return [];
        }

        $rows = $this->db->connection()
            ->table($this->tenant->schema_name.'.services')
            ->whereIn('id', $serviceIds)
            ->where('status', 'active')
            ->when($this->pool === Pool::Online, fn (Builder $query): Builder => $query->where('online', true))
            ->orderBy('id')
            ->get([
                'id', 'duration_min', 'buffer_before_min', 'buffer_after_min', 'processing_gap_min', 'lead_time_min', 'horizon_days',
                'price_minor', 'currency', 'payment_mode', 'requires_approval', 'approval_hold_hours', 'on_approval_timeout',
            ]);

        $staffByService = $this->staffByService($rows->pluck('id')->all());
        $services = [];

        foreach ($rows as $row) {
            if (! is_int($row->id) || ! is_int($row->duration_min) || ! is_int($row->buffer_before_min) || ! is_int($row->buffer_after_min)
                || ! is_int($row->processing_gap_min) || ! is_int($row->lead_time_min) || ! is_int($row->horizon_days)
                || ! is_int($row->price_minor) || ! is_string($row->currency) || ! is_string($row->payment_mode) || ! is_bool($row->requires_approval)
                || ($row->approval_hold_hours !== null && ! is_int($row->approval_hold_hours)) || ! is_string($row->on_approval_timeout)) {
                throw new LogicException(sprintf('A services row of tenant "%s" is not shaped as the schema declares.', $this->tenant->slug));
            }

            $services[$row->id] = new BookableService(
                $row->id,
                new SegmentShape($row->duration_min, $row->buffer_before_min, $row->buffer_after_min),
                $row->processing_gap_min,
                $row->lead_time_min,
                $row->horizon_days,
                $staffByService[$row->id] ?? [],
                new Money($row->price_minor, Currency::of($row->currency)),
                PaymentMode::from($row->payment_mode),
                $row->requires_approval,
                $row->approval_hold_hours,
                ApprovalTimeout::from($row->on_approval_timeout),
            );
        }

        return $services;
    }

    /**
     * The active staff members assigned to each of the services, keyed by service id, those
     * offered online alone on the public road.
     *
     * @param  array<mixed>  $serviceIds
     * @return array<int, list<int>>
     */
    private function staffByService(array $serviceIds): array
    {
        if ($serviceIds === []) {
            return [];
        }

        $rows = $this->db->connection()
            ->table($this->tenant->schema_name.'.service_staff', 'assignment')
            ->join($this->tenant->schema_name.'.staff as staff', 'staff.id', '=', 'assignment.staff_id')
            ->whereIn('assignment.service_id', $serviceIds)
            ->where('staff.status', 'active')
            ->when($this->pool === Pool::Online, fn (Builder $query): Builder => $query->where('staff.online', true))
            ->orderBy('assignment.service_id')
            ->orderBy('staff.id')
            ->get(['assignment.service_id', 'staff.id']);

        $byService = [];

        foreach ($rows as $row) {
            if (! is_int($row->service_id) || ! is_int($row->id)) {
                throw new LogicException(sprintf('A staff row of tenant "%s" is not shaped as the schema declares.', $this->tenant->slug));
            }

            $byService[$row->service_id][] = $row->id;
        }

        return $byService;
    }
}
