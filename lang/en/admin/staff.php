<?php

/*
 * The Staff screen in English: the list, the floor's closures, the member's form and read-only
 * page, the closures card and the server's sentences about them, shared as the strings prop's staff
 * section, which StaffController names on the list, the create route and a member's page, in the
 * language of the request, the user's. Staff names, titles, bios and closure reasons are the
 * tenant's own text and never pass through here. Its Greek twin is lang/el/admin/staff.php.
 *
 * page is the screen: the list's title and heading, the floor's heading (the screen with the
 * closures card alone), the Shifts button, New staff member, the inactive toggle with the count of
 * inactive members (:count), the empty list's two lines, a persisted row's title, the loading
 * label, the list that did not load, an id the list does not hold with its link back to the list,
 * the floor's closures card's title (closed_days), the list's (closures) and a member's exceptions
 * card's title, the lead line under the title, the Search field's label and placeholder and the
 * line where it matches nobody, the four writes that did not reach the server, the three sentences
 * that hold the other controls while a write is in flight (wait_*), and the staff photo's four
 * words (photo_*).
 *
 * table is the list card: its title, the count of the rows shown (:count), the six column heads
 * (job_title is the Title column), and Hours this week's line, one whole line per case the column
 * shows, :hours the figure, which the screen sets as its link to Shifts: every week where the week
 * is the pattern's own (hours_weekly), this week otherwise (hours_this_week), and each with the
 * week's closures (hours_weekly_closed, hours_this_week_closed), :closures the closures as a list
 * in the language, each closed_window (:day the weekday's short name, :time to :end) or the weekday
 * alone.
 *
 * figure is a count of hours with minutes, :hours and :minutes each a figure with its unit as ICU
 * spells it in the language (lib/names.ts unitFigure); a whole figure of hours is ICU's alone, as
 * the Shifts screen spells its own (admin/shifts.php page.hours_minutes).
 *
 * pattern is Weekly availability's read-only line: no pattern, a pattern every week, one every
 * :weeks weeks from :date (the pattern's start in the tenant's date format), :hours the usual
 * week's figure, and the link to Shifts beside the line, where the pattern is edited.
 *
 * statuses are the Status select's options, by the value sent.
 *
 * form is the member's page: the breadcrumb's label and its link back, the three sections' titles,
 * every field's label and help, a linked user as the combobox lists them (:user the name, :address
 * the email), the bio's count (:count of :max characters), the photo's help (:n the largest file's
 * megabytes in words, by StaffPhotoRequest::MAX_KILOBYTES), an empty catalog, an inactive service
 * (:service), Save, its busy word and why it is disabled.
 *
 * danger is the Danger zone: its title, the delete with its busy word and the remedy with its; the
 * implicit member's zone reads refusals.implicit.
 *
 * view is the read-only page without the staff feature: the facts' labels and the words for a fact
 * with no value, plus the empty exceptions.
 *
 * closures is the closures card: the empty list, a partial closure's date and window (:date, :time
 * to :end, each in the tenant's formats), Delete with its busy word, the two sentences that hold
 * the other controls while a write is in flight, and the add form's fields and Add with its busy
 * word. Date, Reason (optional), Closed all day, Start and End repeat the Calendar's closure
 * dialog (admin/calendar.php closure.*) for this screen; the reason's help is the shell's shared
 * closure.reason_help, read by both. Who with Whole business, Until
 * (optional) with its help, a folded run's dates (:date to :until) and the line under the dates,
 * :who the member's name or Whole business and :reason the tenant's own note.
 *
 * colours is the Calendar colours card: its title, Colour bookings by with its three choices (the
 * Calendar's filters.colour_by_choices repeated for this screen) and its help, the line over the
 * members' choices, Automatic and the palette's colours by the word stored, and the sentences while
 * a write is in flight and where one did not reach the server.
 *
 * validation is StaffRequest's, BlackoutRequest's, StaffColourRequest's and StaffServicesRequest's
 * (:n the title's or the bio's longest, from the columns; the colour's :count the palette's
 * colours, StaffColour's cases spelled in words by ICU, and its :automatic colours.automatic);
 * refusals are the links route's unknown service, which StaffRequest adds to the errors bag too,
 * and the delete's two 409s; flashes are the ten writes' answers: five of the member, two of a
 * closure, two of the photo and the member's colour.
 *
 * Three keys are read as choices, by the admin's mirror of the translator's choice, choose(), on
 * the client, and their Greek keeps the choice syntax: table.count by the rows shown,
 * form.bio_count by the characters written, and form.photo_help by the megabytes. Every other key
 * is read as plain text.
 */

