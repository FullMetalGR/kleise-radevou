<?php

/*
 * The home page in English. Its title and meta description are the head's, its heading the
 * hero's headline. Every claim is true of the product as built: no proof, no outcome numbers, no
 * competitor and no fiscal claim. Only the title and the description are read with the public
 * name filled in, so no other value names :name. The trial's length is :count wherever a value
 * names it, its days from the code's constant, so a changed trial changes the page with no edit to
 * this file.
 */

return [
    'title' => ':name - online appointment booking for your business',
    'description' => 'Your own booking page and the same booking on your website. Customers book without an account, and there is no commission on bookings. :count days free, no card needed.',
    'heading' => 'Online booking for your business',
    'hero' => [
        // The four claims the hero leads with. The commission claim names the business's own
        // provider: a payment through a platform connection can carry the platform's fee
        // (payments.platform_fee), so the bare claim would be true only while that fee is zero. The
        // same sentence stands in gets.payments, features.php, faq.php and pricing.php.
        'claims' => [
            'trial' => ':count days free. No card needed.',
            'commission' => 'No commission on bookings, or on payments through your own provider.',
            'page' => 'Your own booking page, and the same booking on your website.',
            'guests' => 'Your customers book without making an account.',
        ],
        // The primary action, to the signup form, and the second, to the demo section below.
        'start' => 'Start your free trial',
        'demo' => 'Try the demo',
    ],
    // What the business gets, each a heading over one sentence.
    'gets' => [
        'heading' => 'What your business gets',
        'items' => [
            'page' => [
                'title' => 'Your own booking page',
                'body' => 'A page in your business\'s name where customers see your services, choose a time and book.',
            ],
            'embed' => [
                'title' => 'Booking on your website',
                'body' => 'Paste one snippet of code into your website, and customers can book there too, in the same way.',
            ],
            'calendar' => [
                'title' => 'Your calendar',
                'body' => 'See every booking by day or by week, each staff member in their own colour, and add a booking yourself when a customer calls.',
            ],
            'emails' => [
                'title' => 'Emails and reminders',
                'body' => 'Customers get a confirmation by email and a reminder before their appointment. Both are sent automatically.',
            ],
            'payments' => [
                'title' => 'Payments online or on site',
                'body' => 'For each service, choose whether customers pay online when they book or pay on site. No commission on bookings, or on payments through your own provider.',
            ],
        ],
    ],
    // How it starts, three steps in order, each a heading over one sentence.
    'steps' => [
        'heading' => 'How to start',
        'items' => [
            'signup' => [
                'title' => 'Sign up',
                'body' => 'Enter your business name and choose the web address of your booking page. Your page is set up as soon as you confirm your email.',
            ],
            'setup' => [
                'title' => 'Add your services and hours',
                'body' => 'Add what you offer, with how long each service takes and its price, and set the hours you and your staff work.',
            ],
            'share' => [
                'title' => 'Share your page',
                'body' => 'Send your customers the link to your booking page, or paste the snippet into your website.',
            ],
        ],
    ],
    // The verticals strip: a heading over a link to each of the six vertical pages, each labelled
    // from site.php's pages.vertical.
    'verticals' => [
        'heading' => 'Made for businesses like yours',
    ],
    // The demo section: the demo's embed mounted from the demo's own origin under a heading and one
    // paragraph, the noscript sentence the mount element holds until the embed replaces it, and a
    // link to the demo's own booking page. The paragraph says what holds of the demo: it is a
    // sample salon, any time it shows can be booked, what is typed is replaced with the sample
    // customer's details and not kept, and no email is sent.
    'demo' => [
        'heading' => 'Try a booking yourself',
        'body' => 'This is a sample hair salon, and you can book any time it shows. The details you type are replaced with a sample customer\'s and are not kept, and no email is sent.',
        'noscript' => 'The demo runs in your browser and needs JavaScript switched on.',
        'page' => 'Open the sample salon\'s own booking page',
    ],
    // The closing call to the signup.
    'closing' => [
        'heading' => 'Try it free for :count days',
        'body' => 'No card needed. Set up your booking page today, and share it with your customers when you are ready.',
        'action' => 'Start your free trial',
    ],
];
