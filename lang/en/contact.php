<?php

/*
 * The contact page in English. Its title and meta description are the head's and its heading the
 * page's h1; the lead sits under it. The labels name the form's three fields and the trap's,
 * which no person sees; the button sends the form. The thank-you is the one sentence the flash
 * carries after a message is sent, and the refusal past the limit sits above the form at 429; the
 * limit counts the messages from one network address, which the refusal calls the connection.
 * Each error is the refusal named under its field, the anti-spam check's under the widget. The
 * title, the description, the heading and the lead are read with the public name filled in as
 * :name.
 */

return [
    'title' => 'Contact - :name',
    'description' => 'Write to :name with a question about the product, the price or getting started. We answer by email, in Greek or in English.',
    'heading' => 'Contact us',
    'lead' => 'Ask about the product, the price or how to start. We answer by email, in Greek or in English.',
    'fields' => [
        'name' => 'Your name',
        'email' => 'Your email address',
        'message' => 'Your message',
    ],
    // The label of the field a person never fills, hidden from sight and from assistive
    // technology; a form that arrives with it filled was posted by a machine.
    'trap' => 'Leave this field empty',
    'send' => 'Send the message',
    'sent' => 'Thank you. Your message has been sent, and we will answer by email.',
    'too_many' => 'Too many messages from this connection. Please wait an hour and try again.',
    'errors' => [
        'name_required' => 'Enter your name.',
        'name_long' => 'Your name can be at most 200 characters long.',
        'email_required' => 'Enter your email address.',
        'email_invalid' => 'Enter an email address, such as you@example.com.',
        'email_long' => 'Your email address can be at most 254 characters long.',
        'message_required' => 'Write your message.',
        'message_long' => 'Your message can be at most 5000 characters long.',
        'check' => 'The anti-spam check did not pass. Please try again.',
    ],
];