return [
    'page' => [
        'title' => 'Staff',
        'floor_title' => 'Closures',
        'shifts' => 'Shifts',
        'new' => 'New staff member',
        'show_inactive' => 'Show inactive staff (:count)',
        'none' => 'No active staff',
        'none_sentence' => 'Every staff member is deactivated. Tick Show inactive staff above to see them.',
        'detail_title' => 'Staff member',
        'loading' => 'Loading the staff',
        'list_error' => 'The staff could not be loaded. Check your connection and try again.',
        'not_found' => 'There is no staff member with this id. They may have been deleted.',
        'back' => 'Back to the list',
        'closed_days' => 'Closed days',
        'closures' => 'Closures',
        'lead' => 'A member\'s hours are set on the Shifts screen, week by week; their page here holds who they are and what they do.',
        'search' => 'Search',
        'search_placeholder' => 'Name, email or title',
        'no_match' => 'No staff member matches the search.',
        'exceptions' => 'Exceptions for this staff member',
        'save_error' => 'The staff member could not be saved. Check your connection and try again.',
        'delete_error' => 'The staff member could not be deleted. Check your connection and try again.',
        'closure_error' => 'The closure could not be changed. Check your connection and try again.',
        'photo_error' => 'The photo could not be changed. Check your connection and try again.',
        'wait_save' => 'Please wait until the staff member has been saved.',
        'wait_photo_upload' => 'Please wait until the photo has been uploaded.',
        'wait_photo_remove' => 'Please wait until the photo has been removed.',
        'photo_none' => 'No photo yet. The booking page shows their initials instead.',
        'photo_upload' => 'Upload photo',
        'photo_replace' => 'Replace photo',
        'photo_remove' => 'Remove photo',
    ],
    'table' => [
        'title' => 'Staff',
        'count' => '{1} :count shown|[2,*] :count shown',
        'id' => 'ID',
        'name' => 'Name',
        'email' => 'Email',
        'status' => 'Status',
        'job_title' => 'Title',
        'hours' => 'Hours this week',
        'hours_weekly' => ':hours, every week',
        'hours_this_week' => ':hours, this week',
        'hours_weekly_closed' => ':hours, every week, closed :closures',
        'hours_this_week_closed' => ':hours, this week, closed :closures',
        'closed_window' => ':day :time-:end',
    ],
    'figure' => ':hours :minutes',
    'pattern' => [
        'none' => 'No repeating pattern.',
        'weekly' => ':hours a week, every week.',
        'cadence' => ':hours a week, every :weeks weeks from :date.',
        'link' => 'Set on Shifts',
    ],
    'statuses' => [
        'active' => 'Active',
        'inactive' => 'Inactive',
    ],
    'form' => [
        'breadcrumb' => 'Breadcrumb',
        'list' => 'Staff',
        'basics' => 'Basics',
        'services' => 'Services performed',
        'weekly' => 'Weekly availability',
        'name' => 'Name',
        'email' => 'Email',
        'email_help' => 'Where this person\'s own notifications go. Not a login.',
        'user' => 'Linked user',
        'user_help' => 'A user of this business who signs in as this person. Most staff need none.',
        'user_option' => ':user (:address)',
        'status' => 'Status',
        'online' => 'Offered online',
        'online_help' => 'Customers can choose this person online. Untick to keep them for bookings you add yourself.',
        'implicit_online_help' => 'Untick to keep this member, who stands for the business, for bookings you add yourself. Customers then cannot book them online, and a service only they perform shows no times on the booking page.',
        'title' => 'Title',
        'title_help' => 'A short line shown under their name on the booking page, such as Senior stylist.',
        'bio' => 'Bio',
        'bio_help' => 'A few sentences about this person, which customers open with View profile on the booking page.',
        'bio_count' => '{1} :count of :max characters|[2,*] :count of :max characters',
        'photo' => 'Photo',
        'photo_help' => '{1} A PNG, JPEG or WebP up to :n megabyte, shown beside their name on the booking page and the business page.|[2,*] A PNG, JPEG or WebP up to :n megabytes, shown beside their name on the booking page and the business page.',
        'no_services' => 'No services yet.',
        'inactive_service' => ':service (inactive)',
        'save' => 'Save',
        'saving' => 'Saving',
        'needs_name' => 'A staff member needs a name before they can be saved.',
    ],
    'danger' => [
        'title' => 'Danger zone',
        'delete' => 'Delete this staff member',
        'deleting' => 'Deleting',
        'deactivate' => 'Deactivate them instead',
        'deactivating' => 'Deactivating',
    ],
    'view' => [
        'email' => 'Email',
        'user' => 'Linked user',
        'online' => 'Offered online',
        'title' => 'Title',
        'bio' => 'Bio',
        'photo' => 'Photo',
        'yes' => 'Yes',
        'no' => 'No',
        'none' => 'None',
        'no_closures' => 'No closures on record.',
    ],
    'closures' => [
        'none' => 'No closures on record.',
        'window' => ':date :time-:end',
        'delete' => 'Delete',
        'deleting' => 'Deleting',
        'wait_removing' => 'The other closures and Add wait until this one has been removed.',
        'wait_adding' => 'Delete waits until the closure has been added.',
        'date' => 'Date',
        'reason' => 'Reason (optional)',
        'all_day' => 'Closed all day',
        'start' => 'Start',
        'end' => 'End',
        'add' => 'Add',
        'adding' => 'Adding',
        'who' => 'Who',
        'business' => 'Whole business',
        'until' => 'Until (optional)',
        'until_help' => 'The last day of a closure running more than one day. Leave it blank for one day.',
        'run' => ':date to :until',
        'who_reason' => ':who, :reason',
    ],
    'colours' => [
        'title' => 'Calendar colours',
        'by' => 'Colour bookings by',
        'by_choices' => [
            'staff' => 'Staff member',
            'category' => 'Service category',
            'status' => 'Status',
        ],
        'by_help' => 'The Day view puts each member in their own column, so a colour can say something else there: the service\'s category, or the booking\'s status. Week and Month keep the same choice.',
        'members_help' => 'By staff member: each picks a colour for their bookings. The automatic choice spreads the palette over the team.',
        'automatic' => 'Automatic',
        'names' => [
            'orange' => 'Orange',
            'sky_blue' => 'Sky blue',
            'green' => 'Green',
            'yellow' => 'Yellow',
            'blue' => 'Blue',
            'vermilion' => 'Vermilion',
            'purple' => 'Purple',
        ],
        'saving' => 'Saving the colours.',
        'error' => 'The colours could not be saved. Check your connection and try again.',
    ],
    'validation' => [
        'name' => 'A staff member needs a name.',
        'email' => 'The email is an address, such as name@example.com, or empty.',
        'user_id' => 'The linked user is named by its id, or empty.',
        'user_exists' => 'The linked user must be a user of this business.',
        'status' => 'The status is active or inactive.',
        'online' => 'Offered online is yes or no.',
        'title_text' => 'The title is a short line, such as Senior stylist, or empty for none.',
        'title_length' => 'The title is at most :n characters.',
        'bio_text' => 'The bio is a few sentences about the person, or empty for none.',
        'bio_length' => 'The bio is at most :n characters.',
        'service_ids' => 'The services a staff member performs are a list of service ids.',
        'service_id' => 'Each service is named by its id.',
        'rules_elsewhere' => 'A staff member\'s hours are set on Shifts, not on their page.',
        'closure_date' => 'A closure needs a date, such as 2026-12-25.',
        'closure_window' => 'A partial closure has both a start and an end, times of day such as 14:00.',
        'closure_order' => 'A closure\'s start is before its end.',
        'closure_reason' => 'The reason is a note in words.',
        'closure_until_date' => 'Until is a date, such as 2026-12-31, or empty for a closure of one day.',
        'closure_until_order' => 'Until is on or after the closure\'s date.',
        'closure_until_span' => 'A closure runs :n days at most. Add a longer one in parts.',
        'colour' => 'The colour is :automatic or one of the palette\'s :count.',
    ],
    'refusals' => [
        'unknown_service' => 'One of the services named is not in the catalog.',
        'referenced' => 'This staff member is still named by existing bookings and cannot be deleted.',
        'implicit' => 'This staff member stands for the business itself and cannot be deleted.',
    ],
    'flashes' => [
        'created' => 'Staff member added.',
        'saved' => 'Staff member saved.',
        'deactivated' => 'Staff member deactivated.',
        'reactivated' => 'Staff member reactivated.',
        'deleted' => 'Staff member deleted.',
        'closure_added' => 'Closure added.',
        'closure_removed' => 'Closure removed.',
        'colour_saved' => 'Colour saved.',
        'photo_uploaded' => 'Photo uploaded.',
        'photo_removed' => 'Photo removed.',
    ],
];
