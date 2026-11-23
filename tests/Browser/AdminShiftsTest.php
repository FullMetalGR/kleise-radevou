<?php

use App\Domain\Availability\CalendarDay;
use App\Site\DemoSalon;
use App\Site\SiteLanguage;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\Webpage;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\Look;
use Tests\Browser\Support\PhoneScripts;
use Tests\Support\LunaFixture;

/*
 * Shifts in a real browser, on the sample salon demo:reset builds: in the week of the reset Maria
 * repeats every two weeks from its first day, Eleni's Wednesday is changed for that day alone and
 * Giorgos's Thursday is an empty override. The owner signs in, opens Shifts from the rail and sees
 * those states, moves a week on and back, gives Katerina's Thursday a second window in the day
 * editor and sees the cell, the day's total and her row head change, then sets the day back to the
 * pattern and sees them as they were. On the system clock: demo:reset seeds the current week, so
 * the dates are the override rows' own. The salon's owner has no password anybody signs in with, so
 * the walk gives it the demo one.
 *
 * The layout, on luna's week with a closure whose long reason holds words wider than a day's
 * column, a day changed alone, a day emptied alone and a member's name of sixty characters, in
 * Greek: at 1440 pixels the week is a grid of eight columns inside its card, today's head on the
 * highlight; at the narrowest window whose card still holds the grid it fits with nothing crossing
 * its cell or running out of its own box; one step narrower each member is a block of seven day
 * rows; both editors are read; and back at 1440 with the page scrolled, the head row rests at the
 * window's top with its line under it, beside a legend whose three samples stand one size. On a
 * phone, in English and in Greek, each member is a block of seven rows, the day's name then its
 * pills to the row's end, every edit control at least 44 pixels tall, nothing scrolling sideways,
 * and both editors fit the sheet.
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
        ->assertSeeIn('.admin-shift-day__hours', adminLine('admin/shifts.editor.hours', ['hours' => '9h']));
    Look::shot($page, 'shift-day-editor-1728');

    $page->click('.admin-shift-day__save')
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

/** A member's name of sixty characters, which wraps inside the member's cell. */
const SHIFTS_LONG_MEMBER = 'Elena Theodora Papadaki-Chatzikonstantinou Vasilopoulou-Daki';

/**
 * A closure's reason long enough to wrap its pill over several lines in a day's column, holding a
 * booking reference of thirty-five characters and a Greek word of eighteen letters, each wider
 * than a day's column and with nowhere to break but inside the word.
 */
const SHIFTS_LONG_REASON = "Dentist across town, booking DENTALCLINICATHENS20261202CONFIRMED, then \u{03C6}\u{03C5}\u{03C3}\u{03B9}\u{03BA}\u{03BF}\u{03B8}\u{03B5}\u{03C1}\u{03B1}\u{03C0}\u{03B5}\u{03C5}\u{03C4}\u{03AE}\u{03C1}\u{03B9}\u{03BF}";

/**
 * The narrowest window, with the sidebar open, whose Shifts card is 896 pixels wide with two to
 * spare, so the week is drawn as the grid there; four pixels narrower it is day rows.
 */
const SHIFTS_FIRST_GRID_WIDTH = 1214;

/**
 * What keeps the week's grid from standing inside its card, as words, so a failure says where: the
 * grid not drawn as a table, a row of other than eight columns, the table past the card's edge, the
 * card scrolling sideways, or a pill, a name, a line or a button crossing its cell or its own box.
 */
