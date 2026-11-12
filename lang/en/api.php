<?php

/*
 * The API's refusals in English: the error envelope's detail sentences, and the validation
 * sentences of the hold body and of the availability query, each written to be shown verbatim by
 * the booking app and the admin. ErrorEnvelope's constants, Instant's and NewHoldRequest's keep
 * their names and hold their keys. The envelope reads its detail through the translator when it is
 * built, in the request's language: on /api/v1 the lang parameter, else the tenant default; under
 * /admin/api the user's; on the webhooks English, since a provider's delivery log is no reader. A
 * limit's 429, answered before the tenant is bound, reads the request's language there. Its Greek
 * twin is lang/el/api.php, key for key. A value's :word is filled by the translator from the
 * code's own constant: :count a limit, read as a choice, and :n an item's position in the chain,
 * counted from one, the item named by its number and never by an ordinal. A field's name is
 * spelled as the wire spells it.
 */

return [
    // The refusals the booking engine raises by type and the limit's, one sentence per reason: the
    // 500's one fixed sentence, the 429, the two 409s of a chain, the catalog's 404 and the pick's
    // 400, the lock's 503, the guard's missing token, and one per reason a guard refuses a
    // transition with.
    'envelope' => [
        'server_error' => 'Something went wrong on our side. Please try again in a moment.',
        'rate_limited' => 'Too many requests in a short time. Please wait a moment and try again.',
        'overlap' => 'That time was just taken. Please pick another.',
        'unavailable' => 'That time is not available. Please pick another.',
        'service_gone' => 'One of the services you chose is no longer offered. Please start again.',
        'staff_not_eligible' => 'That staff member does not perform the service you chose.',
        'busy' => 'We are handling a lot of bookings right now. Please try again in a moment.',
        'missing_token' => 'A token is required.',
        'hold_expired' => 'We could not hold that time any longer. Please pick another.',
        'not_confirmable' => 'That booking is not waiting to be confirmed any more.',
        'payment_required' => 'That booking has to be paid for before it can be confirmed.',
        'not_cancellable' => 'That booking can no longer be cancelled.',
        'not_reschedulable' => 'That booking can no longer be moved to another time.',
        'not_releasable' => 'That booking is no longer a hold that can be given back.',
        'hold_lapsed' => 'That time is not being held any more, so the booking cannot be moved.',
        'window_closed' => 'It is too late to change this booking online. Please get in touch with us instead.',
        'not_approvable' => 'That request is not waiting to be approved any more.',
        'not_rejectable' => 'That request is not waiting to be rejected any more.',
        'not_confirmed' => 'Only a confirmed booking can be marked completed or a no-show.',
        'too_early' => 'That booking has not started yet, so it cannot be marked completed or a no-show.',
        'unaccepted_warning' => 'That time is outside the staff member\'s working hours or on a closure. Confirm the warning to move the booking there anyway.',
        'blocked' => 'Booking online is not available.',
    ],

    // The admin's move of a booking: where the chain cannot go, one sentence per rule the planner
    // or the catalog refused it by that says more than the envelope's unavailable (the lead time
    // and the horizon, which bind customers alone, read that one), and a segment given to a member
    // who does not perform its service or to a segment the booking does not have. The manual
    // booking answers a start already passed with past as well.
    'move' => [
        'past' => 'That time has already passed. Please pick a later one.',
        'off_grid' => 'That time is not on the booking grid. Please pick one from the list.',
        'service_gone' => 'A service of this booking is no longer offered, so the booking cannot be moved.',
        'staff_gone' => 'A staff member of this booking no longer performs its service, so the booking cannot be moved.',
        'staff_not_eligible' => 'That staff member does not perform this service.',
        'no_such_segment' => 'This booking has no such service to give to another staff member.',
    ],

    // The sentence a status is answered with where no reason names its own: 400, 401, 403, 404,
    // 405 and 503, and any other 4xx; the 429 and every other 5xx read the envelope's above.
    'status' => [
        'bad_request' => 'The request could not be understood.',
        'unauthenticated' => 'Sign in to continue.',
        'forbidden' => 'You do not have permission to do that.',
        'not_found' => 'That could not be found.',
        'method_not_allowed' => 'That address does not take this kind of request.',
        'service_unavailable' => 'The service is not available right now. Please try again shortly.',
        'refused' => 'The request was refused.',
    ],

    // A request's first refused field, its detail at 400: the hold body's customer, its start, its
    // note and its preference, POST /holds and the admin's manual booking alike; the chain's
    // items, shared with the availability query, and each item by its position; and the query's
    // window, shared with the move's own read.
    'validation' => [
        'customer_name' => [
            'required' => 'A name is required.',
            'max' => '{1} A name is at most one character.|[2,*] A name is at most :count characters.',
        ],
        'customer_email' => [
            'required' => 'An email address is required.',
            'email' => 'That email address does not look like an email address.',
            'max' => '{1} An email address is at most one character.|[2,*] An email address is at most :count characters.',
        ],
        'customer_phone' => [
            'string' => 'A phone number is text, or leave it out.',
            'max' => '{1} A phone number is at most one character.|[2,*] A phone number is at most :count characters.',
        ],
        'start' => 'The start field must be an instant spelled like 2026-11-03T09:00:00+02:00.',
        'same_staff' => 'The same_staff field must be true or false.',
        'note' => [
            'string' => 'A note is text, or leave it out.',
            'max' => '{1} A note is at most one character.|[2,*] A note is at most :count characters.',
        ],
        'items' => [
            'required' => 'The items field is required.',
            'list' => 'The items field must be a JSON list of items.',
            'max' => '{1} The items field holds at most one item.|[2,*] The items field holds at most :count items.',
        ],
        'item' => [
            'object' => 'Item :n must be an object with service_id and staff_id.',
            'no_service_id' => 'Item :n has no service_id.',
            'no_staff_id' => 'Item :n has no staff_id; send null for no preference.',
            'service_id_integer' => 'The service_id of item :n must be a positive integer.',
            'staff_id_integer' => 'The staff_id of item :n must be a positive integer or null.',
            'service_unavailable' => 'Item :n names a service that is not available.',
            'staff_unqualified' => 'Item :n names a staff member who does not perform that service.',
        ],
        'move' => [
            'date' => 'The date field must be a date spelled YYYY-MM-DD.',
            'one' => 'Name either a date or a start, not both.',
            'staff' => 'The staff field must map a service\'s position in the booking, counted from zero, to a staff member\'s id.',
            'warning_accepted' => 'The warning_accepted field must be true or false.',
            'tell_customer' => 'The tell_customer field must be true or false.',
        ],
        'from' => [
            'day' => 'The from field must be a date spelled YYYY-MM-DD.',
        ],
        'to' => [
            'day' => 'The to field must be a date spelled YYYY-MM-DD.',
            'after' => 'The to field must be a date after from.',
            'max' => '{1} The to field must be at most one day after from.|[2,*] The to field must be at most :count days after from.',
        ],
    ],
];
