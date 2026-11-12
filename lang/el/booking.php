<?php

/*
 * The booking bundle's sentences in Greek: the twin of lang/en/booking.php key for key, in the
 * polite plural throughout; the keys, the placeholders and the choice syntax are that file's.
 * The bundle reads it through the generated module php artisan lang:export booking writes
 * before every build, every Greek letter there a \u escape, so this file stays the one place
 * the bundle's Greek is written.
 *
 * A name the tenant typed, a service's or a member's, is placed after the noun for it where the
 * sentence would otherwise give it an article, so any name reads; the question mark is the
 * semicolon character, as Greek writes it. A choice line counts its Greek forms against the
 * English case by case: one and many, the plural rule English and Greek share. The durations'
 * words change with the number in Greek, so the three shapes are read as choices of their own,
 * where the English has one form: duration.minutes and duration.hours each take the singular at
 * one and the plural from two, and duration.hours_minutes is chosen by its hours, :count, the
 * minutes after them in the plural in both cases. The field
 * label Email keeps its English, the word Greek forms use for it, and is on the translation
 * test's shared list for that reason.
 */

return [
    // This language's own name, which labels the language switch on the other language's pages.
    'language' => 'Ελληνικά',

    // The one line naming the tenant's time zone.
    'zone' => 'Οι ώρες εμφανίζονται στη ζώνη ώρας :zone.',

    // The shell's sentences, rendered before or without the config in the start language.
    'shell' => [
        'loading' => 'Φορτώνονται οι ηλεκτρονικές κρατήσεις...',
        'unavailable' => 'Οι ηλεκτρονικές κρατήσεις δεν είναι διαθέσιμες αυτή τη στιγμή. Για να κλείσετε ραντεβού, επικοινωνήστε απευθείας μαζί μας.',
        'crashed' => 'Κάτι πήγε στραβά. Ανανεώστε τη σελίδα ή επικοινωνήστε απευθείας μαζί μας για να κλείσετε ραντεβού.',
    ],

    // What the client says itself for a failure that is no answer, or an answer with no sentence.
    'wire' => [
        'transport' => 'Κάτι πήγε στραβά κατά τη φόρτωση. Ελέγξτε τη σύνδεσή σας και δοκιμάστε ξανά.',
        'unworded' => 'Δεν ήταν δυνατό να ολοκληρωθεί το αίτημα. Δοκιμάστε ξανά.',
    ],

    // The frame's title on each step before done.
    'titles' => [
        'service' => 'Επιλέξτε υπηρεσίες',
        'staff' => 'Επιλέξτε επαγγελματία',
        'when' => 'Επιλέξτε ώρα',
        'details' => 'Τα στοιχεία σας',
        'review' => 'Επιβεβαιώστε την κράτησή σας',
    ],

    // The crumb above the step.
    'strip' => [
        'label' => 'Βήματα κράτησης',
        'names' => [
            'service' => 'Υπηρεσίες',
            'staff' => 'Επαγγελματίας',
            'when' => 'Ώρα',
            'details' => 'Στοιχεία',
        ],
        'confirm' => 'Επιβεβαίωση',
        'back' => 'Πίσω',
        'current' => 'Βρίσκεστε εδώ',
        'ahead' => 'Δεν έχετε φτάσει ακόμη εδώ',
    ],

    // The service step.
    'service' => [
        'empty' => 'Δεν υπάρχει τίποτα διαθέσιμο για κράτηση αυτή τη στιγμή.',
        'stale' => 'Δεν ήταν δυνατό να ανανεωθεί η λίστα των υπηρεσιών και ίσως δεν είναι ενημερωμένη.',
        'placeholder' => 'Μια υπηρεσία που δεν προσφέρεται πια',
        'chips' => 'Μετάβαση σε κατηγορία',
        'loading' => 'Φορτώνονται οι υπηρεσίες...',
        'other' => 'Λοιπές υπηρεσίες',
        'limit' => '{1} Μπορείτε να επιλέξετε μία υπηρεσία.|[2,*] Μπορείτε να επιλέξετε έως :count υπηρεσίες.',
        'removed' => 'Η υπηρεσία :service αφαιρέθηκε.',
    ],

    // The staff step.
    'staff' => [
        'any' => 'Οποιοσδήποτε επαγγελματίας',
        'any_line' => 'για τη μεγαλύτερη διαθεσιμότητα',
        'view_profile' => 'Προβολή προφίλ',
        'hide_profile' => 'Απόκρυψη προφίλ',
    ],

    // The when step.
    'when' => [
        'stale' => 'Δεν ήταν δυνατό να ανανεωθούν οι διαθέσιμες ώρες και ίσως δεν είναι ενημερωμένες.',
        'loading' => 'Φορτώνονται οι διαθέσιμες ώρες...',
        'hint' => 'Επιλέξτε ημέρα για να δείτε τις διαθέσιμες ώρες.',
        'date_caption' => 'Επιλέξτε ημερομηνία',
        'time_caption' => 'Επιλέξτε ώρα',
        'empty_day' => 'Δεν υπάρχουν διαθέσιμες ώρες αυτήν την ημέρα. Επιλέξτε άλλη ημερομηνία.',
        'next_available' => 'Επόμενη διαθέσιμη ημερομηνία',
        'no_times' => 'Χωρίς ώρες',
        'periods' => 'Ώρα της ημέρας',
        'period' => [
            'morning' => 'Πρωί',
            'afternoon' => 'Απόγευμα',
            'evening' => 'Βράδυ',
        ],
        'times' => '{1} :count ώρα|[2,*] :count ώρες',
        'closed_day' => ':date, χωρίς ώρες',
        'previous_week' => 'Προηγούμενη εβδομάδα',
        'next_week' => 'Επόμενη εβδομάδα',
    ],

    // The details step.
    'details' => [
        'busy' => 'Κρατάμε την ώρα σας...',
        'note' => 'Υπάρχει κάτι που πρέπει να γνωρίζουμε;',
        'name' => 'Όνομα',
        'email' => 'Email',
        'phone' => 'Τηλέφωνο (προαιρετικό)',
        'count' => ':count από :n χαρακτήρες',
        'blocked' => 'Οι ηλεκτρονικές κρατήσεις δεν είναι διαθέσιμες για αυτή τη διεύθυνση email. Επικοινωνήστε με την επιχείρηση.',
    ],

    // The review step.
    'review' => [
        'confirm' => 'Επιβεβαίωση κράτησης',
        'pay' => 'Συνέχεια στην πληρωμή',
        'confirming' => 'Επιβεβαιώνεται η κράτησή σας...',
        'restart' => 'Επιλέξτε άλλη ώρα',
        'important_info' => 'Σημαντικές πληροφορίες',
        'handed_off' => 'Η πληρωμή συνεχίζεται στη σελίδα πληρωμής. Για να αλλάξετε ώρα, επιλέξτε άλλη ώρα.',
    ],

    // The countdown on the review step.
    'countdown' => [
        'label' => 'Χρόνος για επιβεβαίωση',
        'near' => 'Απομένει λιγότερο από ένα λεπτό για να επιβεβαιώσετε.',
        'expired' => 'Ο χρόνος για επιβεβαίωση τελείωσε. Επιλέξτε άλλη ώρα.',
    ],

    // The done step, by the status the server returned, received for any other.
    'done' => [
        'sentences' => [
            'confirmed' => 'Η κράτησή σας επιβεβαιώθηκε.',
            'at_venue' => 'Η κράτησή σας επιβεβαιώθηκε. Θα πληρώσετε επί τόπου: δεν έγινε καμία ηλεκτρονική χρέωση.',
            'awaiting_approval' => 'Το αίτημα στάλθηκε. Θα επικοινωνήσουμε μαζί σας μόλις εξεταστεί.',
            'awaiting_payment' => 'Η κράτησή σας έχει δεσμευτεί και θα ολοκληρωθεί μόλις τακτοποιηθεί η πληρωμή.',
            'received' => 'Λάβαμε την κράτησή σας.',
        ],
        'headings' => [
            'confirmed' => 'Η κράτηση επιβεβαιώθηκε',
            'awaiting_approval' => 'Το αίτημα στάλθηκε',
            'awaiting_payment' => 'Η κράτηση δεσμεύτηκε',
            'received' => 'Η κράτηση ελήφθη',
        ],
    ],

    // The summary card beside the steps.
    'estimate' => [
        'label' => 'Εκτίμηση έως την επιβεβαίωση',
        'total' => 'Σύνολο',
        'continue' => 'Συνέχεια',
        'pick_a_time' => 'Επιλέξτε ώρα',
        'pick_a_service' => 'Επιλέξτε υπηρεσία για να ξεκινήσετε',
        'any' => ':duration με οποιονδήποτε επαγγελματία',
        'staff' => ':duration με :staff',
        'brief' => '{1} :count υπηρεσία - :duration|[2,*] :count υπηρεσίες - :duration',
        'remove' => 'Αφαίρεση της υπηρεσίας :service',
    ],

    // A sold item of a booking: who performs it.
    'summary' => [
        'staff' => 'με :staff',
    ],

    // The booking's own card, and Book again.
    'booking' => [
        'heading' => 'Η κράτησή σας',
        'total' => 'Σύνολο',
        'book_again' => 'Κλείστε ξανά',
    ],

    // The manage page.
    'manage' => [
        'neutral' => 'Αυτός ο σύνδεσμος δεν ισχύει πια. Επικοινωνήστε απευθείας μαζί μας για την κράτησή σας.',
        'loading' => 'Φορτώνεται η κράτησή σας...',
        'cancelling' => 'Ακυρώνεται η κράτησή σας...',
        'question' => 'Θέλετε σίγουρα να ακυρώσετε αυτήν την κράτηση;',
        'cancel' => 'Ακύρωση κράτησης',
        'yes' => 'Ναι, να ακυρωθεί',
        'keep' => 'Να μείνει η κράτηση',
        'try_again' => 'Δοκιμάστε ξανά',
        'trying' => 'Γίνεται νέα προσπάθεια...',
        'keep_to_reschedule' => 'Για να αλλάξετε ώρα, κρατήστε πρώτα αυτήν την κράτηση.',
        'booked_by' => 'Κράτηση από :customer',
        'statuses' => [
            'pending' => 'Αυτή η κράτηση δεν έχει οριστικοποιηθεί ακόμη.',
            'awaiting_approval' => 'Λάβαμε το αίτημά σας και θα επικοινωνήσουμε μαζί σας μόλις εξεταστεί.',
            'awaiting_payment' => 'Αυτή η κράτηση θα ολοκληρωθεί μόλις τακτοποιηθεί η πληρωμή.',
            'completed' => 'Αυτό το ραντεβού έχει πραγματοποιηθεί.',
            'no_show' => 'Αυτό το ραντεβού έχει καταγραφεί ως μη εμφάνιση. Επικοινωνήστε μαζί μας για να κλείσετε ξανά.',
            'cancelled' => 'Αυτή η κράτηση έχει ακυρωθεί.',
            'rejected' => 'Αυτό το αίτημα δεν έγινε δεκτό. Επικοινωνήστε απευθείας μαζί μας.',
            'expired' => 'Η ώρα που σας κρατούσαμε αποδεσμεύτηκε. Κάντε ξανά κράτηση.',
        ],
    ],

    // The reschedule dialog.
    'move' => [
        'pick' => 'Επιλέξτε νέα ώρα',
        'reschedule' => 'Αλλαγή ώρας',
        'close' => 'Κλείσιμο',
        'moving' => 'Αλλάζει η ώρα της κράτησής σας...',
        'moved' => 'Η ώρα της κράτησής σας άλλαξε.',
    ],

    // My bookings.
    'my_bookings' => [
        'title' => 'Οι κρατήσεις μου',
        'intro' => 'Συμπληρώστε τη διεύθυνση email με την οποία κάνατε την κράτηση και θα σας στείλουμε έναν σύνδεσμο για να δείτε τις κρατήσεις σας.',
        'email' => 'Διεύθυνση email',
        'send' => 'Στείλτε μου σύνδεσμο',
        'sending' => 'Γίνεται αποστολή...',
        'upcoming' => 'Επερχόμενες',
        'past' => 'Προηγούμενες',
        'none' => 'Δεν υπάρχουν κρατήσεις με τη διεύθυνσή σας εδώ.',
        'sign_out' => 'Αποσύνδεση',
        'signing_out' => 'Γίνεται αποσύνδεση...',
        'cancelled' => 'Η κράτησή σας ακυρώθηκε.',
    ],

    // A duration: minutes alone, hours alone, and hours with minutes, :count the minutes in the
    // first and the hours in the other two, the minutes' word chosen by their number in the first
    // and the hours' in the other two.
    'duration' => [
        'minutes' => '{1} :count λεπτό|[2,*] :count λεπτά',
        'hours' => '{1} :count ώρα|[2,*] :count ώρες',
        'hours_minutes' => '{1} :count ώρα :minutes λεπτά|[2,*] :count ώρες :minutes λεπτά',
    ],
];
