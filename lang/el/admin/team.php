<?php

/*
 * The Team screen in Greek: the twin of lang/en/admin/team.php key for key, in the polite plural
 * throughout; the keys, the groups and the placeholders are that file's, and its header says which
 * code reads which group. The reader is the business's owner: the page's title is the rail's word
 * for the Team screen, the people who sign in being its users, and the word for the team is kept
 * for the staff alone: a staff member, the role and the staff row alike, is a member of the team,
 * as admin/staff.php and admin/customers.php write it. The owner and the manager are the words the
 * legal pages and the mails give the two roles. A user removed is said with the noun for a user
 * and no word for the team, and the link a user is sent is named after what it does, in the words
 * of the set-password form's button (admin/shell.php set_password.submit).
 *
 * :name is a name a person typed and is never declined: it always follows the noun for a user in
 * apposition, the noun carrying the article. :email follows the noun for an address in
 * apposition. :count is the link's hours as digits. A button is a noun, and a busy word the noun
 * after the same verb.
 *
 * Three keys are read as choices, page.count by the number of users and flashes.invited and
 * flashes.link_sent by the link's hours, each keeping the English cases of one and more than one.
 */

return [
    'page' => [
        'title' => 'Χρήστες',
        'count' => '{1} :count χρήστης|[2,*] :count χρήστες',
    ],
    'invite' => [
        'title' => 'Πρόσκληση νέου χρήστη',
        'name' => 'Όνομα',
        'email' => 'Email',
        'role' => 'Ρόλος',
        'send' => 'Αποστολή πρόσκλησης',
        'sending' => 'Γίνεται αποστολή...',
        'roles_help' => 'Ένας κάτοχος διαχειρίζεται τα πάντα, μαζί και τη χρέωση. Ένας διευθυντής διαχειρίζεται το ημερολόγιο, τις υπηρεσίες και τις ρυθμίσεις. Ένα μέλος της ομάδας βλέπει το δικό του ημερολόγιο και αποφασίζει για τα αιτήματα που υπάρχουν σε αυτό. Το άτομο που προσκαλείτε λαμβάνει σύνδεσμο για να ορίσει τον κωδικό του.',
    ],
    'roles' => [
        'owner' => 'Κάτοχος',
        'manager' => 'Διευθυντής',
        'staff' => 'Μέλος της ομάδας',
    ],
    'members' => [
        'title' => 'Όλοι οι χρήστες',
        'name' => 'Όνομα',
        'email' => 'Email',
        'staff_row' => 'Αντίστοιχο μέλος της ομάδας',
        'role' => 'Ρόλος',
        'actions' => 'Ενέργειες',
        'you' => 'Εσείς',
        'invited' => 'Εκκρεμεί πρόσκληση',
        'no_staff' => 'Δεν υπάρχει',
        'role_of' => 'Ρόλος του χρήστη :name',
        'link' => 'Αποστολή συνδέσμου ορισμού κωδικού',
        'sending' => 'Γίνεται αποστολή...',
        'remove' => 'Αφαίρεση',
        'remove_self' => 'Δεν μπορείτε να αφαιρέσετε τον εαυτό σας. Μπορεί να το κάνει ένας άλλος κάτοχος.',
        'remove_title' => 'Να αφαιρεθεί ο χρήστης :name;',
        'remove_body' => 'Ο χρήστης :name δεν θα μπορεί πια να συνδεθεί σε αυτήν την επιχείρηση. Ένα μέλος της ομάδας που αντιστοιχεί σε αυτόν τον χρήστη παραμένει, με το ωράριό του και τις κρατήσεις του.',
        'removing' => 'Γίνεται αφαίρεση...',
        'remove_pending' => 'Περιμένετε μέχρι να αφαιρεθεί ο χρήστης :name.',
    ],
    'wait' => 'Περιμένετε μέχρι να αποθηκευτεί η αλλαγή που βρίσκεται σε εξέλιξη.',
    'errors' => [
        'invite' => 'Δεν ήταν δυνατή η αποστολή της πρόσκλησης. Ελέγξτε τη σύνδεσή σας και δοκιμάστε ξανά.',
        'role' => 'Δεν ήταν δυνατή η αλλαγή του ρόλου. Ελέγξτε τη σύνδεσή σας και δοκιμάστε ξανά.',
        'link' => 'Δεν ήταν δυνατή η αποστολή του συνδέσμου. Ελέγξτε τη σύνδεσή σας και δοκιμάστε ξανά.',
        'remove' => 'Δεν ήταν δυνατή η αφαίρεση του χρήστη. Ελέγξτε τη σύνδεσή σας και δοκιμάστε ξανά.',
    ],
    'validation' => [
        'name' => 'Συμπληρώστε το όνομα του ατόμου που προσκαλείτε.',
        'email' => 'Συμπληρώστε μια έγκυρη διεύθυνση email.',
        'email_taken' => 'Κάποιος χρήστης της επιχείρησης συνδέεται ήδη με αυτή τη διεύθυνση email.',
        'role' => 'Επιλέξτε έναν από τους ρόλους που προσφέρονται.',
    ],
    'refusals' => [
        'last_owner' => 'Η επιχείρηση χρειάζεται τουλάχιστον έναν κάτοχο. Ορίστε πρώτα κάποιον άλλο ως κάτοχο.',
        'self' => 'Δεν μπορείτε να αφαιρέσετε τον εαυτό σας από τους χρήστες. Μπορεί να το κάνει ένας άλλος κάτοχος.',
    ],
    'flashes' => [
        'invited' => '{1} Μια πρόσκληση στέλνεται στη διεύθυνση email :email. Ο σύνδεσμός της λειτουργεί μία φορά, μέσα σε :count ώρα.|[2,*] Μια πρόσκληση στέλνεται στη διεύθυνση email :email. Ο σύνδεσμός της λειτουργεί μία φορά, μέσα σε :count ώρες.',
        'role_saved' => 'Ο ρόλος του χρήστη :name αποθηκεύτηκε.',
        'link_sent' => '{1} Ένας σύνδεσμος ορισμού κωδικού στέλνεται στη διεύθυνση email :email. Λειτουργεί μία φορά, μέσα σε :count ώρα.|[2,*] Ένας σύνδεσμος ορισμού κωδικού στέλνεται στη διεύθυνση email :email. Λειτουργεί μία φορά, μέσα σε :count ώρες.',
        'removed' => 'Ο χρήστης :name αφαιρέθηκε.',
    ],
];
