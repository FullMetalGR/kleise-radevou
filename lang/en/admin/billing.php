<?php

/*
 * The Billing screen, the Settings Danger zone and the platform's refusals an owner meets, in
 * English: shared as the strings prop's billing section, which BillingController names on the
 * Billing screen and SettingsController beside the settings section for the Danger zone, in the
 * language of the request, the user's. Its Greek twin is lang/el/admin/billing.php.
 *
 * :plan is a plan's name as the catalogue gives it, Solo or Team, a proper noun never translated.
 * :amount is a plan's price in the tenant's money, spelled in the user's language, and :price
 * that price a month as plan.price words it, where a line names a plan beside its price. :end is
 * an instant in the tenant's clock and formats. :day is a day in the tenant's clock spelled by ICU
 * in the reader's language, :zone the tenant's zone, :address the support address. :count is a
 * count read as a choice: of active staff members in digits, or of the days the retention window
 * keeps a closing account, spelled in words by ICU.
 *
 * title is the screen's heading. notices are the state notice's one whole sentence per state, a
 * free tenant's own among them, and paused what a lapse pauses, after_grace what pauses if grace
 * runs out. grace_end, trial_end and trial_ended are the ends the screen spells under the notice.
 * plan is the Plan section: its heading, the price, a trial's plan name, what a plan includes, the
 * narrowed features (:features, the user's language's list) and the line where the plan list is
 * not set up. change is Change plan: its two buttons, its one whole line per direction, its busy
 * words and the link to the Staff screen. checkout is the checkout's control: its four labels,
 * the on-its-way line, its busy words, the plan choice's name, each plan's line and its button.
 * payment is the portal's section.
 *
 * danger is the Settings Danger zone: its heading, the export, the close and the close's confirm.
 * closing is the closing account's words, one whole sentence each, which the Billing notice, the
 * Danger zone, the subscription gate and the close's flash join or read.
 *
 * validation is ChangePlanRequest's and CheckoutRequest's. refusals are the plan change's and the
 * checkout's (PlanChangeRefused, ResubscribeRefused), the two closes an owner meets
 * (CloseRefused) and the subscription gate's 403. flashes are the Billing writes' answers.
 */

