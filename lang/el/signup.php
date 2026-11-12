<?php

/*
 * The signup's sentences in Greek: every sentence its two pages show and every refusal its server
 * answers, translated from the English of lang/en/signup.php; the keys and the placeholders are
 * that file's. The pages hold no sentence of their own, since the admin build refuses Greek: the
 * central host's adapter hands them this whole file as the strings prop in the request's language,
 * and a sentence with a value in it keeps the translator's :name placeholder, which the page fills;
 * the pending page's waiting sentence alone is a choice by the verification link's hours, which the
 * server makes, :count filled, before the page fills the rest.
 *
 * The form's keys are its title, its heading, each field's label, the two selects' placeholders,
 * the slug's example, a web address in Latin letters as every slug is, and the currency's help, the
 * anti-spam group's name, and the button with its busy label, and the terms' box, one sentence
 * whose two link texts sit between the English file's named tags, <terms> and <privacy>, each text
 * the legal page's own Greek name in the case the sentence needs. The pending page's are its two
 * states, each a title, a heading and a sentence, the two words a stranger reads in place of the
 * email address and the business, and the Resend control with its busy label and no sentence of its
 * own: the resend's answer is resent, the one sentence the POST flashes whatever it found. The
 * waiting sentence and the verified heading set the business after a preposition, where the
 * business's own name and the stranger's words read alike, since Greek would give a name an article
 * of its own gender. too_many is the limit's refusal, counted per network address, which it calls
 * the connection, and expired the link's, answered for a link expired, replaced, swept or malformed
 * alike; each is a notice above the button.
 *
 * The errors are every refusal SignupRequest can answer, one sentence on its field, written here so
 * no framework validation file is published: the slug's by SlugProblem's case, the password's floor
 * and ceiling, the currency's, the terms' box's, and the anti-spam check's. The slug is called the
 * booking page's web address here, as the form's label calls it; the console's free tenant form
 * keeps SlugProblem's own English, which calls it the subdomain name, and the admin's Settings and
 * set-password forms keep their own English in code. :max, :floor and :ceiling are the code's
 * constants, Slug::MAX_LENGTH and PasswordRule's, never spelled here. The seven kinds of business
 * are the select's options, by BusinessCategory's value; the console keeps BusinessCategory's own
 * labels.
 */

