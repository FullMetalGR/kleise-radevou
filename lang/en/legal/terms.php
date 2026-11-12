<?php

/*
 * The terms of service in English: written from what the product does, for a later legal review.
 * The trial, the plans, payment, cancellation, suspension and closing are as the platform runs
 * them, and the pricing page is their ceiling: no plan's name, no amount and no count of plans is
 * spelled here, the cancellation's effect is the billing provider's portal's, and the invoice is
 * an invoice. The data protection features are named as built: the deletion at the end of the
 * closing window, the lapsed subscriber's close, and the close at the request of the owner or a
 * manager and the export, both in the Settings Danger zone. A fact still to be supplied stands in
 * square brackets, "to be confirmed", for the legal review. The legal pages' one template renders
 * it: the title and meta description are the head's, the heading the page's h1, the version the
 * date its version line names, and the sections in this order, each a heading over its body, a
 * string a paragraph and a list of strings a list, the section's key its anchor. Every value may
 * name the public name as :name.
 */

return [
    'title' => 'Terms of service - :name',
    'description' => 'The terms on which a business uses :name: the free trial, the plans, payment, cancellation, suspension and closing an account.',
    'heading' => 'Terms of service',
    'version' => '3 November 2026',
    'sections' => [
        'agreement' => [
            'heading' => 'These terms',
            'body' => [
                'These terms set out how a business uses :name, the booking service run by the company named at the top of this page. In them, "we" and "us" mean that company, and "you" means the business that holds an account, acting through the people who sign in to it.',
                ':name is for businesses: an account is opened for a business, in the course of its trade.',
                'The data processing agreement published beside these terms is part of them, and covers the personal data of your customers and staff that :name keeps for you. The privacy policy explains what we do with the personal data we handle for ourselves.',
                'These terms apply from the moment your account is opened.',
            ],
        ],
        'service' => [
            'heading' => 'The service',
            'body' => [
                ':name lets a business that sells appointments publish its own booking page, show the same booking form on its own website, take bookings and payments from its customers, and run its services, staff, calendar and settings from its own admin.',
                'Your customers book, pay, cancel and move their bookings without an account of their own.',
                'We send the emails about bookings to your customers and to you from our own address, and replies to them go to your owner\'s email address. You can switch most of them off in your settings.',
                'Each account has its own address under :name, chosen at the signup. It cannot be changed later, because it is part of every link and every booking form you share.',
            ],
        ],
        'account' => [
            'heading' => 'Opening an account',
            'body' => [
                'To open an account, you give the business\'s name, its address on :name, its kind of business, its time zone and currency, and the name, email address and password of its owner. We email a link to that address and set up the account when the link is opened. A signup whose link is not opened within 24 hours is deleted, and its address becomes free again.',
                'Each person who signs in to your admin does so with their own email address and password, and with one of three roles: owner, manager or staff. You are responsible for keeping those passwords safe, and for what the people you let in do in your admin.',
            ],
        ],
        'trial' => [
            'heading' => 'The free trial',
            'body' => [
                'A new account starts with a free trial of seven days from when it is set up, with every feature. No card is needed for it.',
                'We email the owner three days before the trial ends and again on its last day. To continue after it, the owner chooses a plan and adds a card on the Billing screen of the admin.',
                'If no plan is chosen, setup changes are paused when the trial ends: your booking page keeps taking bookings, and bookings can still be added, approved, declined, cancelled, moved and marked as completed or missed, but your services, staff, opening hours and settings cannot be changed. Thirty days after the trial ends, we start closing the account, as set out under Closing an account.',
            ],
        ],
        'plans' => [
            'heading' => 'Plans and prices',
            'body' => [
                'After the trial, :name is sold by subscription, one plan per account, paid month by month. The plans, what each includes and their monthly prices are those the pricing page of :name shows when you subscribe. Prices are stated excluding VAT.',
                'A price is flat for the account: it does not change with the number of bookings you take. We take no commission on bookings, or on payments through your own provider.',
                'While your subscription runs, the owner can change plan from the Billing screen. Adding staff takes effect at once. To go back to a plan with one staff member, first leave only one staff member active.',
                'We may change the prices of the plans. We email the owner [the notice period, to be confirmed] before a new price applies to your subscription.',
            ],
        ],
        'payment' => [
            'heading' => 'Payment and invoices',
            'body' => [
                'The subscription is paid by card through our billing provider, Stripe. The card is entered on Stripe\'s own page, and we never see its number.',
                'Every payment for your subscription comes with an invoice, which the owner opens from the Billing screen of the admin.',
                'If a payment fails, your account keeps every feature for a grace period of seven days, in which the owner can update the card from the Billing screen. If it is still unpaid when the grace period ends, setup changes are paused until a payment succeeds. Bookings keep running throughout.',
            ],
        ],
        'cancellation' => [
            'heading' => 'Cancelling',
            'body' => [
                'There is no minimum term. The owner can cancel the subscription at any time from the Billing screen, through our billing provider\'s portal, which applies the cancellation and shows when it takes effect.',
                'When the subscription has ended, setup changes are paused, as they are after a trial that ended unpaid: your booking page keeps taking bookings, and the owner can subscribe again from the Billing screen at any time.',
            ],
        ],
        'customers' => [
            'heading' => 'Payments from your customers',
            'body' => [
                'You can let your customers pay online, through a payment provider you connect in your settings, or on site. There are two ways to connect one: with your own account and its keys, for which we take no fee; or through the provider\'s onboarding from :name, for which a fee per payment may apply. The Payments section of your settings shows any such fee before you choose, and a change to it applies to new payments only.',
                'Either way, the payment goes to your own account with the provider. You are the seller of your services, not us: the provider\'s own terms and processing rate apply, and refunds and disputes are yours. We never refund a payment for you; you refund it in your provider\'s dashboard.',
                'Today the provider on offer is Stripe. Where no payment connection works, a booking that would be paid online is taken with payment on site instead.',
            ],
        ],
        'obligations' => [
            'heading' => 'What you agree to',
            'body' => [
                'You agree to:',
                [
                    'use :name for your own business and its bookings, and keep what your booking page says about it accurate;',
                    'tell your customers how you use their personal data, and collect from them only what you are allowed to;',
                    'publish on your booking page only images and text you have the right to publish;',
                    'not use :name to break the law or anyone\'s rights, or to send anything unlawful to anyone;',
                    'not try to get around the security of :name, overload it, or reach any data that is not your business\'s.',
                ],
            ],
        ],
        'availability' => [
            'heading' => 'Availability and support',
            'body' => [
                'We work to keep :name running at all times, but we do not promise that it will run without interruption or fault. We may stop it for a short time for maintenance.',
                'You can ask us for help through the contact page of :name. We answer by email, in Greek or in English. To help you inside your admin, our staff can open a support session there, as the data processing agreement sets out.',
            ],
        ],
        'suspension' => [
            'heading' => 'Suspension',
            'body' => [
                'We may suspend an account that is used against these terms or the law, or to protect :name, the businesses on it or their customers.',
                'While an account is suspended, its booking page and its admin answer that booking is paused, and emails about existing bookings still go to its customers. We email the owner the reason when we suspend the account, and again when the suspension ends. A suspension does not stop or change the subscription.',
            ],
        ],
        'closing' => [
            'heading' => 'Closing an account',
            'body' => [
                'For an account whose trial ended without a plan being chosen, we start closing the account thirty days after the trial\'s end, and we email the owner when we do. For thirty more days, the account stays as it is: the booking page keeps taking bookings, setup changes stay paused, and choosing a plan on the Billing screen keeps the account open.',
                'At the end of those thirty days, the account and everything in it are deleted: your business\'s data, the people who sign in to it, its payment connection and the images you uploaded. We keep only the account\'s own record, for our accounts, which holds none of your customers\' data, and the account\'s address on :name becomes free for a new signup. For an account whose subscription ended, with no plan chosen since, we start closing it in the same way thirty days after its last billing period ended, and we email the owner when we do. Both the deletion and this closing run every day at 04:30, Athens time.',
                'The owner or a manager can also close the account, with the Close this account button in the Danger zone of the Settings screen. Billing then stops at once and nothing more is charged, we email the owner, and the same thirty days run: the booking page keeps taking bookings, setup changes are paused, and choosing a plan on the Billing screen keeps the account open or, where the Billing screen offers no plan, writing to us before the thirty days end. Until the account is deleted, the owner or a manager can take an export of everything it holds, as one zip file, with the Export everything button in the same Danger zone.',
            ],
        ],
        'liability' => [
            'heading' => 'Liability',
            'body' => [
                'You are responsible for your services, your prices and your dealings with your customers, and for the payment providers you connect and their terms.',
                'We are liable for damage we cause by intent or gross negligence, and for any other liability the law does not allow to be limited. For other damage, our total liability in any twelve months is limited to [the cap, to be confirmed], and we are not liable for lost profit or indirect loss.',
            ],
        ],
        'changes' => [
            'heading' => 'Changes to these terms',
            'body' => [
                'We may change these terms. We publish each new version on this page with its date, and we email the owner of every account [the notice period, to be confirmed] before a change takes effect.',
            ],
        ],
        'law' => [
            'heading' => 'Governing law',
            'body' => [
                'These terms are governed by [the governing law, to be confirmed], and the courts of [the venue, to be confirmed] decide any dispute about them.',
            ],
        ],
        'contact' => [
            'heading' => 'Contact',
            'body' => [
                'Write to us through the contact page of :name. Our company\'s name, address and VAT number are given at the top of this page.',
            ],
        ],
    ],
];
