<?php

/*
 * The Bookings screen and the booking dialog in English: the words of the list and of the dialog
 * every screen opens, shared as the strings prop's bookings section, which BookingListController,
 * CalendarController, CustomerController and HomeController name, in the language of the request,
 * the user's. Its Greek twin is lang/el/admin/bookings.php.
 *
 * page is the title and heading, the line of a list that did not load, of a close that could not
 * return to the list, and of a filter that matches nothing.
 *
 * table is the list's card: its title, the count of the rows shown (:count) out of every match
 * (:total), and the column heads.
 *
 * filters is the filter bar: its name, Approval inbox and Clear, the fields, the search's hint by
 * whether it reads the email, the three "any" choices, and an inactive member (:staff) or service
 * (:service).
 *
 * customer_erased is the name a booking whose customer was erased reads in its place.
 *
 * acting is the dialog's line where an action failed and the server worded nothing: refused for
 * this viewer, or anything else.
 *
 * actions are the action bar's five labels and busy their busy words; reject and cancel are the two
 * confirms: their titles, the line while the server answers, the reason field with its help, and
 * the warning that a cancellation cannot be undone. A confirm's button reads its action's label.
 *
 * move is the Move form inside the dialog: its button, the line naming what moves and where it
 * sits now (:services and :staff each ICU's list in the language, :when), the fields, the time
 * list's choices marked where a time crosses the hours or a closure (:time), its states, Save with
 * why it waits, Back, and the confirmation over it: from and to, each member change, the warning
 * per service, each with a whole line for a member or a service the move cannot name
 * (member_unnamed, hours_unnamed, closure_unnamed), the tick box with its help, the button, its
 * busy word and the line while the server answers.
 *
 * dialog is the booking dialog: its title before the booking is read, the loading name, the load
 * error, the missing booking with Back to the list, Close, the facts' labels, What was booked with
 * its column heads, History and the empty trail. trail is the audit trail's words: the actions, the
 * facts' labels, the actors and the hold classes, each a machine value's display word.
 *
 * validation and flashes are the server's: the rejection reason's refusal and the five actions'
 * flashes.
 */

