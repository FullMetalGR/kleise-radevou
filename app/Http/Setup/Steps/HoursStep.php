<?php

declare(strict_types=1);

namespace App\Http\Setup\Steps;

use App\Http\Controllers\Admin\SetupHoursController;
use App\Http\Middleware\RequiresActiveSubscription;
use App\Http\Middleware\RequiresPermission;
use App\Http\Setup\SetupStep;
use App\Infrastructure\Db\ShiftRecords;
use App\Infrastructure\Db\StaffRecords;
use App\Infrastructure\Db\WeeklyPattern;
use App\Setup\SetupProgress;
use App\Tenancy\DefaultWorkingWeek;
use App\Tenancy\Permission;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\Route;

/**
 * The Opening hours step: Settings' week editor over the week the business opens. Without the
 * staff feature it is the implicit member's week, the one Settings' Opening hours edits, and
 * nothing to edit where more than one member is active, Settings then showing each member's week
 * read-only (the roster). With the feature it is one week given to every member the Shifts screen
 * lists, opening on the week they share where they share one and on the default working week
 * otherwise, saying so where saving would replace weeks that differ. An empty stored week opens
 * on the default working week too, so the step always offers hours to confirm; `hourless` says
 * that nobody the week is for holds hours of either kind yet, neither a weekly pattern nor a
 * single dated day, the shown week then being nobody's until it is saved, the step done or not.
 * A member given their hours by the day alone holds hours set by hand, which the shown default
 * must not be saved over unasked.
 *
 * The one write, PUT /admin/setup/hours, is the owner's under the subscription gate, a
 * configuration write like Settings' own.
 */
final readonly class HoursStep implements SetupStep
{
    public function __construct(private ShiftRecords $shifts, private StaffRecords $staff) {}

    public function sections(): array
    {
        return [];
    }

    /**
     * @return array{mode: 'week'|'members'|'roster', week: list<array{weekday: int, start: string, end: string}>, members: list<string>, differs: bool, hourless: bool}
     */
    public function data(Tenant $tenant, SetupProgress $progress): array
    {
        if ($progress->applies('team')) {
            $members = $this->shifts->members($tenant);
            $ids = array_column($members, 'id');
            $shared = $this->shifts->sharedWeek($tenant, $ids);
            $named = array_values(array_map(fn (array $member): string => $member['name'], array_filter($members, fn (array $member): bool => ! $member['is_implicit'])));

            return ['mode' => $named === [] ? 'week' : 'members', 'week' => self::opening($shared ?? []), 'members' => $named, 'differs' => $shared === null, 'hourless' => $shared === [] && ! $this->shifts->holdsDays($tenant, $ids)];
        }

        if ($this->staff->activeCount($tenant) > 1) {
            return ['mode' => 'roster', 'week' => [], 'members' => [], 'differs' => false, 'hourless' => false];
        }

        $week = $this->staff->week($tenant);

        return ['mode' => 'week', 'week' => self::opening($week), 'members' => [], 'differs' => false, 'hourless' => $week === [] && ! $this->shifts->holdsDays($tenant)];
    }

    public static function routes(): void
    {
        Route::put('/setup/hours', SetupHoursController::class)->middleware([RequiresPermission::of(Permission::ManageBilling), RequiresActiveSubscription::NAME])->name('setup.hours');
    }

    /**
     * The week the editor opens on: the one stored, else the default working week.
     *
     * @param  list<array{weekday: int, start: string, end: string}>  $week
     * @return list<array{weekday: int, start: string, end: string}>
     */
    private static function opening(array $week): array
    {
        return WeeklyPattern::windows($week === [] ? DefaultWorkingWeek::windows() : $week);
    }
}
