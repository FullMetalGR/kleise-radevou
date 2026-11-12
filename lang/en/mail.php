<?php

/*
 * The platform's tenant-facing mails in English. Each mail's section holds its subject, its
 * sentences in the order the body reads them, and the reason its footer gives for the mail; the
 * greeting, the one layout's own string and the instant's shape sit beside them for every mail.
 * Every value may name the public name as :name, filled on every line; the reader's own name is
 * :owner, since every reader here owns the business, but for the set-password link, which any user
 * of the tenant may be sent. The zone line is the same sentence under each mail's own key, one per
 * mail, since each mail class reads its own section.
 */

return [
    // What every mail opens with.
    'greeting' => 'Hello :owner,',

    // The one layout: its footer names why the mail was sent, each mail's own reason, and the
    // platform that sent it.
    'layout' => [
        'sent_by' => 'Sent by :name.',
    ],

    // An instant in the tenant's clock: the date, with its weekday and month in the language's own
    // words from the formatter, and the time, joined here, since the word between them is the
    // language's too.
    'time' => [
        'instant' => ':date at :time',
    ],

    // The signup's verification link: the business and its address, the link, what it does and for
    // how long, the trial it starts, and what to do if the reader did not sign up. The window is a
    // choice by the link's hours and the trial by its days, each :count from the code's constant.
    'verification' => [
        'subject' => 'Confirm your email to set up your booking page',
        'confirm' => 'Confirm your email to set up :business at :address. Open this link:',
        'window' => '{1} Open it within an hour. Once your business is set up, the same link opens your sign-in page.|[2,*] Open it within :count hours. Once your business is set up, the same link opens your sign-in page.',
        'trial' => '{1} Your one-day trial starts when the link is opened, and no card is needed.|[2,*] Your :count-day trial starts when the link is opened, and no card is needed.',
        'ignore' => 'If you did not sign up, ignore this email and nothing is set up.',
        'reason' => 'You received this email because this address was used to sign up at :name.',
    ],

    // The trial's two notices: three days before the end and on its last day, each naming the end
    // in the tenant's clock, what the end pauses and what it leaves running, and the Billing
    // screen, with the zone named once at the foot. "Ends today" sent to a trial whose end has
    // already passed, before the hourly lapse moved it (P9F-51a), reads ended and what_ended in
    // place of ends and what_ends, under ended_subject in place of its subject.
    'trial' => [
        'three_days' => [
            'subject' => 'Your free trial ends soon',
            'ends' => 'The free trial for :business ends on :end.',
        ],
        'ends_today' => [
            'subject' => 'The last day of your free trial',
            'ends' => 'The free trial for :business ends in less than a day, on :end.',
            'ended' => 'The free trial for :business ended on :end.',
            'ended_subject' => 'Your free trial has ended',
        ],
        'what_ends' => 'When it ends, changes to your setup pause until you choose a plan: services, staff, opening hours and settings stay as they are. Your bookings keep running and your booking page stays open.',
        'what_ended' => 'With the trial over, changes to your setup pause until you choose a plan: services, staff, opening hours and settings stay as they are. Your bookings keep running and your booking page stays open.',
        'billing' => 'To keep changing your setup after the trial, choose a plan on the Billing screen:',
        'zone' => 'Times are in :zone, the clock you keep.',
        'reason' => 'You received this email because you own :business, an account at :name.',
    ],

    // The closing email: the business, when its trial ended, the retention window and the day it
    // ends in the tenant's clock, what keeps working until then, that the data is deleted after it
    // and nothing more of what or when, and the Billing screen as the way back, with the zone
    // named once at the foot. The subject and kept are choices by the window's days, :count from
    // the code's constant. The owner's own close from the Settings Danger zone opens with set in
    // place of ended, and a free account, whose Billing screen offers no plan, is pointed to
    // support instead. The console's close opens with console in place of ended, then because and
    // the operator's reason as it was written, and points the way back as the owner's own close
    // does. The retention sweep's close of a lapsed subscriber opens with lapsed in place of
    // ended, naming the day the subscription's last billing period ended, with lapsed_unrecorded
    // where no period was ever recorded, or with lapsed_unpaid where the first payment never went
    // through and no period was ever paid, and points the way back as the owner's own close does.
    'closing' => [
        'subject' => '{1} Your account closes in one day|[2,*] Your account closes in :count days',
        'ended' => 'The free trial for :business ended on :end and no plan was chosen, so the account is now closing.',
        'set' => 'The account for :business was set to close from its settings on :at, so it is now closing and nothing more will be charged for it.',
        'console' => 'We set the account for :business on :name to close. The close started on :at, so the account is now closing and nothing more will be charged for it.',
        'lapsed' => 'The subscription for :business ended on :end, at the close of its last billing period, and no plan has been chosen since, so the account is now closing.',
        'lapsed_unrecorded' => 'The subscription for :business ended with no billing period on record, and no plan has been chosen since, so the account is now closing.',
        'lapsed_unpaid' => 'The subscription for :business ended because its first payment never went through, and no plan has been chosen since, so the account is now closing.',
        'because' => 'The reason for the close:',
        'support' => 'To keep your account, write to us at :address before then.',
        'kept' => '{1} We keep it for one day, until :until.|[2,*] We keep it for :count days, until :until.',
        'what_stays' => 'Until then your bookings keep running and your booking page stays open, and changes to your setup stay paused. After that, the data in your account is deleted.',
        'billing' => 'To keep your account, choose a plan on the Billing screen before then:',
        'zone' => 'Times are in :zone, the clock you keep.',
        'reason' => 'You received this email because you own :business, an account at :name.',
    ],

    // The suspension's notice: the business and the instant in the tenant's clock, the operator's
    // reason as it was written, and what the pause stops and what it leaves running, with the zone
    // named once at the foot.
    'suspension' => [
        'subject' => 'Your booking page is paused',
        'paused' => 'We paused :business on :name. The pause started on :end.',
        'because' => 'The reason for the pause:',
        'what_pauses' => 'Until the pause ends, your booking page and your admin show that booking is paused, and customers cannot book, cancel or reschedule online.',
        'what_keeps' => 'Messages about bookings already made, reminders among them, keep going to your customers, and we will email you when the pause ends.',
        'zone' => 'Times are in :zone, the clock you keep.',
        'reason' => 'You received this email because you own :business, an account at :name.',
    ],

    // The resumption's notice: the business and the instant the pause ended in the tenant's clock,
    // and the booking page's address, with the zone named once at the foot.
    'resumption' => [
        'subject' => 'Your booking page is open again',
        'ended' => 'The pause on :business ended on :end. Your booking page and your admin are open again, and customers can book as before:',
        'zone' => 'Times are in :zone, the clock you keep.',
        'reason' => 'You received this email because you own :business, an account at :name.',
    ],

    // The reopen's notice: the business and the instant the console cancelled its closing in the
    // tenant's clock, that the account stays open and nothing in it is deleted, what works again,
    // and the admin's sign-in page, with the zone named once at the foot.
    'reopened' => [
        'subject' => 'Your account stays open',
        'kept' => 'The closing of the account for :business was cancelled on :at. The account stays open on :name, and nothing in it will be deleted.',
        'what_returns' => 'You can change your setup again, and your bookings and your booking page carry on as before. Sign in to your admin here:',
        'zone' => 'Times are in :zone, the clock you keep.',
        'reason' => 'You received this email because you own :business, an account at :name.',
    ],

    // The set-password link: the business and its sign-in page, the link, what it does and for how
    // long, and what to do if the reader was not expecting it. The reader is any user of the tenant
    // the console sent a link to, named by the greeting's :owner. The window reads for a reset, the
    // link the detail sends, and window_first for a first password, the link a free tenant's new
    // owner is sent, who has no password to keep; each is a choice by the link's hours, :count from
    // PasswordLinks::hours().
    'set_password' => [
        'subject' => 'Set your password',
        'choose' => 'Set a password to sign in to :business at :address. Open this link and choose one:',
        'window' => '{1} The link works once, within an hour. Until it is used, nothing changes: any password you already have keeps working.|[2,*] The link works once, within :count hours. Until it is used, nothing changes: any password you already have keeps working.',
        'window_first' => '{1} The link works once, within an hour.|[2,*] The link works once, within :count hours.',
        'ignore' => 'If you were not expecting this email, you can ignore it.',
        'reason' => 'You received this email because a link to set your password at :business was sent from :name.',
    ],

    // The welcome email: sent once when the verification click builds the trial. The business, the
    // trial's end in the tenant's clock, the sign-in address and the booking page's, three first
    // steps, the set-up offer naming the contact address, and the zone named once at the foot. The
    // hours are the second step and name no screen: during the trial every feature is open, so the
    // opening hours are set on the Staff screen, and on a plan without staff in Settings.
    'welcome' => [
        'subject' => 'Welcome to :name: :business is set up',
        'ready' => ':business is set up. Your free trial runs until :end, with every feature included and no card needed.',
        'sign_in' => 'Sign in to your admin here:',
        'page' => 'Your booking page, the address to give your customers:',
        'steps' => 'Three steps to get started:',
        'step_services' => '1. Add your services, each with its duration and price.',
        'step_hours' => '2. Set your opening hours.',
        'step_share' => '3. Send your customers the link to your booking page, or paste the snippet from Settings into your website.',
        'offer' => 'Would you like us to set it up with you? Write to :contact and we will arrange it.',
        'zone' => 'Times are in :zone, the clock you keep.',
        'reason' => 'You received this email because :business was set up at :name with this address.',
    ],
];
