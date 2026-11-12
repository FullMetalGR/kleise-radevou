<?php

/*
 * The business's messages in Greek: the twin of lang/en/business-mail.php key for key, in the
 * polite plural throughout; the keys, the placeholders and the choice syntax are that file's.
 * Every message is read in its recipient's language: a user's own, else the tenant default, and a
 * staff row's address the tenant default.
 *
 * The readers are the business's own people, so the words are the admin's: a staff member is a
 * member of the team, a move of a booking is the admin's noun and verb for it, and the two
 * decisions take decision.php's verbs. The business is named after the word for a business, so
 * its name needs no article of its own, as in mail.php, and the platform's support is named after
 * the word for support, :name read as a neuter noun. Who acted is said in the passive, so one
 * sentence fits a request and a booking alike, and the person after the noun for their part,
 * customer or user, so any name reads. :from, :to and :end are instants as ICU spells them in
 * Greek, each after the article and preposition a time takes, as customer-mail.php writes them.
 * :label is the approval message's label as the Emails section shows it, between Greek quotation
 * marks. The customer's name and note and the service and staff names are the tenant's own and
 * are never here.
 */

return [
    // The heading over the customer's note, where they wrote one.
    'note' => [
        'heading' => 'Ο πελάτης πρόσθεσε:',
    ],

    // The decision links' captions, and the sentence a copy with no links prints in their place.
    'links' => [
        'approve' => 'Για να το εγκρίνετε:',
        'reject' => 'Για να το απορρίψετε:',
        'none' => 'Αυτό το αντίγραφο δεν έχει συνδέσμους για να αποφασίσετε, επειδή στάλθηκε στη διεύθυνση email ενός μέλους της ομάδας και όχι σε κάποιον χρήστη. Ένας κάτοχος ή ένας διευθυντής μπορεί να εγκρίνει ή να απορρίψει το αίτημα από το δικό του αντίγραφο αυτού του μηνύματος, όπως και ένας χρήστης που αντιστοιχεί σε αυτό το μέλος της ομάδας, από το δικό του.',
    ],

    // The new-booking notice, in its two forms.
    'new_booking' => [
        'checkout' => [
            'subject' => 'Νέα κράτηση στην επιχείρηση :business',
            'opening' => 'Μια νέα κράτηση στην επιχείρηση :business:',
        ],
        'paid' => [
            'subject' => 'Ελήφθη πληρωμή για μια κράτηση στην επιχείρηση :business',
            'opening' => 'Έφτασε μια πληρωμή για μια εγκεκριμένη κράτηση στην επιχείρηση :business, και η κράτηση είναι πλέον επιβεβαιωμένη:',
        ],
    ],

    // The approval request, while it waits and once settled.
    'approval_request' => [
        'waiting' => [
            'subject' => 'Ένα αίτημα στην επιχείρηση :business περιμένει απόφαση',
            'with_links' => 'Ένα αίτημα στην επιχείρηση :business περιμένει την απόφασή σας:',
            'without_links' => 'Ένα αίτημα στην επιχείρηση :business περιμένει απόφαση:',
        ],
        'settled' => [
            'subject' => 'Ένα αίτημα στην επιχείρηση :business έχει ήδη κλείσει',
            'opening' => 'Ένα αίτημα στην επιχείρηση :business περίμενε απόφαση:',
            'nothing_left' => 'Στο μεταξύ έκλεισε, οπότε δεν μένει τίποτα να αποφασίσετε.',
        ],
    ],

    // The nag about a request still waiting, and the deadline as a choice by the hours left.
    'approval_nag' => [
        'subject' => 'Ένα αίτημα στην επιχείρηση :business περιμένει ακόμη απόφαση',
        'with_links' => 'Ένα αίτημα στην επιχείρηση :business περιμένει ακόμη την απόφασή σας:',
        'without_links' => 'Ένα αίτημα στην επιχείρηση :business περιμένει ακόμη απόφαση:',
        'runs_out' => '{0} Η προθεσμία για την απόφαση λήγει στις :end, σε λιγότερο από μία ώρα.|{1} Η προθεσμία για την απόφαση λήγει στις :end, σε περίπου μία ώρα.|[2,*] Η προθεσμία για την απόφαση λήγει στις :end, σε περίπου :count ώρες.',
    ],

    // The notice of a request the clock approved, in its four forms.
    'auto_approved' => [
        'subject' => 'Ένα αίτημα στην επιχείρηση :business εγκρίθηκε αυτόματα',
        'opening' => 'Ένα αίτημα στην επιχείρηση :business εγκρίθηκε αυτόματα:',
        'confirmed' => [
            'told' => 'Κανείς δεν αποφάσισε εγκαίρως, οπότε το αίτημα εγκρίθηκε σύμφωνα με την πολιτική έγκρισης της υπηρεσίας. Η κράτηση επιβεβαιώθηκε και ο πελάτης ενημερώθηκε.',
            'untold' => 'Κανείς δεν αποφάσισε εγκαίρως, οπότε το αίτημα εγκρίθηκε σύμφωνα με την πολιτική έγκρισης της υπηρεσίας. Η κράτηση επιβεβαιώθηκε. Ο πελάτης δεν ενημερώθηκε, επειδή το μήνυμα «:label» είναι απενεργοποιημένο στις ρυθμίσεις της επιχείρησης :business.',
        ],
        'awaiting_payment' => [
            'told' => 'Κανείς δεν αποφάσισε εγκαίρως, οπότε το αίτημα εγκρίθηκε σύμφωνα με την πολιτική έγκρισης της υπηρεσίας. Η κράτηση περιμένει την πληρωμή της: στον πελάτη στάλθηκε σύνδεσμος για να πληρώσει, η ώρα μένει στο μεταξύ δεσμευμένη γι\' αυτόν, και η κράτηση επιβεβαιώνεται μόλις σας φτάσει η πληρωμή. Αν η πληρωμή δεν φτάσει εγκαίρως, η ώρα αποδεσμεύεται.',
            'untold' => 'Κανείς δεν αποφάσισε εγκαίρως, οπότε το αίτημα εγκρίθηκε σύμφωνα με την πολιτική έγκρισης της υπηρεσίας. Η κράτηση περιμένει την πληρωμή της. Ο πελάτης δεν ενημερώθηκε, επειδή το μήνυμα «:label» είναι απενεργοποιημένο στις ρυθμίσεις της επιχείρησης :business, οπότε δεν έχει σύνδεσμο για να πληρώσει και η ώρα αποδεσμεύεται όταν λήξει η δέσμευσή της.',
        ],
    ],

    // The notice of a cancellation: what was cancelled, and who cancelled it.
    'cancellation_notice' => [
        'request' => [
            'subject' => 'Ένα αίτημα στην επιχείρηση :business ακυρώθηκε',
            'opening' => 'Ένα αίτημα στην επιχείρηση :business ακυρώθηκε:',
            'means' => 'Δεν μένει τίποτα να αποφασίσετε.',
        ],
        'booking' => [
            'subject' => 'Μια κράτηση στην επιχείρηση :business ακυρώθηκε',
            'opening' => 'Μια κράτηση στην επιχείρηση :business ακυρώθηκε:',
            'means' => 'Η ώρα είναι ξανά ελεύθερη.',
        ],
        'by' => [
            'customer' => 'Ακυρώθηκε από τον πελάτη :customer.',
            'user' => 'Ακυρώθηκε από τον χρήστη :staff.',
            'removed' => 'Ακυρώθηκε από έναν χρήστη που στο μεταξύ αφαιρέθηκε από την ομάδα.',
            'support' => 'Ακυρώθηκε από την υποστήριξη του :name.',
            'system' => 'Ακυρώθηκε από το σύστημα.',
        ],
    ],

    // The notice of a move: what moved, with the start it left and the start it has, and who
    // moved it.
    'move_notice' => [
        'request' => [
            'subject' => 'Ένα αίτημα στην επιχείρηση :business μεταφέρθηκε',
            'opening' => 'Ένα αίτημα στην επιχείρηση :business μεταφέρθηκε. Ήταν για τις :from και τώρα είναι για τις :to:',
        ],
        'booking' => [
            'subject' => 'Μια κράτηση στην επιχείρηση :business μεταφέρθηκε',
            'opening' => 'Μια κράτηση στην επιχείρηση :business μεταφέρθηκε. Ήταν για τις :from και τώρα είναι για τις :to:',
        ],
        'by' => [
            'customer' => 'Μεταφέρθηκε από τον πελάτη :customer.',
            'user' => 'Μεταφέρθηκε από τον χρήστη :staff.',
            'removed' => 'Μεταφέρθηκε από έναν χρήστη που στο μεταξύ αφαιρέθηκε από την ομάδα.',
            'support' => 'Μεταφέρθηκε από την υποστήριξη του :name.',
            'system' => 'Μεταφέρθηκε από το σύστημα.',
        ],
    ],

    // The owners' notice of a payment that landed for a booking no longer waiting for it.
    'late_payment' => [
        'subject' => 'Μια πληρωμή για μια κράτηση στην επιχείρηση :business δεν χρησιμοποιήθηκε',
        'arrived' => 'Μια πληρωμή για μια κράτηση στην επιχείρηση :business έφτασε στον πάροχο πληρωμών σας ενώ η κράτηση δεν την περίμενε πια, οπότε η πληρωμή δεν χρησιμοποιήθηκε:',
        'reference' => 'Κωδικός κράτησης',
        'stands' => 'Η πληρωμή δεν επιβεβαίωσε τίποτα και η κράτηση παραμένει όπως ήταν. Δεν έχει γίνει καμία επιστροφή χρημάτων. Η επιστροφή της πληρωμής είναι δική σας απόφαση και γίνεται στον πίνακα ελέγχου του παρόχου πληρωμών σας, όχι εδώ.',
    ],
];
