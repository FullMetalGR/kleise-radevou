<?php

/*
 * The Customers screens and the manual booking wizard in Greek: the twin of
 * lang/en/admin/customers.php key for key, in the polite plural throughout; the keys, the groups
 * and the placeholders are that file's, and its header says which code reads which group. The
 * reader is the business's owner or manager, so the words are the admin's: a staff member is a
 * member of the team, a booking the record, a no-show its own noun, and a button a noun as Greek
 * interfaces write one. The erasure takes the noun legal/privacy.php, legal/dpa.php and
 * legal/terms.php use for it, and the export the noun legal/terms.php uses (dpa.php has only the
 * verb, privacy.php no word for it). profile.greek and profile.english name the language a
 * customer is written to, for the admin's user, so they are Greek words like any label. :author
 * follows the noun for a user, :when in block.since its preposition and article, :zone the words
 * for a time zone, :search the noun for the search, and :year and :names their prepositions; the
 * layouts of placeholders alone (notes.byline, wizard.step, wizard.when and the summary's two)
 * keep the English order.
 *
 * Six keys are read as choices. erasure.count, refusals.live and wizard.first_more keep both of
 * the English cases, one and more than one, since the code asks for each. validation.tags,
 * validation.note and validation.reason are chosen by a bound the code fixes above one, so their
 * Greek holds the one case the code can ask for. The customer's name, note and tags and the
 * service and staff names are the tenant's own and are never here.
 */