return [
    'page' => [
        'title' => 'Bookings',
        'list_error' => 'The bookings could not be loaded. Check your connection and change a filter to try again.',
        'close_error' => 'The dialog could not return to the list. Check your connection and press Close again.',
        'empty' => 'No bookings match these filters.',
    ],
    'table' => [
        'title' => 'Bookings',
        'count' => ':count of :total',
        'customer' => 'Customer',
        'service' => 'Service',
        'staff' => 'Staff',
        'when' => 'When',
        'status' => 'Status',
        'total' => 'Total',
    ],
    'filters' => [
        'label' => 'Filters',
        'inbox' => 'Approval inbox',
        'clear' => 'Clear',
        'from' => 'From',
        'to' => 'To',
        'status' => 'Status',
        'staff' => 'Staff',
        'service' => 'Service',
        'search' => 'Search',
        'search_name' => 'Name',
        'search_name_email' => 'Name or email',
        'any_status' => 'Any status',
        'all_staff' => 'All staff',
        'all_services' => 'All services',
        'inactive_staff' => ':staff (inactive)',
        'inactive_service' => ':service (inactive)',
    ],
    'customer_erased' => 'Customer erased',
    'acting' => [
        'error' => 'The action could not be completed. Check your connection, then close the dialog and open the booking again to see where it stands.',
        'forbidden' => 'You may not do that to this booking. Close the dialog and open it again to see what you can do now.',
    ],
    'actions' => [
        'approve' => 'Approve',
        'reject' => 'Reject',
        'cancel' => 'Cancel booking',
        'complete' => 'Completed',
        'no_show' => 'No-show',
    ],
    'busy' => [
        'approve' => 'Approving',
        'reject' => 'Rejecting',
        'cancel' => 'Cancelling',
        'complete' => 'Marking completed',
        'no_show' => 'Marking no-show',
    ],
    'reject' => [
        'title' => 'Reject this request',
        'pending' => 'The rejection has been sent. This closes once the server answers.',
        'reason' => 'Reason (optional)',
        'reason_help' => 'Kept with the booking for your team. The customer is not shown it.',
    ],
    'cancel' => [
        'title' => 'Cancel this booking',
        'pending' => 'The cancellation has been sent. This closes once the server answers.',
        'warning' => 'Cancelling cannot be undone. The time is given back to the diary.',
    ],
    // The Move form inside the dialog and the confirmation over it.
    'move' => [
        'open' => 'Move',
        'now' => 'Moving :services, now :when with :staff.',
        'now_alone' => 'Moving :services, now :when.',
        'date' => 'Date',
        'staff' => 'Staff member',
        'staff_for' => 'Staff member for :service',
        'time' => 'Time',
        'time_help' => 'Free times come first. A time outside working hours or on a closure can be chosen too, after a warning.',
        'choose' => 'Choose a time',
        'time_hours' => ':time (outside working hours)',
        'time_closure' => ':time (on a closure)',
        'time_both' => ':time (outside working hours, on a closure)',
        'group_free' => 'Free times',
        'group_marked' => 'Outside working hours or on a closure',
        'loading' => 'Finding the free times.',
        'none' => 'No free times on this day. Choose another day or staff member.',
        'unreadable' => 'The free times could not be read. Change the date to try again.',
        'save' => 'Save',
        'save_why' => 'Choose a time to save.',
        'back' => 'Back',
        'not_moved' => 'The booking was not moved. Check your connection, then try again.',
        'confirm' => [
            'title' => 'Move this booking',
            'from_to' => 'From :from to :to.',
            'member' => ':service: :from to :to.',
            'member_unnamed' => ':service: :from to another staff member.',
            'warning' => 'This time is outside what is open:',
            'hours' => ':service with :staff is outside working hours.',
            'closure' => ':service with :staff is on a closure.',
            'hours_unnamed' => 'A service of this booking is outside working hours.',
            'closure_unnamed' => 'A service of this booking is on a closure.',
            'tell' => 'Tell the customer',
            'tell_help' => 'Sends them the new time. Unticked, they are sent nothing about this move.',
            'move' => 'Move booking',
            'busy' => 'Moving',
            'pending' => 'The move has been sent. This closes once the server answers.',
        ],
    ],
    'dialog' => [
        'title' => 'Booking',
        'loading' => 'Loading the booking',
        'load_error' => 'This booking could not be loaded. Close the dialog and try again.',
        'back' => 'Back to the list',
        'missing' => 'No booking has this address. It may have been mistyped, or the link may be from another business.',
        'close' => 'Close',
        'facts' => [
            'status' => 'Status',
            'total' => 'Total',
            'email' => 'Email',
            'phone' => 'Phone',
            'reason' => 'Rejection reason',
            'source' => 'Source',
            'channel' => 'Channel',
            'note' => 'Note',
        ],
        'items' => [
            'title' => 'What was booked',
            'service' => 'Service',
            'staff' => 'Staff',
            'start' => 'Start',
            'end' => 'End',
            'price' => 'Price',
        ],
        'history' => 'History',
        'no_history' => 'Nothing has happened to this booking yet.',
    ],
    'trail' => [
        'actions' => [
            'held' => 'Held',
            'expired' => 'Expired',
            'confirmed' => 'Confirmed',
            'cancelled' => 'Cancelled',
            'released' => 'Released',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'completed' => 'Completed',
            'no_show' => 'Marked no-show',
            'rescheduled' => 'Rescheduled',
            'customer_erased' => 'Customer erased',
            'customer_exported' => 'Customer data exported',
        ],
        'facts' => [
            'status_from' => 'From',
            'status_to' => 'To',
            'hold_class' => 'Hold',
            'hold_expires_at' => 'Hold expires',
            'item_count' => 'Items',
            'starts_at' => 'Starts',
            'moved_from' => 'Moved from',
            'staff_changed' => 'Staff changed',
            'overridden' => 'Warning accepted',
            'customer_told' => 'Customer told',
            'paid' => 'Amount paid',
        ],
        // The values of a move by the business, each its own line: a service by its place in the
        // booking, counted from one, given from one member to another, one whole line for each
        // member since removed (the trail keeps no name for them); a service placed outside its
        // member's working hours or on a closure; and whether the customer was told.
        'values' => [
            'staff_changed' => 'Service :position: :from to :to',
            'staff_changed_from_removed' => 'Service :position: a removed staff member to :to',
            'staff_changed_to_removed' => 'Service :position: :from to a removed staff member',
            'staff_changed_removed' => 'Service :position: one removed staff member to another',
            'hours' => 'Service :position: outside working hours',
            'closure' => 'Service :position: on a closure',
            'yes' => 'Yes',
            'no' => 'No',
        ],
        'actors' => [
            'customer' => 'Customer',
            'system' => 'System',
            'job' => 'Scheduled job',
        ],
        'holds' => [
            'checkout' => 'Checkout',
            'approval' => 'Approval',
            'payment' => 'Payment',
        ],
    ],
    'validation' => [
        'reason_text' => 'A rejection\'s reason is a note in words.',
    ],
    'flashes' => [
        'approved' => 'Booking approved.',
        'rejected' => 'Booking rejected.',
        'cancelled' => 'Booking cancelled.',
        'completed' => 'Booking marked completed.',
        'no_show' => 'Booking marked as a no-show.',
        'moved' => 'Booking moved.',
    ],
];
