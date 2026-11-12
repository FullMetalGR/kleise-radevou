<?php

/*
 * The Calendar screen in English: the words of the screen, shared to it as the strings prop's
 * calendar section, which CalendarController names, in the language of the request, the user's. Its
 * Greek twin is lang/el/admin/calendar.php. The booking dialog the screen opens reads bookings.php;
 * a closure drawn on a Day column reads its sentence from shifts.php's page group, which the screen
 * carries for the day editor, so each sentence is spelled once.
 *
 * page is the title and heading, the Add menu, its two items and the line under them on what a
 * closure is, the footnote under the grid's card, the line of a window that did not load, the toast
 * of a Colour by write that failed with nothing worded, and the line of a viewer pinned to their
 * own row whose sign-in names none.
 *
 * toolbar is the View control and its three views, Today, the names of Previous and Next, and
 * Day's title (:weekday ICU's long name of the day, :date in the tenant's format).
 *
 * filters is the filter card: Status with Any status, Service with All services and an inactive
 * service (:service its name), Colour by with its three choices and the three lines under it.
 *
 * who is the Who selector: its label, the line under it, the heading over the members, its three
 * presets, and the trigger's summary of a picked set (:staff the first member's name, :count how
 * many more).
 *
 * legend is the swatches' names by Colour by, the bucket of services with no category, the badges'
 * list and Day's list of closures and hours with its two entries, the gap's entry and the hint at
 * the legend's end on starting a booking from an empty slot; badges is the block badge of a
 * request (its meaning the status's own word) and the meaning of the payment badge (its word the
 * status's own).
 *
 * grid is a block's title (:customer and :service, or the erased customer's case with :service
 * alone), a processing gap's, Week's notice below the admin's breakpoint and its control.
 *
 * day is Day's member columns: the trailing column's head, the head of a member with no hours, the
 * pill of no hours ahead and of the next working day (:weekday ICU's name of it, :date in the
 * tenant's format, :hours its windows), the column menu's title (:staff and :date) and its four
 * items. Each reads on any date shown, never "today".
 *
 * closure is the closure dialog: its title, Who with the whole business, the date, the reason
 * (its help the shell's shared closure.reason_help), Closed all day, Start and End, Cancel and Add
 * with its busy word, and the toast where the write failed and the server worded nothing.
 *
 * validation is the server's: ColourByRequest's refusal of a word Colour by does not offer, which a
 * failed write's toast shows.
 */

return [
    'page' => [
        'title' => 'Calendar',
        'add' => 'Add',
        'add_booking' => 'New booking',
        'add_closure' => 'Closure',
        'add_hint' => 'A closure is a day or a window when someone, or the whole business, takes no bookings.',
        'load_error' => 'The calendar could not be loaded. Check your connection and change the view or the date to try again.',
        'colour_by_not_saved' => 'The colours could not be saved. Check your connection and try again.',
        'footnote' => 'Week keeps the time grid, narrowed by Who: one member is their seven days, several are overlaid as before. Month is the counts per day. The hours drawn come from the Shifts screen, for the day shown.',
        'unlinked' => 'Your sign-in is not linked to a staff member, so there is nothing to show here. The owner links it from the Staff screen.',
    ],
    'toolbar' => [
        'view' => 'View',
        'views' => [
            'week' => 'Week',
            'day' => 'Day',
            'month' => 'Month',
        ],
        'today' => 'Today',
        'previous' => 'Previous',
        'next' => 'Next',
        'day_title' => ':weekday :date',
    ],
    'filters' => [
        'label' => 'Filters',
        'status' => 'Status',
        'any_status' => 'Any status',
        'service' => 'Service',
        'all_services' => 'All services',
        'inactive_service' => ':service (inactive)',
        'colour_by' => 'Colour by',
        'colour_by_choices' => [
            'staff' => 'Staff member',
            'category' => 'Service category',
            'status' => 'Status',
        ],
        'colour_by_locked' => 'Only someone who manages settings can change the colours. They apply to everyone.',
        'colour_by_saving' => 'Saving the colours.',
        'colour_by_note' => 'The colours apply to everyone at the business.',
    ],
    'who' => [
        'label' => 'Who',
        'note' => 'Scheduled staff shows everyone with hours on the day shown. A staff member\'s own sign-in opens here on Me and cannot change it.',
        'pick' => 'Or pick who to show',
        'scheduled' => 'Scheduled staff',
        'all' => 'All staff',
        'me' => 'Me',
        'picked' => '{1} :staff and :count other|[2,*] :staff and :count others',
    ],
    'legend' => [
        'staff' => 'Staff colours',
        'category' => 'Category colours',
        'status' => 'Status colours',
        'no_category' => 'No category',
        'badges' => 'Badges',
        'closures' => 'Closures and hours',
        'closure' => 'Closure',
        'outside' => 'Outside the member\'s hours',
        'gap' => 'Gap, the time is free but not bookable',
        'hint' => 'Drag or click an empty slot to start a booking there.',
    ],
    'badges' => [
        'pending' => 'Pending',
        'payment' => 'Approved, awaiting the customer\'s payment',
    ],
    'grid' => [
        'block' => ':customer, :service',
        'block_erased' => 'Customer erased, :service',
        'gap' => 'Processing gap',
        'week_needs_width' => 'The week view needs a wider screen.',
        'to_day' => 'Open Day view',
    ],
    'day' => [
        'other_bookings' => 'Other bookings',
        'not_working' => 'Not working this day',
        'no_hours_ahead' => 'No hours in the next eight weeks',
        'next_working' => 'No hours this day. Next: :weekday :date :hours',
        'menu' => ':staff, :date',
        'book' => 'New booking here',
        'close' => 'Closure here',
        'edit' => 'Edit this day\'s hours',
        'staff_page' => 'Open staff page',
    ],
    'closure' => [
        'title' => 'New closure',
        'who' => 'Who',
        'whole_business' => 'The whole business',
        'date' => 'Date',
        'reason' => 'Reason (optional)',
        'all_day' => 'Closed all day',
        'start' => 'Start',
        'end' => 'End',
        'cancel' => 'Cancel',
        'add' => 'Add',
        'adding' => 'Adding',
        'not_added' => 'The closure could not be added. Check your connection and try again.',
    ],
    'validation' => [
        'colour_by' => 'Choose how to colour the calendar from the choices offered.',
    ],
];
