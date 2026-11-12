<?php

/*
 * The Settings screen's Emails section in Greek: the twin of lang/en/admin/emails.php key for
 * key, in the polite plural; the keys and the groups are that file's, and its header says which
 * code reads them. A label names the event its mail tells, in the nouns and the verbs of the
 * mails' own subjects (lang/el/customer-mail.php and lang/el/business-mail.php): the customer's
 * ten name what happened to the request or the booking, or what the mail brings (the reminder,
 * the link, the thanks); the business's seven what a request, a customer or a payment did.
 * labels.moved says a move as the customer's own mail subject says it, labels.move_notice as the
 * business's mail does. The approved message's label is quoted in guillemets inside two sentences
 * of business-mail.php, so it reads as a whole clause there too. The audience tags name the
 * customer, the business and the owners, the role's word in lang/el/admin/team.php, in the
 * plural; locked names the payment connection as lang/el/admin/payments.php does.
 */

return [
    'labels' => [
        'acknowledged' => 'Το αίτημα ελήφθη',
        'confirmed' => 'Η κράτηση επιβεβαιώθηκε',
        'approved' => 'Το αίτημα εγκρίθηκε',
        'rejected' => 'Το αίτημα απορρίφθηκε',
        'cancelled' => 'Η κράτηση ακυρώθηκε',
        'moved' => 'Η ώρα της κράτησης άλλαξε',
        'reminder' => 'Υπενθύμιση ραντεβού',
        'request_expired' => 'Το αίτημα έληξε',
        'new_booking' => 'Έγινε μια κράτηση',
        'approval_request' => 'Ένα αίτημα περιμένει απόφαση',
        'approval_nag' => 'Ένα αίτημα περιμένει ακόμη απόφαση',
        'auto_approved' => 'Ένα αίτημα εγκρίθηκε αυτόματα',
        'cancellation_notice' => 'Ένας πελάτης ακύρωσε',
        'move_notice' => 'Ένας πελάτης μετέφερε την κράτησή του',
        'late_payment' => 'Μια πληρωμή έφτασε για μια κράτηση που δεν την περίμενε πια',
        'my_bookings_link' => 'Ο σύνδεσμος για τις κρατήσεις σας',
        'thank_you' => 'Σας ευχαριστούμε για την επίσκεψή σας',
    ],
    'audience' => [
        'customer' => 'στον πελάτη',
        'business' => 'στην επιχείρηση',
        'owners' => 'στους κατόχους',
    ],
    'locked' => 'Αποστέλλεται πάντα όσο λειτουργεί μια σύνδεση πληρωμών, γιατί περιέχει τον σύνδεσμο πληρωμής του πελάτη.',
];