return [
    'page' => [
        'title' => 'Πελάτες',
        'search' => 'Αναζήτηση',
        'hint_contact' => 'Όνομα ή email',
        'hint_name' => 'Όνομα',
        'none' => 'Δεν υπάρχουν ακόμη πελάτες',
        'none_sentence' => 'Όποιος κάνει κράτηση εμφανίζεται εδώ, σε μία γραμμή για κάθε διεύθυνση email, μαζί με όλες τις κρατήσεις που έγιναν με αυτήν.',
        'no_match' => 'Κανένας πελάτης δεν ταιριάζει με αυτήν την αναζήτηση.',
        'list_error' => 'Δεν ήταν δυνατή η φόρτωση των πελατών. Ελέγξτε τη σύνδεσή σας και αλλάξτε την αναζήτηση για να δοκιμάσετε ξανά.',
        'open_error' => 'Δεν ήταν δυνατό το άνοιγμα της σελίδας του πελάτη. Ελέγξτε τη σύνδεσή σας και πατήστε ξανά το όνομά του.',
    ],
    'table' => [
        'count' => ':count από :total',
        'name' => 'Όνομα',
        'email' => 'Email',
        'phone' => 'Τηλέφωνο',
        'first_visit' => 'Πρώτη επίσκεψη',
        'last_visit' => 'Τελευταία επίσκεψη',
        'bookings' => 'Κρατήσεις',
        'also_booked_as' => 'Έκανε κράτηση και ως :names',
        'no_visit' => 'Καμία επίσκεψη ακόμη',
        'blocked' => 'Αποκλεισμός',
    ],
    'csv' => [
        'download' => 'Λήψη CSV',
        'downloading' => 'Γίνεται λήψη',
        'download_error' => 'Δεν ήταν δυνατή η λήψη των πελατών. Ελέγξτε τη σύνδεσή σας και δοκιμάστε ξανά.',
        'searched' => 'Πελάτες που ταιριάζουν με την αναζήτηση :search',
        'also_booked_as' => 'Έκανε κράτηση και ως',
        'yes' => 'Ναι',
        'no' => 'Όχι',
    ],
    'customer' => [
        'breadcrumb' => 'Διαδρομή πλοήγησης',
        'bookings_error' => 'Δεν ήταν δυνατή η φόρτωση των κρατήσεων. Ελέγξτε τη σύνδεσή σας και αλλάξτε σελίδα για να δοκιμάσετε ξανά.',
        'bookings_empty' => 'Δεν υπάρχουν κρατήσεις σε αυτήν τη σελίδα.',
        'book_now' => 'Κράτηση τώρα',
        'leave_error' => 'Δεν ήταν δυνατή η φόρτωση των πελατών. Ελέγξτε τη σύνδεσή σας και πατήστε ξανά «Πελάτες».',
    ],
    'tiles' => [
        'title' => 'Με μια ματιά',
        'upcoming' => 'Επερχόμενες',
        'completed' => 'Ολοκληρωμένες',
        'no_shows' => 'Μη εμφανίσεις',
        'cancelled' => 'Ακυρωμένες',
        'last_visit' => 'Τελευταία επίσκεψη',
        'spent' => 'Έχει ξοδέψει :amount.',
        'spent_none' => 'Δεν έχει ξοδέψει τίποτα ακόμη.',
        'book_now_help' => 'Η «Κράτηση τώρα» ανοίγει τον οδηγό νέας κράτησης με συμπληρωμένα το όνομα, το email και το τηλέφωνο αυτού του πελάτη.',
    ],
    'profile' => [
        'title' => 'Προφίλ',
        'tags' => 'Ετικέτες',
        'tags_help' => 'Λίγες λέξεις, χωρισμένες με κόμμα: VIP, τακτικός, βαφή.',
        'birthday' => 'Ημερομηνία γέννησης',
        'language' => 'Προτιμώμενη γλώσσα',
        'not_recorded' => 'Δεν έχει καταγραφεί',
        'greek' => 'Ελληνικά',
        'english' => 'Αγγλικά',
        'save' => 'Αποθήκευση',
        'saving' => 'Γίνεται αποθήκευση',
        'error' => 'Δεν ήταν δυνατή η αποθήκευση του προφίλ. Ελέγξτε τη σύνδεσή σας και πατήστε ξανά «Αποθήκευση».',
    ],
    'notes' => [
        'title' => 'Σημειώσεις',
        'new' => 'Νέα σημείωση',
        'help' => 'Τη βλέπουν μόνο τα μέλη της ομάδας.',
        'add' => 'Προσθήκη σημείωσης',
        'adding' => 'Γίνεται προσθήκη',
        'byline' => ':author, :when',
        'remove' => 'Αφαίρεση',
        'remove_label' => 'Αφαίρεση της σημείωσης του χρήστη :author, :when',
        'confirm_title' => 'Να αφαιρεθεί αυτή η σημείωση;',
        'confirm_body' => 'Η σημείωση αφαιρείται για όλη την ομάδα. Αυτή η ενέργεια δεν αναιρείται.',
        'confirm' => 'Αφαίρεση σημείωσης',
        'removing' => 'Γίνεται αφαίρεση',
        'pending' => 'Η σημείωση αφαιρείται.',
        'add_error' => 'Δεν ήταν δυνατή η προσθήκη της σημείωσης. Ελέγξτε τη σύνδεσή σας και πατήστε ξανά «Προσθήκη σημείωσης».',
        'remove_error' => 'Δεν ήταν δυνατή η αφαίρεση της σημείωσης. Ελέγξτε τη σύνδεσή σας και δοκιμάστε ξανά.',
    ],
    'block' => [
        'title' => 'Online κράτηση',
        'open' => 'Αυτή η διεύθυνση email μπορεί να κάνει online κράτηση.',
        'since' => 'Η online κράτηση είναι αποκλεισμένη από τις :when.',
        'reason' => 'Λόγος',
        'reason_help' => 'Για την ομάδα. Ο πελάτης βλέπει μόνο ότι η online κράτηση δεν είναι διαθέσιμη.',
        'no_reason' => 'Δεν καταγράφηκε λόγος.',
        'block' => 'Αποκλεισμός online κράτησης',
        'confirm_title' => 'Να αποκλειστεί η online κράτηση;',
        'confirm_body' => 'Αυτή η διεύθυνση email δεν θα μπορεί πια να κάνει κράτηση στη σελίδα κρατήσεων και θα βλέπει μόνο ότι η online κράτηση δεν είναι διαθέσιμη. Με την «Κράτηση τώρα» μπορείτε ακόμη να κάνετε κράτηση γι\' αυτήν.',
        'confirm' => 'Αποκλεισμός',
        'blocking' => 'Γίνεται αποκλεισμός',
        'pending' => 'Η online κράτηση αποκλείεται.',
        'unblock' => 'Άρση αποκλεισμού',
        'unblocking' => 'Γίνεται άρση αποκλεισμού',
        'error' => 'Δεν ήταν δυνατή η αλλαγή της ρύθμισης online κράτησης. Ελέγξτε τη σύνδεσή σας και δοκιμάστε ξανά.',
    ],
    'export' => [
        'label' => 'Εξαγωγή',
        'busy' => 'Γίνεται εξαγωγή',
        'error' => 'Δεν ήταν δυνατή η εξαγωγή των δεδομένων του πελάτη. Ελέγξτε τη σύνδεσή σας και δοκιμάστε ξανά.',
    ],
    'erasure' => [
        'title' => 'Ζώνη κινδύνου',
        'what' => 'Η διαγραφή αφαιρεί το όνομα, τη διεύθυνση email και το τηλέφωνο του πελάτη, καθώς και όσα έγραψε στη φόρμα κρατήσεων, από κάθε κράτηση που έκανε, και διατηρεί τις κρατήσεις ως καταχωρίσεις με τις υπηρεσίες και τις ώρες τους.',
        'erase' => 'Διαγραφή αυτού του πελάτη',
        'confirm_title' => 'Να διαγραφεί αυτός ο πελάτης;',
        'confirm' => 'Διαγραφή πελάτη',
        'busy' => 'Γίνεται διαγραφή',
        'pending' => 'Ο πελάτης διαγράφεται.',
        'count' => '{1} Από 1 κράτηση θα αφαιρεθούν το όνομα, η διεύθυνση email και το τηλέφωνο αυτού του πελάτη, καθώς και όσα έγραψε στη φόρμα κρατήσεων. Η κράτηση παραμένει ως καταχώριση με τις υπηρεσίες και τις ώρες της. Αυτή η ενέργεια δεν αναιρείται.|[2,*] Από :count κρατήσεις θα αφαιρεθούν το όνομα, η διεύθυνση email και το τηλέφωνο αυτού του πελάτη, καθώς και όσα έγραψε στη φόρμα κρατήσεων. Οι κρατήσεις παραμένουν ως καταχωρίσεις με τις υπηρεσίες και τις ώρες τους. Αυτή η ενέργεια δεν αναιρείται.',
        'error' => 'Δεν ήταν δυνατή η διαγραφή του πελάτη. Ελέγξτε τη σύνδεσή σας και δοκιμάστε ξανά.',
        'refused_title' => 'Αυτός ο πελάτης δεν μπορεί να διαγραφεί ακόμη',
        'close' => 'Κλείσιμο',
    ],
    'wizard' => [
        'title' => 'Νέα κράτηση',
        'back' => 'Πίσω',
        'cancel' => 'Ακύρωση',
        'continue' => 'Συνέχεια',
        'create' => 'Δημιουργία',
        'creating' => 'Γίνεται δημιουργία...',
        'steps' => [
            'services' => 'Υπηρεσίες',
            'when' => 'Ημερομηνία και ώρα',
            'customer' => 'Πελάτης',
        ],
        'step' => ':n. :step',
        'first_more' => '{1} :service + 1 ακόμη|[2,*] :service + :count ακόμη',
        'when' => ':date :time',
        'segment' => 'Μέρος :n',
        'service' => 'Υπηρεσία',
        'choose_service' => 'Επιλέξτε υπηρεσία',
        'staff' => 'Μέλος της ομάδας',
        'any_staff' => 'Οποιοδήποτε μέλος της ομάδας',
        'remove' => 'Αφαίρεση',
        'add_segment' => 'Προσθήκη μέρους',
        'same_staff' => 'Προτιμήστε το ίδιο μέλος της ομάδας για όλες τις υπηρεσίες',
        'zone' => 'Ώρες στη ζώνη ώρας :zone',
        'loading' => 'Φορτώνονται οι διαθέσιμες ώρες...',
        'no_times' => 'Δεν υπάρχουν διαθέσιμες ώρες αυτήν την ημέρα, επιλέξτε άλλη στο ημερολόγιο',
        'unreadable' => 'Δεν ήταν δυνατή η ανάγνωση των διαθέσιμων ωρών. Ανανεώστε τη σελίδα και δοκιμάστε ξανά.',
        'periods' => [
            'morning' => 'Πρωί',
            'afternoon' => 'Απόγευμα',
            'evening' => 'Βράδυ',
        ],
        'summary' => [
            'date' => 'Ημερομηνία',
            'time' => 'Ώρα',
            'service' => 'Υπηρεσία',
            'span' => ':time - :end',
            'service_staff' => ':service, :staff',
        ],
        'fields' => [
            'name' => 'Όνομα πελάτη',
            'email' => 'Email',
            'phone' => 'Τηλέφωνο',
        ],
        'create_failed' => 'Δεν ήταν δυνατή η δημιουργία της κράτησης. Ελέγξτε τη σύνδεσή σας και πατήστε ξανά «Δημιουργία».',
        'refused_segment' => ':sentence (μέρος :n)',
        'refused_segment_service' => ':sentence (μέρος :n, :service)',
    ],
    'validation' => [
        'tags' => '[2,*] Γράψτε έως :count ετικέτες, έως :n χαρακτήρες η καθεμία, χωρισμένες με κόμμα.',
        'birthday' => 'Επιλέξτε ημερομηνία γέννησης από το :year έως σήμερα ή αφήστε το πεδίο κενό.',
        'language' => 'Επιλέξτε μια γλώσσα από τη λίστα.',
        'note' => '[2,*] Γράψτε μια σημείωση με έως :count χαρακτήρες.',
        'reason' => '[2,*] Γράψτε έναν λόγο με έως :count χαρακτήρες ή αφήστε το πεδίο κενό.',
    ],
    'refusals' => [
        'live' => '{1} Αυτός ο πελάτης έχει 1 κράτηση που δεν έχει τελειώσει ακόμη. Ακυρώστε την πρώτα και μετά διαγράψτε τον πελάτη.|[2,*] Αυτός ο πελάτης έχει :count κρατήσεις που δεν έχουν τελειώσει ακόμη. Ακυρώστε τις πρώτα και μετά διαγράψτε τον πελάτη.',
    ],
    'flashes' => [
        'erased' => 'Ο πελάτης διαγράφηκε.',
        'booked' => 'Η κράτηση δημιουργήθηκε.',
        'profile_saved' => 'Το προφίλ αποθηκεύτηκε.',
        'note_added' => 'Η σημείωση προστέθηκε.',
        'note_removed' => 'Η σημείωση αφαιρέθηκε.',
        'blocked' => 'Η online κράτηση αποκλείστηκε.',
        'unblocked' => 'Έγινε άρση του αποκλεισμού της online κράτησης.',
    ],
];
