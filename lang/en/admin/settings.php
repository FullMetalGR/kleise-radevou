<?php

/*
 * The Settings screen in English: the page, its model and the server's sentences about the save,
 * shared as the strings prop's settings section, which SettingsController names, in the language of
 * the request, the user's. The business's profile, About and Important info, is the tenant's own
 * text and never passes through here. Its Greek twin is lang/el/admin/settings.php.
 *
 * The Payments section's words are admin/payments.php's, the Danger zone's admin/billing.php's and
 * the Emails list's admin/emails.php's; the Emails heading alone is here.
 *
 * page is the screen's title and heading. sections are the headings the page renders, in its
 * order.
 *
 * general is the currency's label and help, and the default language's. booking_page is the theme's
 * label and help and its seven names (themes, keyed by the word settings.theme holds, Theme's
 * cases), the logo's, the cover's and the gallery's labels and helps (:n the largest file's
 * megabytes in words, from LogoRequest's, CoverRequest's and GalleryPhotoRequest's MAX_KILOBYTES,
 * and :capacity the gallery's photos in words, from Settings::GALLERY_CAPACITY, each spelled by ICU
 * on the server), the logo's and the cover's four words each (the empty slot, upload, replace,
 * remove), and the embed snippet's label, help and copy button.
 *
 * booking_rules is the four holds' labels and helps, the start interval's label and help, and
 * one interval as its select offers it (:n its minutes).
 *
 * waits are the sentences that hold the other controls while a write is in flight; errors are the
 * writes that did not reach the server, and the snippet's copy that failed. save is the save bar's
 * button and its busy word.
 *
 * validation is SettingsRequest's, which the screen's model states before a save in the same words,
 * read from this section on the client and through the translator on the server: :max a profile
 * field's longest, from SettingsRequest's named limits, :n the tenant's granularity in minutes, and
 * :intervals the six intervals dividing the hour, SettingsRequest::START_INTERVALS, listed by
 * list.separator and list.last (:items the intervals before the last, :last the last), the same
 * words on both sides; theme's :count is how many themes there are, Theme's cases spelled in words
 * by ICU, and :themes their names from booking_page.themes listed by the same list words. flashes
 * are the three writes' answers: the save, the opening hours and the Calendar's colours.
 *
 * profile is the business page's fields on the Booking page section, their labels and helps, and
 * one country as the select offers it (:country its name from countries.php in the user's language,
 * :code its two capitals); the tenant's own text in them is never translated. links is the Links
 * card's heading, help, row titles and QR link. hours is Opening hours: the intro, the save and its
 * busy word, the sentence over the read-only roster, and the roster's day lines whole, a day with
 * windows (:day the ICU weekday, :windows its windows) and a closed day (:day), each window
 * :start-:end in the tenant's time format, the windows joined by hours.separator. gallery is the
 * count against the capacity (:count the photos held, :capacity the capacity as a figure), a
 * photo's alternative text (:n its place, :count the photos held), and the buttons with their busy
 * words. map is the map line's three: the wait, the failure and the image's alternative text.
 *
 * images is the server's answers to the image writes: the logo's, the cover's and the gallery's
 * flashes, and the gallery's two refusals, full (:capacity the capacity in words, from
 * Settings::GALLERY_CAPACITY, spelled by ICU on the server and handed the client as
 * limits.capacity.spelled, the client stating it under a full gallery's chooser) and a photo held
 * twice.
 *
 * Three keys are read as choices, by the translator's choice on the server and the admin's mirror
 * of it, choose(), on the client, and their Greek keeps the choice syntax: booking_page.logo_help,
 * booking_page.cover_help and booking_page.gallery_help, each by the megabytes. Every other key is
 * read as plain text.
 */

