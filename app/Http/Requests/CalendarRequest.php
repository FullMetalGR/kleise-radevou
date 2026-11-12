<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\Dto\CalendarWho;
use App\Application\Dto\CalendarWindow;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The calendar's query as the screen sends it: from and to as dates with the end exclusive, Who
 * as the server's parameter, `who` (scheduled, all or me) or `staff`, a comma-joined list of
 * the members picked, and booking as the uuid of the dialog the screen has open, which is
 * client state and never the URL. Read tolerantly, by TolerantQuery as the bookings list's is:
 * a wrong shape, a date no calendar has among them, reads as the default, never as a 422,
 * because the window is the screen's own and a malformed one is answered with the week the
 * screen opens on.
 */
final class CalendarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * The window asked for, or the default range given when the query names none it accepts, with
     * Who as asked: a well-formed staff list is the members picked, else `who` read as its word,
     * an unknown or absent one reading as Scheduled. A viewer pinned to their own row, one
     * without viewBookings, reads Me whatever the query says.
     */
    public function window(CalendarWindow $default, bool $pinned = false): CalendarWindow
    {
        $from = TolerantQuery::day($this, 'from');
        $to = TolerantQuery::day($this, 'to');
        [$who, $staff] = $pinned ? [CalendarWho::Me, []] : $this->who();

        return CalendarWindow::accepts($from, $to) && $from !== null && $to !== null
            ? new CalendarWindow($from, $to, $who, $staff)
            : new CalendarWindow($default->from, $default->to, $who, $staff);
    }

    /**
     * @return array{CalendarWho, list<int>}
     */
    private function who(): array
    {
        $picked = TolerantQuery::ids($this, 'staff');

        return $picked !== null ? [CalendarWho::Picked, $picked] : [CalendarWho::spelled(TolerantQuery::text($this, 'who')), []];
    }

    /**
     * The view and the date a link opens the Calendar on: `view` one of week, day and month and
     * `date` a real date, each null where the query names none it accepts. The screen reads
     * them once on its first render and remembers neither.
     *
     * @return array{view: 'week'|'day'|'month'|null, date: string|null}
     */
    public function opening(): array
    {
        return [
            'view' => match (TolerantQuery::text($this, 'view')) {
                'week' => 'week',
                'day' => 'day',
                'month' => 'month',
                default => null,
            },
            'date' => TolerantQuery::day($this, 'date'),
        ];
    }

    /** The booking the dialog is open on, or null. */
    public function booking(): ?string
    {
        return TolerantQuery::text($this, 'booking');
    }
}
