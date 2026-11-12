<?php

/*
 * The Calendar screen in Greek: the twin of lang/en/admin/calendar.php key for key, in the polite
 * plural throughout; the keys, the groups and the placeholders are that file's, and its header
 * says what fills each placeholder and which code reads which group. The screen is open all day
 * in front of everyone who works at the business, so the labels are short and a button is a noun
 * as Greek interfaces write one. A staff member is a member of the team; a booking's status, the
 * filters and Colour by take the words admin/bookings.php, admin/staff.php and admin/shell.php
 * use for the same things; the closure dialog's labels are admin/staff.php's closures group's,
 * word for word, and a closure drawn on a Day column reads admin/shifts.php's page.closed* lines.
 * :staff is a name a person typed, never declined and never given an article: it stands at the
 * head before a comma. who.picked counts the others beside the one named with a word that bends
 * by no gender, both cases kept. toolbar.previous and toolbar.next step a day, a week or a month,
 * so they name a direction and guess no noun's gender. The layouts of placeholders alone
 * (toolbar.day_title, grid.block and day.menu) keep the English order. The labels a sentence
 * quotes are this file's own Greek labels and the rail's, in Greek quotation marks.
 */

return [
    'page' => [
        'title' => 'Ημερολόγιο',
        'add' => 'Προσθήκη',
        'add_booking' => 'Νέα κράτηση',
        'add_closure' => 'Κλειστή περίοδος',
        'add_hint' => 'Κλειστή περίοδος είναι μια ημέρα ή ένα χρονικό διάστημα που κάποιο μέλος της ομάδας, ή όλη η επιχείρηση, δεν δέχεται κρατήσεις.',
        'load_error' => 'Δεν ήταν δυνατή η φόρτωση του ημερολογίου. Ελέγξτε τη σύνδεσή σας και αλλάξτε την προβολή ή την ημερομηνία για να δοκιμάσετε ξανά.',
        'colour_by_not_saved' => 'Δεν ήταν δυνατή η αποθήκευση των χρωμάτων. Ελέγξτε τη σύνδεσή σας και δοκιμάστε ξανά.',
        'footnote' => 'Η προβολή «Εβδομάδα» διατηρεί τον πίνακα των ωρών, περιορισμένο από την επιλογή «Ποιοι»: ένα μέλος της ομάδας εμφανίζεται με τις επτά ημέρες του, ενώ περισσότερα εμφανίζονται το ένα πάνω στο άλλο, όπως πριν. Η προβολή «Μήνας» δείχνει πόσες κρατήσεις έχει κάθε ημέρα. Οι ώρες που εμφανίζονται έρχονται από την οθόνη «Βάρδιες», για την ημέρα που εμφανίζεται.',
        'unlinked' => 'Ο λογαριασμός σας δεν αντιστοιχεί σε μέλος της ομάδας, οπότε δεν υπάρχει τίποτα να εμφανιστεί εδώ. Ο κάτοχος κάνει την αντιστοίχιση από την οθόνη «Ομάδα».',
    ],
    'toolbar' => [
        'view' => 'Προβολή',
        'views' => [
            'week' => 'Εβδομάδα',
            'day' => 'Ημέρα',
            'month' => 'Μήνας',
        ],
        'today' => 'Σήμερα',
        'previous' => 'Πίσω',
        'next' => 'Εμπρός',
        'day_title' => ':weekday :date',
    ],
    'filters' => [
        'label' => 'Φίλτρα',
        'status' => 'Κατάσταση',
        'any_status' => 'Οποιαδήποτε κατάσταση',
        'service' => 'Υπηρεσία',
        'all_services' => 'Όλες οι υπηρεσίες',
        'inactive_service' => ':service (ανενεργή υπηρεσία)',
        'colour_by' => 'Χρωματισμός κρατήσεων κατά',
        'colour_by_choices' => [
            'staff' => 'Μέλος της ομάδας',
            'category' => 'Κατηγορία υπηρεσίας',
            'status' => 'Κατάσταση',
        ],
        'colour_by_locked' => 'Μόνο όποιος διαχειρίζεται τις ρυθμίσεις μπορεί να αλλάξει τα χρώματα. Ισχύουν για όλους.',
        'colour_by_saving' => 'Γίνεται αποθήκευση των χρωμάτων.',
        'colour_by_note' => 'Τα χρώματα ισχύουν για όλους στην επιχείρηση.',
    ],
    'who' => [
        'label' => 'Ποιοι',
        'note' => 'Η «Προγραμματισμένη ομάδα» δείχνει όλους όσοι έχουν ωράριο την ημέρα που εμφανίζεται. Ένα μέλος της ομάδας που συνδέεται με τον δικό του λογαριασμό βλέπει εδώ το «Εγώ» και δεν μπορεί να το αλλάξει.',
        'pick' => 'Ή επιλέξτε ποιοι θα εμφανίζονται',
        'scheduled' => 'Προγραμματισμένη ομάδα',
        'all' => 'Όλα τα μέλη της ομάδας',
        'me' => 'Εγώ',
        'picked' => '{1} :staff και :count ακόμη|[2,*] :staff και :count ακόμη',
    ],
    'legend' => [
        'staff' => 'Χρώματα κατά μέλος της ομάδας',
        'category' => 'Χρώματα κατά κατηγορία',
        'status' => 'Χρώματα κατά κατάσταση',
        'no_category' => 'Χωρίς κατηγορία',
        'badges' => 'Ενδείξεις',
        'closures' => 'Κλειστές περίοδοι και ωράριο',
        'closure' => 'Κλειστή περίοδος',
        'outside' => 'Εκτός ωραρίου εργασίας του μέλους',
        'gap' => 'Χρόνος αναμονής: ελεύθερος, αλλά δεν δέχεται κρατήσεις',
        'hint' => 'Σύρετε ή πατήστε σε μια κενή θέση για να ξεκινήσετε εκεί μια κράτηση.',
    ],
    'badges' => [
        'pending' => 'Σε εκκρεμότητα',
        'payment' => 'Εγκρίθηκε, περιμένει την πληρωμή του πελάτη',
    ],
    'grid' => [
        'block' => ':customer, :service',
        'block_erased' => 'Διαγραμμένος πελάτης, :service',
        'gap' => 'Χρόνος αναμονής',
        'week_needs_width' => 'Η προβολή «Εβδομάδα» χρειάζεται πλατύτερη οθόνη.',
        'to_day' => 'Άνοιγμα της προβολής «Ημέρα»',
    ],
    'day' => [
        'other_bookings' => 'Άλλες κρατήσεις',
        'not_working' => 'Δεν εργάζεται αυτήν την ημέρα',
        'no_hours_ahead' => 'Χωρίς ωράριο τις επόμενες οκτώ εβδομάδες',
        'next_working' => 'Χωρίς ωράριο αυτήν την ημέρα. Επόμενη ημέρα εργασίας: :weekday :date :hours',
        'menu' => ':staff, :date',
        'book' => 'Νέα κράτηση εδώ',
        'close' => 'Κλειστή περίοδος εδώ',
        'edit' => 'Επεξεργασία του ωραρίου αυτής της ημέρας',
        'staff_page' => 'Άνοιγμα της σελίδας του μέλους',
    ],
    'closure' => [
        'title' => 'Νέα κλειστή περίοδος',
        'who' => 'Ποιον αφορά',
        'whole_business' => 'Όλη η επιχείρηση',
        'date' => 'Ημερομηνία',
        'reason' => 'Λόγος (προαιρετικά)',
        'all_day' => 'Κλειστά όλη την ημέρα',
        'start' => 'Έναρξη',
        'end' => 'Λήξη',
        'cancel' => 'Ακύρωση',
        'add' => 'Προσθήκη',
        'adding' => 'Γίνεται προσθήκη',
        'not_added' => 'Δεν ήταν δυνατή η προσθήκη της κλειστής περιόδου. Ελέγξτε τη σύνδεσή σας και δοκιμάστε ξανά.',
    ],
    'validation' => [
        'colour_by' => 'Επιλέξτε πώς θα χρωματίζεται το ημερολόγιο από τις επιλογές που προσφέρονται.',
    ],
];
