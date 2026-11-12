<?php

/*
 * The booking bundle's sentences in English: the flow's six steps, its crumb and its summary
 * card, the shell's three pre-config sentences, the client's own sentences for a failure the
 * server did not word, the countdown, the durations and this language's own name; the manage
 * page's, My bookings', the reschedule dialog's and every sentence the bundle once wrote inline,
 * the time zone's line among them. The bundle reads this file through the generated module php
 * artisan lang:export booking writes before every build, so a sentence here is the bundle's and
 * no copy of it is written in the bundle's code; each constant there holds its key. Its Greek
 * twin is lang/el/booking.php. A value's :word is filled by the bundle's fill() as the
 * translator fills it, and a line read as a choice keeps the translator's choice syntax, :count
 * the number it was chosen by.
 */

return [
    // This language's own name, which labels the language switch on the other language's pages.
    'language' => 'English',

    // The one line naming the tenant's time zone, on the when step, the review step, the manage
    // page and My bookings' list, since every time on them is the tenant's.
    'zone' => 'Times are shown in the :zone time zone.',

    // The shell's sentences, rendered before or without the config in the start language: while
    // the config is on the wire, when it never lands, and when a render crashed.
    'shell' => [
        'loading' => 'Loading online booking...',
        'unavailable' => 'Online booking is unavailable right now. Please contact us directly to book.',
        'crashed' => 'Something went wrong. Please reload the page or contact us directly to book.',
    ],

    // What the client says itself for a failure that is no answer, a transport drop or a body it
    // could not read, and for an error status whose body carried no sentence to show.
    'wire' => [
        'transport' => 'Something went wrong while loading. Please check your connection and try again.',
        'unworded' => 'The request could not be completed. Please try again.',
    ],

    // The frame's title on each step before done, whose title is its heading below.
    'titles' => [
        'service' => 'Select services',
        'staff' => 'Select professional',
        'when' => 'Select time',
        'details' => 'Your details',
        'review' => 'Confirm your booking',
    ],

    // The crumb above the step: its name, each step's name in the trail, the trail's last entry,
    // the back control's name, and what the current step and a step not reached yet say.
    'strip' => [
        'label' => 'Booking steps',
        'names' => [
            'service' => 'Services',
            'staff' => 'Professional',
            'when' => 'Time',
            'details' => 'Details',
        ],
        'confirm' => 'Confirm',
        'back' => 'Back',
        'current' => 'You are here',
        'ahead' => 'Not reached yet',
    ],

    // The service step: the empty catalogue, the refetch that failed over a list, the name of a
    // chosen service the catalogue no longer lists, the jump chips' name, the loading line, the
    // heading of the services under no category, the sentence above the list at the chain's
    // maximum, read as a choice by that maximum, and what a removal announces.
    'service' => [
        'empty' => 'There is nothing available to book right now.',
        'stale' => 'The list of services could not be refreshed and may be out of date.',
        'placeholder' => 'A service that is no longer offered',
        'chips' => 'Jump to a category',
        'loading' => 'Loading services...',
        'other' => 'Other',
        'limit' => '{1} You can choose one service.|[2,*] You can choose up to :count services.',
        'removed' => ':service removed.',
    ],

    // The staff step: the first choice of every picker and the line under it, and the bio's two
    // controls.
    'staff' => [
        'any' => 'Any professional',
        'any_line' => 'for maximum availability',
        'view_profile' => 'View profile',
        'hide_profile' => 'Hide profile',
    ],

    // The when step: the stale line, the loading line, the hint over a week with no times, the two
    // captions, the empty day, the button to the next day with times, a closed day's words, the
    // period control's name and each period's display name; a period's count of times, read as a
    // choice; a closed day's name, :date the day as ICU spells it; and the week row's two arrows.
    'when' => [
        'stale' => 'The available times could not be refreshed and may be out of date.',
        'loading' => 'Loading available times...',
        'hint' => 'Pick a day to see the available times.',
        'date_caption' => 'Select a date',
        'time_caption' => 'Pick a time',
        'empty_day' => 'No times are available on this day. Please pick another date.',
        'next_available' => 'Next available date',
        'no_times' => 'No times',
        'periods' => 'Time of day',
        'period' => [
            'morning' => 'Morning',
            'afternoon' => 'Afternoon',
            'evening' => 'Evening',
        ],
        'times' => '{1} :count time|[2,*] :count times',
        'closed_day' => ':date, no times',
        'previous_week' => 'Previous week',
        'next_week' => 'Next week',
    ],

    // The details step: the summary card's Continue while the hold flies, the note's label, the
    // three fields' labels, the note's count, :count the characters typed and :n the most the
    // note holds, and what a hold refused for an address the business blocked says.
    'details' => [
        'busy' => 'Holding your time...',
        'note' => 'Anything we should know?',
        'name' => 'Name',
        'email' => 'Email',
        'phone' => 'Phone (optional)',
        'count' => ':count of :n characters',
        'blocked' => 'Booking online is not available for this address. Please contact the business.',
    ],

    // The review step: the action button's two branches and its busy label, the restart control,
    // the heading over the tenant's Important info, and what the step says once its hold has been
    // handed off to a checkout.
    'review' => [
        'confirm' => 'Confirm booking',
        'pay' => 'Continue to payment',
        'confirming' => 'Confirming your booking...',
        'restart' => 'Pick another time',
        'important_info' => 'Please note',
        'handed_off' => 'Payment continues on the checkout page. To choose a different time, pick another time.',
    ],

    // The countdown on the review step: the label beside its reading, and its two milestones.
    'countdown' => [
        'label' => 'Time left to confirm',
        'near' => 'Less than a minute left to confirm.',
        'expired' => 'Your time to confirm has run out. Please pick another time.',
    ],

    // The done step, by the status the server returned, received for any other: the sentence in
    // its panel and the heading the frame carries as its title. at_venue is the confirmed
    // sentence of an online booking no provider could take the money for.
    'done' => [
        'sentences' => [
            'confirmed' => 'Your booking is confirmed.',
            'at_venue' => 'Your booking is confirmed. You pay at the venue: nothing has been charged online.',
            'awaiting_approval' => 'Request sent. We will be in touch once it has been reviewed.',
            'awaiting_payment' => 'Your booking is reserved and will be completed once payment is arranged.',
            'received' => 'Your booking has been received.',
        ],
        'headings' => [
            'confirmed' => 'Booking confirmed',
            'awaiting_approval' => 'Request sent',
            'awaiting_payment' => 'Booking reserved',
            'received' => 'Booking received',
        ],
    ],

    // The summary card beside the steps: its label, the total line's word, its Continue on every
    // step but when and on when, its one line before the first service; a line's duration with who
    // performs it, one whole line for a service the customer named nobody for and one for a person
    // named; the phone bar's brief, read as a choice by the number of services; and a line's remove
    // control's name.
    'estimate' => [
        'label' => 'Estimate until you confirm',
        'total' => 'Total',
        'continue' => 'Continue',
        'pick_a_time' => 'Pick a time',
        'pick_a_service' => 'Pick a service to start',
        'any' => ':duration with any professional',
        'staff' => ':duration with :staff',
        'brief' => '{1} :count service - :duration|[2,*] :count services - :duration',
        'remove' => 'Remove :service',
    ],

    // A sold item of a booking, on the review step, the manage page and My bookings' cards: who
    // performs it, where the answer names a person.
    'summary' => [
        'staff' => 'with :staff',
    ],

    // The booking's own card on the review step and the manage page, its heading and the word of
    // its total line, which My bookings' cards carry too, and Book again, on the manage page and on
    // every card of My bookings.
    'booking' => [
        'heading' => 'Your booking',
        'total' => 'Total',
        'book_again' => 'Book again',
    ],

    // The manage page: the panel a dead link shows, the loading line, the cancel's progress line,
    // its question, its three controls, the retry panel's control and its busy label, why
    // Reschedule waits while the cancel row is armed, who booked, :customer their name, address and
    // phone as the booking carries them, and one sentence per status, none for confirmed.
    'manage' => [
        'neutral' => 'This link is no longer valid. Please contact us directly about your booking.',
        'loading' => 'Loading your booking...',
        'cancelling' => 'Cancelling your booking...',
        'question' => 'Are you sure you want to cancel this booking?',
        'cancel' => 'Cancel booking',
        'yes' => 'Yes, cancel it',
        'keep' => 'Keep this booking',
        'try_again' => 'Try again',
        'trying' => 'Trying again...',
        'keep_to_reschedule' => 'To reschedule instead, keep this booking first.',
        'booked_by' => 'Booked by :customer',
        'statuses' => [
            'pending' => 'This booking is not settled yet.',
            'awaiting_approval' => 'We have your request and will be in touch once it has been reviewed.',
            'awaiting_payment' => 'This booking will be completed once payment is arranged.',
            'completed' => 'This appointment has taken place.',
            'no_show' => 'This appointment is recorded as missed. Please contact us to book again.',
            'cancelled' => 'This booking has been cancelled.',
            'rejected' => 'This request was not accepted. Please contact us directly.',
            'expired' => 'The time we were holding has been released. Please book again.',
        ],
    ],

    // The reschedule dialog: its heading, the control that opens it, its close, its progress line
    // and what a landed move says.
    'move' => [
        'pick' => 'Pick a new time',
        'reschedule' => 'Reschedule',
        'close' => 'Close',
        'moving' => 'Moving your booking...',
        'moved' => 'Your booking has been moved.',
    ],

    // My bookings: the page's heading, the form's sentence, its field and its button with the busy
    // label, the list's two headings and what an address with no bookings left reads, the sign-out
    // and its busy label, and what a card's landed cancel says in its region.
    'my_bookings' => [
        'title' => 'My bookings',
        'intro' => 'Enter the email address you booked with and we will send you a link to see your bookings.',
        'email' => 'Email address',
        'send' => 'Send me a link',
        'sending' => 'Sending...',
        'upcoming' => 'Upcoming',
        'past' => 'Past',
        'none' => 'There are no bookings under your address here.',
        'sign_out' => 'Sign out',
        'signing_out' => 'Signing out...',
        'cancelled' => 'Your booking has been cancelled.',
    ],

    // A duration, each shape a whole line read as a choice: minutes alone, hours alone, and hours
    // with minutes, :count the minutes in the first and the hours in the other two.
    'duration' => [
        'minutes' => ':count min',
        'hours' => ':count hr',
        'hours_minutes' => ':count hr :minutes min',
    ],
];