const SHIFTS_GRID_FITS = <<<'JS'
    document.fonts.ready.then(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => {
        const card = document.querySelector('.admin-shifts');
        const table = card?.querySelector(':scope > table');
        if (!card || !table) {
            resolve(['no grid']);
            return;
        }
        const wrong = [];
        if (getComputedStyle(table).display !== 'table') {
            wrong.push('the week is not a grid');
        }
        for (const row of table.querySelectorAll('tr')) {
            if ([...row.children].filter((cell) => getComputedStyle(cell).display === 'table-cell').length !== 8) {
                wrong.push('a row of other than eight columns');
            }
        }
        const edge = card.getBoundingClientRect();
        const grid = table.getBoundingClientRect();
        if (grid.left < edge.left - 0.5 || grid.right > edge.right + 0.5) {
            wrong.push(`the table ${Math.ceil(Math.max(edge.left - grid.left, grid.right - edge.right))}px past the card`);
        }
        if (card.scrollWidth > card.clientWidth + 1) {
            wrong.push(`the card scrolls, ${card.scrollWidth}px of ${card.clientWidth}px`);
        }
        const parts = '.admin-shifts__pill, .admin-shifts__closure, .admin-shifts__alone, .admin-shifts__initials, .admin-shifts__name, .admin-shifts__row-head, .admin-shifts__pattern, .admin-shifts__day-name, .admin-shifts__day-total, .admin-shifts__member-head';
        for (const part of table.querySelectorAll(parts)) {
            const own = part.getBoundingClientRect();
            const cell = part.closest('th, td').getBoundingClientRect();
            const name = part.className.split(' ').find((each) => each.startsWith('admin-shifts__'));
            if (own.left < cell.left - 1 || own.right > cell.right + 1) {
                wrong.push(`${name} crosses its cell by ${Math.ceil(Math.max(cell.left - own.left, own.right - cell.right))}px`);
            }
            if (part.clientWidth > 0 && part.scrollWidth > part.clientWidth + 1) {
                wrong.push(`${name} runs out of its own box`);
            }
        }
        resolve([...new Set(wrong)]);
    }))))
    JS;

/**
 * What keeps the week from reading as day rows, as words: the head row on screen, a member not a
 * block or not seven days, a day beside the member's line or beside another day, a day row short
 * of the block's width, a day without its name, the pills not running from after the name to the
 * row's end, a pill crossing its row or running out of its own box, or an edit control under 44
 * pixels tall.
 */
const SHIFTS_DAY_ROWS = <<<'JS'
    document.fonts.ready.then(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => {
        const card = document.querySelector('.admin-shifts');
        const rows = [...document.querySelectorAll('.admin-shifts__row')];
        if (!card || rows.length === 0) {
            resolve(['no grid']);
            return;
        }
        const wrong = [];
        const head = card.querySelector('thead').getBoundingClientRect();
        if (head.width > 1 || head.height > 1) {
            wrong.push('the head row is on screen');
        }
        const edge = card.getBoundingClientRect();
        for (const row of rows) {
            const block = row.getBoundingClientRect();
            const member = row.querySelector('.admin-shifts__member').getBoundingClientRect();
            const days = [...row.querySelectorAll('.admin-shifts__cell')];
            if (getComputedStyle(row).display !== 'block') {
                wrong.push('a member is not a block');
            }
            if (block.left < edge.left - 1 || block.right > edge.right + 1) {
                wrong.push('a member crosses the card');
            }
            if (days.length !== 7) {
                wrong.push('a member without seven days');
            }
            days.forEach((day, at) => {
                const box = day.getBoundingClientRect();
                const label = day.querySelector('.admin-shifts__cell-day');
                const stack = label?.nextElementSibling;
                if (getComputedStyle(day).display !== 'flex') {
                    wrong.push('a day is not a row');
                }
                if (!label || !stack || getComputedStyle(label).display === 'none') {
                    wrong.push('a day without its name');
                    return;
                }
                if (box.top < member.bottom - 1 || (at > 0 && box.top < days[at - 1].getBoundingClientRect().bottom - 1)) {
                    wrong.push('a day beside another line');
                }
                if (Math.abs(box.left - block.left) > 1 || Math.abs(box.right - block.right) > 1) {
                    wrong.push('a day row short of the block');
                }
                const name = label.getBoundingClientRect();
                const pills = stack.getBoundingClientRect();
                if (pills.left < name.right - 1 || Math.abs(pills.right - box.right) > 1) {
                    wrong.push('the pills do not run from the name to the row\'s end');
                }
                for (const pill of day.querySelectorAll('.admin-shifts__pill, .admin-shifts__closure, .admin-shifts__edit')) {
                    const own = pill.getBoundingClientRect();
                    if (own.left < pills.left - 1 || own.right > pills.right + 1) {
                        wrong.push('a pill crosses its row');
                    }
                    if (pill.matches('.admin-shifts__pill') && pill.clientWidth > 0 && pill.scrollWidth > pill.clientWidth + 1) {
                        wrong.push('a pill runs out of its own box');
                    }
                }
                const edit = day.querySelector('.admin-shifts__edit');
                if (edit !== null && Math.abs(edit.getBoundingClientRect().width - pills.width) > 1) {
                    wrong.push('an edit control short of the row');
                }
                if (edit !== null && edit.getBoundingClientRect().height < 44) {
                    wrong.push(`an edit control ${edit.getBoundingClientRect().height}px tall`);
                }
            });
        }
        resolve([...new Set(wrong)]);
    }))))
    JS;

