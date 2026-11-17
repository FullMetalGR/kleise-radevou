<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Clock;
use App\Application\Dto\BookingRecord;
use App\Application\Dto\CalendarWho;
use App\Application\Dto\CalendarWindow;
use App\Domain\Availability\CalendarDay;
use App\Infrastructure\Db\BookingNotes;
use App\Infrastructure\Db\BookingRecords;
use App\Infrastructure\Db\CalendarRoster;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Db\StaffHours;
use App\Tenancy\Permission;
use App\Tenancy\Tenant;

/**
 * The Calendar's props: the window, one answer the grid draws blocks and gaps from, the members Who
 * resolved to and their hours, and the dialog's detail, which on a calendar is client state asked
 * for by a query parameter and never a route.
 *
 * Every booking passes through BookingPayload::admin with the viewer's permissions and their own
 * staff row, so what reaches the grid is the whitelist's decision. The viewer is the principal the
 * session read, a user or support. The default window is the week of the tenant's today from the
 * tenant's week start, the same field the client's columns and title read, and the tenant's today
 * is the Clock port's instant in the tenant's zone, never the machine's date.
 */
final readonly class CalendarPage
{
    /** The days the month grid shows, six weeks always, as the screen's calendar/model.ts counts them. */
    private const int MONTH_GRID_DAYS = 42;

    public function __construct(
        private BookingRecords $records,
        private BookingNotes $notes,
        private BookingTrail $trail,
        private BookingActions $actions,
        private SettingsReader $settings,
        private Clock $clock,
        private CalendarRoster $roster,
        private StaffHours $hours,
        private OpeningWeek $opening,
    ) {}

    /** The week the calendar opens on: the tenant's today from the tenant's week start. */
    public function thisWeek(Tenant $tenant): CalendarWindow
    {
        return CalendarWindow::weekOf($this->today($tenant), $this->settings->read($tenant)->startOfWeek);
    }

    /** The tenant's today: the Clock port's instant in the tenant's zone, never the machine's date. */
    private function today(Tenant $tenant): string
    {
        return $this->clock->now()->setTimezone($tenant->zone())->format('Y-m-d');
    }

    /**
     * The window the Calendar opens on: the range of the view and the date a link names
     * (CalendarRequest::opening), the screen's own rangeOf on the server, so the first read is the
     * one the screen draws and no second read follows. Day and the tenant's today where the link
     * names neither; Day a date alone, Week the week holding it from the tenant's week start,
     * Month the six weeks from the week holding the month's first day.
     *
     * @param  array{view: 'week'|'day'|'month'|null, date: string|null}  $opening
     */
    public function openingWindow(Tenant $tenant, array $opening): CalendarWindow
    {
        $date = $opening['date'] ?? $this->today($tenant);

        return match ($opening['view']) {
            'week' => CalendarWindow::weekOf($date, $this->settings->read($tenant)->startOfWeek),
            'month' => $this->monthOf($date, $this->settings->read($tenant)->startOfWeek),
            default => new CalendarWindow($date, CalendarDay::plusDays($date, 1)),
        };
    }

    /** The month grid's window: six weeks from the week holding the month's first day, as the screen's Month draws it. */
    private function monthOf(string $date, int $startOfWeek): CalendarWindow
    {
        $from = CalendarDay::weekStart(substr($date, 0, 8).'01', $startOfWeek);

        return new CalendarWindow($from, CalendarDay::plusDays($from, self::MONTH_GRID_DAYS));
    }

    /**
     * The window as the Calendar's grid receives it: the range and Who read back as asked, the
     * staff list the ids picked and empty for every other mode, the members Who resolved to on the
     * server, their hours per date, and every booking with an item on one of their rows through the
     * whitelist, or every booking under All staff.
     *
     * Scheduled staff is every active member with a window in force on a date of the range, Day's
     * one date included; All staff every active member; Me the viewer's own row, none where they
     * have none; a picked set the ids that exist. A closure adds nobody.
     *
     * The implicit row is never a member listed, since it stands for the business and is never
     * rendered, but under Scheduled staff and All staff its bookings are drawn, so a tenant whose
     * bookings sit on it, one with the staff feature and no named member yet, sees them: `implicit`
     * names its id then, and is null under Me and a picked set.
     *
     * `closures` carries, per row and per date, the closures of every row the screen draws a
     * booking's block on that the hours leave out, the implicit row's above all, so that a block
     * on such a row can be marked when a closure holds it; empty where the hours cover every row
     * drawn. A row the screen does not draw is never among them: its closures, with their
     * reasons, are no part of what this viewer was shown.
     *
     * Each day of the hours carries what the Shifts day editor reads: its source and the windows
     * its pattern gives that date, for Edit this day's hours in Day. Each member with no window in
     * force on any date of the window has an entry in `next_working`: the first later date holding
     * a window that no whole-day closure takes, scanned through the resolver seam up to eight weeks
     * past the window, and its windows, or null; Day's column of a member with no hours names it.
     *
     * `closed_days` names the dates of the range the business is closed all day, whoever Who
     * shows: a weekday the opening week holds no window on, or a date the business's own closures
     * take whole (OpeningWeek). Month hatches them.
     *
     * @param  array{id: int, name: string}|null  $own  the viewer's own staff row
     * @return array{
     *     from: string,
     *     to: string,
     *     who: string,
     *     staff: list<int>,
     *     members: list<array{id: int, name: string}>,
     *     implicit: int|null,
     *     hours: array<int, array<string, array{windows: list<array{start: string, end: string}>, closures: list<array{start: string|null, end: string|null, reason: string|null, business: bool}>, source: string, pattern: list<array{start: string, end: string}>}>>,
     *     next_working: array<int, array{date: string, windows: list<array{start: string, end: string}>}|null>,
     *     closures: array<int, array<string, list<array{start: string|null, end: string|null, reason: string|null, business: bool}>>>,
     *     closed_days: list<string>,
     *     bookings: list<array<string, mixed>>,
     * }
     */
    public function window(Tenant $tenant, CalendarWindow $window, Principal $viewer, ?array $own): array
    {
        $members = match ($window->who) {
            CalendarWho::Scheduled, CalendarWho::All => $this->roster->active($tenant),
            CalendarWho::Me => $own === null ? [] : [$own],
            CalendarWho::Picked => $this->roster->named($tenant, $window->staff),
        };
        $hours = $this->hours->editable($tenant, array_column($members, 'id'), $window);

        if ($window->who === CalendarWho::Scheduled) {
            $scheduled = StaffHours::scheduled($hours);
            $members = array_values(array_filter($members, fn (array $member): bool => in_array($member['id'], $scheduled, true)));
            $hours = array_intersect_key($hours, array_flip($scheduled));
        }

        $idle = array_values(array_diff(array_column($members, 'id'), StaffHours::scheduled($hours)));
        $implicit = in_array($window->who, [CalendarWho::Scheduled, CalendarWho::All], true) ? $this->roster->implicitId($tenant) : null;
        $shown = [...array_column($members, 'id'), ...($implicit === null ? [] : [$implicit])];
        $bookings = $this->bookings($tenant, $window->narrowedTo($shown), $viewer, $own['id'] ?? null);

        return [
            'from' => $window->from,
            'to' => $window->to,
            'who' => $window->who->value,
            'staff' => $window->who === CalendarWho::Picked ? $window->staff : [],
            'members' => $members,
            'implicit' => $implicit,
            'hours' => $hours,
            'next_working' => $this->hours->nextWorking($tenant, $idle, $window->to),
            'closures' => $this->hours->closuresOf($tenant, self::uncovered($bookings, $hours, $window->narrows() ? $shown : null), $window),
            'closed_days' => OpeningWeek::closedDays($this->opening->read($tenant, $this->today($tenant)), $window->days(), $this->hours->businessClosures($tenant, $window)),
            'bookings' => $bookings,
        ];
    }

    /**
     * The rows the screen draws an item of the bookings on that the hours do not cover, the
     * implicit row's among them: the closures the window carries for these let a block on such a
     * row tell whether a closure holds it. A booking of several services arrives with every one
     * of them, a colleague's too, so the rows are cut to the ones drawn: every row under All
     * staff, else the rows shown alone, which leaves the implicit row under Scheduled staff and
     * none under Me or a picked set.
     *
     * @param  list<array<string, mixed>>  $bookings
     * @param  array<int, mixed>  $hours
     * @param  list<int>|null  $shown  the rows the screen draws, null where it draws every row
     * @return list<int>
     */
    private static function uncovered(array $bookings, array $hours, ?array $shown): array
    {
        $rows = [];

        foreach ($bookings as $booking) {
            $items = $booking['items'] ?? null;

            foreach (is_array($items) ? $items : [] as $item) {
                $staff = is_array($item) ? ($item['staff_id'] ?? null) : null;

                if (is_int($staff) && ! array_key_exists($staff, $hours)) {
                    $rows[$staff] = $staff;
                }
            }
        }

        return array_values($shown === null ? $rows : array_intersect($rows, $shown));
    }

    /**
     * Every booking in the window through the whitelist, the window's members its scope, whether
     * this viewer may move it now beside it (BookingActions::movable), each item carrying its
     * service's category beside the service's name, null where the service has none, for Colour
     * by: the service row's column, read once per window, never a column of the booking's and so
     * outside the whitelist's buckets.
     *
     * `noted` says whether the customer wrote a note, read for the whole window in one statement;
     * the words stay with the dialog's detail. Every booking here is one whose dialog this viewer
     * opens with its note, a pinned viewer's window being their own row's alone, the detail's
     * scope, so the flag tells nobody of a note they could not read.
     *
     * @return list<array<string, mixed>>
     */
    private function bookings(Tenant $tenant, CalendarWindow $window, Principal $viewer, ?int $ownStaff): array
    {
        $manage = $viewer->holds(Permission::ManageBookings);
        $records = $this->records->window($tenant, $window);
        $categories = $records === [] ? [] : $this->roster->categories($tenant);
        $noted = array_flip($this->notes->notedAmong($tenant, array_map(fn (BookingRecord $record): string => $record->snapshot->uuid, $records)));

        return array_map(function (BookingRecord $record) use ($manage, $ownStaff, $categories, $viewer, $noted): array {
            $booking = [...BookingPayload::admin($record, $manage, $ownStaff), 'movable' => BookingActions::movable($record, $viewer), 'noted' => isset($noted[$record->snapshot->uuid])];
            $items = $booking['items'];
            assert(is_array($items));

            $booking['items'] = array_map(function (mixed $item) use ($categories): mixed {
                if (! is_array($item) || ! is_int($item['service_id'] ?? null)) {
                    return $item;
                }

                return [...$item, 'category' => $categories[$item['service_id']] ?? null];
            }, $items);

            return $booking;
        }, $records);
    }

    /**
     * The dialog's detail for the booking the screen has open, in the shape the Bookings screen's
     * dialog reads: found with the booking, the customer's note through its own reader, its trail
     * and the actions this viewer may take now, or missing. A calendar scoped to one staff row
     * answers missing for a booking with no item on that row, so the row's member reads nothing
     * the grid would not have drawn for them. The booking carries `movable` as a block does.
     *
     * @param  int|null  $scopedTo  the staff row the screen is scoped to, or null for the tenant
     * @return array<string, mixed>|null
     */
    public function detail(Tenant $tenant, ?string $uuid, Principal $viewer, ?int $ownStaff, ?int $scopedTo): ?array
    {
        if ($uuid === null) {
            return null;
        }

        $record = $this->records->one($tenant, $uuid);

        if ($record === null || ($scopedTo !== null && ! self::hasAnItemOn($record, $scopedTo))) {
            return ['state' => 'missing', 'uuid' => $uuid];
        }

        return [
            'state' => 'found',
            'booking' => [...BookingPayload::admin($record, $viewer->holds(Permission::ManageBookings), $ownStaff), 'movable' => BookingActions::movable($record, $viewer)],
            'note' => $this->notes->of($tenant, $uuid),
            'trail' => $this->trail->of($tenant, $uuid),
            'actions' => $this->actions->for($tenant, $record, $viewer),
        ];
    }

    private static function hasAnItemOn(BookingRecord $record, int $staff): bool
    {
        foreach ($record->snapshot->items as $item) {
            if ($item->staffId === $staff) {
                return true;
            }
        }

        return false;
    }
}
