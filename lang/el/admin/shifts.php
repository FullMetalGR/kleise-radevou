<?php

/*
 * The Shifts screen in Greek: the twin of lang/en/admin/shifts.php key for key, in the polite
 * plural throughout; the keys, the groups and the placeholders are that file's, and its header says
 * what fills each placeholder and which code reads which group. The reader is the owner or a
 * manager setting the team's hours, so the words are the admin's: a staff member is a member of the
 * team, a window of hours the noun admin/shell.php's weekly editor uses, the repeating pattern the
 * noun admin/staff.php's pattern group uses, a closure and closed the words admin/staff.php's
 * closures group writes, and a button a noun as Greek interfaces write one. The figures (:hours,
 * :minutes) arrive as ICU's Greek figures with their units, so the lines around them name no unit,
 * the two editors' hours lines among them. :name is a name a person typed, or the business's own,
 * so it is never declined and never given an article: it stands at the head before a comma or after
 * a colon. :from in row_cadence takes its article before a date in digits, as admin/staff.php's
 * pattern.cadence does; every other date stands bare, after the name and a comma in page.edit_day.
 * The layouts of placeholders alone (page.day_head, page.hours_minutes and editor.title) keep the
 * English order. The labels a sentence quotes are this file's own Greek labels and the rail's, in
 * Greek quotation marks, and page.footnote also quotes a section heading of the Settings screen,
 * admin/settings.php's sections.opening_hours.
 */