/**
 * What keeps the open editor from fitting its dialog, as words: the body scrolling sideways, a
 * control or a label crossing the body's sides, or a time field whose time runs out of it.
 */
const SHIFTS_EDITOR_FITS = <<<'JS'
    document.fonts.ready.then(() => new Promise((resolve) => requestAnimationFrame(() => {
        const body = Array.from(document.querySelectorAll('.admin-modal__frame .admin-modal__body')).at(-1);
        if (!body) {
            resolve(['no editor']);
            return;
        }
        const wrong = [];
        if (body.scrollWidth > body.clientWidth + 1) {
            wrong.push(`the body scrolls, ${body.scrollWidth}px of ${body.clientWidth}px`);
        }
        const room = body.getBoundingClientRect();
        for (const part of body.querySelectorAll('button, input, select, label')) {
            const own = part.getBoundingClientRect();
            if (own.width > 0 && (own.left < room.left - 1 || own.right > room.right + 1)) {
                wrong.push(`${part.className.split(' ').find((each) => each.startsWith('admin-')) ?? part.tagName} crosses the body`);
            }
        }
        for (const field of body.querySelectorAll('.admin-shift-day__start, .admin-shift-day__end, .admin-shift-pattern__start, .admin-shift-pattern__end')) {
            const range = document.createRange();
            range.selectNodeContents(field);
            const text = range.getBoundingClientRect();
            const own = field.getBoundingClientRect();
            if (text.left < own.left - 1 || text.right > own.right + 1) {
                wrong.push('a time runs out of its field');
            }
        }
        resolve([...new Set(wrong)]);
    })))
    JS;

/**
 * What keeps the head row from carrying its lower line while the page scrolls under it, as words,
 * once the week's top is scrolled out of the window: the head not resting at the window's top, the
 * table's borders collapsed or spaced apart, where the browser paints no line a held head cell
 * draws below itself, or a head cell drawing no line of a visible colour one pixel below its foot.
 */
const SHIFTS_HEAD_HELD = <<<'JS'
    document.fonts.ready.then(() => new Promise((resolve) => {
        const table = document.querySelector('.admin-shifts > table');
        const head = table?.querySelector('thead');
        if (!table || !head) {
            resolve(['no grid']);
            return;
        }
        window.scrollTo(0, table.getBoundingClientRect().top + window.scrollY + 60);
        requestAnimationFrame(() => requestAnimationFrame(() => {
            const wrong = [];
            const box = head.getBoundingClientRect();
            const week = table.getBoundingClientRect();
            if (Math.abs(box.top) > 1 || week.top > -20 || week.bottom < box.bottom + 40) {
                wrong.push(`the head is not held: its top at ${Math.round(box.top)}px, the week's at ${Math.round(week.top)}px`);
            }
            const model = getComputedStyle(table);
            if (model.borderCollapse !== 'separate' || model.borderSpacing.split(' ').some((each) => parseFloat(each) !== 0)) {
                wrong.push(`the table's borders are ${model.borderCollapse}, ${model.borderSpacing} apart`);
            }
            for (const cell of head.querySelectorAll('th')) {
                const shadows = getComputedStyle(cell).boxShadow.split(/,(?![^(]*\))/).map((each) => each.trim());
                if (!shadows.some((each) => each.endsWith(' 0px 1px 0px 0px') && !each.startsWith('rgba(0, 0, 0, 0)'))) {
                    wrong.push(`${cell.className.split(' ').find((each) => each.startsWith('admin-shifts__'))} draws no line along its foot`);
                }
            }
            resolve([...new Set(wrong)]);
        }));
    }))
    JS;

