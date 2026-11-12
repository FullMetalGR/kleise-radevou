<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Clock;
use App\Http\AdminSession;
use App\Http\AdminStrings;
use App\Http\BookingTrail;
use App\Http\CalendarPage;
use App\Http\HomeActivity;
use App\Http\HomePayload;
use App\Http\HomeRangeFigures;
use App\Http\Requests\HomeRequest;
use App\Infrastructure\Db\BookingRecords;
use App\Infrastructure\Db\CalendarRoster;
use App\Infrastructure\Db\RangeFigureRecords;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Db\SetupChecklist;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Permission;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Home: the admin's landing at /admin for a viewer holding viewBookings. The route admits
 * viewOwnCalendar as well, so a viewer holding that alone, the staff role, is not refused but
 * sent on to the Calendar, their landing, by its route name; this is the controller's first
 * line.
 *
 * The page reads the `home` section of lang/{locale}/admin beside the shell's. The tenant's today,
 * the date the ranges and the seven days count from, is read once off the Clock port's now in the
 * tenant's zone, never the machine's date, and the page receives no copy of it. Every prop is a
 * closure computed when the page renders: `days`, the confirmed and the awaiting counts per day
 * over the seven days from today (BookingRecords::perDay); and, for a manageSettings holder alone,
 * `setup`, the checklist's four facts and the hosted page's address. Nothing is stored and nothing
 * is remembered.
 *
 * The three feeds: `next_up`, the next five live bookings from the same now by first start;
 * `awaiting`, the requests on a live hold, pendingCount's rows, the soonest to end first, up to
 * five; `activity`, the last five trail rows, each the whole sentence HomeActivity names and the
 * names it fills, with the booking's status, services and named members beneath. A row opens the
 * booking dialog on this page as the customer's page opens it: `detail` is read for the uuid the
 * request's booking() names, by a partial reload with the URL preserved, through
 * CalendarPage::detail with no scope, the viewer's own staff row beside it; `members` is how many
 * staff rows the tenant has, the rows half of the rule that hides a staff choice with one value.
 *
 * `range_figures`: the six tiles and the two Top tables over the range the request's range()
 * names, today, yesterday, week or month, any other word or none reading as week, read by
 * RangeFigureRecords and shaped by HomeRangeFigures; the screen's range card asks for it alone by
 * a partial reload.
 */
final readonly class HomeController
{
    /** The days Home counts ahead, today the first. */
    private const int DAYS = 7;

    /** The rows Next up and Awaiting approval each list. */
    private const int FEED = 5;

    public function __construct(
        private CurrentTenant $current,
        private AdminSession $session,
        private SettingsReader $settings,
        private BookingRecords $bookings,
        private SetupChecklist $setup,
        private TenantUrls $urls,
        private Clock $clock,
        private BookingTrail $trail,
        private CalendarPage $page,
        private CalendarRoster $roster,
        private RangeFigureRecords $rangeFigures,
    ) {}

    public function __invoke(HomeRequest $request): Response|RedirectResponse
    {
        $tenant = $this->current->get();
        $viewer = $this->session->principal($request);

        if ($viewer === null || ! $viewer->holds(Permission::ViewBookings)) {
            return redirect(AdminSession::at($request, route('admin.calendar', ['tenant' => $tenant->slug], absolute: false)));
        }

        // The booking dialog a row opens reads the bookings section. The shell's props read the
        // request the kernel holds, of which the form request is a copy.
        AdminStrings::need(request(), 'home', BookingListController::SECTION);
        $now = $this->clock->now();
        $today = $now->setTimezone($tenant->zone())->format('Y-m-d');

        $props = [
            'range_figures' => fn (): array => HomeRangeFigures::of($this->rangeFigures->read(
                $tenant,
                $request->range($today, $this->settings->read($tenant)->startOfWeek),
            )),
            'days' => fn (): array => $this->bookings->perDay($tenant, $today, self::DAYS),
            'next_up' => fn (): array => array_map(HomePayload::booking(...), $this->bookings->nextUp($tenant, $now, self::FEED)),
            'awaiting' => fn (): array => array_map(HomePayload::booking(...), $this->bookings->awaiting($tenant, self::FEED)),
            'activity' => function () use ($tenant): array {
                $entries = $this->trail->latest($tenant, HomeActivity::ROWS);
                $records = $this->bookings->byUuids($tenant, array_values(array_filter(array_map(fn (array $entry): ?string => $entry['booking']['uuid'] ?? null, $entries), is_string(...))));

                return array_map(fn (array $entry): array => HomeActivity::row($entry, $records[$entry['booking']['uuid'] ?? ''] ?? null), $entries);
            },
            'members' => fn (): int => $this->roster->members($tenant),
            'detail' => fn (): ?array => $this->page->detail($tenant, $request->booking(), $viewer, $this->roster->ownRow($tenant, $viewer->ownStaffUserId())['id'] ?? null, null),
        ];

        if ($viewer->holds(Permission::ManageSettings)) {
            $props['setup'] = fn (): array => [...$this->setup->facts($tenant), 'hosted_url' => $this->urls->hostedPage(Slug::of($tenant->slug))];
        }

        return Inertia::render('Home', $props);
    }
}
