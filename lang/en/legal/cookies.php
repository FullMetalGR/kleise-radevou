<?php

/*
 * The cookie notice in English: written from what the product does, for a later legal review. It
 * lists the cookies the platform sets on the central host, each with what it is for, how long it
 * lasts and whether it needs consent, and how the choice is changed. Each cookie is named as the
 * code sets it, which LegalPagesTest holds, so a rename there moves this file; the session cookie
 * alone is described and not named, its name being the deployment's configuration. The legal
 * pages' one template renders it: the title and meta description are the head's, the heading the
 * page's h1, the version the date its version line names, and the sections in this order, each a
 * heading over its body, a string a paragraph and a list of strings a list, the section's key its
 * anchor. Every value may name the public name as :name.
 */

return [
    'title' => 'Cookie notice - :name',
    'description' => 'The cookies :name sets on this site: what each one is for, how long it lasts, whether it needs your consent, and how to change your choice.',
    'heading' => 'Cookie notice',
    'version' => '26 October 2026',
    'sections' => [
        'about' => [
            'heading' => 'What this notice covers',
            'body' => [
                'This notice lists the cookies set on this site: what each cookie is for, how long it lasts and whether it needs your consent. A cookie is a small piece of text a website keeps in your browser.',
                'Opening a page of this site sets no cookie, except on the contact page and the signup\'s pages, which need the cookies below to work. The others are set only when you answer the cookie banner, and by Google Analytics only after you accept it.',
                'Every cookie listed here belongs to this site\'s own address, and none of them is sent to the address of a business on :name.',
            ],
        ],
        'necessary' => [
            'heading' => 'Cookies the site needs',
            'body' => [
                'These cookies are strictly necessary for the site to work, so they need no consent. None of them is used to follow you or to count visits.',
                [
                    'The session cookie, on the contact page and the signup\'s pages: it holds your visit together from one page to the next, so that a form can be sent and its answer shown to you. It lasts until two hours after the last of those pages you opened. While it lasts, its record on our server keeps the network address and the browser of the visit.',
                    'XSRF-TOKEN, on the same pages: it carries a token that protects the forms against requests forged by another website. It lasts as long as the session cookie.',
                    'signup_holder, set when you send the signup form: it lets the page that waits for your confirmation name your business and your email address to your browser alone. It lasts 24 hours.',
                    'analytics_consent, set when you answer the cookie banner: it keeps your answer, granted or denied, so that you are not asked again on every page. It lasts 180 days, after which the banner asks again.',
                ],
            ],
        ],
        'analytics' => [
            'heading' => 'Analytics cookies, only with your consent',
            'body' => [
                'If you accept them in the cookie banner, :name uses Google Analytics to count visits and see which pages are read. Nothing of Google Analytics loads before you accept, and its advertising signals stay off whatever you choose.',
                'Once you accept, Google Analytics sets two cookies, for this site\'s address alone:',
                [
                    '_ga, which tells one browser from another, so that a visit is counted once.',
                    '_ga_ followed by the code of this site\'s Analytics property, which keeps the state of your current visit.',
                ],
                'Google sets each of them to last up to two years from your last visit. If you reject, or later withdraw your consent, both are deleted from this site and Google Analytics stops.',
                'Google Analytics never runs on a business\'s booking page, in its booking form on another website or in its admin. While this site uses no analytics, it shows no banner and sets none of these cookies.',
            ],
        ],
        'other' => [
            'heading' => 'Other services on the site',
            'body' => [
                'The anti-spam check on the signup form and the contact form is Cloudflare Turnstile. It is not analytics, sets no tracking cookie and needs no consent. The privacy policy says what it receives.',
                'The booking demo on the home page is served by a sample business on :name itself. It needs no consent, and nothing you type into it is kept.',
            ],
        ],
        'choice' => [
            'heading' => 'Changing your choice',
            'body' => [
                'While this site uses Google Analytics, a link at the foot of every page opens the cookie banner again, where you can accept or reject. Rejecting deletes the analytics cookies from this site.',
                'You can also delete this site\'s cookies in your browser\'s settings. The banner then asks again on your next visit.',
                'With JavaScript turned off, nothing of Google Analytics loads, and that link opens this notice instead.',
            ],
        ],
        'businesses' => [
            'heading' => 'On a business\'s own address',
            'body' => [
                'This notice covers this site. Each business on :name has its booking page and its admin at an address of its own under :name, and no analytics or advertising cookie is set there.',
            ],
        ],
    ],
];