return [
    'page' => [
        'title' => 'Βάρδιες',
        'lead' => 'Οι ώρες κατά τις οποίες κάθε μέλος της ομάδας δέχεται κρατήσεις, εβδομάδα προς εβδομάδα. Το κελί μιας ημέρας αλλάζει μόνο εκείνη την ημέρα. Το «Ωράριο» αλλάζει την εβδομάδα που επαναλαμβάνεται.',
        'week' => ':from έως :to',
        'this_week' => 'Αυτή η εβδομάδα',
        'previous' => 'Μετάβαση στην προηγούμενη εβδομάδα',
        'next' => 'Μετάβαση στην επόμενη εβδομάδα',
        'loading' => 'Φορτώνεται η εβδομάδα',
        'read_error' => 'Δεν ήταν δυνατή η φόρτωση της εβδομάδας. Ελέγξτε τη σύνδεσή σας και δοκιμάστε ξανά.',
        'member' => 'Μέλος της ομάδας',
        'day_head' => ':weekday :date',
        'hours_minutes' => ':hours :minutes',
        'row_weekly' => ':hours αυτήν την εβδομάδα, κάθε εβδομάδα',
        'row_cadence' => ':hours αυτήν την εβδομάδα, κάθε :weeks εβδομάδες από τις :from',
        'row_none' => ':hours αυτήν την εβδομάδα, χωρίς επαναλαμβανόμενο ωράριο',
        'not_working' => 'Δεν εργάζεται',
        'this_day_only' => 'Μόνο αυτήν την ημέρα',
        'closed_all_day' => 'Κλειστά όλη την ημέρα',
        'closed' => 'Κλειστά :start-:end',
        'closed_reason' => 'Κλειστά :start-:end, :reason',
        'closed_all_day_reason' => 'Κλειστά όλη την ημέρα, :reason',
        'edit_day' => 'Επεξεργασία ωραρίου: :name, :date',
        'pattern' => 'Ωράριο',
        'edit_pattern' => 'Επεξεργασία επαναλαμβανόμενου ωραρίου: :name',
        'legend_override' => ':sample αλλαγή μόνο για εκείνη την ημέρα',
        'legend_off' => ':sample χωρίς ωράριο εκείνη την ημέρα',
        'legend_closed' => 'Κλειστά',
        'legend_closure' => ':sample κλειστή περίοδος, που ορίζεται στην οθόνη «Ομάδα» ή στο «Ημερολόγιο»',
        'footnote' => 'Οι πελάτες κάνουν κρατήσεις μέσα σε αυτές τις ώρες. Το «Ωράριο λειτουργίας» στις «Ρυθμίσεις» περιγράφει την επιχείρηση. Σε μια κλειστή ημέρα κανείς δεν δέχεται κρατήσεις.',
        'empty' => 'Δεν υπάρχουν ενεργά μέλη της ομάδας για προγραμματισμό.',
    ],
    'editor' => [
        'title' => ':name, :weekday :date',
        'help' => 'Μόνο αυτή η ημέρα. Το επαναλαμβανόμενο ωράριο δεν αλλάζει.',
        'start' => 'Έναρξη',
        'end' => 'Λήξη',
        'remove' => 'Αφαίρεση',
        'add_first' => 'Προσθήκη διαστήματος',
        'add_second' => 'Προσθήκη δεύτερου διαστήματος',
        'hours' => ':hours αυτήν την ημέρα.',
        'pattern_gives' => 'Το επαναλαμβανόμενο ωράριο ορίζει: :windows.',
        'pattern_none' => 'Το επαναλαμβανόμενο ωράριο δεν ορίζει ώρες.',
        'not_working' => 'Δεν εργάζεται αυτήν την ημέρα',
        'back' => 'Επαναφορά στο επαναλαμβανόμενο ωράριο',
        'cancel' => 'Ακύρωση',
        'save' => 'Αποθήκευση',
        'saving' => 'Γίνεται αποθήκευση',
        'going_back' => 'Γίνεται επαναφορά',
        'not_saved' => 'Δεν ήταν δυνατή η αποθήκευση της ημέρας. Ελέγξτε τη σύνδεσή σας και δοκιμάστε ξανά.',
    ],
    'pattern' => [
        'title' => ':name, επαναλαμβανόμενο ωράριο',
        'note' => 'Οι αλλαγές που αποθηκεύετε ισχύουν για κάθε εβδομάδα από την ημερομηνία έναρξης και μετά. Οι ημέρες που έχουν ήδη αλλάξει μεμονωμένα διατηρούν το ωράριό τους.',
        'repeats' => 'Επανάληψη',
        'every_week' => 'Κάθε εβδομάδα',
        'every_weeks' => 'Κάθε :weeks εβδομάδες',
        'starts_on' => 'Ημερομηνία έναρξης',
        'ends' => 'Λήγει',
        'never' => 'Ποτέ',
        'on_date' => 'Σε συγκεκριμένη ημερομηνία',
        'ends_on' => 'Ημερομηνία λήξης',
        'help_weekly' => 'Κάθε εβδομάδα: αυτές οι ώρες επαναλαμβάνονται κάθε εβδομάδα.',
        'help_cadence' => 'Κάθε :weeks εβδομάδες: η εβδομάδα της ημερομηνίας έναρξης και έπειτα κάθε :weeks εβδομάδες. Οι άλλες εβδομάδες δεν έχουν ώρες, εκτός αν οριστεί μεμονωμένα κάποια ημέρα τους.',
        'not_working' => 'Δεν εργάζεται',
        'start' => 'Έναρξη',
        'end' => 'Λήξη',
        'window' => ':start έως :end',
        'remove' => 'Αφαίρεση',
        'add_second' => 'Προσθήκη δεύτερου διαστήματος',
        'hours' => ':hours την εβδομάδα. Σε κλειστή ημέρα δεν προγραμματίζονται ώρες.',
        'cancel' => 'Ακύρωση',
        'save' => 'Αποθήκευση ωραρίου',
        'saving' => 'Γίνεται αποθήκευση',
        'not_saved' => 'Δεν ήταν δυνατή η αποθήκευση του επαναλαμβανόμενου ωραρίου. Ελέγξτε τη σύνδεσή σας και δοκιμάστε ξανά.',
    ],
    'validation' => [
        'cadence' => 'Το πεδίο «Επανάληψη» πρέπει να είναι κάθε εβδομάδα ή κάθε 2, 3 ή 4 εβδομάδες.',
        'starts_on' => 'Το πεδίο «Ημερομηνία έναρξης» πρέπει να είναι ημερομηνία, π.χ. 2026-11-02.',
        'ends_on' => 'Το πεδίο «Ημερομηνία λήξης» πρέπει να είναι η ίδια ημέρα με την «Ημερομηνία έναρξης» ή μεταγενέστερη, ή κενό.',
        'day_list' => 'Στείλτε τα διαστήματα της ημέρας ως λίστα, κενή για ημέρα χωρίς εργασία.',
        'day_times' => 'Κάθε διάστημα έχει ώρα έναρξης και ώρα λήξης, π.χ. 09:00 και 17:00.',
        'day_order' => 'Κάθε διάστημα πρέπει να ξεκινά πριν από τη λήξη του.',
        'day_overlap' => 'Δύο διαστήματα της ίδιας ημέρας δεν πρέπει να επικαλύπτονται.',
    ],
    'flashes' => [
        'pattern_saved' => 'Το επαναλαμβανόμενο ωράριο αποθηκεύτηκε.',
        'day_saved' => 'Η ημέρα αποθηκεύτηκε.',
        'day_reset' => 'Η ημέρα επανήλθε στο επαναλαμβανόμενο ωράριο.',
    ],
];
