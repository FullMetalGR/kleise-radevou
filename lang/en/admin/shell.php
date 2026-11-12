<?php

/*
 * The admin's shell in English: the words every admin page carries, shared to it as the strings
 * prop's shell section in the language of the request, the user's, and read on the server through
 * the translator in the same. Its Greek twin is lang/el/admin/shell.php.
 *
 * nav is the rail: its two groups, its eleven entries as App\Http\AdminNav names them, the rail's
 * own name and the control that opens it below the breakpoint. login is the sign-in page and its
 * form, which the console's door renders too, in English. set_password is the set-password link's
 * page outside the shell: its title, also its heading, the field, the help with :floor, the button
 * and its busy words, read as the sign-in page's are: with nobody signed in, in the tenant default.
 * top_bar is the bar's controls. banners holds the support session's and the narrowing record's:
 * the support line with :operator the operator's name, :end the session's end on the tenant's clock
 * and :zone that clock, and each feature's name and its gone sentences, :billing the Billing
 * screen's name, which the banner sets as a link or as plain text. boundary is the error boundary's
 * two sentences, not_found the 404 page inside the shell, its title, also its heading, and its
 * sentence, toaster the toast's dismiss and the two sentences a refusal reads where the server
 * worded nothing, and copy the copy controls.
 *
 * kit is the shared control set's words: the status chip's nine labels of a booking's status (a
 * catalog row's active and inactive take the word its own screen holds), counted, the compact
 * chip's line per status with :count, the figure formatted by formatCount in the reader's language
 * (1,234 or 1.234) and read as a choice by it, one form in English, the date field's, the month
 * grid's (the day calendar's two turning controls, and the names of its month and year dropdowns
 * where a field reaches years back), the combobox's, the confirm's, the pagination's (:page and
 * :last its numbers) and the notice's. shared holds what two later screens read: the weekly
 * editor's words, the image slot's busy labels, a booking's sources and channels, support named
 * for a trail entry, and the help under a closure's reason, which the Calendar's closure dialog
 * and the Staff screen's closures card both show.
 *
 * validation, refusals and flashes are the server's: the sign-in form's four lines, which the
 * console's door reads in English, the set-password form's two refusals with :floor and :ceiling,
 * PasswordRule's two figures by the names signup.php gives them, the four image uploads' sentences,
 * each size read as a choice by its megabytes with :n the figure spelled in words, and the weekly
 * windows', shared by a staff member's hours and the opening hours; the sign-in's refusals and the
 * support link's; and the sign-out's flash and the set-password link's two, the password set and
 * the link expired, which the sign-in page shows, worded as the page is, in the tenant default.
 */

