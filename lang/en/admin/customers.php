<?php

/*
 * The Customers screens and the manual booking wizard in English: the Customers list, a customer's
 * page with its tiles, its Export and its Danger zone, shared as the strings prop's customers
 * section, which CustomerController names, and the wizard's group, which the shared props hand
 * beside the wizard's catalog to every page of a user who may book by hand, in the language of the
 * request, the user's. The wizard sits here and not in bookings.php so that its Greek is written
 * with this file's. Its Greek twin is lang/el/admin/customers.php.
 *
 * page is the list's title and heading, the search's label and its hint by whether it reads the
 * email, the empty list's two lines, the search that matches nothing, a list that did not load
 * and a customer that could not be opened.
 *
 * table is the list's card: the count of the rows shown (:count) out of every match (:total), the
 * column heads, the other names an address booked under (:names, the names as the customers typed
 * them), a last visit where none is yet and the chip of an address blocked from booking online.
 *
 * csv is the list's Download CSV: the button, its busy word and its failure, and the file's own
 * words beside the table's: its title where a search narrows it (:search, as typed), the other
 * names' column head and the Blocked column's two answers.
 *
 * customer is a customer's page: the breadcrumb's name, the bookings that did not load, a page of
 * no bookings, Book now and a list that could not be reached again. tiles is its card At a glance:
 * the five figures, the Spent line (:amount, the sums in each currency) or its line where nothing
 * is spent, and the sentence saying what Book now fills in. profile is its Profile card: the
 * title, the three fields with the tags' help and the language's three choices, Save and its busy
 * word, and the failure. notes is its Notes card: the title, the New note field and its help, Add
 * note and its busy word, a note's byline (:author the name it was written under, :when its day
 * and time), Remove with its confirm, and the two failures. block is its Online booking card: the
 * title, the open state's sentence, the blocked state's (:when the block's day and time) and its
 * line where no reason was given, the Reason field and its help, Block online booking with its
 * confirm, Unblock, and the failure.
 *
 * export is the Export button, its busy word and its failure; erasure is the Danger zone: its
 * title, what an erasure takes and keeps, the button, the confirm's title, button, busy word and
 * pending line, its body as a choice by how many bookings lose the person (:count), the failure,
 * and the dialog that refuses while a booking is live, its title and Close.
 *
 * wizard is the manual booking wizard: the title, the footer's buttons, the three steps and a
 * step's place in the strip (:n the step's figure, :step its name), the strip's first answer where
 * the chain holds more than one service (:service the first, :count the rest), a day and a time
 * together (:date, :time), the services step, the clock the times are in (:zone), the times' three
 * periods, which stay identifiers in code, the summary with a time span (:time to :end) and a
 * service with its member (:service, :staff), the customer's fields, and the create's refusal
 * where the server worded none, or with the segment it named (:sentence the server's, :n the
 * segment's figure, :service its service).
 *
 * validation, refusals and flashes are the server's: the Profile card's three refusals (the tags'
 * read as a choice by the most tags, :count, with :n the most characters of one; the birthday's
 * :year the earliest birthday's year, CustomerProfileRequest::EARLIEST; the language's naming no
 * option), the note's and the reason's (each a choice by its bound, :count), the erasure refused
 * while bookings are live (:count), and the erasure's, the manual booking's, the profile's, the
 * notes' and the block's flashes.
 *
 * Three keys are read as choices, by trans_choice on the server and by the admin's mirror of it,
 * choose(), on the client, and their Greek keeps the choice syntax: erasure.count and
 * refusals.live by the bookings counted, and wizard.first_more by the services after the first;
 * three more on the server alone, validation.tags, validation.note and validation.reason, by
 * their bounds. Every other key is read as plain text.
 */

