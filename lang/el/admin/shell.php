<?php

/*
 * The admin's shell in Greek: the twin of lang/en/admin/shell.php key for key, in the polite
 * plural throughout; the keys, the groups and the placeholders are that file's, and its header
 * says which code reads which group. The reader is the business's owner, manager or a member of
 * the team, so the words are the admin's: the rail's entries name the screens as
 * admin/customers.php and admin/services.php already name them (Customers, Services, Shifts, New
 * booking), the Staff screen is the team and the Team screen its users, a button is a noun as
 * Greek interfaces write one, and a busy word is the noun after "Ginetai" as those two files write
 * theirs. The sign-in's and the password's words are signup.php's, and the set-password form's two
 * refusals are its password lines word for word. The kit's booking statuses take the past form
 * where the state is an event (confirmed, cancelled) and the present where it is a state that
 * lasts (the two that wait), a no-show its own noun. kit.counted is each status's line with its
 * count, the word after the figure in lower case as a sentence goes on, the verb in the plural
 * from two, the no-show's noun its own plural and pending one form, a phrase that does not bend.
 *
 * The four upload sizes are read as choices by their megabytes, :n the figure spelled by ICU in
 * Greek, neuter as the unit is; both cases are kept, the unit's abbreviation not bending. The
 * eight bounded lines of kit.counted are read as choices by their count too, :count the figure
 * formatCount writes, grouped as Greek groups it (1.234). Email,
 * Instagram, Facebook and Google are the same in both languages and on the shared list.
 */

