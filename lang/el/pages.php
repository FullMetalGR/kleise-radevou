<?php

/*
 * The sentences a customer meets on the tenant host's server pages, in Greek: the twin of
 * lang/en/pages.php key for key, in the polite plural throughout; the keys, the placeholders and
 * the choice syntax are that file's, and the words for a booking, a time, a professional and My
 * bookings are lang/el/booking.php's, so the pages and the booking app speak one Greek.
 *
 * The business is named after the word for a business, so its name needs no article of its own, as
 * in mail.php; the public name follows its preposition with none. A time follows its preposition as
 * a sign on a door writes it: with none after until (open_until), with the article after at, joined
 * into the preposition (opens_at). A choice line counts its Greek forms against the English case by
 * case, one and many. The link page's length in days is spelled in words by ICU in Greek, a number
 * whose word does not change with the noun's gender at the length the session holds today.
 * Instagram and Facebook are names written alike in both languages, on the translation test's
 * shared list for that reason.
 */

return [
    // The business page: the header's two links, the flow's visually hidden heading, the section
    // headings, the photo grid's way to the full gallery, read as a choice by the gallery's count,
    // the title block's open state with its time, the footer's line naming the platform, the hours
    // table's closed day, the address block's directions link, the contact pills' link labels, a
    // photo's alternative text with its place and the gallery's count, and the map image's
    // alternative text.
    'business' => [
        'book_now' => 'Κλείστε ραντεβού',
        'my_bookings' => 'Οι κρατήσεις μου',
        'flow_title' => 'Ηλεκτρονική κράτηση',
        'hours' => 'Ωράριο λειτουργίας',
        'find_us' => 'Πού θα μας βρείτε',
        'about' => 'Σχετικά με εμάς',
        'photos' => 'Φωτογραφίες',
        'see_all' => '{1} Δείτε τη φωτογραφία|[2,*] Δείτε όλες τις :count φωτογραφίες',
        'open_until' => 'Ανοιχτά έως :time',
        'opens_at' => 'Ανοίγει στις :time',
        'closed_today' => 'Κλειστά σήμερα',
        'booking_by' => 'Ηλεκτρονικές κρατήσεις μέσω :name',
        'team' => 'Η ομάδα μας',
        'closed' => 'Κλειστά',
        'directions' => 'Οδηγίες μετάβασης',
        'website' => 'Ιστότοπος',
        'instagram' => 'Instagram',
        'facebook' => 'Facebook',
        'reviews' => 'Διαβάστε τις κριτικές μας',
        'photo' => 'Φωτογραφία :n από :count',
        'map' => 'Χάρτης με την τοποθεσία μας, ανοίγει τις οδηγίες μετάβασης',
    ],

    // The mount element's only child on the hosted page, for a browser that runs no script.
    'noscript' => 'Οι ηλεκτρονικές κρατήσεις χρειάζονται JavaScript. Για να κλείσετε ραντεβού, επικοινωνήστε απευθείας μαζί μας.',

    // The page a My bookings link opens: its title naming the business, the sentence above its
    // one button, read as a choice by the session's length in days, :count that length spelled in
    // words, and the button.
    'link' => [
        'title' => 'Οι κρατήσεις σας στην επιχείρηση :business',
        'sentence' => '{1} Πατήστε το κουμπί για να δείτε εδώ τις κρατήσεις σας. Θα παραμείνετε συνδεδεμένοι σε αυτή τη συσκευή για μία ημέρα ή μέχρι να αποσυνδεθείτε.|[2,*] Πατήστε το κουμπί για να δείτε εδώ τις κρατήσεις σας. Θα παραμείνετε συνδεδεμένοι σε αυτή τη συσκευή για :count ημέρες ή μέχρι να αποσυνδεθείτε.',
        'label' => 'Προβολή των κρατήσεών μου',
    ],

    // What My bookings shows above the form or the list: the sentence each of its redirects
    // flashes, after the form, after a used, lapsed or unknown link and after the sign-out, and
    // the form's refusal past the link's limit.
    'my_bookings' => [
        'flashes' => [
            'sent' => 'Αν έχουμε κρατήσεις για αυτή τη διεύθυνση, σας στέλνουμε έναν σύνδεσμο.',
            'invalid' => 'Αυτός ο σύνδεσμος δεν ισχύει πια. Ζητήστε έναν νέο.',
            'signed_out' => 'Αποσυνδεθήκατε.',
        ],
        'refusals' => [
            'too_many' => 'Πάρα πολλά αιτήματα για αυτή τη διεύθυνση. Περιμένετε ένα λεπτό και δοκιμάστε ξανά.',
        ],
    ],

    // The pay hop's pages: the payment link's status page, its button and its heading and sentences
    // by the booking as it stands; the four worded pages, a hold too short to pay online, a hold no
    // longer held, a link that does not work and a payment page that could not be opened; and the
    // sentence each of the hop's redirects flashes onto the page the customer reads the booking on.
    'pay' => [
        'pay_now' => 'Πληρώστε τώρα',
        'waiting' => [
            'title' => 'Η κράτησή σας περιμένει την πληρωμή της',
            'approved' => 'Το αίτημά σας εγκρίθηκε. Η ώρα έχει δεσμευτεί για εσάς και η κράτηση επιβεβαιώνεται μόλις λάβουμε την πληρωμή σας.',
            'just_paid' => 'Αν μόλις πληρώσατε, η πληρωμή μπορεί να χρειαστεί λίγο χρόνο για να φτάσει σε εμάς: ανοίξτε ξανά αυτή τη σελίδα και, μόλις φτάσει, θα γράφει ότι η κράτηση επιβεβαιώθηκε. Μέχρι τότε το κουμπί σάς οδηγεί στη σελίδα πληρωμής και, αφού πληρώσετε, θα γράφει ότι η πληρωμή σας είναι σε εξέλιξη.',
        ],
        'confirmed' => 'Η κράτησή σας επιβεβαιώθηκε',
        'passed' => 'Η ώρα αυτής της κράτησης έχει περάσει',
        'nothing_to_pay' => 'Δεν υπάρχει τίποτα να πληρώσετε για αυτήν την κράτηση',
        'nothing_more' => 'Δεν χρειάζεται να κάνετε τίποτα άλλο εδώ.',
        'closed' => [
            'title' => 'Αυτή η κράτηση δεν μπορεί πια να πληρωθεί ηλεκτρονικά',
            'held' => 'Ο χρόνος δέσμευσης που απομένει είναι πολύ λίγος για να ανοίξει σελίδα πληρωμής και η δέσμευση δεν μπορεί να παραταθεί. Δεν έγινε καμία χρέωση.',
            'given_back' => 'Η ώρα αποδεσμεύεται όταν λήξει η δέσμευση. Αν τη θέλετε ακόμη, επικοινωνήστε απευθείας με την επιχείρηση.',
        ],
        'ended' => [
            'title' => 'Αυτή η κράτηση δεν είναι πια δεσμευμένη',
            'given_back' => 'Η ώρα που είχε δεσμευτεί αποδεσμεύτηκε, οπότε δεν υπάρχει τίποτα να πληρώσετε.',
            'again' => 'Επιστρέψτε στη σελίδα κρατήσεων για να επιλέξετε ξανά ώρα.',
        ],
        'refused' => [
            'title' => 'Αυτός ο σύνδεσμος δεν λειτουργεί',
            'sentence' => 'Ίσως αλλοιώθηκε στη διαδρομή ή η κράτηση στην οποία αναφερόταν έχει λήξει.',
        ],
        'unopened' => [
            'title' => 'Δεν ήταν δυνατό να ανοίξει η σελίδα πληρωμής',
            'charged' => 'Δεν έγινε καμία χρέωση και η κράτησή σας παραμένει δεσμευμένη.',
            'again' => 'Δοκιμάστε ξανά τον σύνδεσμο σε λίγο.',
        ],
        'flashes' => [
            'on_its_way' => 'Η πληρωμή σας είναι σε εξέλιξη. Η κράτησή σας επιβεβαιώνεται μόλις τη λάβουμε και αυτή η σελίδα θα το δείχνει όταν την ανοίξετε ξανά.',
            'at_the_venue' => 'Η κράτησή σας επιβεβαιώθηκε. Θα πληρώσετε επί τόπου: δεν έγινε καμία ηλεκτρονική χρέωση.',
            'settlement_lost' => 'Κάτι άλλαξε ενώ επιβεβαιώναμε την κράτησή σας, οπότε δεν έγινε τίποτα. Αυτή είναι η κράτησή σας όπως είναι τώρα.',
        ],
    ],

    // The page a suspended tenant's host answers on every route: its title, its heading and its
    // one sentence.
    'suspended' => [
        'title' => 'Οι κρατήσεις έχουν διακοπεί προσωρινά',
        'heading' => 'Οι κρατήσεις έχουν διακοπεί προσωρινά',
        'sentence' => 'Αυτή η σελίδα κρατήσεων δεν είναι διαθέσιμη αυτή τη στιγμή. Για να κλείσετε ή να αλλάξετε ένα ραντεβού, επικοινωνήστε απευθείας με την επιχείρηση.',
    ],
];