return [
    'title' => 'Billing',
    'notices' => [
        'active' => 'Your subscription is active and everything on your plan is open to you.',
        'free' => 'This account is free of charge. There is nothing to pay.',
        'grace' => 'Your last payment did not go through. Update your payment method before your grace period ends to keep your subscription active.',
        'past_due' => 'Your payment is overdue. Update your payment method to resume setup changes.',
        'canceled' => 'Your subscription has ended. Subscribe again to resume setup changes.',
        'incomplete' => 'Your first payment is still being processed. Setup changes open as soon as it goes through.',
        'trial' => 'You are on a free trial and everything is open to you.',
        'trial_ended' => 'Your free trial has ended. Subscribe to resume setup changes.',
        'paused' => 'Bookings keep running: customers can still book, pay, cancel and reschedule, and you can still add bookings and approve, reject, move, cancel, complete or mark them no-show. Only setup changes are paused.',
        'after_grace' => 'Nothing is paused yet. If the payment is not settled when your grace period ends, only setup changes will pause: bookings keep running, customers can still book, pay, cancel and reschedule, and you can still add bookings and approve, reject, move, cancel, complete or mark them no-show.',
    ],
    'grace_end' => 'Your grace period ends at :end.',
    'trial_end' => 'Your free trial ends at :end.',
    'trial_ended' => 'Your free trial ended at :end.',
    'plan' => [
        'title' => 'Plan',
        'price' => ':amount a month',
        'trial' => 'Free trial',
        'includes' => [
            'services' => 'Services, bookings and the calendar',
            'page' => 'Your booking page, settings and email notifications',
            'one_member' => 'One staff member',
            'staff' => 'Any number of staff members, each with their own hours, services, calendar and approvals',
        ],
        'narrowed' => 'No longer on your plan: :features.',
        'unreadable' => 'Plans cannot be shown or changed right now because the plan list is not set up. Payment details and invoices are still available.',
    ],
    'change' => [
        'add' => 'Add Staff',
        'remove' => 'Remove Staff',
        'add_line' => 'Add Staff to move to :plan, :price.',
        'remove_line' => 'Remove Staff to move to :plan, :price.',
        'busy' => 'Changing plan',
        'staff_link' => 'Go to the Staff screen',
    ],
    'checkout' => [
        'choose_plan' => 'Choose your plan',
        'subscribe' => 'Subscribe',
        'subscribe_again' => 'Subscribe again',
        'continue' => 'Continue to checkout',
        'on_its_way' => 'Your new subscription is on its way. This page updates once the payment is confirmed.',
        'busy' => 'Opening the checkout',
        'plans' => 'Plans',
        'option' => ':plan, :price',
        'choose' => 'Choose :plan',
    ],
    'payment' => [
        'title' => 'Payment method and invoices',
        'sentence' => 'Your card and your invoices are kept on the billing provider\'s own secure pages, which bring you back here when you are done.',
        'button' => 'Manage payment method and invoices',
        'busy' => 'Opening the portal',
    ],
    'danger' => [
        'title' => 'Danger zone',
        'export' => [
            'button' => 'Export everything',
            'what' => 'One zip file of everything the business holds here: its settings, services, staff and their hours, closed days, every booking with its history, the payment connection without its keys, and the uploaded images.',
            'busy' => 'Exporting',
            'failed' => 'The business\'s data could not be exported. Check your connection and try again.',
        ],
        'close' => [
            'button' => 'Close this account',
            'what' => '{1} Closing keeps the account running for :count day and then deletes everything in it.|[2,*] Closing keeps the account running for :count days and then deletes everything in it.',
            'title' => 'Close this account?',
            'when' => '{1} The account closes in :count day, on :day, and everything in it is deleted then. The day is in :zone, the clock you keep.|[2,*] The account closes in :count days, on :day, and everything in it is deleted then. The day is in :zone, the clock you keep.',
            'keeps' => 'Until then your bookings keep running and your booking page stays open.',
            'pauses' => 'Setup changes pause from now: services, staff, opening hours and settings stay as they are.',
            'billing' => 'Billing stops now, and nothing more is charged.',
            'confirm' => 'Close account',
            'busy' => 'Closing',
            'pending' => 'The account is being closed.',
            'failed' => 'The account could not be closed. Check your connection and try again.',
        ],
    ],
    'closing' => [
        'closes' => 'This account closes on :day, and everything in it is deleted then.',
        'way_back' => 'To keep it, choose a plan on the Billing screen before then.',
        'way_back_free' => 'To keep it, contact support at :address before then.',
        'frozen' => 'This account is closing, so settings cannot be changed. It closes on :day, and everything in it is deleted then.',
        'closed' => 'Your account closes on :day.',
    ],
    'validation' => [
        'no_plan' => 'A plan change needs the plan to move to.',
        'checkout_plan' => 'Choose one of the plans shown.',
    ],
    'refusals' => [
        'downgrade' => '{1} Staff cannot be removed from your plan while :count staff member is active. Deactivate all but one on the Staff screen, then try again.|[2,*] Staff cannot be removed from your plan while :count staff members are active. Deactivate all but one on the Staff screen, then try again.',
        'unchoosable' => '{1} :plan cannot be chosen while :count staff member is active. Deactivate all but one on the Staff screen, then choose it.|[2,*] :plan cannot be chosen while :count staff members are active. Deactivate all but one on the Staff screen, then choose it.',
        'unchoosable_paused' => '{1} :plan cannot be chosen while :count staff member is active, and staff cannot be deactivated while setup changes are paused. Choose a plan with Staff; once it is active, deactivate all but one and remove Staff on this screen.|[2,*] :plan cannot be chosen while :count staff members are active, and staff cannot be deactivated while setup changes are paused. Choose a plan with Staff; once it is active, deactivate all but one and remove Staff on this screen.',
        'no_subscription' => 'No subscription is on file for this business.',
        'charge_pending' => 'Your plan can be changed once your first payment has gone through.',
        'ended' => 'A subscription that has ended cannot change plan.',
        'unlisted' => 'Your subscription is on a plan this screen does not list, so it cannot be changed here.',
        'unknown_plan' => 'That plan is not one we offer.',
        'resubscribe' => 'There is no ended subscription on file to start again.',
        'window_ending' => 'This account closes before a checkout opened now could be completed, so none was opened.',
        'already_closing' => 'This account is already closing.',
        'not_open' => 'This account is not open right now, so it cannot be closed.',
        'subscription' => 'Your subscription is not active, so settings cannot be changed. Bookings, the calendar and every screen still work.',
    ],
    'flashes' => [
        'staff_added' => 'Staff is now on your plan.',
        'staff_removed' => 'Staff is no longer on your plan.',
        'plan_changed' => 'Your plan has been changed.',
        'change_pending' => 'Your plan change was sent and will show here once the billing provider confirms it.',
        'change_unreadable' => 'Your plan change was sent to the billing provider, but its answer could not be read, so this page cannot show it yet. Please contact support at :address.',
        'change_unmatched' => 'Your plan change was sent to the billing provider, but its answer does not match the billing record of this business, so this page cannot show it. Please contact support at :address.',
        'already_on' => 'Your subscription is already on that plan, so nothing was changed.',
        'provider_failed' => 'The billing provider could not be reached, so your plan was not changed. Please try again.',
        'portal_failed' => 'The billing portal could not be opened. Please try again.',
        'checkout_failed' => 'The checkout could not be opened. Please try again.',
    ],
];
