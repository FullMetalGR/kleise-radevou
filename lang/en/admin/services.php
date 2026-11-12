<?php

/*
 * The Services screen in English: the catalog, the service form and the server's sentences about
 * them, shared as the strings prop's services section, which ServiceController names, in the
 * language of the request, the user's. Service names, descriptions and categories are the tenant's
 * own text and never pass through here. Its Greek twin is lang/el/admin/services.php.
 *
 * page is the screen: the list's title and heading, New service, the inactive toggle with the
 * count of inactive services (:count), the empty catalog's two lines, a persisted row's title,
 * the loading label, the list that did not load, an id the list does not hold with its link back
 * to the list, a save and a delete that did not reach the server, and the toast when a member's
 * links did not land after the service did (:staff the member's name, :reason the server's
 * sentence or the save's).
 *
 * table is the catalog card: its title, the count of the rows shown (:count), the seven column
 * heads, a duration in minutes (:n), the sub-line with the processing gap (:n the duration,
 * :count the gap, the choice's own figure, since minutes_gap is read as a choice by the gap) and
 * the two online marks.
 *
 * modes, timeouts and statuses are the options of the form's three selects, by the value sent.
 *
 * form is the detail page: the breadcrumb's label and its link back, the four sections' titles,
 * every field's label and help (:n the granularity in the duration's help, :currency the row's
 * currency code in the price's label), Save, its busy word and why it is disabled.
 *
 * staff is the section of the members who perform the service: its title, the ticked count
 * (:ticked of :count members), an inactive member (:staff), one weekly window (:weekday ICU's
 * short name, :time to :end in the tenant's clock), a member with no weekly hours, the line saying
 * why the ticks are read-only and the two links to the Shifts screen.
 *
 * danger is the Danger zone: its title, the delete with its busy word and the remedy with its.
 *
 * validation is ServiceRequest's, one sentence per field and rule (:n the granularity, the
 * horizon's ceiling or the category's longest, :currency the business's currency code); refusals
 * is the delete guard's 409, a service some booking names; flashes are the five writes' answers.
 *
 * Three keys are read as choices, by the admin's mirror of the translator's choice, choose(), on
 * the client, and their Greek keeps the choice syntax: table.count by the rows shown,
 * table.minutes_gap by the gap, and staff.ticked by the members listed. Every other key is read
 * as plain text.
 */

