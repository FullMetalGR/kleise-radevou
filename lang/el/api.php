<?php

/*
 * The API's refusals in Greek: the twin of lang/en/api.php key for key, in the polite plural
 * throughout; the keys, the placeholders and the choice syntax are that file's, and the words for a
 * booking, a time, a service and a professional are lang/el/booking.php's, since the booking app
 * shows each detail verbatim. A field's name, a wire value (true, false, null) and the format of a
 * date or an instant are spelled as the wire spells them, in Latin letters, since the reader types
 * them so. An item is named by its number, :n counted from one, a number that needs no ordinal
 * ending in Greek either. A limit's choice line counts its Greek forms against the English case by
 * case, one and many.
 */

return [
    // The refusals the booking engine raises by type and the limit's, one sentence per
    // reason.
    'envelope' => [
        'server_error' => 'Κάτι πήγε στραβά από τη δική μας πλευρά. Δοκιμάστε ξανά σε λίγο.',
        'rate_limited' => 'Πάρα πολλά αιτήματα σε σύντομο χρόνο. Περιμένετε λίγο και δοκιμάστε ξανά.',
        'overlap' => 'Αυτή η ώρα μόλις κλείστηκε. Επιλέξτε άλλη.',
        'unavailable' => 'Αυτή η ώρα δεν είναι διαθέσιμη. Επιλέξτε άλλη.',
        'service_gone' => 'Μία από τις υπηρεσίες που επιλέξατε δεν προσφέρεται πια. Ξεκινήστε από την αρχή.',
        'staff_not_eligible' => 'Αυτός ο επαγγελματίας δεν παρέχει την υπηρεσία που επιλέξατε.',
        'busy' => 'Διαχειριζόμαστε πολλές κρατήσεις αυτή τη στιγμή. Δοκιμάστε ξανά σε λίγο.',
        'missing_token' => 'Απαιτείται διακριτικό πρόσβασης.',
        'hold_expired' => 'Δεν ήταν δυνατό να κρατήσουμε αυτήν την ώρα περισσότερο. Επιλέξτε άλλη.',
        'not_confirmable' => 'Αυτή η κράτηση δεν περιμένει πια επιβεβαίωση.',
        'payment_required' => 'Αυτή η κράτηση πρέπει να πληρωθεί πριν επιβεβαιωθεί.',
        'not_cancellable' => 'Αυτή η κράτηση δεν μπορεί πια να ακυρωθεί.',
        'not_reschedulable' => 'Αυτή η κράτηση δεν μπορεί πια να μεταφερθεί σε άλλη ώρα.',
        'not_releasable' => 'Αυτή η κράτηση δεν είναι πια μια δέσμευση που μπορεί να αποδεσμευτεί.',
        'hold_lapsed' => 'Αυτή η ώρα δεν είναι πια δεσμευμένη, οπότε η κράτηση δεν μπορεί να μεταφερθεί.',
        'window_closed' => 'Είναι πολύ αργά για να αλλάξετε αυτήν την κράτηση ηλεκτρονικά. Επικοινωνήστε μαζί μας.',
        'not_approvable' => 'Αυτό το αίτημα δεν περιμένει πια έγκριση.',
        'not_rejectable' => 'Αυτό το αίτημα δεν εκκρεμεί πια, οπότε δεν μπορεί να απορριφθεί.',
        'not_confirmed' => 'Μόνο μια επιβεβαιωμένη κράτηση μπορεί να σημειωθεί ως ολοκληρωμένη ή ως μη εμφάνιση.',
        'too_early' => 'Αυτή η κράτηση δεν έχει αρχίσει ακόμη, οπότε δεν μπορεί να σημειωθεί ως ολοκληρωμένη ή ως μη εμφάνιση.',
        'unaccepted_warning' => 'Αυτή η ώρα είναι εκτός του ωραρίου εργασίας του μέλους της ομάδας ή πέφτει σε κλειστή περίοδο. Επιβεβαιώστε την προειδοποίηση για να μεταφέρετε την κράτηση εκεί παρ\' όλα αυτά.',
        'blocked' => 'Οι ηλεκτρονικές κρατήσεις δεν είναι διαθέσιμες.',
    ],

    // The admin's move of a booking, read by a signed-in user of the business, not by the
    // customer: one sentence per rule the planner or the catalog refused the chain by that says
    // more than the envelope's unavailable, which the lead time and the horizon read.
    'move' => [
        'past' => 'Αυτή η ώρα έχει ήδη περάσει. Επιλέξτε μια αργότερη ώρα.',
        'off_grid' => 'Αυτή η ώρα δεν είναι μία από τις διαθέσιμες ώρες. Επιλέξτε μία από τη λίστα.',
        'service_gone' => 'Μια υπηρεσία αυτής της κράτησης δεν προσφέρεται πια, οπότε η κράτηση δεν μπορεί να μεταφερθεί.',
        'staff_gone' => 'Ένα μέλος της ομάδας αυτής της κράτησης δεν παρέχει πια την υπηρεσία της, οπότε η κράτηση δεν μπορεί να μεταφερθεί.',
        'staff_not_eligible' => 'Αυτός ο επαγγελματίας δεν παρέχει αυτήν την υπηρεσία.',
        'no_such_segment' => 'Αυτή η κράτηση δεν έχει τέτοια υπηρεσία για να ανατεθεί σε άλλον επαγγελματία.',
    ],

    // The sentence a status is answered with where no reason names its own.
    'status' => [
        'bad_request' => 'Το αίτημα δεν ήταν κατανοητό.',
        'unauthenticated' => 'Συνδεθείτε για να συνεχίσετε.',
        'forbidden' => 'Δεν έχετε δικαίωμα να το κάνετε αυτό.',
        'not_found' => 'Αυτό δεν βρέθηκε.',
        'method_not_allowed' => 'Αυτή η διεύθυνση δεν δέχεται αυτό το είδος αιτήματος.',
        'service_unavailable' => 'Το σύστημα δεν είναι διαθέσιμο αυτή τη στιγμή. Δοκιμάστε ξανά σε λίγο.',
        'refused' => 'Το αίτημα απορρίφθηκε.',
    ],

    // A request's first refused field, its detail at 400.
    'validation' => [
        'customer_name' => [
            'required' => 'Το όνομα είναι υποχρεωτικό.',
            'max' => '{1} Το όνομα μπορεί να έχει έως έναν χαρακτήρα.|[2,*] Το όνομα μπορεί να έχει έως :count χαρακτήρες.',
        ],
        'customer_email' => [
            'required' => 'Η διεύθυνση email είναι υποχρεωτική.',
            'email' => 'Αυτή η διεύθυνση email δεν μοιάζει με διεύθυνση email.',
            'max' => '{1} Η διεύθυνση email μπορεί να έχει έως έναν χαρακτήρα.|[2,*] Η διεύθυνση email μπορεί να έχει έως :count χαρακτήρες.',
        ],
        'customer_phone' => [
            'string' => 'Ο αριθμός τηλεφώνου πρέπει να είναι κείμενο, αλλιώς παραλείψτε τον.',
            'max' => '{1} Ο αριθμός τηλεφώνου μπορεί να έχει έως έναν χαρακτήρα.|[2,*] Ο αριθμός τηλεφώνου μπορεί να έχει έως :count χαρακτήρες.',
        ],
        'start' => 'Το πεδίο start πρέπει να είναι χρονική στιγμή, π.χ. 2026-11-03T09:00:00+02:00.',
        'same_staff' => 'Το πεδίο same_staff πρέπει να είναι true ή false.',
        'note' => [
            'string' => 'Η σημείωση πρέπει να είναι κείμενο, αλλιώς παραλείψτε την.',
            'max' => '{1} Η σημείωση μπορεί να έχει έως έναν χαρακτήρα.|[2,*] Η σημείωση μπορεί να έχει έως :count χαρακτήρες.',
        ],
        'items' => [
            'required' => 'Το πεδίο items είναι υποχρεωτικό.',
            'list' => 'Το πεδίο items πρέπει να είναι λίστα JSON με στοιχεία.',
            'max' => '{1} Το πεδίο items μπορεί να περιέχει έως ένα στοιχείο.|[2,*] Το πεδίο items μπορεί να περιέχει έως :count στοιχεία.',
        ],
        'item' => [
            'object' => 'Το στοιχείο :n πρέπει να είναι αντικείμενο με service_id και staff_id.',
            'no_service_id' => 'Το στοιχείο :n δεν έχει service_id.',
            'no_staff_id' => 'Το στοιχείο :n δεν έχει staff_id. Στείλτε null αν δεν έχετε προτίμηση.',
            'service_id_integer' => 'Το service_id του στοιχείου :n πρέπει να είναι θετικός ακέραιος.',
            'staff_id_integer' => 'Το staff_id του στοιχείου :n πρέπει να είναι θετικός ακέραιος ή null.',
            'service_unavailable' => 'Το στοιχείο :n αναφέρει μια υπηρεσία που δεν είναι διαθέσιμη.',
            'staff_unqualified' => 'Το στοιχείο :n αναφέρει έναν επαγγελματία που δεν παρέχει αυτήν την υπηρεσία.',
        ],
        'move' => [
            'date' => 'Το πεδίο date πρέπει να είναι ημερομηνία γραμμένη ως YYYY-MM-DD.',
            'one' => 'Δώστε είτε ημερομηνία είτε ώρα έναρξης, όχι και τα δύο.',
            'staff' => 'Το πεδίο staff πρέπει να αντιστοιχίζει τη θέση μιας υπηρεσίας στην κράτηση, μετρώντας από το μηδέν, στο id ενός επαγγελματία.',
            'warning_accepted' => 'Το πεδίο warning_accepted πρέπει να είναι true ή false.',
            'tell_customer' => 'Το πεδίο tell_customer πρέπει να είναι true ή false.',
        ],
        'from' => [
            'day' => 'Το πεδίο from πρέπει να είναι ημερομηνία γραμμένη ως YYYY-MM-DD.',
        ],
        'to' => [
            'day' => 'Το πεδίο to πρέπει να είναι ημερομηνία γραμμένη ως YYYY-MM-DD.',
            'after' => 'Το πεδίο to πρέπει να είναι ημερομηνία μετά το from.',
            'max' => '{1} Το πεδίο to πρέπει να είναι το πολύ μία ημέρα μετά το from.|[2,*] Το πεδίο to πρέπει να είναι το πολύ :count ημέρες μετά το from.',
        ],
    ],
];
