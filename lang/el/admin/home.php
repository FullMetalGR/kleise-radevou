<?php

/*
 * Home in Greek: the twin of lang/en/admin/home.php key for key, in the polite plural throughout;
 * the keys, the groups and the placeholders are that file's, and its header says which part of
 * the screen reads which group. The reader is the business's owner or manager opening the day, so
 * the words are the rail's and the Bookings screen's: the title is admin/shell.php's nav.home,
 * All bookings is said whole as its English says it, the tiles' Cancelled and No-shows
 * admin/customers.php's tiles, the erased customer's word admin/bookings.php's customer_erased,
 * and each action's verb the stem admin/bookings.php's trail and admin/shell.php's kit.statuses
 * already use, a move the admin's own verb.
 *
 * A name in a sentence is text somebody typed and is never declined: a customer or a user acting
 * stands bare before a verb that does not bend, and where the customer is in another case the
 * noun with its article carries the case and the name follows it, as lang/el/business-mail.php
 * writes it; a user removed from the team is said as lang/el/business-mail.php says one
 * (cancelled.removed, moved.removed). An erased customer's sentence under erased differs from its
 * named twin in the customer's phrase alone. The comparison sentences are one whole phrase per
 * kind of period; the four counted lines keep the choice syntax with Greek's forms. The range,
 * the tiles, the comparisons, the footnote and the Top tables' heads are the lines
 * admin/reports.php holds too, written here first.
 *
 * The placeholders: :from and :to are dates in digits after the article, :end a date and a time
 * after it, :date a date in digits after the colon; :change is the signed percentage, written
 * before the words; :count and :awaiting are figures; :weekday is ICU's short name, :day in
 * days.day the day's figure after it and in days.link that whole line; :filled and :offered are
 * durations in ICU's units; :services and :staff are ICU lists; :booking and :start fill the
 * layout of a row's second line.
 *
 * To mark says a mark as admin/bookings.php's flashes say it, a booking recorded ("καταγράφηκε")
 * as completed or as a no-show, and its buttons are that file's actions; its counted lines keep
 * the choice syntax with Greek's forms. There :time is a time of day, :date a date in digits
 * after the long weekday, :listed a figure, and :reasons the counted phrases the server joins
 * after the colon.
 */

