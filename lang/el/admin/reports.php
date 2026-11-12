<?php

/*
 * Reports in Greek: the twin of lang/en/admin/reports.php key for key, in the polite plural
 * throughout; the keys, the groups and the placeholders are that file's, and its header says
 * which part of the screen reads which group. The reader is the business's owner or manager
 * reading their own figures, so the words are Home's and the Bookings screen's: the title is
 * admin/shell.php's nav.reports; the range, its words and lines, the tiles, the comparisons, the
 * footnote and the error are admin/home.php's lines word for word; the filter card's member,
 * service, From and To and the Filters and Status heads of the CSV are admin/bookings.php's
 * filters; the download button and its busy word are admin/customers.php's csv group's; a member
 * of the team is one phrase throughout, the rail's word for the team never standing for one
 * member.
 *
 * A range's line with two dates takes Home's "from ... to ..." with the article before each date,
 * and so do the two charts' labels inside their sentence; the ranges Home lacks and their
 * comparisons follow Home's forms, the period in its own gender and number, the many days of a
 * custom range a figure before the noun. The four counted lines keep the choice syntax with
 * Greek's forms. A column head that is an adjective agrees with what it counts: the bookings in
 * the Revenue card, the hours in Occupancy by staff. The Cancelled by card's head asks who
 * cancelled, so its three rows answer in the nominative; a booking with no channel is the
 * neuter word for none, the channel's own gender.
 *
 * The placeholders: :date, :from and :to are dates in digits; :change is the signed percentage,
 * written before the words; :count is a figure; :filled and :offered are durations in ICU's units
 * and :hours and :minutes each a number beside its unit; :amount is an amount of money; :chart is
 * one of this file's chart titles, :values a list of figures and :currency the currency's name,
 * which does not decline; in the CSV's in_currency :column is a column head and :currency a code.
 */