/**
 * Where the held head's lower line falls in the window and what it should look like: the pixel
 * row just under the head, its foot rounded to the whole pixel the line is drawn from, the first
 * and last pixel across the head, and, as red, green and blue, the colour of the rule a body cell
 * draws and the colour of the card a head cell stands on.
 */
const SHIFTS_HEAD_FOOT = <<<'JS'
    (() => {
        const head = document.querySelector('.admin-shifts > table > thead');
        const body = document.querySelector('.admin-shifts__cell');
        if (!head || !body) {
            return null;
        }
        const paint = document.createElement('canvas').getContext('2d', { willReadFrequently: true });
        const rgb = (colour) => {
            paint.clearRect(0, 0, 1, 1);
            paint.fillStyle = colour;
            paint.fillRect(0, 0, 1, 1);
            return [...paint.getImageData(0, 0, 1, 1).data.slice(0, 3)];
        };
        const box = head.getBoundingClientRect();
        return {
            row: Math.round(box.bottom),
            from: Math.ceil(box.left),
            to: Math.floor(box.right) - 1,
            rule: rgb(getComputedStyle(body).borderTopColor),
            card: rgb(getComputedStyle(head.querySelector('th')).backgroundColor),
        };
    })()
    JS;

/**
 * A colour a script answered as a list of its red, green and blue.
 *
 * @return array{int, int, int}
 */
function shiftsRgb(mixed $channels): array
{
    assert(is_array($channels) && count($channels) === 3);
    [$red, $green, $blue] = array_values($channels);
    assert(is_int($red) && is_int($green) && is_int($blue));

    return [$red, $green, $blue];
}

/**
 * How far apart two colours are: the most any one of red, green and blue differs.
 *
 * @param  array{int, int, int}  $one
 * @param  array{int, int, int}  $other
 */
function shiftsColoursApart(array $one, array $other): int
{
    return max(abs($one[0] - $other[0]), abs($one[1] - $other[1]), abs($one[2] - $other[2]));
}

/**
 * What keeps the held head's lower line from showing on screen, as words. The window is saved as
 * an image, one pixel per point, and read along the pixel row just under the head from its first
 * pixel across to its last: each is the rule's colour, within a few steps of rounding, and nearer
 * it than the card's. The image is removed once read.
 *
 * @return list<string>
 */
function shiftsHeadLineOnScreen(AwaitableWebpage|Webpage $page): array
{
    $foot = $page->script(SHIFTS_HEAD_FOOT);
    assert(is_array($foot) && is_int($foot['row']) && is_int($foot['from']) && is_int($foot['to']));
    $rule = shiftsRgb($foot['rule']);
    $card = shiftsRgb($foot['card']);

    if (shiftsColoursApart($rule, $card) <= 8) {
        return ['the rule cannot be told from the card'];
    }

    $name = 'shifts-head-line';
    $file = base_path('tests/Browser/Screenshots/'.$name.'.png');
    $page->screenshot(false, $name);

    try {
        $shot = imagecreatefrompng($file);
        assert($shot instanceof GdImage);
        $off = [];

        for ($x = $foot['from']; $x <= $foot['to']; $x++) {
            $index = imagecolorat($shot, $x, $foot['row']);
            assert(is_int($index));
            $pixel = imagecolorsforindex($shot, $index);
            $seen = [$pixel['red'], $pixel['green'], $pixel['blue']];

            if (shiftsColoursApart($seen, $rule) > 8 || shiftsColoursApart($seen, $rule) >= shiftsColoursApart($seen, $card)) {
                $off[$x] = $seen;
            }
        }

        if ($off === []) {
            return [];
        }

        $first = array_key_first($off);

        return [sprintf('%d of %d pixels under the head at %dpx down are not the rule rgb(%s), the first at %dpx across rgb(%s), the card rgb(%s)', count($off), $foot['to'] - $foot['from'] + 1, $foot['row'], implode(', ', $rule), $first, implode(', ', $off[$first]), implode(', ', $card))];
    } finally {
        unlink($file);
    }
}

