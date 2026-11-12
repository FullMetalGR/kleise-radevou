<?php

use App\Domain\Availability\CalendarDay;
use App\Site\DemoSalon;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Browser\Support\Admin;

/*
 * Shifts in a real browser, on the sample salon demo:reset builds: in the week of the reset Maria
 * repeats every two weeks from its first day, Eleni's Wednesday is changed for that day alone and
 * Giorgos's Thursday is an empty override. The owner signs in, opens Shifts from the rail and sees
 * those states, moves a week on and back, gives Katerina's Thursday a second window in the day
 * editor and sees the cell, the day's total and her row head change, then sets the day back to the
 * pattern and sees them as they were. On the system clock: demo:reset seeds the current week, so
 * the dates are the override rows' own. The salon's owner has no password anybody signs in with, so
 * the walk gives it the demo one.
 */

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(function () {
    dropDemoSchemas();
    dropSiteDemo();
});

/** A sample salon stylist's id. */
function shiftsWalkStaff(string $schema, string $name): int
{
    $id = DB::table($schema.'.staff')->where('name', $name)->value('id');
    assert(is_int($id));

    return $id;
}

/** The date of a stylist's one override in the sample week, YYYY-MM-DD. */
function shiftsWalkOverride(string $schema, int $staffId): string
{
    $date = DB::table($schema.'.shift_overrides')->where('staff_id', $staffId)->sole()->date;
    assert(is_string($date));

    return substr($date, 0, 10);
}

/** The cell of a member's date on the grid. */
function shiftsWalkCell(int $staffId, string $date): string
{
    return sprintf('.admin-shifts__row[data-staff="%d"] .admin-shifts__cell[data-date="%s"]', $staffId, $date);
}

/** A row head's sentence for a weekly member with the whole hours given, the figure as ICU spells it in English. */
function shiftsWalkWeekly(int $hours): string
{
    return adminLine('admin/shifts.page.row_weekly', ['hours' => $hours.'h']);
}

it('walks the sample week, moves a week, gives a day two windows and sets it back to the pattern', function () {
    expect(Artisan::call('demo:reset'))->toBe(0);
    $demo = siteDemoSlug();
    $schema = $demo->schemaName();
    // The sample salon is Greek; its owner chose English here, the language this walk reads.
    DB::table('users')->where('email', DemoSalon::OWNER_EMAIL)->update(['password' => Hash::make(Admin::DEMO_PASSWORD), 'locale' => 'en']);
    $eleni = shiftsWalkStaff($schema, 'Eleni Papadopoulou');
    $giorgos = shiftsWalkStaff($schema, 'Giorgos Antoniou');
    $maria = shiftsWalkStaff($schema, DemoSalon::FORTNIGHTLY);
    $katerina = shiftsWalkStaff($schema, 'Katerina Nikolaou');
    $wednesday = shiftsWalkOverride($schema, $eleni);
    $thursday = shiftsWalkOverride($schema, $giorgos);
    $utc = new DateTimeZone('UTC');
    $nextThursday = (new DateTimeImmutable($thursday, $utc))->modify('+7 days')->format('Y-m-d');
    $format = DB::table($schema.'.settings')->where('id', 1)->value('date_format');
    $startOfWeek = DB::table($schema.'.settings')->where('id', 1)->value('start_of_week');
    assert(is_string($format) && is_int($startOfWeek));
    // The week bar names a week by its range alone, the day heads its days without the year.
    $range = function (string $date) use ($startOfWeek, $format, $utc): string {
        $from = CalendarDay::weekStart($date, $startOfWeek);

        return adminLine('admin/shifts.page.week', ['from' => (new DateTimeImmutable($from, $utc))->format($format), 'to' => (new DateTimeImmutable(CalendarDay::plusDays($from, 6), $utc))->format($format)]);
    };
    $day = '24h';
    $longer = '25h';
    $thursdayTotal = sprintf('.admin-shifts__head[data-date="%s"] .admin-shifts__day-total', $thursday);
    $katerinaHead = sprintf('.admin-shifts__row[data-staff="%d"] .admin-shifts__row-head', $katerina);

    $page = Admin::signedIn($this, $demo->value, DemoSalon::OWNER_EMAIL)
        ->click('.admin-rail a[href="/admin/shifts"]')
        ->waitForText(adminLine('admin/shifts.page.lead'))
        ->assertPathIs('/admin/shifts')
        ->assertAttribute(shiftsWalkCell($eleni, $wednesday), 'data-source', 'override')
        ->assertSeeIn(shiftsWalkCell($eleni, $wednesday), '09:00-13:00')
        ->assertSeeIn(shiftsWalkCell($eleni, $wednesday), '14:00-18:00')
        ->assertSeeIn(shiftsWalkCell($eleni, $wednesday), adminLine('admin/shifts.page.this_day_only'))
        ->assertSeeIn(shiftsWalkCell($giorgos, $thursday), adminLine('admin/shifts.page.not_working'))
        ->assertAttribute(shiftsWalkCell($giorgos, $thursday), 'data-source', 'override')
        ->assertAttribute(shiftsWalkCell($maria, $thursday), 'data-source', 'pattern')
        ->assertSeeIn(shiftsWalkCell($katerina, $thursday), '09:00-17:00')
        ->assertSeeIn($thursdayTotal, $day)
        ->assertSeeIn($katerinaHead, shiftsWalkWeekly(38));

    // A week on, Maria's off week and nobody's day edited alone; and back.
    $page->click('.admin-shifts__next')
        ->waitForText($range($nextThursday))
        ->assertAttribute(shiftsWalkCell($maria, $nextThursday), 'data-source', 'none')
        ->assertAttribute(shiftsWalkCell($giorgos, $nextThursday), 'data-source', 'pattern')
        ->click('.admin-shifts__previous')
        ->waitForText($range($thursday))
        ->assertAttribute(shiftsWalkCell($giorgos, $thursday), 'data-source', 'override');

    // Katerina's Thursday gets a second window, an hour after the first ends.
    $page->click(shiftsWalkCell($katerina, $thursday).' .admin-shifts__edit')
        ->assertVisible('.admin-shift-day')
        ->click('.admin-shift-day__add')
        ->assertSeeIn('.admin-shift-day__hours', adminLine('admin/shifts.editor.hours', ['hours' => '9h']))
        ->click('.admin-shift-day__save')
        ->waitForText(adminLine('admin/shifts.flashes.day_saved'))
        ->click('.admin-toast-dismiss')
        ->assertMissing('.admin-shift-day')
        ->assertSeeIn(shiftsWalkCell($katerina, $thursday), '18:00-19:00')
        ->assertSeeIn(shiftsWalkCell($katerina, $thursday), adminLine('admin/shifts.page.this_day_only'))
        ->assertSeeIn($thursdayTotal, $longer)
        ->assertSeeIn($katerinaHead, shiftsWalkWeekly(39));

    // Back to the pattern.
    $page->click(shiftsWalkCell($katerina, $thursday).' .admin-shifts__edit')
        ->click('.admin-shift-day__back')
        ->waitForText(adminLine('admin/shifts.flashes.day_reset'))
        ->assertMissing('.admin-shift-day')
        ->assertAttribute(shiftsWalkCell($katerina, $thursday), 'data-source', 'pattern')
        ->assertDontSeeIn(shiftsWalkCell($katerina, $thursday), '18:00-19:00')
        ->assertSeeIn($thursdayTotal, $day)
        ->assertSeeIn($katerinaHead, shiftsWalkWeekly(38))
        ->assertNoJavaScriptErrors();
});
