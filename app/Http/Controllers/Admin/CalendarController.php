<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\AdminSession;
use App\Http\AdminStrings;
use App\Http\CalendarPage;
use App\Http\Requests\CalendarRequest;
use App\Infrastructure\Db\CalendarRoster;
use App\Infrastructure\Db\StaffRecords;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Permission;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

/**
 * The Calendar screen, under viewBookings or viewOwnCalendar: the filter card's options, the window
 * the query asks for, and the dialog's detail for the booking the screen has open. Every prop a
 * read costs is a closure, so a partial reload asks for the one it needs: moving the week re-reads
 * the window alone, opening a block re-reads the detail alone, and the URL never changes, because
 * the view, the date and the open dialog are the screen's own state. The cheap ones, own, pinned
 * and opening, are plain values sent with every load. The `opening` prop is the view and the date a
 * link names, `?view=` and `?date=`, which the screen starts on and remembers nowhere, so Home's
 * seven days open a day; without them it opens on Day and today. The window a page load serves is
 * the range of what it opens on (CalendarPage::openingWindow), so opening the screen is one read.
 *
 * Who is the one server parameter, the window answering the members it resolved to and their hours;
 * status and service are cut on the client over the window fetched. The `own` prop is the viewer's
 * own staff row, null where none is linked, which the Me preset stands for and support never has.
 *
 * A viewer holding viewOwnCalendar without viewBookings, the staff role, is pinned to Me: the
 * window reads their own row whatever the query says, the options offer their own row alone, and
 * the dialog answers missing for a booking with no item on it; with no row linked the window is
 * empty and no detail is read. The `pinned` prop says so, and the screen shows Who read-only.
 *
 * The setup checklist moved to Home, so no viewer receives a setup prop here.
 *
 * The page carries its own calendar section of the admin's words, the bookings section, since the
 * screen opens the booking dialog, and the shifts section, since Day's column menu mounts the
 * Shifts day editor and a Day column's closure reads its sentence there.
 */
final readonly class CalendarController
{
    /** The section of the admin's words the screen reads, lang/{locale}/admin/calendar.php. */
    public const string SECTION = 'calendar';

    public function __construct(
        private CurrentTenant $current,
        private AdminSession $session,
        private CalendarPage $page,
        private CalendarRoster $roster,
        private StaffRecords $staff,
    ) {}

    public function __invoke(CalendarRequest $request): Response
    {
        // The shell's props read the request the kernel holds, of which the form request is a copy.
        AdminStrings::need(request(), self::SECTION, BookingListController::SECTION, ShiftsController::SECTION);
        $tenant = $this->current->get();
        $viewer = $this->session->principal($request) ?? throw new LogicException('The calendar rendered without a principal; its route declares no permission.');
        $row = $this->roster->ownRow($tenant, $viewer->ownStaffUserId());
        $own = $row['id'] ?? null;
        $pinned = ! $viewer->holds(Permission::ViewBookings);

        $props = [
            'own' => $row,
            'pinned' => $pinned,
            'options' => fn (): array => $pinned ? $this->roster->pinnedOptions($tenant, $row) : $this->roster->options($tenant),
            'window' => fn (): array => $this->page->window($tenant, $request->window($this->page->openingWindow($tenant, $request->opening()), $pinned), $viewer, $row),
            'detail' => fn (): ?array => $pinned && $own === null ? null : $this->page->detail($tenant, $request->booking(), $viewer, $own, $pinned ? $own : null),
            'opening' => $request->opening(),
            // Each member's own colour where one is picked, the spread's otherwise.
            'colours' => fn (): array => $this->staff->colours($tenant),
        ];

        return Inertia::render('Calendar', $props);
    }
}
