<?php

/*
 * The customer's messages in English: every one of them, each under the catalog key it is sent by,
 * its subject and its paragraphs in the order the body reads them, a form or a branch each its own
 * whole key; then the lines every customer message shares, the greeting, the line naming the
 * business's clock, Book again and the line to My bookings, and the calendar file's summary. The
 * templates under app/Notifications name the keys, read through App\Notifications\CustomerMailLines
 * in the booking's language, bookings.language, never the tenant's default nor the application's
 * locale; the bookings link, which names no booking, in the language of the request that asked for
 * it. Its Greek twin is lang/el/customer-mail.php, key for key. A paragraph is one line, which a
 * mail client wraps; the blank lines, the booking's indented lines and the links set on a line of
 * their own are the templates' layout. A value's :word is filled by the translator: :customer the
 * name on the booking, :business the tenant, :service the booking's services as the message lists
 * them, :zone the clock's name, :from and :to the two starts of a move, each an instant as ICU
 * spells it, :link a link the line ends on, :count a number spelled out by ICU. Service names and
 * the business's name are the tenant's own and are never here. No line joins a date to a time: an
 * instant is ICU's own.
 */

return [
    // What every message to the customer opens with.
    'greeting' => 'Hello :customer,',

    // The line naming the clock every instant above it is in, once, at the foot of a message to the
    // customer, the business named in the third person since the clock is theirs.
    'zone' => 'Times are in :zone, the clock :business keeps.',

    // Book again, after the paragraphs of the confirmation, the reminder and the thank-you: the
    // hosted page with the booking's first item preselected, the link set after the words.
    'book_again' => 'Book again: :link',

    // The closing line of every message to the customer while the bookings link is on: the
    // tenant's My bookings page.
    'my_bookings' => 'See all your bookings: :link',

    // The calendar file's one event, named by its services and the business.
    'calendar' => [
        'summary' => ':service at :business',
    ],

    // The acknowledgement of a request a human has to say yes to, with the manage link after its
    // last sentence.
    'acknowledged' => [
        'subject' => 'Your request at :business',
        'received' => 'We have your request for :service at :business:',
        'deciding' => 'It is with :business now. They will approve or decline it, and you will hear from us either way.',
        'manage' => 'You can see your booking, and cancel it if your plans change, here:',
    ],

    // The confirmation: the booking, the total, the calendar file, and the way back to the booking,
    // the manage link where the confirming request carried the token, else where_link's branch for
    // a customer confirmed by their payment, the acknowledgement of their request sent or not.
    'confirmed' => [
        'subject' => 'Your booking at :business is confirmed',
        'confirmed' => 'Your booking at :business is confirmed:',
        'calendar' => 'The appointment is attached as a calendar file, so you can keep it in your own diary.',
        'manage' => 'You can see your booking, and cancel it if your plans change, here:',
        'where_link' => [
            'acknowledged' => 'To see your booking, or to cancel it if your plans change, use the link in the message we sent you when we received your request.',
            'unacknowledged' => 'To change or cancel it, get in touch with :business.',
        ],
    ],

    // The approval of a request, in its two forms: confirmed, with the calendar file and where the
    // customer's link is, the acknowledgement sent or not; and awaiting payment, with the signed
    // payment link after its pay line.
    'approved' => [
        'confirmed' => [
            'subject' => 'Your request at :business is confirmed',
            'approved' => 'Your request at :business has been approved, and your booking is confirmed:',
            'where_link' => [
                'acknowledged' => 'The appointment is attached as a calendar file, so you can keep it in your own diary. To see your booking, or to cancel it if your plans change, use the link in the message we sent you when we received your request.',
                'unacknowledged' => 'The appointment is attached as a calendar file, so you can keep it in your own diary. To change or cancel it, get in touch with :business.',
            ],
        ],
        'awaiting_payment' => [
            'subject' => 'Your request at :business is approved: one step left',
            'approved' => 'Your request at :business has been approved. The booking is confirmed once it is paid for:',
            'pay' => 'To pay, open this link:',
            'held' => 'The time is held for you while you pay. If the payment does not reach us in time, the time is given back and the link says so.',
        ],
    ],

    // The refusal of a request, naming the booking by its times.
    'rejected' => [
        'subject' => 'Your request at :business was declined',
        'declined' => 'Your request at :business has been declined:',
        'free_again' => 'Nothing has been booked and the time is free again. You are welcome to ask for another one.',
    ],

    // The cancellation of a booking or a request, in its four forms: who acted, the customer or
    // the business, and what was cancelled, a request still in front of the owner, which is
    // withdrawn, or a booking; each its subject, the sentence saying what was done, before the
    // booking's lines, and what follows from it.
    'cancelled' => [
        'request' => [
            'by_customer' => [
                'subject' => 'Your request at :business is withdrawn',
                'cancelled' => 'You have withdrawn your request at :business:',
                'means' => 'Nothing was booked and the time is free again. You are welcome to ask for another one whenever you like.',
            ],
            'by_business' => [
                'subject' => 'Your request at :business has been cancelled',
                'cancelled' => ':business has cancelled your request:',
                'means' => 'Nothing has been booked and the time is free again. If you did not expect this, get in touch with :business.',
            ],
        ],
        'booking' => [
            'by_customer' => [
                'subject' => 'Your booking at :business is cancelled',
                'cancelled' => 'You have cancelled your booking at :business:',
                'means' => 'The time is free again for somebody else, and nothing more is needed from you. You are welcome to book again whenever you like.',
            ],
            'by_business' => [
                'subject' => 'Your booking at :business has been cancelled',
                'cancelled' => ':business has cancelled your booking:',
                'means' => 'The appointment will not take place. If you did not expect this, get in touch with :business.',
            ],
        ],
    ],

    // The move of a request or a booking to another time: the subject by what moved, the opening
    // by what moved and who moved it, with the start it left and the start it has now; then the
    // sentences after the booking that are true of it, joined into one paragraph: the calendar
    // file a confirmed booking carries, a request's standing while it still awaits a decision, a
    // booking's while it still awaits its payment, and where the business acted, who to ask.
    'moved' => [
        'request' => [
            'subject' => 'Your request at :business has moved',
            'by_customer' => 'You have moved your request at :business. It was on :from and it is now on :to:',
            'by_business' => ':business has moved your request. It was on :from and it is now on :to:',
        ],
        'booking' => [
            'subject' => 'Your booking at :business has moved',
            'by_customer' => 'You have moved your booking at :business. It was on :from and it is now on :to:',
            'by_business' => ':business has moved your booking. It was on :from and it is now on :to:',
        ],
        'calendar' => 'The updated appointment is attached as a calendar file. A calendar that already holds this appointment updates its entry when the file is imported.',
        'unchanged' => 'Nothing else about your request has changed.',
        'awaiting_payment' => 'Your booking still awaits its payment, and it is confirmed once the payment reaches us.',
        'unexpected' => 'If you did not expect this, get in touch with :business.',
    ],

    // The reminder the tenant's lead before the appointment: the booking, its total, and where
    // the way back to it is, an earlier message, since a reminder carries no credential.
    'reminder' => [
        'subject' => 'A reminder of your booking at :business',
        'reminder' => 'This is a reminder of your booking at :business:',
        'earlier' => 'To see your booking, or to cancel it if your plans change, use the link in an earlier message from us about it. If no earlier message reached you, get in touch with :business.',
    ],

    // A hold the customer was waiting on that lapsed, in its two forms: the request nobody answered
    // in time, and the approved booking whose payment did not arrive in time.
    'request_expired' => [
        'timed_out' => [
            'subject' => 'Your request at :business has expired',
            'expired' => 'Nobody at :business answered your request in time, so it has expired:',
            'free_again' => 'Nothing has been booked and the time is free again. You are welcome to ask for another one.',
        ],
        'unpaid' => [
            'subject' => 'Your booking at :business has expired',
            'expired' => 'Your request at :business was approved, but the payment for it did not reach us in time, so the booking has expired:',
            'free_again' => 'Nothing has been booked and the time is free again. You are welcome to book another one.',
        ],
    ],

    // The bookings link a customer asked for on the hosted page: the one-time link set on a line of
    // its own after the sentence naming it, and how long it opens for, :count the link's lifetime
    // in minutes spelled out, read as a choice by it. It names no customer, so it opens on a
    // greeting of its own.
    'my_bookings_link' => [
        'subject' => 'Your link to your bookings at :business',
        'greeting' => 'Hello,',
        'here' => 'Here is the link to see your bookings at :business:',
        'once' => '{1} It opens once, within one minute. If you did not ask for it, you can ignore this message: nothing has changed.|[2,*] It opens once, within :count minutes. If you did not ask for it, you can ignore this message: nothing has changed.',
    ],

    // The thanks once the business marks the booking completed: the visit, what the customer came
    // for, the closing wish, and the line to the tenant's reviews where its profile holds a link.
    'thank_you' => [
        'subject' => 'Thank you for your visit to :business',
        'thanks' => 'Thank you for visiting :business. We hope you enjoyed your appointment:',
        'again' => 'We would be glad to see you again.',
        'review' => 'Leave us a review: :link',
    ],
];