return [
    'page' => [
        'title' => 'Customers',
        'search' => 'Search',
        'hint_contact' => 'Name or email',
        'hint_name' => 'Name',
        'none' => 'No customers yet',
        'none_sentence' => 'Everyone who books appears here, one row for each email address, with every booking made under it.',
        'no_match' => 'No customers match this search.',
        'list_error' => 'The customers could not be loaded. Check your connection and change the search to try again.',
        'open_error' => 'The customer could not be opened. Check your connection and press their name again.',
    ],
    'table' => [
        'count' => ':count of :total',
        'name' => 'Name',
        'email' => 'Email',
        'phone' => 'Phone',
        'first_visit' => 'First visit',
        'last_visit' => 'Last visit',
        'bookings' => 'Bookings',
        'also_booked_as' => 'Also booked as :names',
        'no_visit' => 'No visit yet',
        'blocked' => 'Blocked',
    ],
    'csv' => [
        'download' => 'Download CSV',
        'downloading' => 'Downloading',
        'download_error' => 'The customers could not be downloaded. Check your connection and try again.',
        'searched' => 'Customers matching :search',
        'also_booked_as' => 'Also booked as',
        'yes' => 'Yes',
        'no' => 'No',
    ],
    'customer' => [
        'breadcrumb' => 'Breadcrumb',
        'bookings_error' => 'The bookings could not be loaded. Check your connection and change the page to try again.',
        'bookings_empty' => 'No bookings on this page.',
        'book_now' => 'Book now',
        'leave_error' => 'The customers could not be loaded. Check your connection and press Customers again.',
    ],
    'tiles' => [
        'title' => 'At a glance',
        'upcoming' => 'Upcoming',
        'completed' => 'Completed',
        'no_shows' => 'No-shows',
        'cancelled' => 'Cancelled',
        'last_visit' => 'Last visit',
        'spent' => 'Spent :amount.',
        'spent_none' => 'Nothing spent yet.',
        'book_now_help' => 'Book now opens the wizard with this customer\'s name, email and phone filled in.',
    ],
    'profile' => [
        'title' => 'Profile',
        'tags' => 'Tags',
        'tags_help' => 'A few words, with a comma between: VIP, regular, colour.',
        'birthday' => 'Birthday',
        'language' => 'Preferred language',
        'not_recorded' => 'Not recorded',
        'greek' => 'Greek',
        'english' => 'English',
        'save' => 'Save',
        'saving' => 'Saving',
        'error' => 'The profile could not be saved. Check your connection and press Save again.',
    ],
    'notes' => [
        'title' => 'Notes',
        'new' => 'New note',
        'help' => 'Seen by the staff only.',
        'add' => 'Add note',
        'adding' => 'Adding',
        'byline' => ':author, :when',
        'remove' => 'Remove',
        'remove_label' => 'Remove the note by :author, :when',
        'confirm_title' => 'Remove this note?',
        'confirm_body' => 'The note is removed for the whole staff. This cannot be undone.',
        'confirm' => 'Remove note',
        'removing' => 'Removing',
        'pending' => 'The note is being removed.',
        'add_error' => 'The note could not be added. Check your connection and press Add note again.',
        'remove_error' => 'The note could not be removed. Check your connection and try again.',
    ],
    'block' => [
        'title' => 'Online booking',
        'open' => 'This address can book online.',
        'since' => 'Online booking blocked since :when.',
        'reason' => 'Reason',
        'reason_help' => 'For the staff; the customer sees only that booking online is not available.',
        'no_reason' => 'No reason recorded.',
        'block' => 'Block online booking',
        'confirm_title' => 'Block online booking?',
        'confirm_body' => 'This address can no longer book on the booking page; it is told only that booking online is not available. Book now still books it.',
        'confirm' => 'Block',
        'blocking' => 'Blocking',
        'pending' => 'Online booking is being blocked.',
        'unblock' => 'Unblock',
        'unblocking' => 'Unblocking',
        'error' => 'Online booking could not be changed. Check your connection and try again.',
    ],
    'export' => [
        'label' => 'Export',
        'busy' => 'Exporting',
        'error' => 'The customer\'s data could not be exported. Check your connection and try again.',
    ],
    'erasure' => [
        'title' => 'Danger zone',
        'what' => 'Erasing takes the customer\'s name, email address, phone and anything typed into the booking form off every booking they made, and keeps the bookings as records with their services and times.',
        'erase' => 'Erase this customer',
        'confirm_title' => 'Erase this customer?',
        'confirm' => 'Erase customer',
        'busy' => 'Erasing',
        'pending' => 'The customer is being erased.',
        'count' => '{1} 1 booking will lose this customer\'s name, email address, phone and anything typed into the booking form. It stays as a record with its services and times. This cannot be undone.|[2,*] :count bookings will lose this customer\'s name, email address, phone and anything typed into the booking form. They stay as records with their services and times. This cannot be undone.',
        'error' => 'The customer could not be erased. Check your connection and try again.',
        'refused_title' => 'This customer cannot be erased yet',
        'close' => 'Close',
    ],
    'wizard' => [
        'title' => 'New booking',
        'back' => 'Back',
        'cancel' => 'Cancel',
        'continue' => 'Continue',
        'create' => 'Create',
        'creating' => 'Creating...',
        'steps' => [
            'services' => 'Services',
            'when' => 'Date and time',
            'customer' => 'Customer',
        ],
        'step' => ':n. :step',
        'first_more' => '{1} :service + 1 more|[2,*] :service + :count more',
        'when' => ':date :time',
        'segment' => 'Segment :n',
        'service' => 'Service',
        'choose_service' => 'Choose a service',
        'staff' => 'Staff',
        'any_staff' => 'Any staff',
        'remove' => 'Remove',
        'add_segment' => 'Add segment',
        'same_staff' => 'Prefer the same staff member for the whole chain',
        'zone' => 'Times in :zone',
        'loading' => 'Loading times...',
        'no_times' => 'No open times on this day, pick another on the calendar',
        'unreadable' => 'The open times could not be read. Reload the page and try again.',
        'periods' => [
            'morning' => 'Morning',
            'afternoon' => 'Afternoon',
            'evening' => 'Evening',
        ],
        'summary' => [
            'date' => 'Date',
            'time' => 'Time',
            'service' => 'Service',
            'span' => ':time - :end',
            'service_staff' => ':service, :staff',
        ],
        'fields' => [
            'name' => 'Customer name',
            'email' => 'Email',
            'phone' => 'Phone',
        ],
        'create_failed' => 'The booking could not be created. Check your connection and press Create again.',
        'refused_segment' => ':sentence (segment :n)',
        'refused_segment_service' => ':sentence (segment :n, :service)',
    ],
    'validation' => [
        'tags' => '{1} Write at most :count tag of at most :n characters, with a comma between.|[2,*] Write at most :count tags of at most :n characters each, with a comma between.',
        'birthday' => 'Choose a birthday between :year and today, or leave it empty.',
        'language' => 'Choose a language from the list.',
        'note' => '{1} Write a note of at most :count character.|[2,*] Write a note of at most :count characters.',
        'reason' => '{1} Write a reason of at most :count character, or leave it empty.|[2,*] Write a reason of at most :count characters, or leave it empty.',
    ],
    'refusals' => [
        'live' => '{1} This customer has 1 booking that is not over yet. Cancel it first, then erase the customer.|[2,*] This customer has :count bookings that are not over yet. Cancel them first, then erase the customer.',
    ],
    'flashes' => [
        'erased' => 'The customer has been erased.',
        'booked' => 'Booking created.',
        'profile_saved' => 'Profile saved.',
        'note_added' => 'Note added.',
        'note_removed' => 'Note removed.',
        'blocked' => 'Online booking blocked.',
        'unblocked' => 'Online booking unblocked.',
    ],
];
