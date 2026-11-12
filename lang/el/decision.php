<?php

/*
 * The decision pages and the booking facts they share with the business's mails, in Greek: the
 * twin of lang/en/decision.php key for key, in the polite plural throughout; the keys and the
 * placeholders are that file's. A page reads them in its link's language, and a message reads the
 * facts in the language it is written in.
 *
 * The business is named after the word for a business, so its name needs no article of its own, as
 * in mail.php. An item is its service with its staff member after "with", as booking.php writes
 * it. The button carries the decision's noun, as a Greek button names an action. The email label
 * is written in Latin letters as Greek forms write it, the value booking.php's details form uses,
 * on the translation test's shared list for that reason. Service and staff names are the tenant's
 * own and are never here.
 */

return [
    // The confirm and the outcome page's title, the tenant named.
    'title' => 'Μια κράτηση στην επιχείρηση :business',

    // The confirm page: the sentence beside the decision's consequence, and the label of the box
    // a rejection offers for its reason.
    'confirm' => [
        'waiting' => 'Μια κράτηση περιμένει την απόφασή σας.',
        'reason' => 'Ο λόγος, αν θέλετε να τον αναφέρετε (προαιρετικά)',
    ],

    // The one decision a link carries: the word on the button and what pressing it does.
    'decisions' => [
        'approve' => [
            'verb' => 'Έγκριση',
            'consequence' => 'Με την έγκριση η κράτηση επιβεβαιώνεται και η ώρα μένει στον πελάτη.',
        ],
        'reject' => [
            'verb' => 'Απόρριψη',
            'consequence' => 'Με την απόρριψη το αίτημα κλείνει και η ώρα αποδεσμεύεται.',
        ],
    ],

    // The outcome page's first sentence, one per state, keyed by the status as the row spells it.
    'outcome' => [
        'pending' => 'Η κράτηση αυτή είναι ακόμη στον πελάτη, που δεν έχει ολοκληρώσει τη διαδικασία.',
        'awaiting_approval' => 'Το αίτημα αυτό περιμένει ακόμη απόφαση.',
        'awaiting_payment' => 'Η κράτηση αυτή εγκρίθηκε και περιμένει την πληρωμή της.',
        'confirmed' => 'Η κράτηση αυτή επιβεβαιώθηκε.',
        'completed' => 'Η κράτηση αυτή ολοκληρώθηκε.',
        'no_show' => 'Η κράτηση αυτή σημειώθηκε ως μη εμφάνιση.',
        'cancelled' => 'Η κράτηση αυτή ακυρώθηκε.',
        'rejected' => 'Το αίτημα αυτό απορρίφθηκε.',
        'expired' => 'Το αίτημα αυτό έληξε και η ώρα επέστρεψε στο πρόγραμμα.',
    ],

    // The outcome page's second sentence on a request whose window closed.
    'window' => [
        'expire' => 'Η προθεσμία για την απόφαση έληξε, οπότε η ώρα θα αποδεσμευτεί.',
        'auto_approve' => 'Η προθεσμία για την απόφαση έληξε, οπότε το αίτημα θα εγκριθεί αυτόματα.',
        'closed' => 'Η προθεσμία για την απόφαση έληξε.',
    ],

    // The one body every refused link gets, its title also its heading.
    'refused' => [
        'title' => 'Ο σύνδεσμος αυτός δεν λειτουργεί',
        'changed' => 'Ίσως αλλοιώθηκε στην πορεία, ή ίσως προοριζόταν για κάποιον άλλον.',
        'again' => 'Ζητήστε από όποιον διαχειρίζεται το πρόγραμμα να σας τον στείλει ξανά.',
    ],

    // The booking's facts: the customer's labels, the erased customer's line, an item with its
    // staff member, the total, and the line naming the clock.
    'facts' => [
        'customer' => 'Πελάτης',
        'email' => 'Email',
        'phone' => 'Τηλέφωνο',
        'erased' => 'Ο πελάτης διαγράφηκε',
        'item' => ':service με :staff',
        'total' => 'Σύνολο: :amount',
        'zone' => 'Οι ώρες αναφέρονται στη ζώνη ώρας :zone, αυτή που ακολουθείτε.',
    ],
];