return [
    'page' => [
        'title' => 'Services',
        'new' => 'New service',
        'show_inactive' => 'Show inactive services (:count)',
        'none' => 'No services yet',
        'none_sentence' => 'A service is the thing a customer books. Add the first one from the button above.',
        'detail_title' => 'Service',
        'loading' => 'Loading the services',
        'list_error' => 'The services could not be loaded. Check your connection and try again.',
        'not_found' => 'There is no service with this id. It may have been deleted.',
        'back' => 'Back to the list',
        'save_error' => 'The service could not be saved. Check your connection and try again.',
        'delete_error' => 'The service could not be deleted. Check your connection and try again.',
        'links_failed' => 'The service was saved, but the services :staff performs could not be updated: :reason',
    ],
    'table' => [
        'title' => 'Catalog',
        'count' => '{1} :count shown|[2,*] :count shown',
        'id' => 'ID',
        'name' => 'Name',
        'category' => 'Category',
        'duration' => 'Duration',
        'price' => 'Price',
        'payment' => 'Payment',
        'status' => 'Status',
        'minutes' => ':n min',
        'minutes_gap' => ':n min, :count min gap',
        'online' => 'Online',
        'not_online' => 'Not online',
    ],
    'modes' => [
        'free' => 'Free',
        'online' => 'Online',
        'onsite' => 'On site',
    ],
    'timeouts' => [
        'expire' => 'Expire the request',
        'auto_approve' => 'Approve it automatically',
    ],
    'statuses' => [
        'active' => 'Active',
        'inactive' => 'Inactive',
    ],
    'form' => [
        'breadcrumb' => 'Breadcrumb',
        'list' => 'Services',
        'basics' => 'Basics',
        'timing' => 'Timing',
        'rules' => 'Booking rules',
        'payment' => 'Payment',
        'name' => 'Name',
        'status' => 'Status',
        'description' => 'Description',
        'description_help' => 'Shown to customers on the booking page, under the service\'s name.',
        'category' => 'Category',
        'category_help' => 'Services sharing a category are listed under it on the booking page. Leave empty for none.',
        'online' => 'Offered online',
        'online_help' => 'Customers can book this online. Untick to keep it for bookings you add yourself.',
        'duration' => 'Duration (minutes)',
        'duration_help' => 'Must be a multiple of :n minutes.',
        'gap' => 'Processing gap (minutes)',
        'gap_help' => 'The stretch after the service where the customer is still present but the staff member is free, such as colour developing: it does not occupy the staff member, so another booking can start inside it.',
        'buffer_before' => 'Buffer before (minutes)',
        'buffer_before_help' => 'Minutes the staff member is occupied before the service starts.',
        'buffer_after' => 'Buffer after (minutes)',
        'buffer_after_help' => 'Minutes the staff member is occupied after the service ends.',
        'lead_time' => 'Lead time (minutes)',
        'lead_time_help' => 'How far ahead of a slot customers booking online must book it.',
        'horizon' => 'Booking horizon (days)',
        'horizon_help' => 'How far into the future customers booking online can book.',
        'cancel_window' => 'Cancellation window (hours)',
        'cancel_window_help' => 'How long before the start a customer can still cancel online.',
        'reschedule_window' => 'Reschedule window (hours)',
        'reschedule_window_help' => 'How long before the start a customer can still move the booking online.',
        'approval' => 'Requires approval',
        'hold' => 'Approval hold (hours)',
        'hold_help' => 'How long a request is held for a decision. Leave empty for the default of the business.',
        'timeout' => 'When the approval hold expires',
        'price' => 'Price (:currency)',
        'price_help' => 'Write it as a person would, such as 12,50 or 12.50.',
        'payment_mode' => 'Payment mode',
        'save' => 'Save',
        'saving' => 'Saving',
        'needs_name' => 'A service needs a name before it can be saved.',
    ],
    'staff' => [
        'title' => 'Staff who perform this',
        'ticked' => '{1} :ticked of :count ticked|[2,*] :ticked of :count ticked',
        'inactive' => ':staff (inactive)',
        'window' => ':weekday :time-:end',
        'no_hours' => 'No weekly hours',
        'read_only' => 'The staff feature is not on your plan, so who performs this service is read-only here.',
        'edit_hours' => 'Edit hours on the Shifts screen',
        'see_hours' => 'See hours on the Shifts screen',
    ],
    'danger' => [
        'title' => 'Danger zone',
        'delete' => 'Delete this service',
        'deleting' => 'Deleting',
        'deactivate' => 'Deactivate it instead',
        'deactivating' => 'Deactivating',
    ],
    'validation' => [
        'name' => 'A service needs a name.',
        'description' => 'The description is text.',
        'duration' => 'The duration is a whole number of minutes above zero.',
        'duration_grid' => 'The duration must be a multiple of the :n-minute granularity.',
        'processing_gap' => 'The processing gap is zero or more whole minutes.',
        'buffer_before' => 'The buffer before is zero or more whole minutes.',
        'buffer_after' => 'The buffer after is zero or more whole minutes.',
        'price' => 'The price is zero or more, in the smallest unit of the currency.',
        'currency' => 'A new service is priced in the currency of the business, :currency.',
        'currency_fixed' => 'The currency of a service cannot be changed.',
        'payment_mode' => 'The payment mode is free, online or onsite.',
        'requires_approval' => 'Requires approval is yes or no.',
        'approval_hold' => 'The approval hold is a whole number of hours above zero, or empty for the default.',
        'timeout' => 'When the approval hold expires is expire or auto_approve.',
        'cancel_window' => 'The cancellation window is zero or more whole hours.',
        'reschedule_window' => 'The reschedule window is zero or more whole hours.',
        'lead_time' => 'The lead time is zero or more whole minutes.',
        'horizon' => 'The booking horizon is zero or more whole days.',
        'horizon_max' => 'The booking horizon is at most :n days.',
        'status' => 'The status is active or inactive.',
        'status_new' => 'A new service starts active; its status is set on its page.',
        'category_text' => 'The category is a word or a short phrase, or empty for none.',
        'category_length' => 'The category is at most :n characters.',
        'online' => 'Offered online is yes or no.',
    ],
    'refusals' => [
        'referenced' => 'This service is still used by existing bookings and cannot be deleted.',
    ],
    'flashes' => [
        'created' => 'Service created.',
        'saved' => 'Service saved.',
        'deactivated' => 'Service deactivated.',
        'reactivated' => 'Service reactivated.',
        'deleted' => 'Service deleted.',
    ],
];
