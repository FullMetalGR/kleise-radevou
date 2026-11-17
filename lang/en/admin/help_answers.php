<?php

/*
 * The help panel's answers in English, sent with the panel only when it opens, in the language of
 * the request, the user's. Its Greek twin is lang/el/admin/help_answers.php, which holds the same
 * topics and the same answers under the same keys in the same order.
 *
 * Each key at the top is a topic, in the order the panel shows them: its title, and its answers in
 * the order they show under it, each keyed, with its question and its answer as a list of short
 * paragraphs, one to three. No value is empty and none holds markup: the panel shows each as
 * plain text.
 *
 * The topics, in their order, are getting_started, bookings, calendar, closures_and_hours,
 * staff_and_shifts, services, booking_page, payments, billing and team, two answers each. An
 * answer names a screen, a button or a status as the admin's own words in this language name it,
 * says when only an owner or a manager can do a thing, and says when it depends on the plan.
 */

return [
    'getting_started' => [
        'title' => 'Getting started',
        'answers' => [
            'setup' => [
                'question' => 'How do I set up my business?',
                'answer' => [
                    'The first time an owner opens Home, a setup wizard opens by itself. Its steps are Business profile, Services, Team, Opening hours, Online payments and Booking link. The Team step shows only when Staff is on your plan, as it is on the Team plan and during the free trial.',
                    'You can close the wizard and come back to it. Until setup is finished, Home shows a banner with how many steps are done, and Continue opens the wizard again. Team and Online payments can be skipped with Skip for now. Finish on the Booking link step ends the setup once every other step is done or skipped.',
                    'Only an owner sees the wizard and its banner. Everything set in it can be changed later on its own screen.',
                ],
            ],
            'ask_us' => [
                'question' => 'How do I contact support or open a ticket?',
                'answer' => [
                    'Press the Help button, the round question mark in the bottom-right corner, and search the answers first. If none helps, press Open a ticket under the answers, write a subject and a message, and press Send. You find the ticket again under Tickets.',
                    'Everyone signed in to your business sees all of its tickets and can reply in any of them. When we reply, a dot shows on the Help button until you open the ticket. A reply from your side reopens a closed ticket.',
                    'Tickets are not available on the demo account.',
                ],
            ],
        ],
    ],
    'bookings' => [
        'title' => 'Bookings and their statuses',
        'answers' => [
            'statuses' => [
                'question' => 'What does each booking status mean?',
                'answer' => [
                    'Pending means a customer has chosen a time and is still finishing the booking, paying online included. Awaiting approval is a request that waits for a decision. Awaiting payment is an approved request whose customer has been emailed a link to pay. In these three, the time is held for the booking.',
                    'Confirmed is a booking that stands. Once its time is over, an owner or a manager marks it with Completed or No-show, on the booking itself or in To mark on Home.',
                    'Cancelled is a booking the business or the customer cancelled. Rejected is a request that was turned down. Expired is a booking whose hold ran out: the customer did not finish or did not pay in time, or nobody decided a request in time and its service is set to expire it.',
                ],
            ],
            'requests' => [
                'question' => 'How do I approve or reject a booking request?',
                'answer' => [
                    'A service set to Requires approval sends each online booking to you as a request, in Awaiting approval. The Bookings entry in the menu shows how many wait, and Approval inbox on the Bookings screen lists them.',
                    'Open the booking and press Approve or Reject. A reason for a rejection is optional: it is kept with the booking for your team, and the customer is not shown it. An approved booking becomes Confirmed, or Awaiting payment when the service is paid online and a payment provider is connected.',
                    'A request waits for the Approval hold set on its service, or the one in Settings, under Booking rules, when the service leaves it empty. Then the service\'s rule applies: Expire the request, or Approve it automatically. Owners and managers decide every request. A staff member has no Bookings screen: they open the request on their own calendar and press Approve or Reject there.',
                ],
            ],
        ],
    ],
    'calendar' => [
        'title' => 'The calendar',
        'answers' => [
            'views' => [
                'question' => 'How do I switch the calendar between the day, week and month views?',
                'answer' => [
                    'The Calendar opens on Day and today. Switch between Day, Week and Month, and move with Today, Previous and Next.',
                    'Week shows a row per member and a column per day, each day listing its first six bookings and how many more; with one member shown, it is their time grid. Month lists each day\'s first two bookings and how many more. Week needs a wider screen, so on a phone use Day.',
                    'Who picks whose bookings show: Scheduled staff (everyone with hours on that day), All staff, Me, or the members you pick. A staff member\'s own sign-in opens on Me and cannot change it.',
                ],
            ],
            'add_and_move' => [
                'question' => 'How do I add a booking or move one to another time?',
                'answer' => [
                    'In the Calendar, press Add, then New booking, or press New booking at the top of any screen. You can also drag or click an empty slot in the calendar to start a booking there. Choose the services, then the date and time, then the customer, and press Create.',
                    'To move a booking, drag it with the mouse to a new time in the calendar, or open it and press Move. Free times come first. A time outside working hours or on a closure can be chosen too, after a warning. Tick Tell the customer to email them the new time; unticked, they are sent nothing about the move.',
                    'Only owners and managers can add or move bookings.',
                ],
            ],
        ],
    ],
    'closures_and_hours' => [
        'title' => 'Closures and hours',
        'answers' => [
            'opening_hours' => [
                'question' => 'How do I set my opening hours or working hours?',
                'answer' => [
                    'When Staff is not on your plan, set them in Settings, under Opening hours. Press Add window on each day you are open; a day with no windows is a closed day. Then press Save opening hours.',
                    'When Staff is on your plan, each staff member has their own hours, set on the Shifts screen, and Settings has no Opening hours section. If Staff stops being active while more than one member is active, Settings shows their hours read-only, and bookings keep using them.',
                    'Customers can book online only inside these hours. Only owners and managers can change them.',
                ],
            ],
            'closure' => [
                'question' => 'How do I close a day, take time off or block time?',
                'answer' => [
                    'In the Calendar, press Add, then Closure. Under Who, choose The whole business or one member. Then set the Date, and Until for a closure of more than one day. Tick Closed all day, or give a start and an end. The reason is optional and is a note for you.',
                    'You can also add a closure on the Staff screen, under Closures, or on the Closures screen when Staff is not on your plan. A member\'s day menu in the calendar has Closure here as well.',
                    'If bookings fall inside the closure, you choose what happens to them: Add and keep them, Add and move them, which lists them so you can move each one, or the button that adds the closure and cancels them. Each cancelled customer is emailed, with the reason you typed. Only owners and managers can add closures.',
                ],
            ],
        ],
    ],
    'staff_and_shifts' => [
        'title' => 'Staff and shifts',
        'answers' => [
            'add_member' => [
                'question' => 'How do I add a staff member?',
                'answer' => [
                    'On the Staff screen, press New staff member. Fill in their details, tick the services they perform under Services performed, and press Save. Their hours are set on the Shifts screen.',
                    'Linked user ties a staff member to a user of the business who signs in as them, so that they see their own calendar; most staff need none. To stop someone being booked, open their page on the Staff screen, set Status to Inactive and press Save, rather than deleting them.',
                    'More than one staff member needs Staff on your plan, as on the Team plan and during the free trial. Only owners and managers can add or change staff.',
                ],
            ],
            'shifts' => [
                'question' => 'How do I change a staff member\'s shifts or working hours?',
                'answer' => [
                    'Open the Shifts screen. It shows each member\'s hours week by week; This week, Previous week and Next week move between weeks.',
                    'Press Pattern on a member\'s row to set the week that repeats: Every week or every few weeks, from Starts on, and when it Ends. Press Save pattern. Days already changed on their own keep their hours.',
                    'Press a day\'s cell to change that day alone: give it its windows, or choose Not working this day, and press Save. Time off is a closure for that member, added on the Staff screen or in the Calendar. Shifts needs Staff on your plan, and only owners and managers can change it.',
                ],
            ],
        ],
    ],
    'services' => [
        'title' => 'Services',
        'answers' => [
            'add_service' => [
                'question' => 'How do I add a service and set its price and duration?',
                'answer' => [
                    'On the Services screen, press New service. Fill in Name, Duration and the price, and under Payment mode choose Free, Online or On site. Untick Offered online to keep it for bookings you add yourself.',
                    'When Staff is on your plan, tick who performs it under Staff who perform this. Then press Save.',
                    'To stop offering a service, open it on the Services screen, set Status to Inactive and press Save, rather than deleting it. Only owners and managers can add or change services.',
                ],
            ],
            'booking_rules' => [
                'question' => 'How far ahead can customers book, and until when can they cancel?',
                'answer' => [
                    'Each service sets its own Booking rules. Lead time is how far ahead of a time customers booking online must book it. Booking horizon is how far into the future they can book.',
                    'Cancellation window is how long before the start a customer can still cancel online, and Reschedule window how long before it they can still move the booking online.',
                    'Requires approval sends each online booking to you as a request first. Settings, under Booking rules, holds the rules of the whole business: the checkout, approval and payment holds, the reminder and how often start times are offered. Only owners and managers can change these rules.',
                ],
            ],
        ],
    ],
    'booking_page' => [
        'title' => 'The booking page and link',
        'answers' => [
            'share_link' => [
                'question' => 'Where do I find my booking link, QR code or website embed?',
                'answer' => [
                    'In Settings, open Booking page. Under Links you find Your booking page, with Copy link and Download QR, and a link for each service and each staff member that is active and offered online, which opens the booking page with them already chosen.',
                    'Copy booking link on a service\'s or a staff member\'s own page copies the same link. Embed snippet, with Copy snippet, is the code to paste into your own website.',
                    'Settings is open to owners and managers.',
                ],
            ],
            'page_content' => [
                'question' => 'How do I change what my booking page shows?',
                'answer' => [
                    'In Settings, under Booking page, set the Theme, the Logo, the About text, your address, Phone, Public email, Website and social links, Important info, a Cover photo and a Gallery. Then press Save.',
                    'Each service shows its Description and is listed under its Category, set on the Services screen. Each staff member shows their Title, Bio and Photo, set on their page on the Staff screen.',
                    'Untick Offered online on a service or a staff member, and customers cannot choose it online. Only owners and managers can change what the booking page shows.',
                ],
            ],
        ],
    ],
    'payments' => [
        'title' => 'Payments',
        'answers' => [
            'online_payments' => [
                'question' => 'How do I take payments online?',
                'answer' => [
                    'In Settings, open Payments and connect a payment provider with Use your own credentials or, where it is offered, with Connect through us. Then set a service\'s Payment mode to Online.',
                    'Until a provider is connected, online services take bookings and collect payment in person. Paying on site needs no connection.',
                    'Only owners and managers can connect or change payments. Online payments are part of every plan.',
                ],
            ],
            'paying_and_refunds' => [
                'question' => 'When does a customer pay, and how do I refund a payment?',
                'answer' => [
                    'Once a payment provider is connected, a customer booking a service paid online pays while booking, and the booking stays Pending until the payment goes through. If it does not go through within the Checkout hold, the booking becomes Expired.',
                    'When the service needs approval, the customer pays after you approve: they are emailed a payment link, and the booking is Awaiting payment until they pay. The link stays open for the Payment hold set in Settings, under Booking rules.',
                    'Refunds are not made here. Cancelling a paid booking refunds nothing: refund the payment in your payment provider\'s own dashboard.',
                ],
            ],
        ],
    ],
    'billing' => [
        'title' => 'Billing and the plan',
        'answers' => [
            'plans' => [
                'question' => 'Which plan do I need, and how do I change it?',
                'answer' => [
                    'Solo is for one staff member. Team adds Staff: any number of staff members, each with their own hours, services, calendar and approvals. Both include services, bookings, the calendar, your booking page, settings and email notifications.',
                    'On the Billing screen, Add Staff moves you to Team and Remove Staff moves you back to Solo. Staff can be removed only while one staff member is active, so deactivate the others on the Staff screen first. Manage payment method and invoices opens your card and your invoices.',
                    'Only an owner sees the Billing screen.',
                ],
            ],
            'trial_and_lapse' => [
                'question' => 'What happens when my free trial ends or a payment fails?',
                'answer' => [
                    'To keep everything open, an owner chooses a plan on the Billing screen before the trial ends. If not, setup changes pause: services, staff, shifts, closures, opening hours and settings stay as they are.',
                    'Bookings keep running while setup changes are paused. Customers can still book, pay, cancel and reschedule, and you can still add bookings and approve, reject, move, cancel, complete or mark them no-show.',
                    'If a subscription payment does not go through, nothing pauses during a grace period: an owner updates the payment method on the Billing screen before it ends. After a lapse, an owner subscribes again on the Billing screen to resume setup changes.',
                ],
            ],
        ],
    ],
    'team' => [
        'title' => 'The team and roles',
        'answers' => [
            'invite' => [
                'question' => 'How do I invite someone to sign in to my business?',
                'answer' => [
                    'On the Team screen, under Invite someone, enter their Name and Email, choose a Role, and press Send invitation. They are emailed a link to set their password, which works once and for a limited time.',
                    'Send sign-in link on a member\'s row emails them a new link, for example when they have forgotten their password. You can change a member\'s Role in the list. Remove takes away their sign-in; a staff row linked to them stays, with its hours and its bookings.',
                    'To give a staff member their own calendar, invite them with the Staff role, then choose their user under Linked user, on the member\'s page on the Staff screen. Only an owner sees the Team screen. The business needs at least one owner, and you cannot remove yourself.',
                ],
            ],
            'roles' => [
                'question' => 'What can an owner, a manager or a staff member do?',
                'answer' => [
                    'An owner runs everything, billing included. Only an owner sees the Billing and Team screens.',
                    'A manager runs the calendar and the bookings, the services, staff, shifts, closures and settings, and sees Customers and Reports.',
                    'A staff member sees only their own calendar, once their sign-in is linked to their staff row, and approves or rejects the requests on it. They cannot add, move, cancel or mark bookings.',
                ],
            ],
        ],
    ],
];
