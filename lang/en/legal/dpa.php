<?php

/*
 * The data processing agreement in English: written from what the product does, for a later legal
 * review. The tenant is the controller of its customers' and staff's data and the platform its
 * processor; the data and the people it is about are the booking's fields, the note, the My
 * bookings link and session and the staff rows; the sub-processors are seven, Viva named as a
 * provider not yet built; the customer's export and erasure, the tenant export, the close at the
 * request of the owner or a manager, the lapsed subscriber's close, the closing's drop and the
 * sweep are named as built. A fact still to be supplied stands in square brackets, "to be
 * confirmed", for the legal review. The legal pages' one template renders it: the title and meta
 * description are the head's, the heading the page's h1, the version the date its version line
 * names, and the sections in this order, each a heading over its body, a string a paragraph and a
 * list of strings a list, the section's key its anchor. Every value may name the public name as
 * :name.
 */

return [
    'title' => 'Data processing agreement - :name',
    'description' => 'The agreement under which :name keeps and handles the personal data of a business\'s customers and staff for the business.',
    'heading' => 'Data processing agreement',
    'version' => '3 November 2026',
    'sections' => [
        'parties' => [
            'heading' => 'The parties and this agreement',
            'body' => [
                'This agreement is between a business that holds an account on :name (the business) and the company named at the top of this page, which runs :name (we). It is part of the terms of service, and it sets out how we handle the personal data the business keeps in :name, as Article 28 of the General Data Protection Regulation (EU) 2016/679 requires.',
                'It applies for as long as the business uses :name, and after that for as long as we keep any of the business\'s data.',
            ],
        ],
        'roles' => [
            'heading' => 'Who decides',
            'body' => [
                'The business is the controller of the personal data of its customers and staff that it keeps in :name: it decides what it collects, why, and for how long it needs it, within what :name lets it do. We are its processor.',
                'We handle that data only to provide :name to the business and on its documented instructions: the terms of service, this agreement, and what the business sets and does in its admin, such as which emails go to its customers. If we believe an instruction breaks the law, we tell the business.',
            ],
        ],
        'processing' => [
            'heading' => 'Subject matter, duration, nature and purpose',
            'body' => [
                [
                    'Subject matter: keeping the business\'s bookings and the data needed to take them, run them and follow them up.',
                    'Duration: while the business holds an account, and until its data is deleted at the end, as set out below.',
                    'Nature: storing, organising, showing and deleting the data, sending it by email to the people it is about, and passing what a payment needs to the payment provider the business connects.',
                    'Purpose: to let the business publish its booking page, take and manage bookings and payments, and tell its customers and staff about them.',
                ],
            ],
        ],
        'data' => [
            'heading' => 'The data and the people it is about',
            'body' => [
                'The business\'s customers, the people who book with it or ask for their bookings:',
                [
                    'for each booking, the customer\'s name, email address and, where given, phone number;',
                    'the services booked, their times and the staff member who takes each;',
                    'the price, the way of paying and the payment provider\'s reference for the payment;',
                    'whether the booking was approved, declined, cancelled, moved, completed or missed, and the reason the business gives when it declines a request;',
                    'whether the booking came through the booking page, the booking form on the business\'s website or the admin, and the channel of a link the business tagged;',
                    'the note a customer may add to a booking, of up to 500 characters, shown in the business\'s admin and in its own emails about the booking, and never in an email to the customer;',
                    'for a customer who asks for their bookings by email, the address and a record of the one-time link sent to it, which works for fifteen minutes;',
                    'a record of the session that link opens on the business\'s address, which lasts thirty days or until the customer signs out, with its key in a cookie in the customer\'s browser;',
                    'the emails sent to customers about their bookings, some with a calendar file attached;',
                    'while a customer books, the session of the visit, whose record keeps the network address and the browser while it lasts;',
                    'the network address of each request, and the email address a customer asks their bookings link for, each used for a minute at most to limit how often the booking page can be asked.',
                ],
                'The business\'s staff: each staff member\'s name and, where the business gives them, email address, photo, title and short biography, their working hours, the times they are away with any note the business keeps on them, and the bookings they take.',
                'The business\'s own details on its booking page, such as its address, phone, email address, links and photos, where they name a person.',
                ':name asks for no special category of data, such as health data. The business decides what its customers are asked; it should not invite such data in a booking\'s note unless the law allows it and the business has its own basis for it.',
                'The history of each booking records what changed, when and by whom: a person who signs in, the customer, the system, or our support. It never copies a customer\'s name or email address.',
            ],
        ],
        'obligations' => [
            'heading' => 'What we do',
            'body' => [
                'We:',
                [
                    'handle the data only on the business\'s instructions, unless the law requires otherwise, in which case we tell the business first, unless the law forbids it;',
                    'make sure everyone at our company who can reach the data is bound to keep it confidential;',
                    'keep the data secure, as set out below;',
                    'use only the sub-processors named below, on the conditions below;',
                    'help the business answer its customers\' requests and meet its own obligations, as set out below;',
                    'tell the business without undue delay after we become aware of a personal data breach affecting its data, with what we know of it and what we are doing about it;',
                    'delete the data at the end, as set out below;',
                    'give the business what it needs to show that this agreement is kept, and allow audits, as set out below.',
                ],
            ],
        ],
        'security' => [
            'heading' => 'Security',
            'body' => [
                [
                    'Each business\'s data is kept in a database schema of its own, apart from every other business\'s, and every write of a booking first checks that it is going to the right business\'s schema.',
                    'The link a customer uses to manage a booking, the link to a customer\'s bookings and the session it opens are kept only as one-way hashes. The one exception is the link to manage a booking while its online payment is waiting: it is kept encrypted until the booking\'s confirmation is sent or the wait ends, then removed.',
                    'Passwords are kept only as one-way hashes. A payment provider\'s credentials are encrypted, never shown again once saved, and never written to a log.',
                    'A customer enters their card details on the payment provider\'s own page; they never reach :name.',
                    'When our application logs a problem with an email, it names the business, the message and the booking, never the address.',
                    'The emails carry no tracking pixel and load no remote image.',
                    'Because the history of a booking holds no personal data, erasing a customer leaves nothing of them in it.',
                ],
            ],
        ],
        'access' => [
            'heading' => 'Access by our staff',
            'body' => [
                'Our staff reach a business\'s bookings and customers only through a support session in the business\'s admin, opened to give the business the help it asks for. A session lasts at most sixty minutes from when it is started and cannot reach the Billing screen. Every change it makes to a booking is shown in the booking\'s history as made by support, with the name of the person who made it, and each session is recorded in our own audit log. The business is not told by email when a session starts.',
                'Our console, which only our staff can reach, shows each account\'s details and, from the business\'s data, only the number of its services, staff members and bookings and when its latest booking was made. It shows no customer.',
            ],
        ],
        'subprocessors' => [
            'heading' => 'Sub-processors',
            'body' => [
                'The business agrees that we use these sub-processors:',
                [
                    'Linode, hosting: the servers on which all of the business\'s data is stored.',
                    'RunCloud, server management: the service through which we run those servers, which can reach them.',
                    'Qboxmail, email: it delivers the emails to the business\'s customers and staff, with their address and what the email says.',
                    'Stripe, payments: it bills the business\'s subscription. Where the business connects it for its customers\' online payments, it also takes those payments. For each one it receives from us the booking\'s reference, the names of the services, the total and any note the business leaves on the payment, and from the customer, on its own page, their payment details.',
                    'Viva, payments: a payment provider we plan to offer for the business\'s customers\' payments. It is not yet in use, and it receives nothing until the business connects it.',
                    'Cloudflare, anti-spam: the Turnstile check on our signup form and contact form. It receives no data of the business\'s customers.',
                    'Google: Analytics on our own site, only with each visitor\'s consent, which never runs on the business\'s booking page, its booking form or its admin; and Maps, which makes the map on the business\'s booking page from the business\'s address, the only data it receives.',
                ],
                'Where the business connects its own account with a payment provider, the business\'s own agreement with that provider also governs what the provider does with the data.',
                'We give each sub-processor data protection obligations no weaker than ours, and we remain responsible to the business for them. Where a sub-processor handles data outside the European Economic Area, the transfer rests on [the safeguard for each sub-processor, to be confirmed].',
                'We tell the business by email of any sub-processor we intend to add or replace, [the notice period, to be confirmed] before the change. The business may object; if we cannot meet the objection, the business may end its use of :name before the change takes effect.',
            ],
        ],
        'assistance' => [
            'heading' => 'Helping with customers\' requests',
            'body' => [
                'A customer who asks to see, correct, export or erase their data asks the business. If a customer asks us, we pass the request to the business and do not answer it ourselves.',
                'The business finds every booking of one customer on the Customers screen of its admin, and the customer\'s page there holds two tools for its owner and managers. The Export button downloads one file, in JSON, of every booking made under the customer\'s email address, with its services, its times and what the customer typed into the booking form. The Erase this customer button keeps the bookings, which the business needs for its accounts and its calendar, with the customer\'s name, email address and phone removed, their notes deleted and the links to manage those bookings ended. An erasure is refused while a booking of the customer is not over yet, such as one awaiting a decision or a payment or a confirmed booking still to come; the business cancels it first. Neither tool is paused when setup changes are.',
                'We also help the business, as far as the information we hold allows, to keep the data secure, to deal with a personal data breach, and with any impact assessment or prior consultation the law asks of it.',
            ],
        ],
        'retention' => [
            'heading' => 'How long data is kept',
            'body' => [
                [
                    'A booking and its services: 24 months after its last service ends. The customer\'s name, email address and phone are then removed, and the booking is kept for the business\'s accounts.',
                    'A booking\'s note: 24 months, then deleted.',
                    'The history of a booking: 24 months, then deleted.',
                    'The link to manage a booking: until 30 days after its last service ends, when it is removed and stops working.',
                    'A link to a customer\'s bookings: until it lapses, fifteen minutes after it is sent. The session it opens: until it ends, after thirty days or when the customer signs out.',
                    'The notifications the business\'s payment provider sends us: 90 days.',
                    'Everything in a closed account: thirty days after the closing starts, as set out below.',
                ],
                'A scheduled deletion applies these periods every day at 04:30, Athens time, so data is deleted or removed at the first run after its period ends, and the link to manage a booking stops working then. The link to a customer\'s bookings and the session it opens stop working the moment their time is up, before the deletion removes their records.',
            ],
        ],
        'end' => [
            'heading' => 'Deletion at the end',
            'body' => [
                'When the business\'s account closes, its data stays for thirty days, during which choosing a plan on the Billing screen reopens the account. At the end of the thirty days, the business\'s own database schema is deleted, with every booking, customer and staff member in it. We keep only the account\'s own record for our accounts, which holds none of the business\'s customers\' data.',
                'For an account whose trial ended without a plan being chosen, we start the closing thirty days after the trial\'s end, and the thirty days above then run.',
                'The owner or a manager closes the account with the Close this account button in the Danger zone of the Settings screen, which stops its billing at once, and the thirty days above then run; where the Billing screen offers no plan, writing to us before the thirty days end keeps the account open. For an account whose subscription ended, with no plan chosen since, we start the closing thirty days after its last billing period ended, and the same thirty days then run. Until the account is deleted, the owner or a manager can export everything the business holds with the Export everything button in the same Danger zone: one zip file, with a JSON file for each kind of record and the uploaded images, built when it is asked for, kept nowhere on our servers and holding no password, key or link token. The deletion at the end of the thirty days runs every day at 04:30, Athens time: with the schema, it deletes the people who sign in to the account, its payment connection, our staff\'s support sessions in it, our billing provider\'s notifications about it and its uploaded images.',
                'Copies of the data in our backups are deleted [within the backups\' retention period, to be confirmed].',
            ],
        ],
        'audits' => [
            'heading' => 'Showing compliance and audits',
            'body' => [
                'We give the business, when it asks, the information it needs to show that this agreement is kept, and we allow and contribute to audits, including inspections, by the business or an auditor it appoints.',
                'The notice, frequency, cost and confidentiality of an audit are [to be confirmed].',
            ],
        ],
        'law' => [
            'heading' => 'Liability and law',
            'body' => [
                'Liability under this agreement is as the terms of service set it out, and this agreement is governed by the law that governs them.',
                'The business writes to us about this agreement through the contact page of :name.',
            ],
        ],
    ],
];
