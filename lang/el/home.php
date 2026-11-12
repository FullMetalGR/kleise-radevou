<?php

/*
 * The home page in Greek: translated from the English of lang/en/home.php; the keys and the
 * placeholders are that file's. Its title and meta description are the head's, its heading the
 * hero's headline. Every claim is true of the product as built: no proof, no outcome numbers, no
 * competitor and no fiscal claim. Only the title and the description are read with the public
 * name filled in, so no other value names :name. The trial's length is :count wherever a value
 * names it, its days from the code's constant, so a changed trial changes the page with no edit to
 * this file.
 */

return [
    'title' => ':name - κλείσιμο ραντεβού online για την επιχείρησή σας',
    'description' => 'Δική σας σελίδα κρατήσεων, και το ίδιο σύστημα στην ιστοσελίδα σας. Οι πελάτες κλείνουν ραντεβού χωρίς λογαριασμό, και δεν υπάρχει προμήθεια στις κρατήσεις. :count ημέρες δωρεάν, χωρίς κάρτα.',
    'heading' => 'Online ραντεβού για την επιχείρησή σας',
    'hero' => [
        // The four claims the hero leads with. The commission claim names the business's own
        // provider: a payment through a platform connection can carry the platform's fee
        // (payments.platform_fee), so the bare claim would be true only while that fee is zero. The
        // same sentence stands in gets.payments, features.php, faq.php and pricing.php.
        'claims' => [
            'trial' => ':count ημέρες δωρεάν. Χωρίς κάρτα.',
            'commission' => 'Χωρίς προμήθεια στις κρατήσεις, ούτε στις πληρωμές μέσω του δικού σας παρόχου.',
            'page' => 'Δική σας σελίδα κρατήσεων, και το ίδιο σύστημα στην ιστοσελίδα σας.',
            'guests' => 'Οι πελάτες σας κλείνουν ραντεβού χωρίς να φτιάξουν λογαριασμό.',
        ],
        // The primary action, to the signup form, and the second, to the demo section below.
        'start' => 'Ξεκινήστε τη δωρεάν δοκιμή',
        'demo' => 'Δοκιμάστε το demo',
    ],
    // What the business gets, each a heading over one sentence.
    'gets' => [
        'heading' => 'Τι κερδίζει η επιχείρησή σας',
        'items' => [
            'page' => [
                'title' => 'Δική σας σελίδα κρατήσεων',
                'body' => 'Μια σελίδα με το όνομα της επιχείρησής σας, όπου οι πελάτες βλέπουν τις υπηρεσίες σας, διαλέγουν ώρα και κλείνουν ραντεβού.',
            ],
            'embed' => [
                'title' => 'Κρατήσεις στην ιστοσελίδα σας',
                'body' => 'Επικολλήστε ένα μικρό κομμάτι κώδικα στην ιστοσελίδα σας, και οι πελάτες κλείνουν ραντεβού και εκεί, με τον ίδιο τρόπο.',
            ],
            'calendar' => [
                'title' => 'Το ημερολόγιό σας',
                'body' => 'Δείτε κάθε κράτηση ανά ημέρα ή ανά εβδομάδα, κάθε μέλος της ομάδας με το δικό του χρώμα, και προσθέστε εσείς μια κράτηση όταν σας τηλεφωνεί ένας πελάτης.',
            ],
            'emails' => [
                'title' => 'Email και υπενθυμίσεις',
                'body' => 'Οι πελάτες λαμβάνουν επιβεβαίωση με email και υπενθύμιση πριν από το ραντεβού τους. Και τα δύο στέλνονται αυτόματα.',
            ],
            'payments' => [
                'title' => 'Πληρωμή online ή επί τόπου',
                'body' => 'Για κάθε υπηρεσία, επιλέξτε αν οι πελάτες πληρώνουν online όταν κλείνουν ραντεβού ή επί τόπου. Χωρίς προμήθεια στις κρατήσεις, ούτε στις πληρωμές μέσω του δικού σας παρόχου.',
            ],
        ],
    ],
    // How it starts, three steps in order, each a heading over one sentence.
    'steps' => [
        'heading' => 'Πώς να ξεκινήσετε',
        'items' => [
            'signup' => [
                'title' => 'Εγγραφείτε',
                'body' => 'Γράψτε το όνομα της επιχείρησής σας και επιλέξτε τη διεύθυνση της σελίδας κρατήσεών σας. Η σελίδα σας δημιουργείται μόλις επιβεβαιώσετε το email σας.',
            ],
            'setup' => [
                'title' => 'Προσθέστε υπηρεσίες και ωράριο',
                'body' => 'Προσθέστε ό,τι προσφέρετε, με τη διάρκεια και την τιμή κάθε υπηρεσίας, και ορίστε τις ώρες που δουλεύετε εσείς και η ομάδα σας.',
            ],
            'share' => [
                'title' => 'Μοιραστείτε τη σελίδα σας',
                'body' => 'Στείλτε στους πελάτες σας τον σύνδεσμο της σελίδας κρατήσεών σας, ή επικολλήστε τον κώδικα στην ιστοσελίδα σας.',
            ],
        ],
    ],
    // The verticals strip: a heading over a link to each of the six vertical pages, each labelled
    // from site.php's pages.vertical.
    'verticals' => [
        'heading' => 'Φτιαγμένο για επιχειρήσεις σαν τη δική σας',
    ],
    // The demo section: the demo's embed mounted from the demo's own origin under a heading and one
    // paragraph, the noscript sentence the mount element holds until the embed replaces it, and a
    // link to the demo's own booking page. The paragraph says what holds of the demo: it is a
    // sample salon, any time it shows can be booked, what is typed is replaced with the sample
    // customer's details and not kept, and no email is sent.
    'demo' => [
        'heading' => 'Δοκιμάστε μόνοι σας μια κράτηση',
        'body' => 'Αυτό είναι ένα δοκιμαστικό κομμωτήριο, και μπορείτε να κλείσετε όποια ώρα δείχνει. Τα στοιχεία που γράφετε αντικαθίστανται από τα στοιχεία ενός δοκιμαστικού πελάτη και δεν αποθηκεύονται, και δεν στέλνεται κανένα email.',
        'noscript' => 'Το demo λειτουργεί στο πρόγραμμα περιήγησής σας και χρειάζεται ενεργοποιημένη JavaScript.',
        'page' => 'Ανοίξτε τη σελίδα κρατήσεων του δοκιμαστικού κομμωτηρίου',
    ],
    // The closing call to the signup.
    'closing' => [
        'heading' => 'Δοκιμάστε το δωρεάν για :count ημέρες',
        'body' => 'Χωρίς κάρτα. Στήστε σήμερα τη σελίδα κρατήσεών σας, και μοιραστείτε τη με τους πελάτες σας όταν είστε έτοιμοι.',
        'action' => 'Ξεκινήστε τη δωρεάν δοκιμή',
    ],
];