/**
 * What keeps the legend's three samples from standing one size, as words: a sample taller or
 * shorter than the others, or one leaving its words more or less room across, padding and line
 * on both sides. The day off's 1.5px line is drawn in whole device pixels, one pixel at one pixel
 * per point, so there its sample may stand a pixel off the others; a line taken off nothing
 * stands two pixels off.
 */
const SHIFTS_LEGEND_SAMPLES = <<<'JS'
    document.fonts.ready.then(() => new Promise((resolve) => requestAnimationFrame(() => {
        const samples = [...document.querySelectorAll('.admin-shifts__legend-sample')];
        if (samples.length !== 3) {
            resolve(['no legend']);
            return;
        }
        const wrong = [];
        const heights = samples.map((sample) => sample.getBoundingClientRect().height);
        const across = samples.map((sample) => {
            const style = getComputedStyle(sample);
            return 2 * (parseFloat(style.paddingLeft) + parseFloat(style.borderLeftWidth));
        });
        if (heights.some((height) => Math.abs(height - heights[0]) > 1.25)) {
            wrong.push(`the samples stand ${heights.map((height) => height.toFixed(2)).join(', ')}px tall`);
        }
        if (across.some((room) => Math.abs(room - across[0]) > 1.25)) {
            wrong.push(`the samples leave ${across.map((room) => room.toFixed(2)).join(', ')}px across`);
        }
        resolve(wrong);
    })))
    JS;

/**
 * Luna's week as Shifts shows it today, set up so every kind of day is in view: a closure of
 * Elena's with a long reason on her Wednesday, Nikos's Thursday changed alone to other hours and
 * Elena's Tuesday emptied alone, beside the days each has off; Elena's name made sixty characters
 * long. Answers Elena's id and the dates of the week, from the tenant's week start.
 *
 * @return array{elena: int, nikos: int, dates: list<string>}
 */
function shiftsLunaWeek(): array
{
    $start = DB::table('tenant_luna.settings')->where('id', 1)->value('start_of_week');
    assert(is_int($start));
    $first = CalendarDay::weekStart(athensToday()->format('Y-m-d'), $start);
    $dates = array_map(fn (int $day): string => CalendarDay::plusDays($first, $day), range(0, 6));
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');
    DB::table('tenant_luna.blackouts')->insert(['staff_id' => $elena, 'date' => $dates[2], 'start_time' => '14:00', 'end_time' => '16:00', 'reason' => SHIFTS_LONG_REASON]);
    DB::table('tenant_luna.shift_overrides')->insert(['staff_id' => $nikos, 'date' => $dates[3], 'windows' => json_encode([['start' => '12:00', 'end' => '20:00']], JSON_THROW_ON_ERROR)]);
    DB::table('tenant_luna.shift_overrides')->insert(['staff_id' => $elena, 'date' => $dates[1], 'windows' => '[]']);
    DB::table('tenant_luna.staff')->where('id', $elena)->update(['name' => SHIFTS_LONG_MEMBER]);

    return ['elena' => $elena, 'nikos' => $nikos, 'dates' => $dates];
}

