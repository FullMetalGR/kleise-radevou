<?php

/*
 * The pricing page in English. Its title and meta description are the head's and its heading the
 * page's h1. No plan's name, no amount and no count of plans is spelled here: they are the plan
 * catalogue's, in config/billing.php, so a changed catalogue changes the page with no edit to this
 * file. Every amount is followed by card.period, which says it is a month's price and ex VAT. The
 * FAQ answers the six objections to the price, each true of the product as built; the commission
 * answer keeps the narrowed form the home's hero carries and the trial's end is answered as
 * faq.php answers it. The title, the description, the heading, the lead and the FAQ's items are
 * read with the public name filled in as :name; the cards, the floor's lines, the features and the
 * table are read with nothing filled in (App\Site\PricingSheet), so none of those names :name. The
 * trial's length is :count wherever a value names it, its days from the code's constant, so a
 * changed trial changes the page with no edit to this file.
 */

return [
    'title' => 'Pricing - :name',
    'description' => 'Monthly prices for :name, ex VAT, with no commission on bookings and no contract. :count days free, no card needed.',
    'heading' => 'Pricing',
    'lead' => 'One flat price a month for your business, however many bookings you take. Start with :count days free. No card needed.',
    // Each plan's card: its name and amount are the catalogue's; this line follows the amount.
    'card' => [
        'period' => 'a month, ex VAT',
        'trial' => ':count days free, no card needed.',
        'includes' => 'What it includes',
        'action' => 'Start your free trial',
    ],
    // What every plan includes, the floor: each card's first lines, and the table's first rows.
    'includes' => [
        'Services, bookings and the calendar',
        'Your own booking page, and the same booking on your website',
        'Email confirmations and reminders',
        'Online payments, or payment on site',
    ],
    // Each feature a plan may grant, by its slug: its row in the table, with what a plan with it
    // and a plan without it reads there, and the line a card carries with it and without it.
    'features' => [
        'staff' => [
            'label' => 'Staff members',
            'has' => 'Any number',
            'lacks' => 'One',
            'with' => 'Any number of staff members, each with their own hours, services and calendar',
            'without' => 'One staff member',
        ],
    ],
    // The table of what each plan has: its heading, the head of the rows' column, and the cell a
    // plan reads under a row every plan has.
    'table' => [
        'heading' => 'Compare the plans',
        'item' => 'What you get',
        'included' => 'Included',
    ],
    // The questions a business asks about the price, each a heading over its answer's paragraphs,
    // the key its anchor.
    'faq' => [
        'heading' => 'Questions about the price',
        'items' => [
            'commission' => [
                'question' => 'Do you take a commission?',
                'answer' => [
                    'No commission on bookings, or on payments through your own provider. You pay the monthly price of your plan, the same however many bookings you take.',
                ],
            ],
            // Whether a cancellation runs to the end of the paid month is the billing provider's
            // portal setting, not this application's, so the answer says no more than any
            // setting makes true.
            'contract' => [
                'question' => 'Is there a contract?',
                'answer' => [
                    'No. You pay month by month and can cancel at any time.',
                ],
            ],
            'card' => [
                'question' => 'Do I need a card for the free trial?',
                'answer' => [
                    'No. The :count days are free and no card is needed. You add a card only when you choose a plan.',
                ],
            ],
            // The checkout collects the business's VAT number and asks the billing provider for
            // automatic tax where billing.provider.automatic_tax is on, so whether the invoice
            // carries VAT rests on the provider's own tax setup; the answer names the invoice
            // alone.
            'invoice' => [
                'question' => 'Do I get an invoice?',
                'answer' => [
                    'Yes. Every payment for your subscription comes with an invoice, which you open from the Billing screen of your admin.',
                ],
            ],
            'trial' => [
                'question' => 'What happens when the trial ends?',
                'answer' => [
                    'We email you three days before it ends and again on its last day. To keep going, choose a plan and add a card on the Billing screen of your admin.',
                    'If you do not, setup changes are paused: your booking page keeps taking bookings, but you cannot change your services, staff, opening hours or settings. Thirty days after the trial ends, we start closing the account. We email you, and you have thirty more days to choose a plan and keep it.',
                ],
            ],
            // Change plan is offered while a subscription runs (active, grace and past_due), never
            // in the trial, whose road is the plan choice.
            'change' => [
                'question' => 'Can I change plan?',
                'answer' => [
                    'Yes. While your subscription runs, change it from the Billing screen of your admin. Adding staff takes effect at once. To go back to one staff member, first leave only one staff member active.',
                ],
            ],
        ],
    ],
];
