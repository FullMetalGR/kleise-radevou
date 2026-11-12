<?php

/*
 * The business's messages in English: the seven a user of the business or a staff row's own address
 * is sent, each under the catalog key it is sent by, its subject and its paragraphs in the order
 * the body reads them, a form or a case each its own whole key; then the lines two of them share,
 * the heading over the customer's note and the decision links' captions beside the sentence a copy
 * carrying none prints instead. The templates under app/Notifications name the keys, read through
 * App\Notifications\BusinessMailLines in the recipient's language: a user's own, users.locale else
 * the tenant default, and a staff row's address the tenant default, never the application's locale.
 * Its Greek twin is lang/el/business-mail.php, key for key. A paragraph is one line, which a mail
 * client wraps; the blank lines, the booking's indented lines, the customer's facts and the links
 * set on a line of their own are the templates' layout. A value's :word is filled by the
 * translator: :business the tenant, :customer the name on the booking, :staff the name of the user
 * who acted, :name the platform's public name, :from and :to the two starts of a move and :end the
 * instant a request runs out, each an instant as ICU spells it, :count a number of hours, and
 * :label the words beside the customer's approval message's checkbox in the Emails section. The
 * booking's facts, its items, its total, the customer's labels and the business's clock line, are
 * decision.php's; the customer's name and note and the service and staff names are as typed and are
 * never here. No line joins a date to a time: an instant is ICU's own.
 */

return [
    // The heading over the customer's note, where they wrote one, in the new-booking notice and
    // the approval request, after who the customer is and before the total.
    'note' => [
        'heading' => 'The customer added:',
    ],

    // The decision links of the approval request and the nag, each caption over its link, and
    // the sentence a copy with no links prints in their place: the copy to a staff row's own
    // address, which has no user for a link to decide as.
    'links' => [
        'approve' => 'To approve it:',
        'reject' => 'To reject it:',
        'none' => 'This copy carries no links to decide it with, because it went to the address on a staff row rather than to a user. An owner or manager can approve or reject it from their own copy of this message, and so can a user linked to this staff row, from theirs.',
    ],

    // The new-booking notice, in its two forms: checkout, the booking the customer settled
    // themselves; and paid, an approved booking its payment confirmed, opening and titled on the
    // payment.
    'new_booking' => [
        'checkout' => [
            'subject' => 'New booking at :business',
            'opening' => 'A new booking at :business:',
        ],
        'paid' => [
            'subject' => 'Payment received for a booking at :business',
            'opening' => 'A payment landed for an approved booking at :business, and the booking is now confirmed:',
        ],
    ],

    // The approval request: while it waits, its opening one per copy, the one carrying the
    // recipient's links and the one carrying none; once settled before the queue reached it, its
    // own subject, opening and the sentence in place of the links.
    'approval_request' => [
        'waiting' => [
            'subject' => 'A request at :business is waiting for a decision',
            'with_links' => 'A request at :business is waiting for your decision:',
            'without_links' => 'A request at :business is waiting for a decision:',
        ],
        'settled' => [
            'subject' => 'A request at :business has already been settled',
            'opening' => 'A request at :business was waiting for a decision:',
            'nothing_left' => 'It has since been settled, so there is nothing left to decide.',
        ],
    ],

    // The nag about a request still waiting: its opening one per copy, as the request's, and when
    // the request runs out, a choice by the hours left, rounded to the nearest, none under half an
    // hour.
    'approval_nag' => [
        'subject' => 'A request at :business is still waiting for a decision',
        'with_links' => 'A request at :business is still waiting for your decision:',
        'without_links' => 'A request at :business is still waiting for a decision:',
        'runs_out' => '{0} It runs out on :end, in less than an hour.|{1} It runs out on :end, in about an hour.|[2,*] It runs out on :end, in about :count hours.',
    ],

    // The notice of a request the clock approved: what happened, in the form true of where the
    // grant landed, confirmed or awaiting the customer's payment, each told or untold, as the
    // customer's approval message is switched on or off.
    'auto_approved' => [
        'subject' => 'A request at :business was approved automatically',
        'opening' => 'A request at :business was approved automatically:',
        'confirmed' => [
            'told' => 'Nobody decided it in time, so the approval policy set on the service granted it. The booking is confirmed and the customer has been told.',
            'untold' => 'Nobody decided it in time, so the approval policy set on the service granted it. The booking is confirmed. The customer has not been told, because the ":label" message is switched off in the settings of :business.',
        ],
        'awaiting_payment' => [
            'told' => 'Nobody decided it in time, so the approval policy set on the service granted it. The booking awaits its payment: the customer has been sent a link to pay, the time is held for them meanwhile, and the booking is confirmed once the payment reaches you. If it does not arrive in time, the time is given back.',
            'untold' => 'Nobody decided it in time, so the approval policy set on the service granted it. The booking awaits its payment. The customer has not been told, because the ":label" message is switched off in the settings of :business, so they have no link to pay with and the time is given back when the hold ends.',
        ],
    ],

    // The notice of a cancellation: what was cancelled, a request or a booking, each its own
    // subject, opening and what follows from it; and who cancelled it, one sentence per who acted,
    // the customer, a user, a user since removed, the platform's support and the system.
    'cancellation_notice' => [
        'request' => [
            'subject' => 'A request at :business was cancelled',
            'opening' => 'A request at :business was cancelled:',
            'means' => 'There is nothing left to decide.',
        ],
        'booking' => [
            'subject' => 'A booking at :business was cancelled',
            'opening' => 'A booking at :business was cancelled:',
            'means' => 'The time is free again.',
        ],
        'by' => [
            'customer' => 'The customer, :customer, cancelled it.',
            'user' => ':staff cancelled it.',
            'removed' => 'Somebody whose login has since been removed cancelled it.',
            'support' => ':name support cancelled it.',
            'system' => 'The system cancelled it.',
        ],
    ],

    // The notice of a move: what moved, a request or a booking, each its own subject and its
    // opening with the start it left and the start it has; and who moved it, as the
    // cancellation's.
    'move_notice' => [
        'request' => [
            'subject' => 'A request at :business was moved',
            'opening' => 'A request at :business was moved. It was on :from and it is now on :to:',
        ],
        'booking' => [
            'subject' => 'A booking at :business was moved',
            'opening' => 'A booking at :business was moved. It was on :from and it is now on :to:',
        ],
        'by' => [
            'customer' => 'The customer, :customer, moved it.',
            'user' => ':staff moved it.',
            'removed' => 'Somebody whose login has since been removed moved it.',
            'support' => ':name support moved it.',
            'system' => 'The system moved it.',
        ],
    ],

    // The owners' notice of a payment that landed for a booking no longer waiting for it: what
    // arrived, the booking's reference by its label, and what the payment did and did not do.
    'late_payment' => [
        'subject' => 'A payment for a booking at :business bought nothing',
        'arrived' => 'A payment reached your payment provider for a booking at :business that was no longer waiting for it, so the payment bought nothing:',
        'reference' => 'Booking reference',
        'stands' => "The payment confirmed nothing and the booking stands as it was. Nothing has been refunded. Refunding the payment is yours to decide, and it is done in your payment provider's dashboard, not here.",
    ],
];