it('draws luna\'s week in Greek as a grid of eight columns inside its card at 1440 pixels and at the narrowest window that holds it, folds it into day rows one step narrower, reads both editors, and holds the head row\'s line at the top as the page scrolls', function () {
    expect(strlen(SHIFTS_LONG_MEMBER))->toBe(60);
    expect(max(array_map(mb_strlen(...), explode(' ', SHIFTS_LONG_REASON))))->toBeGreaterThanOrEqual(30);
    $el = SiteLanguage::Greek;
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => $el->value]);
    ['elena' => $elena, 'nikos' => $nikos, 'dates' => $dates] = shiftsLunaWeek();
    $cell = fn (int $staffId, int $day): string => shiftsWalkCell($staffId, $dates[$day]);
    $card = "document.querySelector('.admin-shifts').clientWidth";

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->resize(1440, 900);
    $page->navigate(Admin::url('luna', '/shifts'))
        ->waitForText(adminLine('admin/shifts.page.lead', [], $el))
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, $el->value)
        ->assertSeeIn($cell($elena, 2), SHIFTS_LONG_REASON)
        ->assertSeeIn($cell($elena, 1), adminLine('admin/shifts.page.this_day_only', [], $el))
        ->assertSeeIn($cell($nikos, 3), '12:00-20:00')
        ->assertSeeIn($cell($nikos, 0), adminLine('admin/shifts.page.not_working', [], $el))
        ->assertAttribute('.admin-shifts__head[aria-current="date"]', 'data-date', athensToday()->format('Y-m-d'))
        ->assertScript(SHIFTS_GRID_FITS, [])
        ->assertScript(sprintf(SHIFTS_WEEK_ROW, 'false'), [])
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'shifts-1440-el');

    $page->click($cell($elena, 2).' .admin-shifts__edit')
        ->assertVisible('.admin-shift-day')
        ->assertScript(SHIFTS_EDITOR_FITS, [])
        ->assertScript(PhoneScripts::DIALOG_FOOTER_BUTTONS_INSIDE, true);
    Look::shot($page, 'shifts-day-editor-1440-el');
    $page->click('.admin-shift-day__cancel')
        ->assertMissing('.admin-shift-day')
        ->assertScript(Admin::focusIsOn($cell($elena, 2).' .admin-shifts__edit'), true)
        ->click(sprintf('.admin-shifts__row[data-staff="%d"] .admin-shifts__pattern', $nikos))
        ->assertVisible('.admin-shift-pattern')
        ->assertScript(SHIFTS_EDITOR_FITS, [])
        ->assertScript(PhoneScripts::DIALOG_FOOTER_BUTTONS_INSIDE, true);
    Look::shot($page, 'shifts-pattern-editor-1440-el');
    $page->click('.admin-shift-pattern__cancel')
        ->assertMissing('.admin-shift-pattern')
        ->assertScript(Admin::focusIsOn(sprintf('.admin-shifts__row[data-staff="%d"] .admin-shifts__pattern', $nikos)), true);

    $page->resize(SHIFTS_FIRST_GRID_WIDTH, 900)
        ->assertScript("{$card} >= 896 && {$card} <= 900", true)
        ->assertScript(SHIFTS_GRID_FITS, [])
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'shifts-'.SHIFTS_FIRST_GRID_WIDTH.'-el');

    $page->resize(SHIFTS_FIRST_GRID_WIDTH - 4, 900)
        ->assertScript("{$card} < 896", true)
        ->assertScript(SHIFTS_DAY_ROWS, [])
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'shifts-'.(SHIFTS_FIRST_GRID_WIDTH - 4).'-el');

    // Back at 1440 in a short window, so the page scrolls far enough to hold the head at the top.
    $page->resize(1440, 400);
    expect(['head' => $page->script(SHIFTS_HEAD_HELD), 'line' => shiftsHeadLineOnScreen($page), 'samples' => $page->script(SHIFTS_LEGEND_SAMPLES)])->toBe(['head' => [], 'line' => [], 'samples' => []]);
    $page->assertNoJavaScriptErrors();
});

/**
 * What is wrong with the header and the week's row. The header's row is one line: the menu's
 * button where the shell shows one, the title and the shell's button, each centred on the title's
 * height. The week's row stands under the header outside it: This week, the previous week's
 * chevron and the next week's in that order on one line, then the week's range. With %s true the
 * range is a line of its own under the buttons, as wide as the row; with it false it is on the
 * buttons' line after them and reaches the row's far end. Nothing passes the page's width. An
 * empty list where nothing is.
 */
