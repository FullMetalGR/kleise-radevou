<?php

/*
 * The site's chrome in Greek: the header, the nav, the language switch, the footer with its
 * entity line, the legal pages' frame, the consent banner and the 404. Translated from the
 * English of lang/en/site.php; the keys and the placeholders are that file's. Every value may
 * name the public name as :name, which the layout fills from config('site.name'), read as a
 * neuter noun as a website's name is in Greek; the entity line names its three values instead,
 * with the Greek tax number's own label.
 */

return [
    // This language's own name, which labels the language switch on the other language's pages.
    'language' => 'Ελληνικά',
    // The accessible names of the header's nav and the footer's.
    'nav' => 'Κύριο μενού',
    'footer' => 'Ιστότοπος',
    // Each page's link in the nav and the footer, by its route's name after site.; a page is
    // linked once its route exists, and a page served once per key under each of its keys.
    'pages' => [
        'features' => 'Δυνατότητες',
        'pricing' => 'Τιμές',
        'faq' => 'Ερωτήσεις',
        'contact' => 'Επικοινωνία',
        'terms' => 'Όροι χρήσης',
        'privacy' => 'Πολιτική απορρήτου',
        'cookies' => 'Ενημέρωση για τα cookies',
        'dpa' => 'Σύμβαση επεξεργασίας δεδομένων',
        // The six vertical pages, each linked by its key in the footer and in the home's
        // verticals strip, each named for the business the page speaks to.
        'vertical' => [
            'hair-salons' => 'Κομμωτήρια',
            'barbers' => 'Κουρεία',
            'nails' => 'Στούντιο νυχιών',
            'beauty' => 'Ινστιτούτα αισθητικής',
            'massage' => 'Θεραπευτές μασάζ',
            'physiotherapy' => 'Φυσικοθεραπευτές',
        ],
    ],
    // The entity line: the legal entity behind the platform, from site.entity's name and
    // address, on each legal page and in the footer of every page. entity_vat is the same line
    // with the VAT number's clause after the address, printed only while site.entity holds a VAT
    // number; with none configured the line is entity, and no number is ever put in its place.
    'entity' => ':entity, :address',
    'entity_vat' => ':entity, :address, ΑΦΜ: :vat',
    // The legal pages' frame: the draft notice each carries at its top while the texts are
    // unreviewed (site.legal_reviewed), and the line naming its version's date.
    'legal' => [
        'draft' => 'Το κείμενο αυτό είναι προσχέδιο υπό έλεγχο.',
        'version' => 'Έκδοση: :date',
    ],
    // The consent banner, shown while the analytics tag's id is set: its accessible name, the
    // sentence naming what is asked, the link to the cookie notice after it, the two choices of the
    // same weight, and the footer's control that reopens the banner, a link to the cookie notice
    // where no script runs.
    'consent' => [
        'label' => 'Επιλογή για τα cookies',
        'sentence' => 'Το :name θα ήθελε να χρησιμοποιεί cookies του Google Analytics, για να μετρά τις επισκέψεις και να βλέπει ποιες σελίδες διαβάζονται. Δεν αποθηκεύεται κανένα, αν δεν τα αποδεχτείτε.',
        'notice' => 'Διαβάστε την ενημέρωση για τα cookies',
        'accept' => 'Αποδοχή',
        'reject' => 'Απόρριψη',
        'settings' => 'Ρυθμίσεις cookies',
    ],
    'not_found' => [
        'title' => 'Η σελίδα δεν βρέθηκε - :name',
        'description' => 'Δεν υπάρχει σελίδα σε αυτή τη διεύθυνση του :name.',
        'heading' => 'Δεν υπάρχει σελίδα εδώ',
        'body' => 'Η διεύθυνση μπορεί να γράφτηκε λάθος ή η σελίδα να μετακινήθηκε.',
        'home' => 'Μετάβαση στην αρχική σελίδα',
    ],
];
