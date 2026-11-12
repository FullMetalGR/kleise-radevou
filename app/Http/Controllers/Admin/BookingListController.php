<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Enum\BookingStatus;
use App\Http\AdminSession;
use App\Http\AdminStrings;
use App\Http\BookingActions;
use App\Http\BookingPayload;
use App\Http\BookingTrail;
use App\Http\Requests\BookingListRequest;
use App\Infrastructure\Db\BookingNotes;
use App\Infrastructure\Db\BookingRecords;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Permission;
use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;
use stdClass;

/**
 * The Bookings screen, and the booking dialog over it. The dialog is a route: GET
 * /admin/bookings/{uuid} renders the same page as the list with the dialog open, so a bookmark, a
 * reload and a new tab land on the same thing.
 *
 * Every prop but the filters is a closure, so a partial reload asks for the one it needs: a
 * filter change re-reads bookings, opening and closing the dialog re-reads detail; the filters
 * are the request's own query read back, eager because they cost nothing. The rows and the booking
 * both pass through BookingPayload::admin with the viewer's permissions. The dialog's detail
 * carries the actions this viewer may take on the booking now, BookingActions, which the footer
 * renders verbatim.
 */
final readonly class BookingListController
{
    /**
     * The section of the admin's words the list and the booking dialog read,
     * lang/{locale}/admin/bookings.php, which every screen opening the dialog names.
     */
    public const string SECTION = 'bookings';

    public function __construct(
        private CurrentTenant $current,
        private AdminSession $session,
        private BookingRecords $records,
        private BookingNotes $notes,
        private BookingTrail $trail,
        private BookingActions $actions,
        private DatabaseManager $db,
    ) {}

    public function index(BookingListRequest $request): Response
    {
        return $this->page($request, null);
    }

    public function show(BookingListRequest $request, string $uuid): Response
    {
        return $this->page($request, $uuid);
    }

    private function page(BookingListRequest $request, ?string $uuid): Response
    {
        // The shell's props read the request the kernel holds, of which the form request is a copy.
        AdminStrings::need(request(), self::SECTION);
        $tenant = $this->current->get();
        $viewer = $this->session->principal($request) ?? throw new LogicException('The bookings screen rendered without a principal; its route declares no permission.');
        $manage = $viewer->holds(Permission::ManageBookings);
        $searchable = BookingPayload::searchable($manage);
        $filter = $request->filter()->searchingIn($searchable);

        return Inertia::render('Bookings', [
            'filters' => $filter->toQuery(),
            'options' => fn (): array => [...$this->options($tenant), 'searchable' => $searchable],
            'bookings' => function () use ($tenant, $filter, $manage): array {
                $page = $this->records->page($tenant, $filter);

                return [
                    'rows' => array_map(fn ($record): array => BookingPayload::admin($record, $manage), $page['records']),
                    'total' => $page['total'],
                    'page' => $filter->page,
                    'page_size' => $filter::PAGE_SIZE,
                ];
            },
            'detail' => function () use ($tenant, $uuid, $manage, $viewer): ?array {
                if ($uuid === null) {
                    return null;
                }

                $record = $this->records->one($tenant, $uuid);

                return $record === null
                    ? ['state' => 'missing', 'uuid' => $uuid]
                    : [
                        'state' => 'found',
                        'booking' => [...BookingPayload::admin($record, $manage), 'movable' => BookingActions::movable($record, $viewer)],
                        'note' => $this->notes->of($tenant, $uuid),
                        'trail' => $this->trail->of($tenant, $uuid),
                        'actions' => $this->actions->for($tenant, $record, $viewer),
                    ];
            },
        ]);
    }

    /**
     * What the bar offers: the nine statuses, the full staff roster and the full catalog with
     * whether each row is active, since this filter narrows history, and how many staff rows the
     * tenant has, the rows half of the one-value rule. The implicit member is counted and never
     * offered: it stands for the business and is never rendered. The page adds the customer columns
     * the search reads for this viewer, BookingPayload's decision.
     *
     * @return array{statuses: list<string>, staff: list<array{id: int, name: string, active: bool}>, services: list<array{id: int, name: string, active: bool}>, members: int}
     */
    private function options(Tenant $tenant): array
    {
        $staff = $this->db->connection()->table($tenant->schema_name.'.staff')
            ->orderBy('sort')->orderBy('id')->get(['id', 'name', 'status', 'is_implicit'])->all();
        $services = $this->db->connection()->table($tenant->schema_name.'.services')
            ->orderBy('sort')->orderBy('id')->get(['id', 'name', 'status'])->all();

        return [
            'statuses' => array_map(fn (BookingStatus $status): string => $status->value, BookingStatus::cases()),
            'staff' => self::choices(array_filter($staff, fn (stdClass $member): bool => $member->is_implicit !== true)),
            'services' => self::choices($services),
            'members' => count($staff),
        ];
    }

    /**
     * @param  array<stdClass>  $rows
     * @return list<array{id: int, name: string, active: bool}>
     */
    private static function choices(array $rows): array
    {
        $choices = [];

        foreach ($rows as $row) {
            if (! is_int($row->id) || ! is_string($row->name)) {
                throw new LogicException('A catalog row is not shaped as the schema declares.');
            }

            $choices[] = ['id' => $row->id, 'name' => $row->name, 'active' => $row->status === 'active'];
        }

        return $choices;
    }
}
