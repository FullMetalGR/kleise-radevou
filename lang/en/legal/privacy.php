<?php

/*
 * The privacy policy in English: written from what the product does, for a later legal review. It
 * covers the platform as the controller of its own data: the site's visitors, the contact form's
 * senders, the signup and the people who sign in to a tenant's account; a tenant's customers and
 * staff are the agreement's (dpa.php). The scheduled deletion that applies retention and the
 * deletion of a closed account are named as built. A fact still to be supplied stands in square
 * brackets, "to be confirmed", for the legal review. The legal pages' one template renders it: the
 * title and meta description are the head's, the heading the page's h1, the version the date its
 * version line names, and the sections in this order, each a heading over its body, a string a
 * paragraph and a list of strings a list, the section's key its anchor. Every value may name the
 * public name as :name.
 */

return [
    'title' => 'Privacy policy - :name',
    'description' => 'How :name handles the personal data of the people who visit this site, write to us, sign up a business or sign in to one.',
    'heading' => 'Privacy policy',
    'version' => '3 November 2026',
    'sections' => [
        'scope' => [
            'heading' => 'Who we are and what this policy covers',
            'body' => [
                'This policy explains how the company named at the top of this page, which runs :name, handles personal data as its controller: the data of the people who visit this site, who write to us, who sign up a business, and who sign in to a business\'s account.',
                'It does not cover the data a business keeps about its own customers and staff in :name. The business is the controller of that data and decides what it collects and why; we keep and handle it for the business as its processor, under the data processing agreement. A customer with a question about a booking or about their data asks the business they booked with.',
            ],
        ],
        'visits' => [
            'heading' => 'Visiting this site',
            'body' => [
                'Opening a page of this site sets no cookie. Our web servers log each request with the network address it came from, to keep the service secure and to find faults.',
                'The contact page and the signup\'s pages use a session. Its record keeps the network address and the browser of the visit while it lasts, and the cookie notice lists its cookies.',
                'Google Analytics runs only if you accept it in the cookie banner. It then counts visits and shows us which pages are read: the tag sends Google the pages you open, details of your browser and device, and an identifier kept in its cookies. Its advertising signals stay off. It never runs on a business\'s booking page, in its booking form on another website or in its admin. You can withdraw your consent at any time with the link at the foot of every page that opens the cookie banner again.',
                'The booking demo on the home page runs on a sample business. What you type into it is replaced before anything is saved, and it sends no email.',
            ],
        ],
        'contact' => [
            'heading' => 'Writing to us',
            'body' => [
                'When you send the contact form, we keep your name, your email address, your message and the language of the page you sent it from, and we email the message to our support mailbox so that we can answer you. We do not store the network address it came from with it.',
                'To stop the form being misused, your network address is used for an hour to count how many messages come from one connection, and the form is protected by Cloudflare Turnstile.',
            ],
        ],
        'signup' => [
            'heading' => 'Signing up a business',
            'body' => [
                'To sign up a business, its owner gives the business\'s name, its address on :name, its kind of business, its time zone and currency, and their own name, email address and password. We keep the password only as a one-way hash, from which it cannot be read back.',
                'We email the owner a link to confirm the email address. When the link is opened, the account is set up and its seven-day trial starts. A signup whose link is not opened within 24 hours is deleted with the owner\'s details, and its address on :name becomes free again.',
                'The signup form is protected by Cloudflare Turnstile, and the number of signups from one network address is limited.',
            ],
        ],
        'accounts' => [
            'heading' => 'Accounts and the people who sign in',
            'body' => [
                'For each business\'s account we keep its details and, for each person who signs in to it, their name, email address, role and password hash, and whether their email address has been confirmed. Signing in uses a session on the business\'s own address, whose record keeps the network address and the browser while it lasts.',
                'We use these details to run the account, to let people sign in and to send the emails the account needs: the link to confirm the email address, the welcome email, the reminders before the trial ends, the notice that an account is closing, the notices that an account was suspended and that the suspension has ended, and a link to set a password.',
                'When the owner chooses a plan, our billing provider, Stripe, keeps a customer record under the owner\'s email address and takes the card on its own page. We keep the references Stripe gives us for the customer and the subscription, the plan and the subscription\'s state, and never the card, and we keep Stripe\'s notifications to us about the subscription for the time the retention section gives.',
                'Our staff run :name from a console of our own. It shows each account\'s details and the people who sign in to it, and every action taken there is written to an audit log that refers to accounts and people by reference, never by name or email address. Where we suspend an account, the reason we give is kept on the account while the suspension lasts, and with its record in the audit log.',
            ],
        ],
        'emails' => [
            'heading' => 'The emails we send',
            'body' => [
                'We send every email from our own address, through our mail provider, Qboxmail. An email about a business\'s account goes to the person it is for. Emails about a booking are sent for the business it is with, and replies to them go to the business\'s owner; the data processing agreement covers them.',
                'Our emails carry no tracking pixel and load no remote image.',
            ],
        ],
        'bases' => [
            'heading' => 'Why we use it, and on which basis',
            'body' => [
                [
                    'To set up and run a business\'s account, let its people sign in, send the emails the account needs and bill its subscription: the contract with the business, and at the signup the steps taken at its owner\'s request before it.',
                    'To answer a message sent through the contact form: our legitimate interest in answering the people who write to us, and theirs in an answer.',
                    'To protect the forms and the service, with the anti-spam check, the limits on how often a form can be sent, the sessions\' safeguards and the servers\' logs: our legitimate interest in keeping :name secure and working.',
                    'To count visits with Google Analytics: your consent, which you can withdraw at any time.',
                    'To keep the records of our accounts: the obligations the law places on us.',
                ],
            ],
        ],
        'processors' => [
            'heading' => 'Who handles data for us',
            'body' => [
                'These providers handle personal data for us:',
                [
                    'Linode, our hosting provider, and RunCloud, through which we run our servers on it. The data this policy describes is stored on those servers, in the European Union.',
                    'Qboxmail, which delivers our emails.',
                    'Stripe, which takes the payments for subscriptions and keeps their billing records.',
                    'Cloudflare, whose Turnstile check protects the signup form and the contact form. It receives the network address you connect from and details of your browser, and nothing you type into the form.',
                    'Google, whose Analytics runs on this site only with your consent, and whose Maps service makes the map on a business\'s booking page from the business\'s address, which is all it receives.',
                ],
                'A business on :name may also connect a payment provider for its own customers\' payments; the data processing agreement lists every provider that handles a business\'s customers\' data.',
                'Where one of these providers handles data outside the European Economic Area, the transfer rests on [the safeguard for each provider, to be confirmed].',
                'We do not sell personal data, and we do not use it for advertising.',
            ],
        ],
        'retention' => [
            'heading' => 'How long we keep it',
            'body' => [
                [
                    'A message sent through the contact form: twelve months from when it was sent, whether or not it has been dealt with.',
                    'A signup whose link is not opened: 24 hours.',
                    'A business\'s account and the people who sign in to it: while the account exists. When an account closes, everything in it is deleted thirty days later, except the account\'s own record, kept for our accounts, which holds none of the business\'s customers\' data.',
                    'A session\'s record: while the session lasts, until it is cleared after it lapses.',
                    'Stripe\'s notifications to us about subscriptions: 90 days.',
                    'The audit log of our console: 24 months. The reason for a suspension: on the account while the suspension lasts, and with its record in the audit log for those 24 months.',
                    'The servers\' logs: [the period, to be confirmed].',
                    'Google Analytics data: [the retention period set in Google Analytics, to be confirmed].',
                ],
                'The deletion of a signup whose link was never opened runs every hour. A scheduled deletion runs every day at 04:30, Athens time: it deletes the contact form\'s messages, Stripe\'s notifications and the entries of our console\'s audit log once their periods above have passed, and a closed account, with the people who sign in to it, at the end of its thirty days.',
            ],
        ],
        'rights' => [
            'heading' => 'Your rights',
            'body' => [
                'You may ask us for a copy of the personal data we hold about you, have it corrected, have it erased, have its use restricted, object to its use, and receive it in a form a machine can read. Where we rely on your consent, you may withdraw it at any time; this does not affect what was done before.',
                'To use any of these rights, write to us through the contact page of :name. We answer within one month, as the law requires, and may ask you to confirm who you are.',
                'If you are a customer of a business on :name, ask that business: it decides about your data, and we help it answer.',
                'You also have the right to complain to a data protection supervisory authority; in Greece, this is the Hellenic Data Protection Authority.',
            ],
        ],
        'security' => [
            'heading' => 'How we keep it safe',
            'body' => [
                'Passwords, and the links and tokens we email, are kept only as one-way hashes. A business\'s payment credentials are encrypted. Each business\'s data is kept apart from every other business\'s, in a database schema of its own. When our application logs a problem with an email, it names no address, and it never logs what a contact message says.',
            ],
        ],
        'changes' => [
            'heading' => 'Changes to this policy',
            'body' => [
                'We publish each new version of this policy on this page, with its date.',
            ],
        ],
    ],
];
