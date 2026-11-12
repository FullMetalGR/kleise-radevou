<?php

/*
 * The FAQ in English. Its title and meta description are the head's and its heading the page's
 * h1. The questions render in this order, each a heading over its answer's paragraphs, its key
 * the question's anchor. Every answer is true of the product as built, the commission answer
 * keeps the narrowed form the home's hero carries, and the trial's end is answered as pricing.php
 * answers it. Every value may name the public name as :name. The trial's length is :count wherever
 * a value names it, its days from the code's constant, so a changed trial changes the page with no
 * edit to this file.
 */

return [
    'title' => 'Questions - :name',
    'description' => 'Answers about :name: the free trial and what happens when it ends, the card, commission, where your data is kept, booking on your website and booking without an account.',
    'heading' => 'Questions and answers',
    'lead' => 'What businesses ask before they start.',
    'items' => [
        'trial' => [
            'question' => 'What does the free trial include?',
            'answer' => [
                'Everything. For :count days you can use every feature, staff members included.',
            ],
        ],
        'ends' => [
            'question' => 'What happens when the trial ends?',
            'answer' => [
                'We email you three days before it ends and again on its last day. To keep going, choose a plan and add a card on the Billing screen of your admin.',
                'If you do not, setup changes are paused: your booking page keeps taking bookings, but you cannot change your services, staff, opening hours or settings. Thirty days after the trial ends, we start closing the account. We email you, and you have thirty more days to choose a plan and keep it.',
            ],
        ],
        'card' => [
            'question' => 'Do I need a card to start?',
            'answer' => [
                'No. You add a card only when you choose a plan.',
            ],
        ],
        'commission' => [
            'question' => 'Do you take a commission?',
            'answer' => [
                'No commission on bookings, or on payments through your own provider. You pay a monthly price, the same however many bookings you take.',
            ],
        ],
        'data' => [
            'question' => 'Where is my data kept?',
            'answer' => [
                'Your business\'s data and your customers\' details are kept on servers in the European Union.',
            ],
        ],
        'embed' => [
            'question' => 'How do I put the booking on my website?',
            'answer' => [
                'In your admin, open Settings, go to the Booking page section and copy the snippet from the Links card. Paste it into your website where the booking should appear. Customers book there in the same way as on your booking page.',
            ],
        ],
        'accounts' => [
            'question' => 'Do my customers need an account?',
            'answer' => [
                'No. They book with their name and contact details, and the confirmation they receive has a link to cancel or move the booking.',
                'A customer who wants to can also open My bookings from a one-time link sent to their email, to see every booking they have with you and book again.',
            ],
        ],
    ],
];
