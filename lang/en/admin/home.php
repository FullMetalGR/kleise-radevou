<?php

/*
 * Home: the landing's title, the setup checklist moved from the Calendar, the range card, the
 * six tiles each compared with the period before, one whole sentence per kind of range so a
 * language can inflect the period, the footnote, the two Top tables, the three feeds and the
 * next seven days, whose summary is one whole sentence per case the strip shows: the bookings
 * alone, or the bookings with the one or the several awaiting approval, each chosen by the
 * bookings' count. Recent activity is one whole sentence per action and shape of actor
 * (HomeActivity), never a phrase spliced into another, and an erased customer's row one whole
 * sentence of its own under erased, no stand-in in the customer's place. Its Greek twin is
 * lang/el/admin/home.php.
 */

return [
    'page_title' => 'Home',
    'title' => 'Home',
    'setup' => [
        'title' => 'Get ready for your first booking',
        'service' => 'Add a service',
        'hours' => 'Set your opening hours',
        'link' => 'Copy your booking link',
        'booking' => 'Take your first booking',
        'hosted' => 'Open your booking page',
        'done' => 'Done',
        'open' => 'Not done yet',
    ],
    'range' => [
        'label' => 'Range',
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'week' => 'This week',
        'month' => 'This month',
        'line' => [
            'today' => 'Today: :date',
            'yesterday' => 'Yesterday: :date',
            'week' => 'This week: :from to :to',
            'month' => 'This month: :from to :to',
        ],
        'error' => 'The figures could not be loaded. Check your connection and choose the range again.',
    ],
    'tiles' => [
        'bookings' => 'Bookings',
        'revenue' => 'Revenue',
        'occupancy' => 'Occupancy',
        'new_customers' => 'New customers',
        'cancelled' => 'Cancelled',
        'no_shows' => 'No-shows',
        'filled' => ':filled of :offered',
        'duration' => ':hours :minutes',
        'no_hours' => 'No staff hours in this range',
        'returning' => '{1} :count returning|[0,*] :count returning',
    ],
    'change' => [
        'day' => [
            'by' => ':change vs the day before',
            'same' => 'No change vs the day before',
            'from_zero' => 'Up from 0 vs the day before',
        ],
        'week' => [
            'by' => ':change vs the week before',
            'same' => 'No change vs the week before',
            'from_zero' => 'Up from 0 vs the week before',
        ],
        'month' => [
            'by' => ':change vs the month before',
            'same' => 'No change vs the month before',
            'from_zero' => 'Up from 0 vs the month before',
        ],
    ],
    'footnote' => 'Bookings and revenue count confirmed and completed bookings by their first start. Occupancy is the minutes those bookings fill over the minutes your staff hours offer less closures, plus any booked minute outside those hours, so it never passes 100%.',
    'top' => [
        'services' => 'Top services',
        'staff' => 'Top staff',
        'name' => 'Name',
        'bookings' => 'Bookings',
        'revenue' => 'Revenue',
        'empty' => 'No confirmed or completed bookings in this range.',
    ],
    'customer_erased' => 'Customer erased',
    'with' => ':services with :staff',
    'next_up' => [
        'title' => 'Next up',
        'empty' => 'No bookings coming up.',
    ],
    'awaiting' => [
        'title' => 'Awaiting approval',
        'empty' => 'No requests are waiting for a decision.',
        'decide_by' => 'Decide by :end',
        'see_all' => 'See all requests',
    ],
    'activity' => [
        'title' => 'Recent activity',
        'empty' => 'Nothing has happened yet.',
        'all' => 'All bookings',
        'line' => ':booking, :start',
        'held' => [
            'online' => ':customer booked online',
            'request' => ':customer asked for a booking',
        ],
        'added' => [
            'user' => ':staff added a booking for :customer',
            'removed' => 'A former team member added a booking for :customer',
            'support' => 'Support (:staff) added a booking for :customer',
            'support_removed' => 'Support added a booking for :customer',
        ],
        'confirmed' => [
            'customer' => ':customer confirmed',
            'paid' => ':customer paid online',
            'system' => 'The booking for :customer was confirmed',
        ],
        'approved' => [
            'user' => ':staff approved :customer\'s request',
            'removed' => 'A former team member approved :customer\'s request',
            'support' => 'Support (:staff) approved :customer\'s request',
            'support_removed' => 'Support approved :customer\'s request',
            'system' => ':customer\'s request was approved automatically',
        ],
        'rejected' => [
            'user' => ':staff declined :customer\'s request',
            'removed' => 'A former team member declined :customer\'s request',
            'support' => 'Support (:staff) declined :customer\'s request',
            'support_removed' => 'Support declined :customer\'s request',
            'system' => ':customer\'s request was declined automatically',
        ],
        'cancelled' => [
            'customer' => ':customer cancelled',
            'user' => ':staff cancelled :customer\'s booking',
            'removed' => 'A former team member cancelled :customer\'s booking',
            'support' => 'Support (:staff) cancelled :customer\'s booking',
            'support_removed' => 'Support cancelled :customer\'s booking',
            'system' => 'The booking for :customer was cancelled',
        ],
        'released' => [
            'customer' => ':customer left before finishing the booking',
            'system' => 'The unfinished booking for :customer was let go',
        ],
        'rescheduled' => [
            'customer' => ':customer moved the booking',
            'user' => ':staff moved :customer\'s booking',
            'removed' => 'A former team member moved :customer\'s booking',
            'support' => 'Support (:staff) moved :customer\'s booking',
            'support_removed' => 'Support moved :customer\'s booking',
            'system' => 'The booking for :customer was moved',
        ],
        'completed' => [
            'user' => ':staff marked :customer\'s booking completed',
            'removed' => 'A former team member marked :customer\'s booking completed',
            'support' => 'Support (:staff) marked :customer\'s booking completed',
            'support_removed' => 'Support marked :customer\'s booking completed',
        ],
        'no_show' => [
            'user' => ':staff marked :customer as a no-show',
            'removed' => 'A former team member marked :customer as a no-show',
            'support' => 'Support (:staff) marked :customer as a no-show',
            'support_removed' => 'Support marked :customer as a no-show',
        ],
        'expired' => [
            'approval' => 'The request from :customer ran out',
            'checkout' => 'The unfinished booking for :customer ran out',
            'payment' => 'The booking for :customer expired unpaid',
            'other' => 'The booking for :customer expired',
        ],
        'customer_erased' => 'A customer\'s data was erased',
        'customer_exported' => 'A customer\'s data was exported',
        'unknown' => 'The booking for :customer changed',
        'erased' => [
            'held' => [
                'online' => 'An erased customer booked online',
                'request' => 'An erased customer asked for a booking',
            ],
            'added' => [
                'user' => ':staff added a booking for an erased customer',
                'removed' => 'A former team member added a booking for an erased customer',
                'support' => 'Support (:staff) added a booking for an erased customer',
                'support_removed' => 'Support added a booking for an erased customer',
            ],
            'confirmed' => [
                'customer' => 'An erased customer confirmed',
                'paid' => 'An erased customer paid online',
                'system' => 'The booking for an erased customer was confirmed',
            ],
            'approved' => [
                'user' => ':staff approved an erased customer\'s request',
                'removed' => 'A former team member approved an erased customer\'s request',
                'support' => 'Support (:staff) approved an erased customer\'s request',
                'support_removed' => 'Support approved an erased customer\'s request',
                'system' => 'An erased customer\'s request was approved automatically',
            ],
            'rejected' => [
                'user' => ':staff declined an erased customer\'s request',
                'removed' => 'A former team member declined an erased customer\'s request',
                'support' => 'Support (:staff) declined an erased customer\'s request',
                'support_removed' => 'Support declined an erased customer\'s request',
                'system' => 'An erased customer\'s request was declined automatically',
            ],
            'cancelled' => [
                'customer' => 'An erased customer cancelled',
                'user' => ':staff cancelled an erased customer\'s booking',
                'removed' => 'A former team member cancelled an erased customer\'s booking',
                'support' => 'Support (:staff) cancelled an erased customer\'s booking',
                'support_removed' => 'Support cancelled an erased customer\'s booking',
                'system' => 'The booking for an erased customer was cancelled',
            ],
            'released' => [
                'customer' => 'An erased customer left before finishing the booking',
                'system' => 'The unfinished booking for an erased customer was let go',
            ],
            'rescheduled' => [
                'customer' => 'An erased customer moved the booking',
                'user' => ':staff moved an erased customer\'s booking',
                'removed' => 'A former team member moved an erased customer\'s booking',
                'support' => 'Support (:staff) moved an erased customer\'s booking',
                'support_removed' => 'Support moved an erased customer\'s booking',
                'system' => 'The booking for an erased customer was moved',
            ],
            'completed' => [
                'user' => ':staff marked an erased customer\'s booking completed',
                'removed' => 'A former team member marked an erased customer\'s booking completed',
                'support' => 'Support (:staff) marked an erased customer\'s booking completed',
                'support_removed' => 'Support marked an erased customer\'s booking completed',
            ],
            'no_show' => [
                'user' => ':staff marked an erased customer as a no-show',
                'removed' => 'A former team member marked an erased customer as a no-show',
                'support' => 'Support (:staff) marked an erased customer as a no-show',
                'support_removed' => 'Support marked an erased customer as a no-show',
            ],
            'expired' => [
                'approval' => 'The request from an erased customer ran out',
                'checkout' => 'The unfinished booking for an erased customer ran out',
                'payment' => 'The booking for an erased customer expired unpaid',
                'other' => 'The booking for an erased customer expired',
            ],
            'unknown' => 'The booking for an erased customer changed',
        ],
    ],
    'days' => [
        'title' => '{1} The next day|[2,*] The next :count days',
        'bookings' => '{0} :count bookings|{1} :count booking|[2,*] :count bookings',
        'summary' => [
            'one_awaiting' => '{1} :count booking, :awaiting awaiting approval|[2,*] :count bookings, :awaiting awaiting approval',
            'many_awaiting' => ':count bookings, :awaiting awaiting approval',
        ],
        'day' => ':weekday :day',
        'link' => '{0} :day, no bookings|{1} :day, one booking|[2,*] :day, :count bookings',
        'footnote' => 'Confirmed in the theme colour, awaiting approval in amber. A day opens the Calendar on it.',
    ],
];
