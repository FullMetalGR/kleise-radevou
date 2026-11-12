<?php

/*
 * The Shifts screen in English: the words of the screen, shared to it as the strings prop's shifts
 * section, which ShiftsController names, in the language of the request, the user's, and read on
 * the server through the translator in the same. Its Greek twin is lang/el/admin/shifts.php.
 *
 * page is the screen's heading, its sentence and the week's range (:from and :to its first and last
 * dates in the tenant's format), the week's controls (previous and next the chevrons' names for
 * assistive technology) and the line of a week that did not load, the member column's head, a day
 * column's head (:weekday ICU's short name, :date the day and month in the tenant's format), a
 * figure of hours and minutes (:hours and :minutes, each a figure with its unit as ICU spells it
 * in the language, lib/names.ts; a whole figure of hours is ICU's alone), the row head (:hours the
 * week's figure, :weeks the cadence and :from the pattern's start), a day's states (not working,
 * the line under a day edited alone, and a closure with :start, :end and :reason, or the whole
 * day), a cell's description for assistive technology (:name and :date), the row head's Pattern
 * button and its name (:name), the legend's three entries, each one whole line whose :sample is
 * its sample pill (a window in the tenant's time format, page.not_working and the closure's sample
 * word, legend_closed), the footnote on the opening hours and the line of a roster with nobody to
 * list.
 *
 * editor is the day editor: its title (:name, :weekday ICU's long name and :date), its help, the
 * fields, the line of the day's hours (:hours the page's figure, whole hours an hour figure alone)
 * and of what the pattern gives (:windows its windows), the four actions with their busy words, and
 * the toast where the write failed and the server worded nothing.
 *
 * pattern is the pattern editor, opened by the row head's Pattern button (page.pattern, named for
 * assistive technology by page.edit_pattern with :name): its title (:name), the note on what a save
 * reaches, Repeats and its four cadences (:weeks the count), Starts on, Ends with never or a date,
 * the line on the cadence (:weeks the count), a day not worked, a window's fields named for
 * assistive technology and the window as one whole line around them (:start and :end the two
 * fields), the line of the week's hours (:hours the page's figure, as the day editor's), Cancel,
 * Save pattern with its busy word, and the toast where the save failed and the server worded
 * nothing.
 *
 * validation, flashes and refusals are the server's: the pattern's and the day's refusals, and
 * the three writes' toasts.
 */

return [
    'page' => [
        'title' => 'Shifts',
        'lead' => 'The hours each member can be booked, week by week. A day\'s cell changes that day alone; Pattern changes the week it repeats.',
        'week' => ':from to :to',
        'this_week' => 'This week',
        'previous' => 'Previous week',
        'next' => 'Next week',
        'loading' => 'Loading',
        'read_error' => 'The week could not be loaded. Check your connection and try again.',
        'member' => 'Staff member',
        'day_head' => ':weekday :date',
        'hours_minutes' => ':hours :minutes',
        'row_weekly' => ':hours this week, every week',
        'row_cadence' => ':hours this week, every :weeks weeks from :from',
        'row_none' => ':hours this week, no repeating pattern',
        'not_working' => 'Not working',
        'this_day_only' => 'This day only',
        'closed_all_day' => 'Closed all day',
        'closed' => 'Closed :start-:end',
        'closed_reason' => 'Closed :start-:end, :reason',
        'closed_all_day_reason' => 'Closed all day, :reason',
        'edit_day' => 'Edit the hours of :name on :date',
        'pattern' => 'Pattern',
        'edit_pattern' => 'Edit the repeating pattern of :name',
        'legend_override' => ':sample changed for that day alone',
        'legend_off' => ':sample no hours that day',
        'legend_closed' => 'Closed',
        'legend_closure' => ':sample a closure, set on the Staff screen or the calendar',
        'footnote' => 'Customers book inside these hours. Opening hours in Settings describe the business; a closed day closes everyone.',
        'empty' => 'No active staff to schedule.',
    ],
    'editor' => [
        'title' => ':name, :weekday :date',
        'help' => 'This day alone. The repeating pattern is not changed.',
        'start' => 'Start',
        'end' => 'End',
        'remove' => 'Remove',
        'add_first' => 'Add a window',
        'add_second' => 'Add a second window',
        'hours' => ':hours on this day.',
        'pattern_gives' => 'The pattern gives :windows.',
        'pattern_none' => 'The pattern gives no hours.',
        'not_working' => 'Not working this day',
        'back' => 'Back to the pattern',
        'cancel' => 'Cancel',
        'save' => 'Save',
        'saving' => 'Saving',
        'going_back' => 'Going back',
        'not_saved' => 'The day could not be saved. Check your connection and try again.',
    ],
    'pattern' => [
        'title' => ':name, repeating pattern',
        'note' => 'Saved changes apply to every week from the start date on. Days already changed on their own keep their hours.',
        'repeats' => 'Repeats',
        'every_week' => 'Every week',
        'every_weeks' => 'Every :weeks weeks',
        'starts_on' => 'Starts on',
        'ends' => 'Ends',
        'never' => 'Never',
        'on_date' => 'On a date',
        'ends_on' => 'Ends on',
        'help_weekly' => 'Every week: these hours repeat each week.',
        'help_cadence' => 'Every :weeks weeks: the week of the start date, then every :weeks weeks after it. The other weeks have no hours unless a day is set on its own.',
        'not_working' => 'Not working',
        'start' => 'Start',
        'end' => 'End',
        'window' => ':start to :end',
        'remove' => 'Remove',
        'add_second' => 'Add a second window',
        'hours' => ':hours a week. No hours are scheduled on a closed day.',
        'cancel' => 'Cancel',
        'save' => 'Save pattern',
        'saving' => 'Saving',
        'not_saved' => 'The pattern could not be saved. Check your connection and try again.',
    ],
    'validation' => [
        'cadence' => 'Repeats is every week or every 2, 3 or 4 weeks.',
        'starts_on' => 'Starts on needs a date, such as 2026-11-02.',
        'ends_on' => 'Ends on needs a date on or after Starts on, or none.',
        'day_list' => 'Send the day\'s windows as a list, an empty one for not working.',
        'day_times' => 'Each window needs a start and an end, such as 09:00 and 17:00.',
        'day_order' => 'Each window must start before it ends.',
        'day_overlap' => 'Two windows of a day must not overlap.',
    ],
    'flashes' => [
        'pattern_saved' => 'Pattern saved.',
        'day_saved' => 'Day saved.',
        'day_reset' => 'Day back to the pattern.',
    ],
];
