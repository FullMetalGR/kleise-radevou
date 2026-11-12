<?php

/*
 * The six vertical pages in English. One entry per vertical, by its key, each the slots the one
 * template renders: its title and meta description, the head's; its heading, the page's h1, and
 * an intro; three benefits, each a title over one sentence or two; the example services under
 * their heading; and the closing line over the signup action. Each link to a vertical is
 * labelled from site.php's pages.vertical. Every claim is true of the product as built: no
 * proof, no outcome numbers, no competitor and no fiscal claim, and the link to cancel or move a
 * booking is the confirmation's, since a reminder carries none. Every value may name the public
 * name as :name.
 */

return [
    'hair-salons' => [
        'title' => 'Online booking for hair salons - :name',
        'description' => 'Your own booking page for cuts, colour and styling, and the same booking on your salon\'s website. Seven days free, no card needed.',
        'heading' => 'Online booking for your hair salon',
        'intro' => 'Clients choose the service, the stylist and the time, and book from your own page or your website at any hour, without making an account.',
        'benefits' => [
            [
                'title' => 'Colour and cut in one visit',
                'body' => 'A client can book colour and a cut in the same visit. Set the time the colour needs to develop, and the stylist is free to take someone else during it.',
            ],
            [
                'title' => 'Each stylist with their own calendar',
                'body' => 'Each stylist has their own working hours, the services they offer and their own calendar. Clients choose a stylist, or anyone available.',
            ],
            [
                'title' => 'A reminder before every appointment',
                'body' => 'Clients get a confirmation by email, with a link to cancel or move the booking within your rules, and a reminder before they come.',
            ],
        ],
        'services' => [
            'heading' => 'What your clients can book',
            'items' => ['Cut and blow-dry', 'Colour', 'Highlights and balayage', 'Blow-dry and styling', 'Hair treatments', 'Bridal hair'],
        ],
        'closing' => [
            'line' => 'Open your salon\'s booking page today.',
            'action' => 'Start your free trial',
        ],
    ],
    'barbers' => [
        'title' => 'Online booking for barbers - :name',
        'description' => 'Your own booking page for cuts, fades and beard trims, and the same booking on your barbershop\'s website. Seven days free, no card needed.',
        'heading' => 'Online booking for your barbershop',
        'intro' => 'Customers see when each barber is free and book a cut from your page or your website, without making an account.',
        'benefits' => [
            [
                'title' => 'Every barber on one calendar',
                'body' => 'Each barber has their own hours and their own calendar, and you see them all together. Customers choose a barber, or anyone available.',
            ],
            [
                'title' => 'Phone calls and walk-ins too',
                'body' => 'Add a booking yourself when a customer calls or walks in. It takes its place on the same calendar, so no one else can book that time.',
            ],
            [
                'title' => 'Paid at the shop or online',
                'body' => 'For each service, choose whether customers pay at the shop or online in full when they book.',
            ],
        ],
        'services' => [
            'heading' => 'What your customers can book',
            'items' => ['Haircut', 'Skin fade', 'Beard trim', 'Cut and beard', 'Hot towel shave', 'Kids\' cut'],
        ],
        'closing' => [
            'line' => 'Open your barbershop\'s booking page today.',
            'action' => 'Start your free trial',
        ],
    ],
    'nails' => [
        'title' => 'Online booking for nail studios - :name',
        'description' => 'Your own booking page for manicures, gel and nail art, and the same booking on your studio\'s website. Seven days free, no card needed.',
        'heading' => 'Online booking for your nail studio',
        'intro' => 'Clients choose the treatment and a time that suits them, and book from your page or your website at any hour, without making an account.',
        'benefits' => [
            [
                'title' => 'Time to set up between clients',
                'body' => 'Add time before or after a treatment to clean and prepare, and no one can book into it.',
            ],
            [
                'title' => 'Hands and feet in one visit',
                'body' => 'A client can book a manicure and a pedicure in the same visit, each with its own length and price.',
            ],
            [
                'title' => 'A reminder before they come',
                'body' => 'Clients get a confirmation by email, with a link to cancel or move the appointment within your rules, and a reminder before they come.',
            ],
        ],
        'services' => [
            'heading' => 'What your clients can book',
            'items' => ['Manicure', 'Gel manicure', 'Pedicure', 'Gel removal', 'Nail extensions', 'Nail art'],
        ],
        'closing' => [
            'line' => 'Open your nail studio\'s booking page today.',
            'action' => 'Start your free trial',
        ],
    ],
    'beauty' => [
        'title' => 'Online booking for beauty salons - :name',
        'description' => 'Your own booking page for facials, waxing, lashes and brows, and the same booking on your salon\'s website. Seven days free, no card needed.',
        'heading' => 'Online booking for your beauty salon',
        'intro' => 'Clients look through your treatments, choose a time and book from your page or your website, without making an account.',
        'benefits' => [
            [
                'title' => 'Your own page, in your own name',
                'body' => 'Your booking page shows your salon\'s name, logo, photos and opening hours, and the same booking works on your website.',
            ],
            [
                'title' => 'Your approval where you want it',
                'body' => 'A treatment can ask for your approval first: the time is held for the client while you decide.',
            ],
            [
                'title' => 'Your cancellation rules',
                'body' => 'Set how late a client may cancel or move a booking, treatment by treatment. Once they do, the time is free for someone else.',
            ],
        ],
        'services' => [
            'heading' => 'What your clients can book',
            'items' => ['Facial', 'Waxing', 'Lash extensions', 'Brow shaping and tint', 'Make-up', 'Body treatments'],
        ],
        'closing' => [
            'line' => 'Open your beauty salon\'s booking page today.',
            'action' => 'Start your free trial',
        ],
    ],
    'massage' => [
        'title' => 'Online booking for massage therapists - :name',
        'description' => 'Your own booking page for massages of every length, and the same booking on your practice\'s website. Seven days free, no card needed.',
        'heading' => 'Online booking for your massage practice',
        'intro' => 'Clients choose a massage, see when you are free and book from your page or your website, without making an account.',
        'benefits' => [
            [
                'title' => 'Time between sessions',
                'body' => 'Add time after each session to prepare the room, and no one can book into it.',
            ],
            [
                'title' => 'Your hours, your days off',
                'body' => 'Set the hours you work each day and block out the days you are away. Clients see only the times you are free.',
            ],
            [
                'title' => 'Paid ahead or on the day',
                'body' => 'For each massage, choose whether it is paid online in full when it is booked, or at your practice.',
            ],
        ],
        'services' => [
            'heading' => 'What your clients can book',
            'items' => ['Relaxing massage', 'Deep tissue massage', 'Sports massage', 'Hot stone massage', 'Back, neck and shoulders', 'Reflexology'],
        ],
        'closing' => [
            'line' => 'Open your massage practice\'s booking page today.',
            'action' => 'Start your free trial',
        ],
    ],
    'physiotherapy' => [
        'title' => 'Online booking for physiotherapists - :name',
        'description' => 'Your own booking page for assessments and treatment sessions, and the same booking on your practice\'s website. Seven days free, no card needed.',
        'heading' => 'Online booking for your physiotherapy practice',
        'intro' => 'Patients choose the session and a time that suits them, and book from your page or your website, without making an account.',
        'benefits' => [
            [
                'title' => 'A first visit by approval',
                'body' => 'An assessment can ask for your approval first: the time is held for the patient while you decide.',
            ],
            [
                'title' => 'Each therapist with their own calendar',
                'body' => 'Each physiotherapist has their own working hours, the sessions they offer and their own calendar.',
            ],
            [
                'title' => 'Patients move their own appointments',
                'body' => 'The confirmation has a link to cancel or move the appointment within the rules you set, and the time is free again for someone else.',
            ],
        ],
        'services' => [
            'heading' => 'What your patients can book',
            'items' => ['Initial assessment', 'Follow-up session', 'Sports injury treatment', 'Back and neck pain', 'Post-operative rehabilitation', 'Manual therapy'],
        ],
        'closing' => [
            'line' => 'Open your physiotherapy practice\'s booking page today.',
            'action' => 'Start your free trial',
        ],
    ],
];
