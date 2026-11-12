<?php

/*
 * The signup's sentences in English: every sentence its two pages show and every refusal its server
 * answers. The pages hold no sentence of their own, since the admin build refuses Greek: the
 * central host's adapter hands them this whole file as the strings prop in the request's language,
 * and a sentence with a value in it keeps the translator's :name placeholder, which the page fills.
 *
 * The form's keys are its title, its heading, each field's label, the two selects' placeholders,
 * the slug's example and the currency's help, the anti-spam group's name, and the button with its
 * busy label, and the terms' box, one sentence whose two link texts sit between named tags, <terms>
 * and <privacy>, which the form turns into links to the two legal pages, each text the page's own
 * name. The pending page's are its two states, each a title, a heading and a sentence, the two
 * words a stranger reads in place of the email address and the business, and the Resend control
 * with its busy label and no sentence of its own: the resend's answer is resent, the one sentence
 * the POST flashes whatever it found. The waiting sentence is a choice by the verification link's
 * hours, SignupVerification::LINK_LIFETIME, which the server makes before the page fills :email,
 * :business and :address. too_many is the limit's refusal, counted per network address, which it
 * calls the connection, and expired the link's, answered for a link expired, replaced, swept or
 * malformed alike; each is a notice above the button.
 *
 * The errors are every refusal SignupRequest can answer, one sentence on its field, written here so
 * no framework validation file is published: the slug's by SlugProblem's case, the password's floor
 * and ceiling, the currency's, the terms' box's, and the anti-spam check's. The slug is called the
 * booking page's web address here, as the form's label calls it; the console's free tenant form
 * keeps SlugProblem's own English, which calls it the subdomain name, and the admin's Settings and
 * set-password forms keep their own English in code. :max, :floor and :ceiling are the code's
 * constants, Slug::MAX_LENGTH and PasswordRule's, never spelled here. The seven kinds of business
 * are the select's options, by BusinessCategory's value; the console keeps BusinessCategory's own
 * labels.
 */

return [
    'form' => [
        'title' => 'Sign up',
        'heading' => 'Start your booking page',
        'slug' => 'Your booking page address',
        'slug_example' => 'your-business',
        'name' => 'Business name',
        'category' => 'Kind of business',
        'category_placeholder' => 'Choose a kind of business',
        'timezone' => 'Time zone',
        'timezone_placeholder' => 'Choose a time zone',
        'currency' => 'Currency',
        'currency_help' => 'Three letters, such as EUR.',
        'owner_name' => 'Your name',
        'owner_email' => 'Your email address',
        'password' => 'Password',
        'terms' => 'I accept the <terms>terms of service</terms> and the <privacy>privacy policy</privacy>.',
        'check' => 'Anti-spam check',
        'submit' => 'Sign up',
        'submitting' => 'Signing up...',
    ],
    'pending' => [
        'waiting_title' => 'Check your email',
        'waiting_heading' => 'Check your email',
        'waiting' => '{1} We sent a link to :email. Open it within an hour to set up :business at :address.|[2,*] We sent a link to :email. Open it within :count hours to set up :business at :address.',
        'your_address' => 'the email address you gave',
        'your_business' => 'your business',
        'verified_title' => 'Setting up',
        'verified_heading' => 'Setting up :business',
        'verified' => 'Your email is confirmed. This page opens your sign-in page at :address as soon as it is ready.',
        'resend' => 'Send the link again',
        'resending' => 'Sending...',
    ],
    'categories' => [
        'hair_salon' => 'Hair salon',
        'barber' => 'Barbershop',
        'nails' => 'Nail studio',
        'beauty' => 'Beauty salon',
        'massage' => 'Massage',
        'physiotherapy' => 'Physiotherapy',
        'other' => 'Other',
    ],
    'too_many' => 'Too many signup attempts from this connection. Please wait a minute and try again.',
    'expired' => 'This link is no longer valid. Please sign up again.',
    'resent' => 'If that signup is still waiting, a new link is on its way.',
    'errors' => [
        'slug' => [
            'empty' => 'Choose a web address for your booking page.',
            'invalid_character' => 'Use only the letters a to z, digits and hyphens.',
            'leading_hyphen' => 'The address cannot start with a hyphen.',
            'trailing_hyphen' => 'The address cannot end with a hyphen.',
            'punycode_form' => 'The address cannot have hyphens as its third and fourth characters.',
            'too_long' => 'The address can be at most :max characters long.',
            'reserved' => 'That address is reserved. Please choose another.',
            'taken' => 'That address is already taken. Please choose another.',
            'held_by_signup' => 'That address is held by a signup in progress. If that signup is not confirmed, the address becomes free again after a day.',
        ],
        'name' => 'Enter the name of your business.',
        'category' => 'Choose the kind of business from the list.',
        'timezone' => 'Choose your business\'s time zone from the list.',
        'currency' => 'The currency is three letters, such as EUR.',
        'owner_name' => 'Enter your name.',
        'owner_email' => 'Enter an email address, such as you@example.com.',
        'password_short' => 'Choose a password of at least :floor characters.',
        'password_long' => 'Choose a shorter password: at most :ceiling characters, fewer if it uses letters outside the Latin alphabet.',
        'terms' => 'Accept the terms of service and the privacy policy to sign up.',
        'check' => 'The anti-spam check did not pass. Please try again.',
    ],
];