return [
    'nav' => [
        'groups' => [
            'schedule' => 'Προγραμματισμός',
            'business' => 'Επιχείρηση',
        ],
        'home' => 'Αρχική',
        'calendar' => 'Ημερολόγιο',
        'bookings' => 'Κρατήσεις',
        'shifts' => 'Βάρδιες',
        'services' => 'Υπηρεσίες',
        'staff' => 'Ομάδα',
        'closures' => 'Κλειστές περίοδοι',
        'customers' => 'Πελάτες',
        'reports' => 'Αναφορές',
        'team' => 'Χρήστες',
        'settings' => 'Ρυθμίσεις',
        'billing' => 'Χρέωση',
        'main' => 'Κύρια πλοήγηση',
        'menu' => 'Μενού',
    ],
    'login' => [
        'title' => 'Σύνδεση',
        'heading' => 'Σύνδεση',
        'email' => 'Email',
        'password' => 'Κωδικός πρόσβασης',
        'submit' => 'Σύνδεση',
        'submitting' => 'Γίνεται σύνδεση...',
    ],
    'set_password' => [
        'title' => 'Ορίστε τον κωδικό πρόσβασής σας',
        'password' => 'Νέος κωδικός πρόσβασης',
        'help' => 'Τουλάχιστον :floor χαρακτήρες.',
        'submit' => 'Ορισμός κωδικού',
        'submitting' => 'Γίνεται ορισμός κωδικού...',
    ],
    'top_bar' => [
        'new_booking' => 'Νέα κράτηση',
        'light' => 'Φωτεινό θέμα',
        'dark' => 'Σκούρο θέμα',
        'sign_out' => 'Αποσύνδεση',
        'signing_out' => 'Γίνεται αποσύνδεση...',
    ],
    'banners' => [
        'support' => [
            'line' => 'Έχετε συνδεθεί ως Υποστήριξη (:operator) έως :end (:zone).',
            'end' => 'Τερματισμός συνεδρίας',
            'ending' => 'Γίνεται τερματισμός συνεδρίας',
        ],
        'billing' => 'Χρέωση',
        'features' => [
            'staff' => [
                'name' => 'Ομάδα',
                'gone' => 'Η λειτουργία «Ομάδα» δεν περιλαμβάνεται πια στο πακέτο σας: κάθε μέλος της ομάδας διατηρεί το ωράριό του και οι κρατήσεις συνεχίζουν να το χρησιμοποιούν, και η λειτουργία επανέρχεται μόλις την προσθέσετε στην οθόνη :billing.',
                'gone_free' => 'Η λειτουργία «Ομάδα» δεν περιλαμβάνεται πια στο πακέτο σας: κάθε μέλος της ομάδας διατηρεί το ωράριό του και οι κρατήσεις συνεχίζουν να το χρησιμοποιούν, και η λειτουργία επανέρχεται μόλις η υποστήριξη της πλατφόρμας την προσθέσει σε αυτόν τον δωρεάν λογαριασμό.',
            ],
        ],
    ],
    'boundary' => [
        'notice' => 'Δεν ήταν δυνατή η εμφάνιση αυτής της οθόνης.',
        'rest' => 'Το υπόλοιπο διαχειριστικό εξακολουθεί να λειτουργεί: επιλέξτε άλλη οθόνη από το μενού.',
    ],
    'not_found' => [
        'title' => 'Η σελίδα δεν βρέθηκε',
        'sentence' => 'Δεν υπάρχει τίποτα σε αυτή τη διεύθυνση. Επιλέξτε μια οθόνη από το μενού.',
    ],
    'toaster' => [
        'dismiss' => 'Κλείσιμο',
        'fallback' => 'Κάτι πήγε στραβά. Δοκιμάστε ξανά.',
        'not_done' => 'Δεν ήταν δυνατό να γίνει αυτό. Δοκιμάστε ξανά.',
    ],
    'copy' => [
        'copied' => 'Αντιγράφηκε στο πρόχειρο.',
        'failed' => 'Η αντιγραφή απέτυχε. Επιλέξτε τον σύνδεσμο και αντιγράψτε τον μόνοι σας:',
        'booking_link' => 'Αντιγραφή συνδέσμου κράτησης',
        'link' => 'Αντιγραφή συνδέσμου',
    ],
    'kit' => [
        'statuses' => [
            'confirmed' => 'Επιβεβαιώθηκε',
            'awaiting_approval' => 'Περιμένει έγκριση',
            'pending' => 'Σε εκκρεμότητα',
            'awaiting_payment' => 'Περιμένει πληρωμή',
            'cancelled' => 'Ακυρώθηκε',
            'rejected' => 'Απορρίφθηκε',
            'no_show' => 'Μη εμφάνιση',
            'completed' => 'Ολοκληρώθηκε',
            'expired' => 'Έληξε',
        ],
        'counted' => [
            'confirmed' => '{1} :count επιβεβαιώθηκε|[2,*] :count επιβεβαιώθηκαν',
            'awaiting_approval' => '{1} :count περιμένει έγκριση|[2,*] :count περιμένουν έγκριση',
            'pending' => ':count σε εκκρεμότητα',
            'awaiting_payment' => '{1} :count περιμένει πληρωμή|[2,*] :count περιμένουν πληρωμή',
            'cancelled' => '{1} :count ακυρώθηκε|[2,*] :count ακυρώθηκαν',
            'rejected' => '{1} :count απορρίφθηκε|[2,*] :count απορρίφθηκαν',
            'no_show' => '{1} :count μη εμφάνιση|[2,*] :count μη εμφανίσεις',
            'completed' => '{1} :count ολοκληρώθηκε|[2,*] :count ολοκληρώθηκαν',
            'expired' => '{1} :count έληξε|[2,*] :count έληξαν',
        ],
        'date_field' => [
            'choose' => 'Επιλέξτε ημερομηνία',
            'clear' => 'Καθαρισμός',
        ],
        'month_grid' => [
            'previous' => 'Μετάβαση στον προηγούμενο μήνα',
            'next' => 'Μετάβαση στον επόμενο μήνα',
            'month' => 'Μήνας',
            'year' => 'Έτος',
        ],
        'combobox' => [
            'clear' => 'Καθαρισμός',
            'no_matches' => 'Δεν βρέθηκαν αποτελέσματα',
        ],
        'confirm' => [
            'keep' => 'Διατήρηση',
        ],
        'pagination' => [
            'previous' => 'Προηγούμενη',
            'next' => 'Επόμενη',
            'page' => 'Σελίδα :page από :last',
        ],
        'notice' => [
            'dismiss' => 'Κλείσιμο',
        ],
    ],
    'shared' => [
        'weekly' => [
            'closed' => 'Κλειστά',
            'start' => 'Έναρξη',
            'end' => 'Λήξη',
            'remove' => 'Αφαίρεση',
            'add' => 'Προσθήκη διαστήματος',
        ],
        'image' => [
            'uploading' => 'Γίνεται μεταφόρτωση',
            'removing' => 'Γίνεται αφαίρεση',
        ],
        'sources' => [
            'hosted' => 'Σελίδα κρατήσεων',
            'embed' => 'Ενσωμάτωση στον ιστότοπό σας',
            'admin' => 'Καταχώριση στο διαχειριστικό',
        ],
        'channels' => [
            'instagram' => 'Instagram',
            'facebook' => 'Facebook',
            'google' => 'Google',
            'qr' => 'Κωδικός QR',
            'other' => 'Άλλο',
        ],
        'support' => [
            'actor' => 'Υποστήριξη',
            'named' => 'Υποστήριξη (:operator)',
        ],
        'closure' => [
            'reason_help' => 'Μια σημείωση για εσάς. Οι πελάτες δεν τη βλέπουν ποτέ.',
        ],
    ],
    'validation' => [
        'login' => [
            'email_required' => 'Συμπληρώστε τη διεύθυνση email με την οποία συνδέεστε.',
            'email_string' => 'Συμπληρώστε τη διεύθυνση email ως κείμενο.',
            'password_required' => 'Συμπληρώστε τον κωδικό πρόσβασής σας.',
            'password_string' => 'Συμπληρώστε τον κωδικό πρόσβασης ως κείμενο.',
        ],
        'set_password' => [
            'too_short' => 'Επιλέξτε κωδικό με τουλάχιστον :floor χαρακτήρες.',
            'too_long' => 'Επιλέξτε μικρότερο κωδικό: έως :ceiling χαρακτήρες, λιγότερους αν έχει γράμματα εκτός του λατινικού αλφαβήτου.',
        ],
        'upload' => [
            'missing' => 'Επιλέξτε ένα αρχείο εικόνας για μεταφόρτωση.',
            'logo_type' => 'Το λογότυπο πρέπει να είναι αρχείο PNG, JPEG ή WebP.',
            'logo_size' => '{1} Το λογότυπο μπορεί να έχει μέγεθος έως :n MB.|[2,*] Το λογότυπο μπορεί να έχει μέγεθος έως :n MB.',
            'cover_type' => 'Η εικόνα εξωφύλλου πρέπει να είναι αρχείο PNG, JPEG ή WebP.',
            'cover_size' => '{1} Η εικόνα εξωφύλλου μπορεί να έχει μέγεθος έως :n MB.|[2,*] Η εικόνα εξωφύλλου μπορεί να έχει μέγεθος έως :n MB.',
            'gallery_type' => 'Κάθε φωτογραφία της συλλογής πρέπει να είναι αρχείο PNG, JPEG ή WebP.',
            'gallery_size' => '{1} Κάθε φωτογραφία της συλλογής μπορεί να έχει μέγεθος έως :n MB.|[2,*] Κάθε φωτογραφία της συλλογής μπορεί να έχει μέγεθος έως :n MB.',
            'staff_photo_type' => 'Η φωτογραφία ενός μέλους της ομάδας πρέπει να είναι αρχείο PNG, JPEG ή WebP.',
            'staff_photo_size' => '{1} Η φωτογραφία ενός μέλους της ομάδας μπορεί να έχει μέγεθος έως :n MB.|[2,*] Η φωτογραφία ενός μέλους της ομάδας μπορεί να έχει μέγεθος έως :n MB.',
        ],
        'weekly' => [
            'list' => 'Το εβδομαδιαίο ωράριο είναι μια λίστα με χρονικά διαστήματα.',
            'weekday' => 'Κάθε διάστημα ορίζει την ημέρα της εβδομάδας με έναν αριθμό από το 1, τη Δευτέρα, έως το 7, την Κυριακή.',
            'times' => 'Κάθε διάστημα έχει ώρα έναρξης και ώρα λήξης, σε μορφή ώρας, π.χ. 09:00.',
            'order' => 'Κάθε διάστημα πρέπει να ξεκινά πριν από τη λήξη του.',
        ],
    ],
    'refusals' => [
        'sign_in' => 'Η διεύθυνση email και ο κωδικός πρόσβασης δεν αντιστοιχούν σε λογαριασμό αυτής της επιχείρησης.',
        'too_many' => 'Πάρα πολλές προσπάθειες σύνδεσης από αυτή τη διεύθυνση IP. Περιμένετε ένα λεπτό και δοκιμάστε ξανά.',
        'support_link' => 'Αυτός ο σύνδεσμος υποστήριξης δεν είναι έγκυρος.',
    ],
    'flashes' => [
        'signed_out' => 'Αποσυνδεθήκατε.',
        'password_set' => 'Ο κωδικός πρόσβασής σας ορίστηκε. Συνδεθείτε.',
        'link_expired' => 'Αυτός ο σύνδεσμος έχει λήξει. Ζητήστε έναν νέο.',
    ],
];
