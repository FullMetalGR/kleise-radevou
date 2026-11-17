<?php

/*
 * The setup wizard on Home and its banner: the wizard's title, the step list and the line that
 * stands for it on a phone, the footer's buttons, the question asked before unsaved edits are
 * left, and the toast once setup is complete; the banner's count and Continue. Each step has a
 * group of its own under steps, its name first and then the step's own words, so a step's words
 * never sit in another's lines. A step borrowing another screen's words reads that screen's
 * section, never a copy of it here. Sent only to an owner the wizard is offered to.
 */

return [
    'title' => 'Set up your business',
    'list' => 'Setup steps',
    'position' => 'Step :number of :total',
    'done' => 'Done',
    'skipped' => 'Skipped',
    'close' => 'Close',
    'back' => 'Back',
    'next' => 'Next',
    'skip' => 'Skip for now',
    'complete' => 'Setup is complete. Your business is ready to take bookings.',
    'discard' => [
        'title' => 'Discard your changes?',
        'body' => 'The changes on this step are not saved. If you leave it, they are lost.',
        'confirm' => 'Discard changes',
        'keep' => 'Keep editing',
    ],
    'banner' => [
        'progress' => 'Finish setting up: :done of :total done',
        'continue' => 'Continue',
    ],
    'steps' => [
        'profile' => [
            'name' => 'Business profile',
            'help' => 'Tell your customers about your business and where to find it. All of this shows on your booking page, and you can change it later in Settings.',
            'save' => 'Save and continue',
            'saving' => 'Saving...',
            'wait' => 'Please wait until the business profile has been saved.',
            'error' => 'The business profile could not be saved. Check your connection and try again.',
        ],
        'services' => [
            'name' => 'Services',
            'help' => 'Add the services your customers can book, each with how long it takes and its price.',
            'empty' => 'No services yet.',
            'add' => 'Add service',
            'adding' => 'Adding...',
            'wait' => 'Please wait until the service has been added.',
            'error' => 'The service could not be added. Check your connection and try again.',
            'more' => 'To change or remove a service, or set its other details, use the Services screen.',
            'open' => 'Open Services',
            'blocked' => 'Add a service to continue.',
        ],
        'team' => [
            'name' => 'Team',
            'help' => 'Add the team members your customers can book, and pick the services each one performs. If you work alone, you can skip this step.',
            'empty' => 'No team members yet.',
            'no_services' => 'No services',
            'add' => 'Add team member',
            'adding' => 'Adding...',
            'wait' => 'Please wait until the team member has been added.',
            'error' => 'The team member could not be added. Check your connection and try again.',
            'more' => 'To change or remove a team member, or set their hours, use the Staff screen.',
            'open' => 'Open Staff',
        ],
        'hours' => [
            'name' => 'Opening hours',
            'help' => 'Set the days and hours your business is open. Customers can book online only inside these hours.',
            'members' => 'Saving gives this week to every team member: :names. You can change a member\'s own hours later on the Shifts screen.',
            'differs' => 'Your team members have different hours now. Saving replaces each member\'s hours with this week.',
            'roster' => 'Each team member keeps the hours they already have, shown read-only in Settings, so there is nothing to set here.',
            'empty' => 'Add hours to at least one day.',
            'save' => 'Save and continue',
            'saving' => 'Saving...',
        ],
        'payments' => [
            'name' => 'Online payments',
            'help' => 'Connect a payment provider so customers can pay online when they book. You can skip this step and connect one later in Settings.',
        ],
        'link' => [
            'name' => 'Booking link',
            'help' => 'This is your booking page. Share its address with your customers, or add it to your website and social media, so they can book online.',
            'open' => 'Open booking page',
            'finish' => 'Finish',
            'finishing' => 'Finishing...',
        ],
    ],
];