return [
    'page_title' => 'Αρχική',
    'title' => 'Αρχική',
    'range' => [
        'label' => 'Περίοδος',
        'today' => 'Σήμερα',
        'yesterday' => 'Χθες',
        'week' => 'Αυτή η εβδομάδα',
        'month' => 'Αυτός ο μήνας',
        'line' => [
            'today' => 'Σήμερα: :date',
            'yesterday' => 'Χθες: :date',
            'week' => 'Αυτή η εβδομάδα: από τις :from έως τις :to',
            'month' => 'Αυτός ο μήνας: από τις :from έως τις :to',
        ],
        'error' => 'Δεν ήταν δυνατή η φόρτωση των στοιχείων. Ελέγξτε τη σύνδεσή σας και επιλέξτε ξανά την περίοδο.',
    ],
    'tiles' => [
        'bookings' => 'Κρατήσεις',
        'revenue' => 'Έσοδα',
        'occupancy' => 'Πληρότητα',
        'new_customers' => 'Νέοι πελάτες',
        'cancelled' => 'Ακυρωμένες',
        'no_shows' => 'Μη εμφανίσεις',
        'filled' => ':filled από :offered',
        'duration' => ':hours :minutes',
        'no_hours' => 'Δεν υπάρχει ωράριο εργασίας της ομάδας σε αυτήν την περίοδο',
        'returning' => '{1} :count πελάτης που επέστρεψε|[0,*] :count πελάτες που επέστρεψαν',
    ],
    'change' => [
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
        'month' => [
            'by' => ':change σε σχέση με τον προηγούμενο μήνα',
            'same' => 'Καμία μεταβολή σε σχέση με τον προηγούμενο μήνα',
            'from_zero' => 'Αύξηση από το μηδέν σε σχέση με τον προηγούμενο μήνα',
        ],
    ],
    'footnote' => 'Οι κρατήσεις και τα έσοδα μετρούν τις επιβεβαιωμένες και τις ολοκληρωμένες κρατήσεις με βάση την πρώτη τους έναρξη. Η πληρότητα είναι τα λεπτά που καλύπτουν αυτές οι κρατήσεις προς τα λεπτά που προσφέρει το ωράριο εργασίας της ομάδας σας χωρίς τις κλειστές περιόδους, συν κάθε λεπτό κράτησης εκτός αυτού του ωραρίου, ώστε να μην ξεπερνά ποτέ το 100%.',
    'top' => [
        'services' => 'Κορυφαίες υπηρεσίες',
        'staff' => 'Κορυφαία μέλη της ομάδας',
        'bookings' => 'Κρατήσεις',
        'bookings_line' => 'Κρατήσεις: :count',
        'revenue' => 'Έσοδα',
        'empty' => 'Δεν υπάρχουν επιβεβαιωμένες ή ολοκληρωμένες κρατήσεις σε αυτήν την περίοδο.',
    ],
    'customer_erased' => 'Διαγραμμένος πελάτης',
    'with' => ':services με :staff',
    'next_up' => [
        'title' => 'Επόμενες κρατήσεις',
        'empty' => 'Δεν υπάρχουν επερχόμενες κρατήσεις.',
    ],
    'awaiting' => [
        'title' => 'Περιμένουν έγκριση',
        'empty' => 'Κανένα αίτημα δεν περιμένει απόφαση.',
        'decide_by' => 'Αποφασίστε έως τις :end',
        'see_all' => 'Προβολή όλων των αιτημάτων',
    ],
    'activity' => [
        'title' => 'Πρόσφατη δραστηριότητα',
        'empty' => 'Δεν έχει γίνει τίποτα ακόμη.',
        'all' => 'Όλες οι κρατήσεις',
        'line' => ':booking, :start',
        'held' => [
            'online' => ':customer έκανε online κράτηση',
            'request' => ':customer ζήτησε κράτηση',
        ],
        'added' => [
            'user' => ':staff πρόσθεσε κράτηση για τον πελάτη :customer',
            'removed' => 'Ένας χρήστης που αφαιρέθηκε από την ομάδα πρόσθεσε κράτηση για τον πελάτη :customer',
            'support' => 'Η υποστήριξη (:staff) πρόσθεσε κράτηση για τον πελάτη :customer',
            'support_removed' => 'Η υποστήριξη πρόσθεσε κράτηση για τον πελάτη :customer',
        ],
        'confirmed' => [
            'customer' => ':customer επιβεβαίωσε την κράτηση',
            'paid' => ':customer πλήρωσε online',
            'system' => 'Η κράτηση του πελάτη :customer επιβεβαιώθηκε',
        ],
        'approved' => [
            'user' => ':staff ενέκρινε το αίτημα του πελάτη :customer',
            'removed' => 'Ένας χρήστης που αφαιρέθηκε από την ομάδα ενέκρινε το αίτημα του πελάτη :customer',
            'support' => 'Η υποστήριξη (:staff) ενέκρινε το αίτημα του πελάτη :customer',
            'support_removed' => 'Η υποστήριξη ενέκρινε το αίτημα του πελάτη :customer',
            'system' => 'Το αίτημα του πελάτη :customer εγκρίθηκε αυτόματα',
        ],
        'rejected' => [
            'user' => ':staff απέρριψε το αίτημα του πελάτη :customer',
            'removed' => 'Ένας χρήστης που αφαιρέθηκε από την ομάδα απέρριψε το αίτημα του πελάτη :customer',
            'support' => 'Η υποστήριξη (:staff) απέρριψε το αίτημα του πελάτη :customer',
            'support_removed' => 'Η υποστήριξη απέρριψε το αίτημα του πελάτη :customer',
            'system' => 'Το αίτημα του πελάτη :customer απορρίφθηκε αυτόματα',
        ],
        'cancelled' => [
            'customer' => ':customer ακύρωσε την κράτηση',
            'user' => ':staff ακύρωσε την κράτηση του πελάτη :customer',
            'removed' => 'Ένας χρήστης που αφαιρέθηκε από την ομάδα ακύρωσε την κράτηση του πελάτη :customer',
            'support' => 'Η υποστήριξη (:staff) ακύρωσε την κράτηση του πελάτη :customer',
            'support_removed' => 'Η υποστήριξη ακύρωσε την κράτηση του πελάτη :customer',
            'system' => 'Η κράτηση του πελάτη :customer ακυρώθηκε',
        ],
        'released' => [
            'customer' => ':customer αποχώρησε πριν ολοκληρώσει την κράτηση',
            'system' => 'Η ημιτελής κράτηση του πελάτη :customer αποδεσμεύτηκε',
        ],
        'rescheduled' => [
            'customer' => ':customer μετέφερε την κράτηση',
            'user' => ':staff μετέφερε την κράτηση του πελάτη :customer',
            'removed' => 'Ένας χρήστης που αφαιρέθηκε από την ομάδα μετέφερε την κράτηση του πελάτη :customer',
            'support' => 'Η υποστήριξη (:staff) μετέφερε την κράτηση του πελάτη :customer',
            'support_removed' => 'Η υποστήριξη μετέφερε την κράτηση του πελάτη :customer',
            'system' => 'Η κράτηση του πελάτη :customer μεταφέρθηκε',
        ],
        'completed' => [
            'user' => ':staff κατέγραψε την κράτηση του πελάτη :customer ως ολοκληρωμένη',
            'removed' => 'Ένας χρήστης που αφαιρέθηκε από την ομάδα κατέγραψε την κράτηση του πελάτη :customer ως ολοκληρωμένη',
            'support' => 'Η υποστήριξη (:staff) κατέγραψε την κράτηση του πελάτη :customer ως ολοκληρωμένη',
            'support_removed' => 'Η υποστήριξη κατέγραψε την κράτηση του πελάτη :customer ως ολοκληρωμένη',
        ],
        'no_show' => [
            'user' => ':staff κατέγραψε την κράτηση του πελάτη :customer ως μη εμφάνιση',
            'removed' => 'Ένας χρήστης που αφαιρέθηκε από την ομάδα κατέγραψε την κράτηση του πελάτη :customer ως μη εμφάνιση',
            'support' => 'Η υποστήριξη (:staff) κατέγραψε την κράτηση του πελάτη :customer ως μη εμφάνιση',
            'support_removed' => 'Η υποστήριξη κατέγραψε την κράτηση του πελάτη :customer ως μη εμφάνιση',
        ],
        'expired' => [
            'approval' => 'Το αίτημα του πελάτη :customer έληξε',
            'checkout' => 'Η ημιτελής κράτηση του πελάτη :customer έληξε',
            'payment' => 'Η κράτηση του πελάτη :customer έληξε χωρίς πληρωμή',
            'other' => 'Η κράτηση του πελάτη :customer έληξε',
        ],
        'customer_erased' => 'Τα δεδομένα ενός πελάτη διαγράφηκαν',
        'customer_exported' => 'Έγινε εξαγωγή των δεδομένων ενός πελάτη',
        'unknown' => 'Η κράτηση του πελάτη :customer άλλαξε',
        'erased' => [
            'held' => [
                'online' => 'Ένας διαγραμμένος πελάτης έκανε online κράτηση',
                'request' => 'Ένας διαγραμμένος πελάτης ζήτησε κράτηση',
            ],
            'added' => [
                'user' => ':staff πρόσθεσε κράτηση για έναν διαγραμμένο πελάτη',
                'removed' => 'Ένας χρήστης που αφαιρέθηκε από την ομάδα πρόσθεσε κράτηση για έναν διαγραμμένο πελάτη',
                'support' => 'Η υποστήριξη (:staff) πρόσθεσε κράτηση για έναν διαγραμμένο πελάτη',
                'support_removed' => 'Η υποστήριξη πρόσθεσε κράτηση για έναν διαγραμμένο πελάτη',
            ],
            'confirmed' => [
                'customer' => 'Ένας διαγραμμένος πελάτης επιβεβαίωσε την κράτηση',
                'paid' => 'Ένας διαγραμμένος πελάτης πλήρωσε online',
                'system' => 'Η κράτηση ενός διαγραμμένου πελάτη επιβεβαιώθηκε',
            ],
            'approved' => [
                'user' => ':staff ενέκρινε το αίτημα ενός διαγραμμένου πελάτη',
                'removed' => 'Ένας χρήστης που αφαιρέθηκε από την ομάδα ενέκρινε το αίτημα ενός διαγραμμένου πελάτη',
                'support' => 'Η υποστήριξη (:staff) ενέκρινε το αίτημα ενός διαγραμμένου πελάτη',
                'support_removed' => 'Η υποστήριξη ενέκρινε το αίτημα ενός διαγραμμένου πελάτη',
                'system' => 'Το αίτημα ενός διαγραμμένου πελάτη εγκρίθηκε αυτόματα',
            ],
            'rejected' => [
                'user' => ':staff απέρριψε το αίτημα ενός διαγραμμένου πελάτη',
                'removed' => 'Ένας χρήστης που αφαιρέθηκε από την ομάδα απέρριψε το αίτημα ενός διαγραμμένου πελάτη',
                'support' => 'Η υποστήριξη (:staff) απέρριψε το αίτημα ενός διαγραμμένου πελάτη',
                'support_removed' => 'Η υποστήριξη απέρριψε το αίτημα ενός διαγραμμένου πελάτη',
                'system' => 'Το αίτημα ενός διαγραμμένου πελάτη απορρίφθηκε αυτόματα',
            ],
            'cancelled' => [
                'customer' => 'Ένας διαγραμμένος πελάτης ακύρωσε την κράτηση',
                'user' => ':staff ακύρωσε την κράτηση ενός διαγραμμένου πελάτη',
                'removed' => 'Ένας χρήστης που αφαιρέθηκε από την ομάδα ακύρωσε την κράτηση ενός διαγραμμένου πελάτη',
                'support' => 'Η υποστήριξη (:staff) ακύρωσε την κράτηση ενός διαγραμμένου πελάτη',
                'support_removed' => 'Η υποστήριξη ακύρωσε την κράτηση ενός διαγραμμένου πελάτη',
                'system' => 'Η κράτηση ενός διαγραμμένου πελάτη ακυρώθηκε',
            ],
            'released' => [
                'customer' => 'Ένας διαγραμμένος πελάτης αποχώρησε πριν ολοκληρώσει την κράτηση',
                'system' => 'Η ημιτελής κράτηση ενός διαγραμμένου πελάτη αποδεσμεύτηκε',
            ],
            'rescheduled' => [
                'customer' => 'Ένας διαγραμμένος πελάτης μετέφερε την κράτηση',
                'user' => ':staff μετέφερε την κράτηση ενός διαγραμμένου πελάτη',
                'removed' => 'Ένας χρήστης που αφαιρέθηκε από την ομάδα μετέφερε την κράτηση ενός διαγραμμένου πελάτη',
                'support' => 'Η υποστήριξη (:staff) μετέφερε την κράτηση ενός διαγραμμένου πελάτη',
                'support_removed' => 'Η υποστήριξη μετέφερε την κράτηση ενός διαγραμμένου πελάτη',
                'system' => 'Η κράτηση ενός διαγραμμένου πελάτη μεταφέρθηκε',
            ],
            'completed' => [
                'user' => ':staff κατέγραψε την κράτηση ενός διαγραμμένου πελάτη ως ολοκληρωμένη',
                'removed' => 'Ένας χρήστης που αφαιρέθηκε από την ομάδα κατέγραψε την κράτηση ενός διαγραμμένου πελάτη ως ολοκληρωμένη',
                'support' => 'Η υποστήριξη (:staff) κατέγραψε την κράτηση ενός διαγραμμένου πελάτη ως ολοκληρωμένη',
                'support_removed' => 'Η υποστήριξη κατέγραψε την κράτηση ενός διαγραμμένου πελάτη ως ολοκληρωμένη',
            ],
            'no_show' => [
                'user' => ':staff κατέγραψε την κράτηση ενός διαγραμμένου πελάτη ως μη εμφάνιση',
                'removed' => 'Ένας χρήστης που αφαιρέθηκε από την ομάδα κατέγραψε την κράτηση ενός διαγραμμένου πελάτη ως μη εμφάνιση',
                'support' => 'Η υποστήριξη (:staff) κατέγραψε την κράτηση ενός διαγραμμένου πελάτη ως μη εμφάνιση',
                'support_removed' => 'Η υποστήριξη κατέγραψε την κράτηση ενός διαγραμμένου πελάτη ως μη εμφάνιση',
            ],
            'expired' => [
                'approval' => 'Το αίτημα ενός διαγραμμένου πελάτη έληξε',
                'checkout' => 'Η ημιτελής κράτηση ενός διαγραμμένου πελάτη έληξε',
                'payment' => 'Η κράτηση ενός διαγραμμένου πελάτη έληξε χωρίς πληρωμή',
                'other' => 'Η κράτηση ενός διαγραμμένου πελάτη έληξε',
            ],
            'unknown' => 'Η κράτηση ενός διαγραμμένου πελάτη άλλαξε',
        ],
    ],
    'days' => [
        'title' => '{1} Η επόμενη ημέρα|[2,*] Οι επόμενες :count ημέρες',
        'bookings' => '{0} :count κρατήσεις|{1} :count κράτηση|[2,*] :count κρατήσεις',
        'summary' => [
            'one_awaiting' => '{1} :count κράτηση, :awaiting περιμένει έγκριση|[2,*] :count κρατήσεις, :awaiting περιμένει έγκριση',
            'many_awaiting' => ':count κρατήσεις, :awaiting περιμένουν έγκριση',
        ],
        'day' => ':weekday :day',
        'link' => '{0} :day, καμία κράτηση|{1} :day, μία κράτηση|[2,*] :day, :count κρατήσεις',
        'footnote' => 'Οι επιβεβαιωμένες κρατήσεις φαίνονται στο χρώμα του θέματος και όσες περιμένουν έγκριση σε πορτοκαλί. Πατήστε μια ημέρα για να ανοίξει το Ημερολόγιο σε αυτήν.',
    ],
    'to_mark' => [
        'title' => 'Προς καταγραφή',
        'count' => '{1} :count κράτηση προς καταγραφή|[2,*] :count κρατήσεις προς καταγραφή',
        'empty' => 'Τίποτα προς καταγραφή',
        'today' => 'Σήμερα',
        'day' => ':weekday :date',
        'select_day' => 'Επιλογή της ημέρας',
        'select_booking' => 'Επιλογή της κράτησης του πελάτη :customer στις :time',
        'select_erased' => 'Επιλογή της κράτησης διαγραμμένου πελάτη στις :time',
        'selected' => '{1} :count επιλεγμένη|[2,*] :count επιλεγμένες',
        'mark_completed' => 'Καταγραφή επιλεγμένων ως ολοκληρωμένες',
        'mark_no_show' => 'Καταγραφή επιλεγμένων ως μη εμφάνιση',
        'mark_rest' => 'Καταγραφή των υπόλοιπων ως ολοκληρωμένες',
        'showing' => 'Εμφανίζονται :listed από :count. Καταγράψτε αυτές για να δείτε τις υπόλοιπες.',
        'confirm' => [
            'completed' => '{1} Να καταγραφεί :count κράτηση ως ολοκληρωμένη;|[2,*] Να καταγραφούν :count κρατήσεις ως ολοκληρωμένες;',
            'no_show' => '{1} Να καταγραφεί :count κράτηση ως μη εμφάνιση;|[2,*] Να καταγραφούν :count κρατήσεις ως μη εμφανίσεις;',
            'undone' => 'Αυτή η ενέργεια δεν αναιρείται.',
            'thanks' => '{1} Ο πελάτης λαμβάνει το email ευχαριστίας.|[2,*] Κάθε πελάτης λαμβάνει το email ευχαριστίας.',
            'mark_completed' => 'Καταγραφή ολοκλήρωσης',
            'mark_no_show' => 'Καταγραφή μη εμφάνισης',
            'back' => 'Πίσω',
            'pending' => 'Η καταγραφή στάλθηκε. Το παράθυρο θα κλείσει μόλις απαντήσει ο διακομιστής.',
        ],
        'flashes' => [
            'completed' => '{1} :count κράτηση καταγράφηκε ως ολοκληρωμένη.|[2,*] :count κρατήσεις καταγράφηκαν ως ολοκληρωμένες.',
            'no_show' => '{1} :count κράτηση καταγράφηκε ως μη εμφάνιση.|[2,*] :count κρατήσεις καταγράφηκαν ως μη εμφανίσεις.',
            'none' => 'Δεν καταγράφηκε καμία κράτηση.',
            'left' => '{1} :count δεν καταγράφηκε: :reasons.|[2,*] :count δεν καταγράφηκαν: :reasons.',
            'changed' => '{1} :count άλλαξε στο μεταξύ|[2,*] :count άλλαξαν στο μεταξύ',
            'busy' => '{1} :count ήταν απασχολημένη, δοκιμάστε ξανά|[2,*] :count ήταν απασχολημένες, δοκιμάστε ξανά',
            'failed' => '{1} :count δεν ήταν δυνατό να καταγραφεί|[2,*] :count δεν ήταν δυνατό να καταγραφούν',
        ],
        'validation' => [
            'outcome' => 'Επιλέξτε Ολοκλήρωση ή Μη εμφάνιση.',
            'bookings' => 'Επιλέξτε ξανά τις κρατήσεις προς καταγραφή.',
        ],
    ],
];
