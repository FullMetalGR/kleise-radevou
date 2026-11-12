<?php

/*
 * The customer's messages in Greek: the twin of lang/en/customer-mail.php key for key, in the
 * polite plural throughout; the keys, the placeholders and the choice syntax are that file's.
 * Every message about a booking is read in the booking's language, bookings.language, so a
 * booking made in Greek is written to in Greek whatever its tenant's default becomes.
 *
 * The business is named after the word for a business, so its name needs no article of its own,
 * as in mail.php. The greeting is mail.php's own shape, the salutation and then the name on the
 * booking as it was typed. An item is its service with its staff member after "with", as
 * booking.php writes it. :from and :to are instants as ICU spells them in Greek, each after the
 * article and preposition a date takes. The bookings link's lifetime is spelled in words by ICU
 * in Greek and read as a choice by it, one and many. Service names and the business's name are
 * the tenant's own and are never here.
 */

return [
    // What every message to the customer opens with.
    'greeting' => 'Γεια σας :customer,',

    // The line naming the clock every instant above it is in, once, at the foot of a message to the
    // customer, the business named in the third person since the clock is theirs.
    'zone' => 'Οι ώρες αναφέρονται στη ζώνη ώρας :zone, αυτή που ακολουθεί η επιχείρηση :business.',

    // Book again, the link set after the words.
    'book_again' => 'Κλείστε ξανά ραντεβού: :link',

    // The closing line to the tenant's My bookings page.
    'my_bookings' => 'Δείτε όλες τις κρατήσεις σας: :link',

    // The calendar file's one event, named by its services and the business.
    'calendar' => [
        'summary' => ':service στην επιχείρηση :business',
    ],

    // The acknowledgement of a request a human has to say yes to.
    'acknowledged' => [
        'subject' => 'Το αίτημά σας στην επιχείρηση :business',
        'received' => 'Λάβαμε το αίτημά σας για :service στην επιχείρηση :business:',
        'deciding' => 'Το αίτημα βρίσκεται τώρα στην επιχείρηση :business, που θα το εγκρίνει ή θα το απορρίψει, και θα σας ενημερώσουμε σε κάθε περίπτωση.',
        'manage' => 'Μπορείτε να δείτε την κράτησή σας, και να την ακυρώσετε αν αλλάξουν τα σχέδιά σας, εδώ:',
    ],

    // The confirmation, and where the way back to the booking is.
    'confirmed' => [
        'subject' => 'Η κράτησή σας στην επιχείρηση :business επιβεβαιώθηκε',
        'confirmed' => 'Η κράτησή σας στην επιχείρηση :business επιβεβαιώθηκε:',
        'calendar' => 'Το ραντεβού επισυνάπτεται ως αρχείο ημερολογίου, ώστε να το προσθέσετε στο ημερολόγιό σας.',
        'manage' => 'Μπορείτε να δείτε την κράτησή σας, και να την ακυρώσετε αν αλλάξουν τα σχέδιά σας, εδώ:',
        'where_link' => [
            'acknowledged' => 'Για να δείτε την κράτησή σας, ή για να την ακυρώσετε αν αλλάξουν τα σχέδιά σας, χρησιμοποιήστε τον σύνδεσμο στο μήνυμα που σας στείλαμε όταν λάβαμε το αίτημά σας.',
            'unacknowledged' => 'Για να αλλάξετε ή να ακυρώσετε την κράτησή σας, επικοινωνήστε με την επιχείρηση :business.',
        ],
    ],

    // The approval of a request, in its two forms.
    'approved' => [
        'confirmed' => [
            'subject' => 'Το αίτημά σας στην επιχείρηση :business επιβεβαιώθηκε',
            'approved' => 'Το αίτημά σας στην επιχείρηση :business εγκρίθηκε και η κράτησή σας επιβεβαιώθηκε:',
            'where_link' => [
                'acknowledged' => 'Το ραντεβού επισυνάπτεται ως αρχείο ημερολογίου, ώστε να το προσθέσετε στο ημερολόγιό σας. Για να δείτε την κράτησή σας, ή για να την ακυρώσετε αν αλλάξουν τα σχέδιά σας, χρησιμοποιήστε τον σύνδεσμο στο μήνυμα που σας στείλαμε όταν λάβαμε το αίτημά σας.',
                'unacknowledged' => 'Το ραντεβού επισυνάπτεται ως αρχείο ημερολογίου, ώστε να το προσθέσετε στο ημερολόγιό σας. Για να αλλάξετε ή να ακυρώσετε την κράτησή σας, επικοινωνήστε με την επιχείρηση :business.',
            ],
        ],
        'awaiting_payment' => [
            'subject' => 'Το αίτημά σας στην επιχείρηση :business εγκρίθηκε: μένει ένα βήμα',
            'approved' => 'Το αίτημά σας στην επιχείρηση :business εγκρίθηκε. Η κράτηση επιβεβαιώνεται μόλις πληρωθεί:',
            'pay' => 'Για να πληρώσετε, ανοίξτε αυτόν τον σύνδεσμο:',
            'held' => 'Η ώρα παραμένει δεσμευμένη για εσάς όσο πληρώνετε. Αν η πληρωμή δεν μας φτάσει εγκαίρως, η ώρα αποδεσμεύεται και θα το δείτε στον σύνδεσμο.',
        ],
    ],

    // The refusal of a request.
    'rejected' => [
        'subject' => 'Το αίτημά σας στην επιχείρηση :business απορρίφθηκε',
        'declined' => 'Το αίτημά σας στην επιχείρηση :business απορρίφθηκε:',
        'free_again' => 'Δεν έγινε καμία κράτηση και η ώρα είναι ξανά ελεύθερη. Μπορείτε να ζητήσετε κάποια άλλη.',
    ],

    // The cancellation of a booking or a request, in its four forms.
    'cancelled' => [
        'request' => [
            'by_customer' => [
                'subject' => 'Το αίτημά σας στην επιχείρηση :business αποσύρθηκε',
                'cancelled' => 'Αποσύρατε το αίτημά σας στην επιχείρηση :business:',
                'means' => 'Δεν έγινε καμία κράτηση και η ώρα είναι ξανά ελεύθερη. Μπορείτε να ζητήσετε κάποια άλλη όποτε θέλετε.',
            ],
            'by_business' => [
                'subject' => 'Το αίτημά σας στην επιχείρηση :business ακυρώθηκε',
                'cancelled' => 'Η επιχείρηση :business ακύρωσε το αίτημά σας:',
                'means' => 'Δεν έγινε καμία κράτηση και η ώρα είναι ξανά ελεύθερη. Αν δεν το περιμένατε, επικοινωνήστε με την επιχείρηση :business.',
            ],
        ],
        'booking' => [
            'by_customer' => [
                'subject' => 'Η κράτησή σας στην επιχείρηση :business ακυρώθηκε',
                'cancelled' => 'Ακυρώσατε την κράτησή σας στην επιχείρηση :business:',
                'means' => 'Η ώρα είναι ξανά ελεύθερη για κάποιον άλλον, και δεν χρειάζεται να κάνετε τίποτα άλλο. Μπορείτε να κλείσετε ξανά ραντεβού όποτε θέλετε.',
            ],
            'by_business' => [
                'subject' => 'Η κράτησή σας στην επιχείρηση :business ακυρώθηκε',
                'cancelled' => 'Η επιχείρηση :business ακύρωσε την κράτησή σας:',
                'means' => 'Το ραντεβού δεν θα πραγματοποιηθεί. Αν δεν το περιμένατε, επικοινωνήστε με την επιχείρηση :business.',
            ],
        ],
    ],

    // The move of a request or a booking to another time.
    'moved' => [
        'request' => [
            'subject' => 'Η ώρα του αιτήματός σας στην επιχείρηση :business άλλαξε',
            'by_customer' => 'Αλλάξατε την ώρα του αιτήματός σας στην επιχείρηση :business. Ήταν για τις :from και τώρα είναι για τις :to:',
            'by_business' => 'Η επιχείρηση :business άλλαξε την ώρα του αιτήματός σας. Ήταν για τις :from και τώρα είναι για τις :to:',
        ],
        'booking' => [
            'subject' => 'Η ώρα της κράτησής σας στην επιχείρηση :business άλλαξε',
            'by_customer' => 'Αλλάξατε την ώρα της κράτησής σας στην επιχείρηση :business. Ήταν για τις :from και τώρα είναι για τις :to:',
            'by_business' => 'Η επιχείρηση :business άλλαξε την ώρα της κράτησής σας. Ήταν για τις :from και τώρα είναι για τις :to:',
        ],
        'calendar' => 'Το ενημερωμένο ραντεβού επισυνάπτεται ως αρχείο ημερολογίου. Ένα ημερολόγιο που έχει ήδη αυτό το ραντεβού ενημερώνει την καταχώρισή του όταν εισαχθεί το αρχείο.',
        'unchanged' => 'Τίποτα άλλο στο αίτημά σας δεν άλλαξε.',
        'awaiting_payment' => 'Η κράτησή σας περιμένει ακόμη την πληρωμή της και επιβεβαιώνεται μόλις μας φτάσει η πληρωμή.',
        'unexpected' => 'Αν δεν το περιμένατε, επικοινωνήστε με την επιχείρηση :business.',
    ],

    // The reminder before the appointment, and where the way back to it is.
    'reminder' => [
        'subject' => 'Υπενθύμιση για την κράτησή σας στην επιχείρηση :business',
        'reminder' => 'Σας υπενθυμίζουμε την κράτησή σας στην επιχείρηση :business:',
        'earlier' => 'Για να δείτε την κράτησή σας, ή για να την ακυρώσετε αν αλλάξουν τα σχέδιά σας, χρησιμοποιήστε τον σύνδεσμο σε ένα προηγούμενο μήνυμά μας για αυτήν. Αν δεν σας έφτασε κανένα προηγούμενο μήνυμα, επικοινωνήστε με την επιχείρηση :business.',
    ],

    // A hold the customer was waiting on that lapsed, in its two forms.
    'request_expired' => [
        'timed_out' => [
            'subject' => 'Το αίτημά σας στην επιχείρηση :business έληξε',
            'expired' => 'Κανείς στην επιχείρηση :business δεν απάντησε εγκαίρως στο αίτημά σας, οπότε αυτό έληξε:',
            'free_again' => 'Δεν έγινε καμία κράτηση και η ώρα είναι ξανά ελεύθερη. Μπορείτε να ζητήσετε κάποια άλλη.',
        ],
        'unpaid' => [
            'subject' => 'Η κράτησή σας στην επιχείρηση :business έληξε',
            'expired' => 'Το αίτημά σας στην επιχείρηση :business εγκρίθηκε, αλλά η πληρωμή του δεν μας έφτασε εγκαίρως, οπότε η κράτηση έληξε:',
            'free_again' => 'Δεν έγινε καμία κράτηση και η ώρα είναι ξανά ελεύθερη. Μπορείτε να κλείσετε κάποια άλλη.',
        ],
    ],

    // The bookings link a customer asked for on the hosted page, :count its lifetime in minutes
    // spelled out, read as a choice by it.
    'my_bookings_link' => [
        'subject' => 'Ο σύνδεσμος για τις κρατήσεις σας στην επιχείρηση :business',
        'greeting' => 'Γεια σας,',
        'here' => 'Αυτός είναι ο σύνδεσμος για να δείτε τις κρατήσεις σας στην επιχείρηση :business:',
        'once' => '{1} Ανοίγει μία φορά, μέσα σε ένα λεπτό. Αν δεν τον ζητήσατε, μπορείτε να αγνοήσετε αυτό το μήνυμα: δεν άλλαξε τίποτα.|[2,*] Ανοίγει μία φορά, μέσα σε :count λεπτά. Αν δεν τον ζητήσατε, μπορείτε να αγνοήσετε αυτό το μήνυμα: δεν άλλαξε τίποτα.',
    ],

    // The thanks once the business marks the booking completed.
    'thank_you' => [
        'subject' => 'Σας ευχαριστούμε για την επίσκεψή σας στην επιχείρηση :business',
        'thanks' => 'Σας ευχαριστούμε που επισκεφθήκατε την επιχείρηση :business. Ελπίζουμε να μείνατε ικανοποιημένοι από το ραντεβού σας:',
        'again' => 'Θα χαρούμε να σας δούμε ξανά.',
        'review' => 'Αφήστε μας μια κριτική: :link',
    ],
];
