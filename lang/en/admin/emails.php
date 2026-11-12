<?php

/*
 * The Settings screen's Emails section in English: shared as the strings prop's emails section,
 * which SettingsController names beside the settings section, in the language of the request, the
 * user's. Its Greek twin is lang/el/admin/emails.php.
 *
 * labels are the words beside each message's checkbox, one per case of
 * App\Application\Notification\MessageKey under the case's own value, each naming the event
 * rather than the template, since that is what a tenant decides about. MessageKey::labelKey()
 * answers a case's key here; the shared settings prop carries each label read in the request's
 * language, and the automatic approval's notice to the business quotes the approved message's
 * label in the mail's language. audience is the tag beside a checkbox naming who the message goes
 * to: the customer, the business, or the owners alone for the late payment. locked is the one
 * sentence beside a message held on, the approved message while a payment connection works.
 */

return [
    'labels' => [
        'acknowledged' => 'Request received',
        'confirmed' => 'Booking confirmed',
        'approved' => 'Request approved',
        'rejected' => 'Request declined',
        'cancelled' => 'Booking cancelled',
        'moved' => 'Booking moved to a new time',
        'reminder' => 'Appointment reminder',
        'request_expired' => 'Request timed out',
        'new_booking' => 'A booking was made',
        'approval_request' => 'A request is waiting for a decision',
        'approval_nag' => 'A request is still waiting',
        'auto_approved' => 'A request was approved automatically',
        'cancellation_notice' => 'A customer cancelled',
        'move_notice' => 'A customer moved their booking',
        'late_payment' => 'A payment arrived for a booking no longer waiting for it',
        'my_bookings_link' => 'Your bookings link',
        'thank_you' => 'Thank you for your visit',
    ],
    'audience' => [
        'customer' => 'to the customer',
        'business' => 'to the business',
        'owners' => 'to the owners',
    ],
    'locked' => "Always sent while a payment connection works, because it carries the customer's link to pay.",
];