return [
    'nav' => [
        'groups' => [
            'schedule' => 'Schedule',
            'business' => 'Business',
        ],
        'home' => 'Home',
        'calendar' => 'Calendar',
        'bookings' => 'Bookings',
        'shifts' => 'Shifts',
        'services' => 'Services',
        'staff' => 'Staff',
        'closures' => 'Closures',
        'customers' => 'Customers',
        'reports' => 'Reports',
        'team' => 'Team',
        'settings' => 'Settings',
        'billing' => 'Billing',
        'main' => 'Main',
        'menu' => 'Menu',
    ],
    'login' => [
        'title' => 'Sign in',
        'heading' => 'Sign in',
        'email' => 'Email',
        'password' => 'Password',
        'submit' => 'Sign in',
        'submitting' => 'Signing in...',
    ],
    'set_password' => [
        'title' => 'Set your password',
        'password' => 'New password',
        'help' => 'At least :floor characters.',
        'submit' => 'Set password',
        'submitting' => 'Setting password...',
    ],
    'top_bar' => [
        'new_booking' => 'New booking',
        'light' => 'Light theme',
        'dark' => 'Dark theme',
        'sign_out' => 'Sign out',
        'signing_out' => 'Signing out...',
    ],
    'banners' => [
        'support' => [
            'line' => 'You are signed in as Support (:operator) until :end (:zone).',
            'end' => 'End session',
            'ending' => 'Ending session',
        ],
        'billing' => 'Billing',
        'features' => [
            'staff' => [
                'name' => 'Staff',
                'gone' => 'The staff feature is no longer on your plan: every staff member keeps their hours and bookings keep using them, and it comes back when you add it on the :billing screen.',
                'gone_free' => 'The staff feature is no longer on your plan: every staff member keeps their hours and bookings keep using them, and it comes back when platform support adds it to this free account.',
            ],
        ],
    ],
    'boundary' => [
        'notice' => 'This screen could not be displayed.',
        'rest' => 'The rest of the dashboard still works: pick another screen from the menu.',
    ],
    'not_found' => [
        'title' => 'Page not found',
        'sentence' => 'There is nothing at this address. Pick a screen from the menu.',
    ],
    'toaster' => [
        'dismiss' => 'Dismiss',
        'fallback' => 'Something went wrong. Please try again.',
        'not_done' => 'That could not be done. Please try again.',
    ],
    'copy' => [
        'copied' => 'Copied to the clipboard.',
        'failed' => 'Copying failed. Select the link and copy it yourself:',
        'booking_link' => 'Copy booking link',
        'link' => 'Copy link',
    ],
    'kit' => [
        'statuses' => [
            'confirmed' => 'Confirmed',
            'awaiting_approval' => 'Awaiting approval',
            'pending' => 'Pending',
            'awaiting_payment' => 'Awaiting payment',
            'cancelled' => 'Cancelled',
            'rejected' => 'Rejected',
            'no_show' => 'No-show',
            'completed' => 'Completed',
            'expired' => 'Expired',
        ],
        'counted' => [
            'confirmed' => ':count Confirmed',
            'awaiting_approval' => ':count Awaiting approval',
            'pending' => ':count Pending',
            'awaiting_payment' => ':count Awaiting payment',
            'cancelled' => ':count Cancelled',
            'rejected' => ':count Rejected',
            'no_show' => ':count No-show',
            'completed' => ':count Completed',
            'expired' => ':count Expired',
        ],
        'date_field' => [
            'choose' => 'Choose a date',
            'clear' => 'Clear',
        ],
        'month_grid' => [
            'previous' => 'Previous month',
            'next' => 'Next month',
            'month' => 'Month',
            'year' => 'Year',
        ],
        'combobox' => [
            'clear' => 'Clear',
            'no_matches' => 'No matches',
        ],
        'confirm' => [
            'keep' => 'Keep it',
        ],
        'pagination' => [
            'previous' => 'Previous',
            'next' => 'Next',
            'page' => 'Page :page of :last',
        ],
        'notice' => [
            'dismiss' => 'Dismiss',
        ],
    ],
    'shared' => [
        'weekly' => [
            'closed' => 'Closed',
            'start' => 'Start',
            'end' => 'End',
            'remove' => 'Remove',
            'add' => 'Add window',
        ],
        'image' => [
            'uploading' => 'Uploading',
            'removing' => 'Removing',
        ],
        'sources' => [
            'hosted' => 'Booking page',
            'embed' => 'Embed on your website',
            'admin' => 'Added in the admin',
        ],
        'channels' => [
            'instagram' => 'Instagram',
            'facebook' => 'Facebook',
            'google' => 'Google',
            'qr' => 'QR code',
            'other' => 'Other',
        ],
        'support' => [
            'actor' => 'Support',
            'named' => 'Support (:operator)',
        ],
        'closure' => [
            'reason_help' => 'A note for you. Customers never see it.',
        ],
    ],
    'validation' => [
        'login' => [
            'email_required' => 'Enter the email address you sign in with.',
            'email_string' => 'Enter the email address as text.',
            'password_required' => 'Enter your password.',
            'password_string' => 'Enter the password as text.',
        ],
        'set_password' => [
            'too_short' => 'Choose a password of at least :floor characters.',
            'too_long' => 'Choose a shorter password: at most :ceiling characters, fewer if it uses letters outside the Latin alphabet.',
        ],
        'upload' => [
            'missing' => 'Choose an image file to upload.',
            'logo_type' => 'The logo is a PNG, JPEG or WebP.',
            'logo_size' => '{1} The logo is at most :n megabyte.|[2,*] The logo is at most :n megabytes.',
            'cover_type' => 'The cover is a PNG, JPEG or WebP.',
            'cover_size' => '{1} The cover is at most :n megabyte.|[2,*] The cover is at most :n megabytes.',
            'gallery_type' => 'A gallery photo is a PNG, JPEG or WebP.',
            'gallery_size' => '{1} A gallery photo is at most :n megabyte.|[2,*] A gallery photo is at most :n megabytes.',
            'staff_photo_type' => 'A staff photo is a PNG, JPEG or WebP.',
            'staff_photo_size' => '{1} A staff photo is at most :n megabyte.|[2,*] A staff photo is at most :n megabytes.',
        ],
        'weekly' => [
            'list' => 'The weekly hours are a list of windows.',
            'weekday' => 'Each window names a weekday from 1, Monday, to 7, Sunday.',
            'times' => 'Each window has a start and an end, times of day such as 09:00.',
            'order' => 'A window starts before it ends.',
        ],
    ],
    'refusals' => [
        'sign_in' => 'That email and password do not match an account at this business.',
        'too_many' => 'Too many sign-in attempts from this address. Please wait a minute and try again.',
        'support_link' => 'This support link is not valid.',
    ],
    'flashes' => [
        'signed_out' => 'You have signed out.',
        'password_set' => 'Your password is set. Sign in.',
        'link_expired' => 'This link has expired. Ask for a new one.',
    ],
];
