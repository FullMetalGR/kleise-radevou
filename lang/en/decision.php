<?php

/*
 * The decision pages and the booking facts they share with the business's mails, in English: the
 * confirm page an approve or reject link opens, the outcome page every link with nothing left to
 * decide answers, and the one refusal; then the facts the pages and the business's messages
 * print the same way, the customer's labels, an item's name with its staff member, the total and
 * the line naming the business's clock. DecisionWords, OutcomeWords, CustomerFacts and the views
 * name the keys; the pages read them through the translator in the request's language, the
 * locale SetTenantLocale set from the link's prefix else the tenant default, and a message reads
 * the facts in the language it is written in. Its Greek twin is lang/el/decision.php, key for
 * key. A value's :word is filled by the translator: :business the tenant, :service and :staff an
 * item's service and staff member, :amount the total as the formatter spells it in the language
 * with the row's currency, :zone the clock's name. Service and staff names are the tenant's own
 * and are never here. No line joins a date to a time: an instant is ICU's own.
 */

return [
    // The confirm and the outcome page's title, the tenant named.
    'title' => 'A booking at :business',

    // The confirm page: the sentence beside the decision's consequence, and the label of the box
    // a rejection offers for its reason.
    'confirm' => [
        'waiting' => 'A booking is waiting for your decision.',
        'reason' => 'Why, if you want to say (optional)',
    ],

    // The one decision a link carries: the word on the button and what pressing it does, said
    // in the owner's terms rather than the status machine's.
    'decisions' => [
        'approve' => [
            'verb' => 'Approve',
            'consequence' => 'Approving it confirms the booking and the slot stays theirs.',
        ],
        'reject' => [
            'verb' => 'Reject',
            'consequence' => 'Rejecting it turns the request down and releases the slot.',
        ],
    ],

    // The outcome page's first sentence, one per booking state, keyed by the status as the
    // row spells it.
    'outcome' => [
        'pending' => 'This booking is still with the customer: they have not finished checking out.',
        'awaiting_approval' => 'This request is still waiting for a decision.',
        'awaiting_payment' => 'This booking is approved and waiting to be paid for.',
        'confirmed' => 'This booking is confirmed.',
        'completed' => 'This booking is done.',
        'no_show' => 'This booking is marked as a no-show.',
        'cancelled' => 'This booking was cancelled.',
        'rejected' => 'This request was rejected.',
        'expired' => 'This request timed out and the slot went back to the diary.',
    ],

    // The outcome page's second sentence on a request whose window closed: the policy the
    // service carried, keyed as on_approval_timeout spells it, or the window alone where the
    // reader may not be shown the policy.
    'window' => [
        'expire' => 'Its window has closed, so the slot will be released.',
        'auto_approve' => 'Its window has closed, so it will be approved automatically.',
        'closed' => 'Its window has closed.',
    ],

    // The one body every refused link gets, its title also its heading.
    'refused' => [
        'title' => 'This link does not work',
        'changed' => 'It may have been changed on its way here, or it may have been meant for somebody else.',
        'again' => 'Ask whoever runs the diary to send it again.',
    ],

    // The booking's facts as the pages and the business's messages print them: the customer's
    // labels by CustomerFacts' identifiers and the line standing in for an erased customer's
    // name, an item with the staff member who performs it, the total, and the line naming the
    // clock every instant above it is in.
    'facts' => [
        'customer' => 'Customer',
        'email' => 'Email',
        'phone' => 'Phone',
        'erased' => 'Customer erased',
        'item' => ':service with :staff',
        'total' => 'Total: :amount',
        'zone' => 'Times are in :zone, the clock you keep.',
    ],
];
