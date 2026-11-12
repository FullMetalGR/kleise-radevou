<?php

/*
 * The features page in English. Its title and meta description are the head's and its heading
 * the page's h1. The groups render in this order, each its heading over its paragraphs, its key
 * the group's anchor. Every claim is true of the product as built: no proof, no outcome
 * numbers, no competitor and no fiscal claim, and the commission claim in the narrowed form the
 * home's hero carries. Every value may name the public name as :name.
 */

return [
    'title' => 'Features - :name',
    'description' => 'What :name gives your business: your own booking page and booking on your website, a calendar for you and your staff, emails and reminders, online payments, and an admin that works on your phone.',
    'heading' => 'What your business gets',
    'lead' => 'Every feature on this page is open during the free trial, from its first day, staff members included.',
    'groups' => [
        'page' => [
            'heading' => 'Your booking page and your website',
            'body' => [
                'Your business gets a booking page of its own, in its own name, with your logo, photos, opening hours and address. Customers choose a service and a time and, if you have a team, the person they would like or anyone available.',
                'Paste one snippet of code into your website, and customers can book there too, in the same way.',
                'Customers book without making an account, and can book more than one service in the same visit.',
            ],
        ],
        'calendar' => [
            'heading' => 'The calendar and your staff',
            'body' => [
                'See your bookings by day or by week, and each month at a glance. Add a booking yourself when a customer calls.',
                'With a team, each staff member has their own working hours, the services they offer and their own calendar.',
                'A service can ask for your approval first: the time is held for the customer while you decide.',
            ],
        ],
        'emails' => [
            'heading' => 'Emails and reminders',
            'body' => [
                'Customers get a confirmation by email with a calendar file attached, and a reminder before their appointment.',
                'The confirmation has a link to cancel or move the booking, within the rules you set for each service.',
                'You choose how long before an appointment the reminder is sent, and you can switch off the messages you do not want. When a customer replies to one, the reply comes to you.',
            ],
        ],
        'payments' => [
            'heading' => 'Payments',
            'body' => [
                'For each service, choose whether it is free, paid online in full, or paid on site.',
                'Online payments go through Stripe. No commission on bookings, or on payments through your own provider.',
                'Payment on site always works, with nothing to connect.',
            ],
        ],
        'phone' => [
            'heading' => 'The admin on your phone',
            'body' => [
                'Your admin works on a phone: see the day\'s bookings, approve a request or cancel a booking, wherever you are.',
            ],
        ],
    ],
];