const SHIFTS_WEEK_ROW = <<<'JS'
    document.fonts.ready.then(() => {
        const apart = %s;
        const header = document.querySelector('.admin-page-header__row');
        const bar = document.querySelector('.admin-shifts__bar');
        const [title, thisWeek, previous, next, week] = ['.admin-page-title', '.admin-shifts__this-week', '.admin-shifts__previous', '.admin-shifts__next', '.admin-shifts__week'].map((selector) => document.querySelector(selector)?.getBoundingClientRect());
        if (header === null || bar === null || [title, thisWeek, previous, next, week].includes(undefined)) {
            return ['missing'];
        }
        const middle = (box) => box.top + box.height / 2;
        const wrong = [];
        if ([...header.children].some((child) => Math.abs(middle(child.getBoundingClientRect()) - middle(title)) > 1)) {
            wrong.push('header on more than one line');
        }
        if (bar.closest('.admin-page-header') !== null) {
            wrong.push('week inside the header');
        }
        if (Math.abs(middle(previous) - middle(thisWeek)) > 1 || Math.abs(middle(next) - middle(thisWeek)) > 1) {
            wrong.push('buttons not on one line');
        }
        if (thisWeek.right > previous.left || previous.right > next.left) {
            wrong.push('buttons out of order');
        }
        const row = bar.getBoundingClientRect();
        if (apart && (week.top < Math.max(thisWeek.bottom, next.bottom) || Math.abs(week.left - row.left) > 1 || Math.abs(week.right - row.right) > 1)) {
            wrong.push('range not on a line of its own');
        }
        if (!apart && (week.top > next.bottom || week.left < next.right || Math.abs(week.right - row.right) > 1)) {
            wrong.push('range not after the buttons to the row\'s end');
        }
        if (Math.max(next.right, week.right, row.right) > document.documentElement.clientWidth) {
            wrong.push('past the page');
        }
        return wrong;
    })
    JS;

it('stands the week in its own row under a header of one line on a phone, the range on a line of its own under the buttons, at 390 and at 320 pixels', function (SiteLanguage $language) {
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => $language->value]);
    shiftsLunaWeek();

    $page = Admin::signInOnPhone($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME);
    $page->navigate(Admin::url('luna', '/shifts'))
        ->waitForText(adminLine('admin/shifts.page.lead', [], $language))
        ->assertScript(PhoneScripts::VIEWPORT_WIDTH, 390)
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, $language->value)
        ->assertScript(sprintf(SHIFTS_WEEK_ROW, 'true'), [])
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);

    $page->resize(320, 700)
        ->assertScript(sprintf(SHIFTS_WEEK_ROW, 'true'), [])
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertNoJavaScriptErrors();
})->with([SiteLanguage::English, SiteLanguage::Greek]);

it('reads each member as a block of seven day rows on a phone, the pills running to the row\'s end, every edit control at least 44 pixels tall and both editors inside the sheet', function (SiteLanguage $language) {
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => $language->value]);
    ['elena' => $elena, 'nikos' => $nikos, 'dates' => $dates] = shiftsLunaWeek();
    $edit = shiftsWalkCell($elena, $dates[2]).' .admin-shifts__edit';
    $pattern = sprintf('.admin-shifts__row[data-staff="%d"] .admin-shifts__pattern', $nikos);

    $page = Admin::signInOnPhone($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME);
    $page->navigate(Admin::url('luna', '/shifts'))
        ->waitForText(adminLine('admin/shifts.page.lead', [], $language))
        ->assertScript(PhoneScripts::VIEWPORT_WIDTH, 390)
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, $language->value)
        ->assertSeeIn(shiftsWalkCell($elena, $dates[2]), SHIFTS_LONG_REASON)
        ->assertScript(SHIFTS_DAY_ROWS, [])
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'shifts-390-'.$language->value);

    $page->click($edit)
        ->assertVisible('.admin-shift-day')
        ->assertScript(PhoneScripts::SHEET_AT_BOTTOM, true)
        ->assertScript(SHIFTS_EDITOR_FITS, [])
        ->assertScript(PhoneScripts::DIALOG_FOOTER_BUTTONS_INSIDE, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'shifts-day-editor-390-'.$language->value);
    $page->click('.admin-shift-day__cancel')
        ->assertMissing('.admin-shift-day')
        ->assertScript(Admin::focusIsOn($edit), true)
        ->click($pattern)
        ->assertVisible('.admin-shift-pattern')
        ->assertScript(SHIFTS_EDITOR_FITS, [])
        ->assertScript(PhoneScripts::DIALOG_FOOTER_BUTTONS_INSIDE, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'shifts-pattern-editor-390-'.$language->value);
    $page->click('.admin-shift-pattern__cancel')
        ->assertMissing('.admin-shift-pattern')
        ->assertScript(Admin::focusIsOn($pattern), true)
        ->assertNoJavaScriptErrors();
})->with([SiteLanguage::English, SiteLanguage::Greek]);