return [
    'page' => [
        'title' => 'Settings',
    ],
    'sections' => [
        'general' => 'General',
        'booking_page' => 'Booking page',
        'booking_rules' => 'Booking rules',
        'opening_hours' => 'Opening hours',
        'payments' => 'Payments',
        'emails' => 'Emails',
    ],
    'general' => [
        'currency' => 'Currency',
        'currency_help' => 'Three letters, such as EUR. New services are priced in it; existing services and bookings keep theirs.',
        'language' => 'Default language',
        'language_help' => 'The booking page, the embed where a visitor names no language, and the emails follow it. A booking keeps the language it was made in.',
    ],
    'booking_page' => [
        'theme' => 'Theme',
        'theme_help' => 'Colours the admin, the booking page and the embed together.',
        'themes' => [
            'neutral' => 'Neutral',
            'blue' => 'Blue',
            'green' => 'Green',
            'orange' => 'Orange',
            'red' => 'Red',
            'rose' => 'Rose',
            'violet' => 'Violet',
        ],
        'logo' => 'Logo',
        'logo_help' => '{1} A PNG, JPEG or WebP up to :n megabyte. Shown in the hosted page header and never by the embed, which lives on your own site.|[2,*] A PNG, JPEG or WebP up to :n megabytes. Shown in the hosted page header and never by the embed, which lives on your own site.',
        'logo_none' => 'No logo yet. The hosted booking page shows the business name alone.',
        'logo_upload' => 'Upload logo',
        'logo_replace' => 'Replace logo',
        'logo_remove' => 'Remove logo',
        'snippet' => 'Embed snippet',
        'snippet_help' => 'Paste this into your own site. It is generated from your address and the saved theme; the mount element carries the theme as an attribute.',
        'copy' => 'Copy snippet',
        'cover' => 'Cover photo',
        'cover_help' => '{1} A wide photo, PNG, JPEG or WebP, up to :n megabyte. Shown across the top of the hosted booking page and never by the embed.|[2,*] A wide photo, PNG, JPEG or WebP, up to :n megabytes. Shown across the top of the hosted booking page and never by the embed.',
        'cover_none' => 'No cover yet. The hosted booking page starts with its header.',
        'cover_upload' => 'Upload cover',
        'cover_replace' => 'Replace cover',
        'cover_remove' => 'Remove cover',
        'gallery' => 'Gallery',
        'gallery_help' => '{1} Up to :capacity photos, each a PNG, JPEG or WebP up to :n megabyte. Shown on the hosted booking page after the opening hours, in the order added.|[2,*] Up to :capacity photos, each a PNG, JPEG or WebP up to :n megabytes. Shown on the hosted booking page after the opening hours, in the order added.',
    ],
    'booking_rules' => [
        'checkout' => 'Checkout hold (minutes)',
        'checkout_help' => 'How long a customer has to finish checking out before the time is released.',
        'approval' => 'Approval hold (hours)',
        'approval_help' => 'How long a request waits for a decision before the service\'s timeout rule applies.',
        'payment' => 'Payment hold (hours)',
        'payment_help' => 'How long an emailed payment link stays open.',
        'reminder' => 'Reminder lead time (hours)',
        'reminder_help' => 'How long before an appointment the reminder goes. Zero sends no reminder.',
        'start_interval' => 'Start times every',
        'start_interval_help' => 'How often a new appointment can start, as customers see it. You can still book any time from the admin.',
        'start_option' => ':n minutes',
    ],
    'waits' => [
        'settings' => 'Please wait until the settings have been saved.',
        'hours' => 'Please wait until the opening hours have been saved.',
        'logo_upload' => 'Please wait until the logo has been uploaded.',
        'logo_remove' => 'Please wait until the logo has been removed.',
        'cover_upload' => 'Please wait until the cover has been uploaded.',
        'cover_remove' => 'Please wait until the cover has been removed.',
        'photo_add' => 'Please wait until the photo has been added.',
        'photo_remove' => 'Please wait until the photo has been removed.',
    ],
    'errors' => [
        'save' => 'The settings could not be saved. Check your connection and try again.',
        'hours' => 'The opening hours could not be saved. Check your connection and try again.',
        'logo' => 'The logo could not be changed. Check your connection and try again.',
        'cover' => 'The cover could not be changed. Check your connection and try again.',
        'gallery' => 'The gallery could not be changed. Check your connection and try again.',
        'copy_failed' => 'Copying failed. Select the snippet and copy it yourself.',
    ],
    'save' => [
        'save' => 'Save',
        'saving' => 'Saving',
    ],
    'validation' => [
        'currency' => 'The currency is three letters, such as EUR.',
        'language' => 'Choose a language from the list.',
        'theme' => 'The theme is one of the :count: :themes.',
        'checkout' => 'The checkout hold is a whole number of minutes, one or more.',
        'approval' => 'The approval hold is a whole number of hours, one or more.',
        'payment' => 'The payment hold is a whole number of hours, one or more.',
        'reminder' => 'The reminder lead is zero, for no reminder, or a whole number of hours above it.',
        'start_interval' => 'Start times are every :intervals minutes, a multiple of the :n-minute granularity.',
        'list' => [
            'separator' => ', ',
            'last' => ':items or :last',
        ],
        'emails' => 'The switched-off messages are a list of message keys.',
        'email_key' => 'Each switched-off message is named by its key.',
        'about' => 'The about text is :max characters or fewer.',
        'address_line' => 'Each address line is :max characters or fewer.',
        'city' => 'The city is :max characters or fewer.',
        'postal_code' => 'The postal code is :max characters or fewer.',
        'country' => 'The country is two capital letters, such as GR.',
        'phone' => 'The phone number is :max characters or fewer.',
        'public_email' => 'The public email is an email address such as hello@example.com, or empty.',
        'website' => 'The website is a link starting with https://, or empty.',
        'instagram' => 'The Instagram link starts with https://, or is empty.',
        'facebook' => 'The Facebook link starts with https://, or is empty.',
        'google_reviews' => 'The Google reviews link starts with https://, or is empty.',
        'important_info' => 'Important info is :max characters or fewer.',
    ],
    'flashes' => [
        'saved' => 'Settings saved.',
        'hours_saved' => 'Opening hours saved.',
        'colour_by_saved' => 'The calendar colours are saved.',
    ],
    'profile' => [
        'about' => 'About',
        'about_help' => 'A few sentences about your business, shown at the top of your booking page.',
        'address_line1' => 'Address line 1',
        'address_help' => 'Shown on your booking page with a Get directions link. Leave it empty to show no address.',
        'address_line2' => 'Address line 2',
        'city' => 'City',
        'postal_code' => 'Postal code',
        'country' => 'Country',
        'country_option' => ':country (:code)',
        'phone' => 'Phone',
        'phone_help' => 'Shown on your booking page as a link that calls you.',
        'public_email' => 'Public email',
        'public_email_help' => 'An address customers may write to, shown on your booking page. Leave it empty to show none.',
        'website' => 'Website',
        'instagram' => 'Instagram',
        'facebook' => 'Facebook',
        'link_help' => 'A link starting with https://, shown on your booking page.',
        'google_reviews' => 'Google reviews link',
        'reviews_help' => 'Your Google reviews page, shown on your booking page as "Read our reviews".',
        'important_info' => 'Important info',
        'important_info_help' => 'Shown to customers on the review step before they book and in the booking confirmation email.',
    ],
    'links' => [
        'title' => 'Links',
        'help' => 'Share a link, or print its QR code. A service\'s or a person\'s link opens the booking page with them already chosen.',
        'page' => 'Your booking page',
        'services' => 'Services',
        'staff' => 'Staff',
        'download_qr' => 'Download QR',
    ],
    'hours' => [
        'intro' => 'The hours the business takes bookings. A day with no windows is a closed day. Times are the business\'s own; nothing is converted.',
        'save' => 'Save opening hours',
        'saving' => 'Saving hours',
        'roster' => 'The staff feature is no longer active, so these hours are read-only. Each staff member keeps the hours they have, and bookings keep using them.',
        'day' => ':day: :windows',
        'closed_day' => ':day: Closed',
        'window' => ':start-:end',
        'separator' => ', ',
    ],
    'gallery' => [
        'count' => ':count of :capacity photos',
        'photo_alt' => 'Photo :n of :count',
        'remove' => 'Remove',
        'removing' => 'Removing',
        'add' => 'Add photo',
        'adding' => 'Adding',
    ],
    'map' => [
        'pending' => 'The map is being prepared.',
        'failed' => 'The map could not be made. If the address is wrong, correct it and save; if it is right, platform support can make the map again.',
        'alt' => 'Map of the saved address',
    ],
    'images' => [
        'flashes' => [
            'logo_uploaded' => 'Logo uploaded.',
            'logo_removed' => 'Logo removed.',
            'cover_uploaded' => 'Cover uploaded.',
            'cover_removed' => 'Cover removed.',
            'photo_added' => 'Photo added to the gallery.',
            'photo_removed' => 'Photo removed from the gallery.',
        ],
        'refusals' => [
            'full' => 'The gallery holds :capacity photos. Remove one to add another.',
            'already_held' => 'That photo is already in the gallery.',
        ],
    ],
];
