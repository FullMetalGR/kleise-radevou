<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Dto\CalendarWindow;
use App\Billing\EntitlementResolver;
use App\Billing\Feature;
use App\Http\AdminSession;
use App\Http\AdminStrings;
use App\Http\CalendarPage;
use App\Http\FeatureGate;
use App\Http\Requests\ShiftDayRequest;
use App\Http\Requests\ShiftPatternRequest;
use App\Http\Requests\TolerantQuery;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Db\ShiftRecords;
use App\Infrastructure\Db\StaffRecords;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Shifts screen, and its writes under the prefix. The page reads under manageSettings: the
 * members, the week asked for by `?week=` (a date, the week holding it in the tenant's week start;
 * this week where the query names none or no date, as the Calendar falls back), each member's days
 * with their windows, source and closures, their patterns and the totals, every one a closure so a
 * partial reload asks for what it needs; `editable` says whether the staff feature is held.
 * Without it the page still reads, the screen read-only under the narrowing notice as the Staff
 * list is, and no read is gated by the subscription.
 *
 * The writes sit under manageSettings and the active subscription on their routes and answer
 * not-found without the staff feature, asked before the form request resolves as the staff writes
 * ask it (StaffController), so an invalid body is not-found too; a member the schema does not hold
 * is not-found, and so is a date that is not one. Each is replace-all for its row, flashes a key
 * of admin/shifts.php and goes back. The page's words are the shifts section, named here through
 * AdminStrings::need.
 */
final readonly class ShiftsController
{
    public const string PAGE = 'Shifts';

    public const string SECTION = 'shifts';

    public const string PATTERN_SAVED = 'admin/shifts.flashes.pattern_saved';

    public const string DAY_SAVED = 'admin/shifts.flashes.day_saved';

    public const string DAY_RESET = 'admin/shifts.flashes.day_reset';

    public function __construct(
        private CurrentTenant $current,
        private ShiftRecords $shifts,
        private CalendarPage $calendar,
        private SettingsReader $settings,
        private FeatureGate $gate,
        private EntitlementResolver $entitlements,
        private StaffRecords $staff,
    ) {}

    public function show(Request $request): Response
    {
        AdminStrings::need($request, self::SECTION);
        $tenant = $this->current->get();
        $week = $this->week($request);
        $members = fn (): array => $this->shifts->members($tenant);
        $read = null;
        $hours = function () use ($tenant, $members, $week, &$read): array {
            return $read ??= $this->shifts->week($tenant, array_column($members(), 'id'), $week);
        };

        return Inertia::render(self::PAGE, [
            'members' => $members,
            'week' => fn (): array => ['from' => $week->from, 'to' => $week->to],
            'days' => fn (): array => $hours()['days'],
            'patterns' => fn (): array => $hours()['patterns'],
            'totals' => fn (): array => $hours()['totals'],
            'editable' => fn (): bool => $this->entitlements->resolve($tenant)->allows(Feature::Staff),
            // Each member's own colour where one is picked, the spread's otherwise.
            'colours' => fn (): array => $this->staff->colours($tenant),
        ]);
    }

    public function pattern(int $id): RedirectResponse
    {
        $this->gate->require(Feature::Staff);
        $request = app(ShiftPatternRequest::class);

        if (! $this->shifts->replacePattern($this->current->get(), $id, $request->cadenceWeeks(), $request->startsOn(), $request->endsOn(), $request->windows())) {
            throw new NotFoundHttpException;
        }

        return $this->back($request, self::PATTERN_SAVED);
    }

    public function day(int $id, string $date): RedirectResponse
    {
        $this->gate->require(Feature::Staff);
        $day = self::date($date);
        $request = app(ShiftDayRequest::class);

        if (! $this->shifts->replaceDay($this->current->get(), $id, $day, $request->windows())) {
            throw new NotFoundHttpException;
        }

        return $this->back($request, self::DAY_SAVED);
    }

    public function dropDay(Request $request, int $id, string $date): RedirectResponse
    {
        $this->gate->require(Feature::Staff);

        if (! $this->shifts->dropDay($this->current->get(), $id, self::date($date))) {
            throw new NotFoundHttpException;
        }

        return $this->back($request, self::DAY_RESET);
    }

    /**
     * The week asked for: the one holding the query's date in the tenant's week start, or this
     * week where it names none or no date.
     */
    private function week(Request $request): CalendarWindow
    {
        $asked = TolerantQuery::day($request, 'week');

        return $asked === null
            ? $this->calendar->thisWeek($this->current->get())
            : CalendarWindow::weekOf($asked, $this->settings->read($this->current->get())->startOfWeek);
    }

    /** The path's date, a calendar date YYYY-MM-DD, else not-found. */
    private static function date(string $date): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            throw new NotFoundHttpException;
        }

        return $date;
    }

    private function back(Request $request, string $key): RedirectResponse
    {
        $request->session()->flash('flash', ['kind' => 'success', 'message' => ShiftDayRequest::line($key)]);

        return redirect()->back(fallback: AdminSession::at($request, self::path()));
    }

    public static function path(): string
    {
        return AdminSession::PREFIX.'/shifts';
    }
}