return [
    'page_title' => 'Αναφορές',
    'filters' => [
        'range' => 'Περίοδος',
        'words' => [
            'today' => 'Σήμερα',
            'yesterday' => 'Χθες',
            'week' => 'Αυτή η εβδομάδα',
            'last_7_days' => 'Τελευταίες 7 ημέρες',
            'last_30_days' => 'Τελευταίες 30 ημέρες',
            'month' => 'Αυτός ο μήνας',
            'last_month' => 'Περασμένος μήνας',
            'year' => 'Αυτό το έτος',
            'custom' => 'Προσαρμοσμένη περίοδος',
        ],
        'staff' => 'Μέλος της ομάδας',
        'all_staff' => 'Όλα τα μέλη της ομάδας',
        'service' => 'Υπηρεσία',
        'all_services' => 'Όλες οι υπηρεσίες',
        'from' => 'Από',
        'to' => 'Έως',
        'apply' => 'Εφαρμογή',
        'applying' => 'Γίνεται εφαρμογή...',
        'stated' => [
            'today' => 'Σήμερα: :date',
            'yesterday' => 'Χθες: :date',
            'week' => 'Αυτή η εβδομάδα: από τις :from έως τις :to',
            'last_7_days' => 'Τελευταίες 7 ημέρες: από τις :from έως τις :to',
            'last_30_days' => 'Τελευταίες 30 ημέρες: από τις :from έως τις :to',
            'month' => 'Αυτός ο μήνας: από τις :from έως τις :to',
            'last_month' => 'Περασμένος μήνας: από τις :from έως τις :to',
            'year' => 'Αυτό το έτος: από τις :from έως τις :to',
            'custom' => 'Προσαρμοσμένη περίοδος: από τις :from έως τις :to',
            'custom_day' => 'Προσαρμοσμένη περίοδος: :date',
        ],
        'to_before_from' => 'Η ημερομηνία «Έως» είναι πριν από την ημερομηνία «Από».',
        'too_long' => 'Μια προσαρμοσμένη περίοδος καλύπτει το πολύ :count ημέρες.',
        'loading' => 'Φορτώνονται τα στοιχεία της περιόδου που επιλέξατε.',
        'error' => 'Δεν ήταν δυνατή η φόρτωση των στοιχείων. Ελέγξτε τη σύνδεσή σας και επιλέξτε ξανά την περίοδο.',
        'download' => 'Λήψη CSV',
        'downloading' => 'Γίνεται λήψη...',
        'download_error' => 'Δεν ήταν δυνατή η λήψη του CSV. Δοκιμάστε ξανά.',
    ],
    'tiles' => [
        'bookings' => 'Κρατήσεις',
        'revenue' => 'Έσοδα',
        'occupancy' => 'Πληρότητα',
        'new_customers' => 'Νέοι πελάτες',
        'cancelled' => 'Ακυρωμένες',
        'no_shows' => 'Μη εμφανίσεις',
        'returning' => '{1} :count πελάτης που επέστρεψε|[0,*] :count πελάτες που επέστρεψαν',
        'occupancy_of' => ':filled από :offered',
        'hours_minutes' => ':hours :minutes',
        'no_hours' => 'Δεν υπάρχει ωράριο εργασίας της ομάδας',
        'elsewhere' => 'Επιπλέον :amount',
        'note' => 'Οι κρατήσεις και τα έσοδα μετρούν τις επιβεβαιωμένες και τις ολοκληρωμένες κρατήσεις με βάση την πρώτη τους έναρξη. Η πληρότητα είναι τα λεπτά που καλύπτουν αυτές οι κρατήσεις προς τα λεπτά που προσφέρει το ωράριο εργασίας της ομάδας σας χωρίς τις κλειστές περιόδους, συν κάθε λεπτό κράτησης εκτός αυτού του ωραρίου, ώστε να μην ξεπερνά ποτέ το 100%.',
    ],
    'compare' => [
        'day' => [
            'by' => ':change σε σχέση με την προηγούμενη ημέρα',
            'same' => 'Καμία μεταβολή σε σχέση με την προηγούμενη ημέρα',
            'from_zero' => 'Αύξηση από το μηδέν σε σχέση με την προηγούμενη ημέρα',
        ],
        'week' => [
            'by' => ':change σε σχέση με την προηγούμενη εβδομάδα',
            'same' => 'Καμία μεταβολή σε σχέση με την προηγούμενη εβδομάδα',
            'from_zero' => 'Αύξηση από το μηδέν σε σχέση με την προηγούμενη εβδομάδα',
        ],
        'last_7_days' => [
            'by' => ':change σε σχέση με τις προηγούμενες 7 ημέρες',
            'same' => 'Καμία μεταβολή σε σχέση με τις προηγούμενες 7 ημέρες',
            'from_zero' => 'Αύξηση από το μηδέν σε σχέση με τις προηγούμενες 7 ημέρες',
        ],
        'last_30_days' => [
            'by' => ':change σε σχέση με τις προηγούμενες 30 ημέρες',
            'same' => 'Καμία μεταβολή σε σχέση με τις προηγούμενες 30 ημέρες',
            'from_zero' => 'Αύξηση από το μηδέν σε σχέση με τις προηγούμενες 30 ημέρες',
        ],
        'month' => [
            'by' => ':change σε σχέση με τον προηγούμενο μήνα',
            'same' => 'Καμία μεταβολή σε σχέση με τον προηγούμενο μήνα',
            'from_zero' => 'Αύξηση από το μηδέν σε σχέση με τον προηγούμενο μήνα',
        ],
        'year' => [
            'by' => ':change σε σχέση με το προηγούμενο έτος',
            'same' => 'Καμία μεταβολή σε σχέση με το προηγούμενο έτος',
            'from_zero' => 'Αύξηση από το μηδέν σε σχέση με το προηγούμενο έτος',
        ],
        'custom' => [
            'by' => '{1} :change σε σχέση με την προηγούμενη ημέρα|[2,*] :change σε σχέση με τις προηγούμενες :count ημέρες',
            'same' => '{1} Καμία μεταβολή σε σχέση με την προηγούμενη ημέρα|[2,*] Καμία μεταβολή σε σχέση με τις προηγούμενες :count ημέρες',
            'from_zero' => '{1} Αύξηση από το μηδέν σε σχέση με την προηγούμενη ημέρα|[2,*] Αύξηση από το μηδέν σε σχέση με τις προηγούμενες :count ημέρες',
        ],
    ],
    'over_time' => [
        'title' => 'Εξέλιξη στον χρόνο',
        'bookings' => [
            'day' => 'Κρατήσεις ανά ημέρα',
            'week' => 'Κρατήσεις ανά εβδομάδα',
            'month' => 'Κρατήσεις ανά μήνα',
        ],
        'revenue' => [
            'day' => 'Έσοδα ανά ημέρα',
            'week' => 'Έσοδα ανά εβδομάδα',
            'month' => 'Έσοδα ανά μήνα',
        ],
        'chart' => ':chart, από τις :from έως τις :to: :values',
        'chart_revenue' => ':chart σε :currency, από τις :from έως τις :to: :values',
        'from' => 'Από',
        'to' => 'Έως',
        'bookings_head' => 'Κρατήσεις',
        'revenue_head' => 'Έσοδα',
    ],
    'tables' => [
        'statuses' => 'Κρατήσεις ανά κατάσταση',
        'revenue' => 'Έσοδα',
        'confirmed' => 'Επιβεβαιωμένες',
        'completed' => 'Ολοκληρωμένες',
        'no_show_rate' => 'Ποσοστό μη εμφανίσεων',
        'no_shows' => 'Μη εμφανίσεις',
        'no_completed' => 'Δεν υπάρχουν ακόμη ολοκληρωμένες κρατήσεις',
        'cancelled_by' => 'Ποιος ακύρωσε',
        'by_customer' => 'Πελάτης',
        'by_business' => 'Επιχείρηση',
        'unrecorded' => 'Δεν έχει καταγραφεί',
        'by_service' => 'Ανά υπηρεσία',
        'by_staff' => 'Ανά μέλος της ομάδας',
        'by_source' => 'Ανά πηγή',
        'by_channel' => 'Ανά κανάλι',
        'service' => 'Υπηρεσία',
        'staff' => 'Μέλος της ομάδας',
        'source' => 'Πηγή',
        'channel' => 'Κανάλι',
        'bookings' => 'Κρατήσεις',
        'revenue_head' => 'Έσοδα',
        'no_channel' => 'Κανένα',
        'note' => 'Οι κρατήσεις μετρούν όλες τις κρατήσεις της περιόδου, όποια κι αν είναι η κατάστασή τους. Τα έσοδα μετρούν τις επιβεβαιωμένες και τις ολοκληρωμένες κρατήσεις.',
    ],
    'occupancy' => [
        'title' => 'Πληρότητα ανά μέλος της ομάδας',
        'booked' => 'Ώρες με κρατήσεις',
        'available' => 'Διαθέσιμες ώρες',
        'no_hours' => 'Χωρίς ωράριο',
    ],
    'csv' => [
        'filters' => 'Φίλτρα',
        'figures' => 'Στοιχεία',
        'figure' => 'Στοιχείο',
        'value' => 'Τιμή',
        'before' => 'Προηγούμενη περίοδος',
        'change' => 'Μεταβολή',
        'detail' => 'Λεπτομέρεια',
        'status' => 'Κατάσταση',
        'in_currency' => ':column (:currency)',
    ],
];
