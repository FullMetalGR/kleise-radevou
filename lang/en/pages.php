<?php

/*
 * The sentences a customer meets on the tenant host's server pages, in English: the business page
 * around the flow, the noscript line of the hosted page's mount, the My bookings link page and the
 * flashes and the refusal My bookings shows, the pay hop's pages and flashes, and the suspended
 * page. Each constant that held one of these sentences in code keeps its name and holds its key.
 * The pages read them through the translator in the request's language, the locale SetTenantLocale
 * set from the path's prefix else the tenant default, and the suspended page in the language
 * ResolveTenant reads for it before the tenant is bound. A flashed sentence rides the flash as its
 * key and is read by the page that shows it, in that page's language. Its Greek twin is
 * lang/el/pages.php, key for key. A value's :word is filled by the translator, :name the
 * platform's public name as in mail.php, and a line read as a choice keeps the translator's choice
 * syntax, :count the number it was chosen by. Tenant-authored text, About, the team's names and
 * titles and Important info, is never here.
 */

return [
    // The business page: the header's two links, the flow's visually hidden heading, the section
    // headings, the photo grid's way to the full gallery, read as a choice by the gallery's count,
    // the title block's open state with its time, the footer's line naming the platform, the hours
    // table's closed day, the address block's directions link, the contact pills' link labels, a
    // photo's alternative text with its place and the gallery's count, and the map image's
    // alternative text. Instagram and Facebook are names both languages share.
    'business' => [
        'book_now' => 'Book now',
        'my_bookings' => 'My bookings',
        'flow_title' => 'Book online',
        'hours' => 'Opening times',
        'find_us' => 'Where to find us',
        'about' => 'About',
        'photos' => 'Photos',
        'see_all' => '{1} See the photo|[2,*] See all :count photos',
        'open_until' => 'Open until :time',
        'opens_at' => 'Opens at :time',
        'closed_today' => 'Closed today',
        'booking_by' => 'Online booking by :name',
        'team' => 'Our team',
        'closed' => 'Closed',
        'directions' => 'Get directions',
        'website' => 'Website',
        'instagram' => 'Instagram',
        'facebook' => 'Facebook',
        'reviews' => 'Read our reviews',
        'photo' => 'Photo :n of :count',
        'map' => 'Map of our location, opening directions',
    ],

    // The mount element's only child on the hosted page, for a browser that runs no script.
    'noscript' => 'Online booking requires JavaScript. Please contact us directly to book.',

    // The page a My bookings link opens: its title naming the business, the sentence above its
    // one button, read as a choice by the session's length in days, :count that length spelled in
    // words, and the button.
    'link' => [
        'title' => 'Your bookings at :business',
        'sentence' => '{1} Press the button to see your bookings here. You stay signed in on this device for one day, or until you sign out.|[2,*] Press the button to see your bookings here. You stay signed in on this device for :count days, or until you sign out.',
        'label' => 'Show my bookings',
    ],

    // What My bookings shows above the form or the list: the sentence each of its redirects
    // flashes, after the form, after a used, lapsed or unknown link and after the sign-out, and
    // the form's refusal past the link's limit.
    'my_bookings' => [
        'flashes' => [
            'sent' => 'If we have bookings for this address, a link is on its way.',
            'invalid' => 'That link is no longer valid. Ask for a new one.',
            'signed_out' => 'You have signed out.',
        ],
        'refusals' => [
            'too_many' => 'Too many requests for this address. Please wait a minute and try again.',
        ],
    ],

    // The pay hop's pages: the payment link's status page, its button and its heading and sentences
    // by the booking as it stands; the four worded pages, a hold too short to pay online, a hold no
    // longer held, a link that does not work and a payment page that could not be opened; and the
    // sentence each of the hop's redirects flashes onto the page the customer reads the booking on.
    'pay' => [
        'pay_now' => 'Pay now',
        'waiting' => [
            'title' => 'Your booking is waiting for its payment',
            'approved' => 'Your request has been approved. The time is held for you, and the booking is confirmed once your payment reaches us.',
            'just_paid' => 'If you have just paid, the payment can take a moment to reach us: open this page again and it says the booking is confirmed once it has. Until then the button takes you to the payment page, and once you have paid it says the payment is on its way.',
        ],
        'confirmed' => 'Your booking is confirmed',
        'passed' => 'The time of this booking has passed',
        'nothing_to_pay' => 'There is nothing to pay on this booking',
        'nothing_more' => 'Nothing more is needed from you here.',
        'closed' => [
            'title' => 'This booking can no longer be paid online',
            'held' => 'Too little time is left on its hold to open a payment page, and the hold cannot be made longer. Nothing has been charged.',
            'given_back' => 'The time is given back when the hold ends. Contact the business directly if you still want it.',
        ],
        'ended' => [
            'title' => 'This booking is no longer held',
            'given_back' => 'The time it held has been given back, so there is nothing to pay.',
            'again' => 'Go back to the booking page to choose a time again.',
        ],
        'refused' => [
            'title' => 'This link does not work',
            'sentence' => 'It may have been changed on its way here, or the booking it was for has ended.',
        ],
        'unopened' => [
            'title' => 'The payment page could not be opened',
            'charged' => 'Nothing has been charged and your booking is still held.',
            'again' => 'Try the link again in a moment.',
        ],
        'flashes' => [
            'on_its_way' => 'Your payment is on its way to us. Your booking is confirmed once it arrives, and this page says so when you open it again.',
            'at_the_venue' => 'Your booking is confirmed. You pay at the venue: nothing has been charged online.',
            'settlement_lost' => 'Something changed while we were confirming your booking, so nothing was done. This is your booking as it stands now.',
        ],
    ],

    // The page a suspended tenant's host answers on every route: its title, its heading and its
    // one sentence.
    'suspended' => [
        'title' => 'Booking is paused',
        'heading' => 'Booking is paused',
        'sentence' => 'This booking page is not available right now. Please contact the business directly to make or change an appointment.',
    ],
];
