<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Clock;
use App\Application\Notification\MessageKey;
use App\Http\AdminSession;
use App\Http\AdminStrings;
use App\Http\BookingTrail;
use App\Http\CalendarPage;
use App\Http\HomeActivity;
use App\Http\HomePayload;
use App\Http\HomeRangeFigures;
use App\Http\Requests\HomeRequest;
use App\Http\Setup\SetupWizard;
use App\Infrastructure\Db\BookingRecords;
use App\Infrastructure\Db\CalendarRoster;
use App\Infrastructure\Db\RangeFigureRecords;
use App\Infrastructure\Db\SettingsReader;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Permission;
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
 * over the seven days from today (BookingRecords::perDay). Nothing is stored and nothing is
 * remembered.
 *
 * `setup`: for an owner in setup (SetupWizard::progress) with a step still open, the steps that
 * apply with their facts, whether the wizard has come up by itself, the step it opens on by
 * itself, and each step's own data; the wizard's word sections are asked for beside Home's only
 * then. Whether it is offered is read on every answer, partial reloads included, since the words
 * it needs are named before the page renders; anyone else is sent no part of it.
 * `setup_finished`: true on the one answer that finds every step closed and records setup as
 * finished, which carries no `setup`, and absent from every other, so the page says setup is
 * complete once, as it happens. The record is written as the prop is resolved, so only by an
 * answer that carries it: a partial reload asking for other props records nothing, and the whole
 * answer after it is the one that says so.
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
 * `to_mark`: the confirmed bookings whose last segment has ended by the same now, the ones nobody
 * has marked completed or a no-show yet, up to TO_MARK of them by the date their first start falls
 * on in the tenant's zone, today first, then older dates latest first, beside the count of every
 * one and whether the tenant sends the thank-you email a Completed mark sets off
 * (HomePayload::toMark). Each row is marked by the booking dialog's own two routes, a selection of
 * rows by MarkOutcomesController.
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

    /** The most rows To mark lists; the landing page reads no more, and the count says the rest. */
    public const int TO_MARK = 100;

    public function __construct(
        private CurrentTenant $current,
        private AdminSession $session,
        private SettingsReader $settings,
        private BookingRecords $bookings,
        private SetupWizard $setup,
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
            'to_mark' => fn (): array => HomePayload::toMark(
                $this->bookings->toMark($tenant, $now, self::TO_MARK),
                $this->bookings->toMarkCount($tenant, $now),
                $tenant->zone(),
                $today,
                $this->settings->read($tenant)->sends(MessageKey::ThankYou),
            ),
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

        $progress = $this->setup->progress($tenant, $viewer);

        if ($progress?->complete() === true) {
            // Resolved only for an answer that carries it, so the record is never written unsaid.
            $props['setup_finished'] = function () use ($tenant): bool {
                $this->setup->finish($tenant);

                return true;
            };
        } elseif ($progress !== null) {
            AdminStrings::need(request(), ...$this->setup->sections($progress));
            $props['setup'] = fn (): array => $this->setup->payload($tenant, $progress, $request);
        }

        return Inertia::render('Home', $props);
    }
}