return [
    'form' => [
        'title' => 'Εγγραφή',
        'heading' => 'Φτιάξτε τη σελίδα κρατήσεών σας',
        'slug' => 'Η διεύθυνση της σελίδας κρατήσεών σας',
        'slug_example' => 'i-epixeirisi-sas',
        'name' => 'Όνομα επιχείρησης',
        'category' => 'Είδος επιχείρησης',
        'category_placeholder' => 'Επιλέξτε είδος επιχείρησης',
        'timezone' => 'Ζώνη ώρας',
        'timezone_placeholder' => 'Επιλέξτε ζώνη ώρας',
        'currency' => 'Νόμισμα',
        'currency_help' => 'Τρία γράμματα, για παράδειγμα EUR.',
        'owner_name' => 'Το όνομά σας',
        'owner_email' => 'Η διεύθυνση email σας',
        'password' => 'Κωδικός πρόσβασης',
        'terms' => 'Αποδέχομαι τους <terms>όρους χρήσης</terms> και την <privacy>πολιτική απορρήτου</privacy>.',
        'check' => 'Έλεγχος κατά του spam',
        'submit' => 'Εγγραφή',
        'submitting' => 'Γίνεται εγγραφή...',
    ],
    'pending' => [
        'waiting_title' => 'Ελέγξτε το email σας',
        'waiting_heading' => 'Ελέγξτε το email σας',
        'waiting' => '{1} Στείλαμε έναν σύνδεσμο στο :email. Ανοίξτε τον μέσα σε μία ώρα, για να στηθεί η σελίδα για :business στη διεύθυνση :address.|[2,*] Στείλαμε έναν σύνδεσμο στο :email. Ανοίξτε τον μέσα σε :count ώρες, για να στηθεί η σελίδα για :business στη διεύθυνση :address.',
        'your_address' => 'email που δώσατε',
        'your_business' => 'την επιχείρησή σας',
        'verified_title' => 'Σε προετοιμασία',
        'verified_heading' => 'Στήνουμε τη σελίδα για :business',
        'verified' => 'Το email σας επιβεβαιώθηκε. Αυτή η σελίδα θα σας μεταφέρει στη σελίδα σύνδεσης, στη διεύθυνση :address, μόλις είναι έτοιμη.',
        'resend' => 'Στείλτε ξανά τον σύνδεσμο',
        'resending' => 'Αποστολή...',
    ],
    'categories' => [
        'hair_salon' => 'Κομμωτήριο',
        'barber' => 'Κουρείο',
        'nails' => 'Στούντιο νυχιών',
        'beauty' => 'Ινστιτούτο αισθητικής',
        'massage' => 'Μασάζ',
        'physiotherapy' => 'Φυσικοθεραπεία',
        'other' => 'Άλλο',
    ],
    'too_many' => 'Πάρα πολλές προσπάθειες εγγραφής από αυτή τη σύνδεση. Παρακαλούμε περιμένετε ένα λεπτό και δοκιμάστε ξανά.',
    'expired' => 'Αυτός ο σύνδεσμος δεν ισχύει πια. Παρακαλούμε κάντε ξανά την εγγραφή.',
    'resent' => 'Αν αυτή η εγγραφή περιμένει ακόμα, σας στέλνουμε νέο σύνδεσμο.',
    'errors' => [
        'slug' => [
            'empty' => 'Επιλέξτε μια διεύθυνση για τη σελίδα κρατήσεών σας.',
            'invalid_character' => 'Χρησιμοποιήστε μόνο τα λατινικά γράμματα a έως z, ψηφία και παύλες.',
            'leading_hyphen' => 'Η διεύθυνση δεν μπορεί να ξεκινά με παύλα.',
            'trailing_hyphen' => 'Η διεύθυνση δεν μπορεί να τελειώνει με παύλα.',
            'punycode_form' => 'Η διεύθυνση δεν μπορεί να έχει παύλες ως τρίτο και τέταρτο χαρακτήρα.',
            'too_long' => 'Η διεύθυνση μπορεί να έχει έως :max χαρακτήρες.',
            'reserved' => 'Αυτή η διεύθυνση είναι δεσμευμένη. Παρακαλούμε επιλέξτε άλλη.',
            'taken' => 'Αυτή η διεύθυνση χρησιμοποιείται ήδη. Παρακαλούμε επιλέξτε άλλη.',
            'held_by_signup' => 'Αυτή η διεύθυνση είναι κρατημένη από μια εγγραφή σε εξέλιξη. Αν εκείνη η εγγραφή δεν επιβεβαιωθεί, η διεύθυνση ελευθερώνεται ξανά μετά από μία ημέρα.',
        ],
        'name' => 'Γράψτε το όνομα της επιχείρησής σας.',
        'category' => 'Επιλέξτε το είδος της επιχείρησης από τη λίστα.',
        'timezone' => 'Επιλέξτε τη ζώνη ώρας της επιχείρησής σας από τη λίστα.',
        'currency' => 'Το νόμισμα γράφεται με τρία γράμματα, για παράδειγμα EUR.',
        'owner_name' => 'Γράψτε το όνομά σας.',
        'owner_email' => 'Γράψτε μια διεύθυνση email, για παράδειγμα you@example.com.',
        'password_short' => 'Επιλέξτε κωδικό με τουλάχιστον :floor χαρακτήρες.',
        'password_long' => 'Επιλέξτε μικρότερο κωδικό: έως :ceiling χαρακτήρες, λιγότερους αν έχει γράμματα εκτός του λατινικού αλφαβήτου.',
        'terms' => 'Για να εγγραφείτε, αποδεχτείτε τους όρους χρήσης και την πολιτική απορρήτου.',
        'check' => 'Ο έλεγχος κατά του spam δεν ολοκληρώθηκε. Παρακαλούμε δοκιμάστε ξανά.',
    ],
];
