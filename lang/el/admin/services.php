<?php

/*
 * The Services screen in Greek: the twin of lang/en/admin/services.php key for key, in the polite
 * plural throughout; the keys, the groups and the placeholders are that file's, and its header says
 * which code reads which group. The reader is the business's owner or manager: a staff member is a
 * member of the team, an appointment the customer's, the booking window and the minutes are the
 * words Greek booking tools write, a deadline to act is a deadline, and the approval's two outcomes
 * take decision.php's verbs. A refusal that names a value the form sends, a payment mode, a status
 * or a timeout, names it as sent. :staff follows the words for a member of the team or stands
 * before its parenthesis, :reason follows a colon, :currency its noun or stands in its parenthesis,
 * :n stands before the noun it counts, :count and :ticked where the English has them (in
 * table.minutes_gap :count is the gap, before its noun); staff.window keeps the English order.
 * Service names, descriptions and categories are the tenant's own text and never pass through here.
 *
 * Three keys are read as choices, table.count by the rows shown, table.minutes_gap by the gap
 * and staff.ticked by the members listed; the first and the last keep the English cases of one
 * and more than one, and the gap takes the singular at one, since a gap of one minute is reachable.
 */

return [
    'page' => [
        'title' => 'Υπηρεσίες',
        'new' => 'Νέα υπηρεσία',
        'show_inactive' => 'Εμφάνιση ανενεργών υπηρεσιών (:count)',
        'none' => 'Δεν υπάρχουν ακόμη υπηρεσίες',
        'none_sentence' => 'Υπηρεσία είναι ό,τι κλείνει ένας πελάτης. Προσθέστε την πρώτη με το κουμπί παραπάνω.',
        'detail_title' => 'Υπηρεσία',
        'loading' => 'Φορτώνονται οι υπηρεσίες',
        'list_error' => 'Δεν ήταν δυνατή η φόρτωση των υπηρεσιών. Ελέγξτε τη σύνδεσή σας και δοκιμάστε ξανά.',
        'not_found' => 'Δεν υπάρχει υπηρεσία με αυτό το ID. Μπορεί να έχει διαγραφεί.',
        'back' => 'Επιστροφή στη λίστα',
        'save_error' => 'Δεν ήταν δυνατή η αποθήκευση της υπηρεσίας. Ελέγξτε τη σύνδεσή σας και δοκιμάστε ξανά.',
        'delete_error' => 'Δεν ήταν δυνατή η διαγραφή της υπηρεσίας. Ελέγξτε τη σύνδεσή σας και δοκιμάστε ξανά.',
        'links_failed' => 'Η υπηρεσία αποθηκεύτηκε, αλλά δεν ήταν δυνατή η ενημέρωση των υπηρεσιών που παρέχει το μέλος της ομάδας :staff: :reason',
    ],
    'table' => [
        'title' => 'Μενού υπηρεσιών',
        'count' => '{1} Εμφανίζεται :count|[2,*] Εμφανίζονται :count',
        'id' => 'ID',
        'name' => 'Όνομα',
        'category' => 'Κατηγορία',
        'duration' => 'Διάρκεια',
        'price' => 'Τιμή',
        'payment' => 'Πληρωμή',
        'status' => 'Κατάσταση',
        'minutes' => ':n λεπτά',
        'minutes_gap' => '{1} :n λεπτά, με :count λεπτό αναμονής|[2,*] :n λεπτά, με :count λεπτά αναμονής',
        'online' => 'Online',
        'not_online' => 'Όχι online',
    ],
    'modes' => [
        'free' => 'Δωρεάν',
        'online' => 'Online',
        'onsite' => 'Επί τόπου',
    ],
    'timeouts' => [
        'expire' => 'Λήξη του αιτήματος',
        'auto_approve' => 'Αυτόματη έγκριση',
    ],
    'statuses' => [
        'active' => 'Ενεργή',
        'inactive' => 'Ανενεργή',
    ],
    'form' => [
        'breadcrumb' => 'Διαδρομή πλοήγησης',
        'list' => 'Υπηρεσίες',
        'basics' => 'Βασικά στοιχεία',
        'timing' => 'Χρόνοι',
        'rules' => 'Κανόνες κράτησης',
        'payment' => 'Πληρωμή',
        'name' => 'Όνομα',
        'status' => 'Κατάσταση',
        'description' => 'Περιγραφή',
        'description_help' => 'Εμφανίζεται στους πελάτες στη σελίδα κρατήσεων, κάτω από το όνομα της υπηρεσίας.',
        'category' => 'Κατηγορία',
        'category_help' => 'Οι υπηρεσίες της ίδιας κατηγορίας εμφανίζονται κάτω από αυτήν στη σελίδα κρατήσεων. Αφήστε το πεδίο κενό για καμία κατηγορία.',
        'online' => 'Διαθέσιμη online',
        'online_help' => 'Οι πελάτες μπορούν να την κλείσουν online. Αποεπιλέξτε το πλαίσιο για να είναι διαθέσιμη μόνο στις κρατήσεις που προσθέτετε εσείς.',
        'duration' => 'Διάρκεια (λεπτά)',
        'duration_help' => 'Πρέπει να είναι πολλαπλάσιο των :n λεπτών.',
        'gap' => 'Χρόνος αναμονής (λεπτά)',
        'gap_help' => 'Το διάστημα μετά την υπηρεσία κατά το οποίο ο πελάτης είναι ακόμη παρών αλλά το μέλος της ομάδας είναι ελεύθερο, όπως όσο δρα μια βαφή: δεν απασχολεί το μέλος της ομάδας, οπότε μια άλλη κράτηση μπορεί να ξεκινήσει μέσα σε αυτό.',
        'buffer_before' => 'Χρόνος πριν (λεπτά)',
        'buffer_before_help' => 'Τα λεπτά κατά τα οποία το μέλος της ομάδας είναι απασχολημένο πριν από την έναρξη της υπηρεσίας.',
        'buffer_after' => 'Χρόνος μετά (λεπτά)',
        'buffer_after_help' => 'Τα λεπτά κατά τα οποία το μέλος της ομάδας είναι απασχολημένο μετά τη λήξη της υπηρεσίας.',
        'lead_time' => 'Ελάχιστη προειδοποίηση (λεπτά)',
        'lead_time_help' => 'Πόσο νωρίτερα από την ώρα του ραντεβού πρέπει να κάνουν την online κράτησή τους οι πελάτες.',
        'horizon' => 'Χρονικό πλαίσιο κράτησης (ημέρες)',
        'horizon_help' => 'Πόσο μακριά στο μέλλον μπορούν να κλείσουν οι πελάτες που κάνουν online κράτηση.',
        'cancel_window' => 'Προθεσμία ακύρωσης (ώρες)',
        'cancel_window_help' => 'Μέχρι πόσο πριν από την έναρξη μπορεί ένας πελάτης να ακυρώσει ακόμη online.',
        'reschedule_window' => 'Προθεσμία αλλαγής ραντεβού (ώρες)',
        'reschedule_window_help' => 'Μέχρι πόσο πριν από την έναρξη μπορεί ένας πελάτης να αλλάξει ακόμη online το ραντεβού.',
        'approval' => 'Απαιτεί έγκριση',
        'hold' => 'Προθεσμία έγκρισης (ώρες)',
        'hold_help' => 'Πόσο χρόνο περιμένει ένα αίτημα απόφαση. Αφήστε το πεδίο κενό για την προεπιλογή της επιχείρησης.',
        'timeout' => 'Όταν λήξει η προθεσμία έγκρισης',
        'price' => 'Τιμή (:currency)',
        'price_help' => 'Γράψτε την όπως θα τη γράφατε συνήθως, π.χ. 12,50 ή 12.50.',
        'payment_mode' => 'Τρόπος πληρωμής',
        'save' => 'Αποθήκευση',
        'saving' => 'Γίνεται αποθήκευση',
        'needs_name' => 'Μια υπηρεσία χρειάζεται όνομα για να αποθηκευτεί.',
    ],
    'staff' => [
        'title' => 'Μέλη της ομάδας που την παρέχουν',
        'ticked' => '{1} Επιλεγμένα: :ticked από :count|[2,*] Επιλεγμένα: :ticked από :count',
        'inactive' => ':staff (ανενεργό μέλος)',
        'window' => ':weekday :time-:end',
        'no_hours' => 'Χωρίς εβδομαδιαίο ωράριο',
        'read_only' => 'Η λειτουργία «Ομάδα» δεν περιλαμβάνεται στο πακέτο σας, οπότε εδώ μπορείτε μόνο να δείτε ποιος παρέχει αυτήν την υπηρεσία.',
        'edit_hours' => 'Επεξεργασία ωραρίου στην οθόνη «Βάρδιες»',
        'see_hours' => 'Προβολή ωραρίου στην οθόνη «Βάρδιες»',
    ],
    'danger' => [
        'title' => 'Ζώνη κινδύνου',
        'delete' => 'Διαγραφή αυτής της υπηρεσίας',
        'deleting' => 'Γίνεται διαγραφή',
        'deactivate' => 'Απενεργοποίηση αντί για διαγραφή',
        'deactivating' => 'Γίνεται απενεργοποίηση',
    ],
    'validation' => [
        'name' => 'Μια υπηρεσία χρειάζεται όνομα.',
        'description' => 'Η περιγραφή πρέπει να είναι κείμενο.',
        'duration' => 'Η διάρκεια πρέπει να είναι ακέραιος αριθμός λεπτών μεγαλύτερος από το μηδέν.',
        'duration_grid' => 'Η διάρκεια πρέπει να είναι πολλαπλάσιο του βήματος των :n λεπτών.',
        'processing_gap' => 'Ο χρόνος αναμονής πρέπει να είναι μηδέν ή περισσότερα ακέραια λεπτά.',
        'buffer_before' => 'Ο χρόνος πριν πρέπει να είναι μηδέν ή περισσότερα ακέραια λεπτά.',
        'buffer_after' => 'Ο χρόνος μετά πρέπει να είναι μηδέν ή περισσότερα ακέραια λεπτά.',
        'price' => 'Η τιμή πρέπει να είναι μηδέν ή περισσότερο, στη μικρότερη μονάδα του νομίσματος.',
        'currency' => 'Μια νέα υπηρεσία τιμολογείται στο νόμισμα της επιχείρησης, :currency.',
        'currency_fixed' => 'Το νόμισμα μιας υπηρεσίας δεν μπορεί να αλλάξει.',
        'payment_mode' => 'Ο τρόπος πληρωμής πρέπει να είναι free, online ή onsite.',
        'requires_approval' => 'Το πεδίο «Απαιτεί έγκριση» πρέπει να είναι ναι ή όχι.',
        'approval_hold' => 'Η προθεσμία έγκρισης πρέπει να είναι ακέραιος αριθμός ωρών μεγαλύτερος από το μηδέν ή κενή για την προεπιλογή.',
        'timeout' => 'Η επιλογή για τη λήξη της προθεσμίας έγκρισης πρέπει να είναι expire ή auto_approve.',
        'cancel_window' => 'Η προθεσμία ακύρωσης πρέπει να είναι μηδέν ή περισσότερες ακέραιες ώρες.',
        'reschedule_window' => 'Η προθεσμία αλλαγής ραντεβού πρέπει να είναι μηδέν ή περισσότερες ακέραιες ώρες.',
        'lead_time' => 'Η ελάχιστη προειδοποίηση πρέπει να είναι μηδέν ή περισσότερα ακέραια λεπτά.',
        'horizon' => 'Το χρονικό πλαίσιο κράτησης πρέπει να είναι μηδέν ή περισσότερες ακέραιες ημέρες.',
        'horizon_max' => 'Το χρονικό πλαίσιο κράτησης μπορεί να είναι έως :n ημέρες.',
        'status' => 'Η κατάσταση πρέπει να είναι active ή inactive.',
        'status_new' => 'Μια νέα υπηρεσία ξεκινά ενεργή. Η κατάστασή της ορίζεται στη σελίδα της.',
        'category_text' => 'Η κατηγορία πρέπει να είναι μια λέξη ή μια σύντομη φράση, ή κενή για καμία κατηγορία.',
        'category_length' => 'Η κατηγορία μπορεί να έχει έως :n χαρακτήρες.',
        'online' => 'Το πεδίο «Διαθέσιμη online» πρέπει να είναι ναι ή όχι.',
    ],
    'refusals' => [
        'referenced' => 'Αυτή η υπηρεσία χρησιμοποιείται ακόμη από υπάρχουσες κρατήσεις και δεν μπορεί να διαγραφεί.',
    ],
    'flashes' => [
        'created' => 'Η υπηρεσία δημιουργήθηκε.',
        'saved' => 'Η υπηρεσία αποθηκεύτηκε.',
        'deactivated' => 'Η υπηρεσία απενεργοποιήθηκε.',
        'reactivated' => 'Η υπηρεσία ενεργοποιήθηκε ξανά.',
        'deleted' => 'Η υπηρεσία διαγράφηκε.',
    ],
];
