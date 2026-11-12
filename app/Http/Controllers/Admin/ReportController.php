<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Clock;
use App\Application\Dto\FigureFilter;
use App\Application\Dto\FigureRange;
use App\Application\Dto\RangeFigures;
use App\Http\AdminStrings;
use App\Http\CsvDownload;
use App\Http\RangeTiles;
use App\Http\ReportCsv;
use App\Http\ReportPayload;
use App\Http\Requests\ReportRequest;
use App\Infrastructure\Db\BlackoutRecords;
use App\Infrastructure\Db\RangeFigureRecords;
use App\Infrastructure\Db\ReportFigures;
use App\Infrastructure\Db\SettingsReader;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Tenant;
use Closure;
use Illuminate\Database\DatabaseManager;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Reports screen, under viewBookings and read-only: the numbers an owner asks for at the end
 * of the week, computed when asked and stored nowhere.
 *
 * The range is the request's word, read against the tenant's today, the Clock port's instant in
 * the tenant's zone and never the machine's date, and the tenant's week start; the filters are the
 * request's member and service, kept where the screen offers them: the active members, the
 * business's own row left out as the Bookings bar leaves it, and the active services. The range,
 * the filters and the options are read back eager; the six tiles (RangeFigureRecords, without
 * Home's Top tables, shaped by RangeTiles as Home's are) and everything below them, Over time, the
 * status cards, Occupancy by staff and the By tables (ReportFigures, and the tiles' own read for
 * Occupancy by staff, read once for both props), all under the filters, are closures, so a change
 * of filter is a partial reload of the four. Download CSV (csv) reads the same query the same way
 * under the same permission and writes what the screen shows as a file. Nothing is remembered but
 * what the URL carries. The page's words are the reports section, named here through
 * AdminStrings::need.
 */
final readonly class ReportController
{
    /** The section of lang/{locale}/admin the page reads its words from. */
    public const string SECTION = 'reports';

    public function __construct(
        private CurrentTenant $current,
        private SettingsReader $settings,
        private ReportFigures $figures,
        private RangeFigureRecords $tiles,
        private DatabaseManager $db,
        private Clock $clock,
    ) {}

    public function index(ReportRequest $request): Response
    {
        // The dispatched request is the one the shared props read; the form request is a copy.
        AdminStrings::need(request(), self::SECTION);
        $asked = $this->asked($request);
        $tiles = $this->tilesOf($asked);

        return Inertia::render('Reports', [
            'range' => $asked['range']->toArray(),
            'filters' => $asked['filter']->toArray(),
            'options' => $asked['options'],
            'figures' => fn (): array => RangeTiles::of($tiles()),
            'report' => fn (): array => $this->report($asked, $tiles()),
        ]);
    }

    /**
     * Download CSV: what the screen shows for the same query, read as the page reads it, a stale
     * or malformed range this week, written by ReportCsv and CsvDownload as a file named by the
     * range's dates, never cached.
     */
    public function csv(ReportRequest $request): StreamedResponse
    {
        $asked = $this->asked($request);
        $figures = $this->tiles->tilesOnly($asked['tenant'], $asked['range'], $asked['filter']);
        $rows = ReportCsv::rows(RangeTiles::of($figures), $this->report($asked, $figures), $asked['filter'], $asked['options'], $asked['tenant']->currencyCode(), $this->settings->read($asked['tenant'])->dateFormat);
        $name = sprintf('reports-%s-to-%s.csv', $asked['range']->from, $asked['range']->to);

        return CsvDownload::of($rows, $name);
    }

    /**
     * What a request asks for, read against the tenant's today and week start: the range, the
     * filters kept where the screen offers them, and the options.
     *
     * @return array{tenant: Tenant, range: FigureRange, filter: FigureFilter, options: array{staff: list<array{id: int, name: string}>, services: list<array{id: int, name: string}>}, startOfWeek: int}
     */
    private function asked(ReportRequest $request): array
    {
        $tenant = $this->current->get();
        $today = $this->clock->now()->setTimezone($tenant->zone())->format('Y-m-d');
        $startOfWeek = $this->settings->read($tenant)->startOfWeek;
        $options = ['staff' => $this->choices($tenant, 'staff'), 'services' => $this->choices($tenant, 'services')];

        return [
            'tenant' => $tenant,
            'range' => $request->range($today, $startOfWeek),
            'filter' => $request->filter(array_column($options['staff'], 'id'), array_column($options['services'], 'id')),
            'options' => $options,
            'startOfWeek' => $startOfWeek,
        ];
    }

    /**
     * The tiles' figures read once however many props ask for them: the tiles and Occupancy by
     * staff come of the same read.
     *
     * @param  array{tenant: Tenant, range: FigureRange, filter: FigureFilter, options: array{staff: list<array{id: int, name: string}>, services: list<array{id: int, name: string}>}, startOfWeek: int}  $asked
     * @return Closure(): RangeFigures
     */
    private function tilesOf(array $asked): Closure
    {
        $read = null;

        return function () use (&$read, $asked): RangeFigures {
            return $read ??= $this->tiles->tilesOnly($asked['tenant'], $asked['range'], $asked['filter']);
        };
    }

    /**
     * Everything below the tiles: Over time, the status cards, Occupancy by staff and the By tables.
     *
     * @param  array{tenant: Tenant, range: FigureRange, filter: FigureFilter, options: array{staff: list<array{id: int, name: string}>, services: list<array{id: int, name: string}>}, startOfWeek: int}  $asked
     * @return array<string, mixed>
     */
    private function report(array $asked, RangeFigures $figures): array
    {
        [$tenant, $range, $filter] = [$asked['tenant'], $asked['range'], $asked['filter']];

        return ReportPayload::of($this->figures->read($tenant, $range, $filter), $this->figures->overTime($tenant, $range, $filter, $asked['startOfWeek']), $figures->occupancy);
    }

    /**
     * The active rows a filter offers, in the catalogue's order; the business's own member left out.
     *
     * @param  'staff'|'services'  $table
     * @return list<array{id: int, name: string}>
     */
    private function choices(Tenant $tenant, string $table): array
    {
        $rows = $this->db->connection()->table($tenant->schema_name.'.'.$table)->where('status', 'active');

        if ($table === 'staff') {
            $rows->where('is_implicit', false);
        }

        return array_values(array_map(
            fn (stdClass $row): array => ['id' => BlackoutRecords::int($row->id), 'name' => is_string($row->name) ? $row->name : ''],
            $rows->orderBy('sort')->orderBy('id')->get(['id', 'name'])->all(),
        ));
    }
}
