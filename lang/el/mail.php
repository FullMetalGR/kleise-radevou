<?php

/*
 * The platform's tenant-facing mails in Greek: translated from the English of lang/en/mail.php;
 * the keys and the placeholders are that file's. Each mail's section holds its subject, its
 * sentences in the order the body reads them, and the reason its footer gives for the mail; the
 * greeting, the one layout's own string and the instant's shape sit beside them for every mail.
 * Every value may name the public name as :name, filled on every line, read as a neuter noun as a
 * website's name is in Greek; the reader's own name is :owner, as it was given, since every
 * reader here owns the business, but for the set-password link, which any user of the tenant may
 * be sent. The business is named after the word for a business, so its name needs no article of
 * its own. An instant follows its verb with no article, as a Greek notice dates an event, since
 * the article would change with the weekday's gender. The zone line is the same sentence under
 * each mail's own key, one per mail, since each mail class reads its own section. The Billing
 * screen and Settings are named by the labels the admin shows, in English, between Greek
 * quotation marks.
 */

return [
    // What every mail opens with.
    'greeting' => 'Γεια σας :owner,',

    // The one layout: its footer names why the mail was sent, each mail's own reason, and the
    // platform that sent it.
    'layout' => [
        'sent_by' => 'Στάλθηκε από το :name.',
    ],

    // An instant in the tenant's clock: the date, with its weekday and month in the language's own
    // words from the formatter, and the time, joined here, since the word between them is the
    // language's too.
    'time' => [
        'instant' => ':date στις :time',
    ],

    // The signup's verification link: the business and its address, the link, what it does and for
    // how long, the trial it starts, and what to do if the reader did not sign up. The window is a
    // choice by the link's hours and the trial by its days, each :count from the code's constant.
    'verification' => [
        'subject' => 'Επιβεβαιώστε το email σας για να στήσετε τη σελίδα κρατήσεών σας',
        'confirm' => 'Επιβεβαιώστε το email σας για να στήσετε την επιχείρηση :business στη διεύθυνση :address. Ανοίξτε αυτόν τον σύνδεσμο:',
        'window' => '{1} Ανοίξτε τον μέσα σε μία ώρα. Αφού στηθεί η επιχείρησή σας, ο ίδιος σύνδεσμος ανοίγει τη σελίδα σύνδεσής σας.|[2,*] Ανοίξτε τον μέσα σε :count ώρες. Αφού στηθεί η επιχείρησή σας, ο ίδιος σύνδεσμος ανοίγει τη σελίδα σύνδεσής σας.',
        'trial' => '{1} Η δωρεάν δοκιμή μίας ημέρας ξεκινά όταν ανοίξετε τον σύνδεσμο, και δεν χρειάζεται κάρτα.|[2,*] Η δωρεάν δοκιμή :count ημερών ξεκινά όταν ανοίξετε τον σύνδεσμο, και δεν χρειάζεται κάρτα.',
        'ignore' => 'Αν δεν κάνατε εσείς την εγγραφή, αγνοήστε αυτό το email και δεν θα στηθεί τίποτα.',
        'reason' => 'Λάβατε αυτό το email επειδή αυτή η διεύθυνση χρησιμοποιήθηκε για εγγραφή στο :name.',
    ],

    // The trial's two notices: three days before the end and on its last day, each naming the end
    // in the tenant's clock, what the end pauses and what it leaves running, and the Billing
    // screen, with the zone named once at the foot. "Ends today" sent to a trial whose end has
    // already passed, before the hourly lapse moved it (P9F-51a), reads ended and what_ended in
    // place of ends and what_ends, under ended_subject in place of its subject.
    'trial' => [
        'three_days' => [
            'subject' => 'Η δωρεάν δοκιμή σας λήγει σύντομα',
            'ends' => 'Η δωρεάν δοκιμή της επιχείρησης :business λήγει :end.',
        ],
        'ends_today' => [
            'subject' => 'Η τελευταία ημέρα της δωρεάν δοκιμής σας',
            'ends' => 'Η δωρεάν δοκιμή της επιχείρησης :business λήγει σε λιγότερο από μία ημέρα: :end.',
            'ended' => 'Η δωρεάν δοκιμή της επιχείρησης :business έληξε :end.',
            'ended_subject' => 'Η δωρεάν δοκιμή σας έληξε',
        ],
        'what_ends' => 'Όταν λήξει, οι αλλαγές στη διαμόρφωσή σας παγώνουν μέχρι να επιλέξετε πακέτο: οι υπηρεσίες, η ομάδα, το ωράριο λειτουργίας και οι ρυθμίσεις μένουν όπως είναι. Οι κρατήσεις σας συνεχίζονται και η σελίδα κρατήσεών σας μένει ανοιχτή.',
        'what_ended' => 'Με τη λήξη της δοκιμής, οι αλλαγές στη διαμόρφωσή σας παγώνουν μέχρι να επιλέξετε πακέτο: οι υπηρεσίες, η ομάδα, το ωράριο λειτουργίας και οι ρυθμίσεις μένουν όπως είναι. Οι κρατήσεις σας συνεχίζονται και η σελίδα κρατήσεών σας μένει ανοιχτή.',
        'billing' => 'Για να μπορείτε να αλλάζετε τη διαμόρφωσή σας και μετά τη δοκιμή, επιλέξτε πακέτο στην οθόνη «Χρέωση»:',
        'zone' => 'Οι ώρες αναφέρονται στη ζώνη ώρας :zone, αυτή που ακολουθείτε.',
        'reason' => 'Λάβατε αυτό το email ως κάτοχος του λογαριασμού :business στο :name.',
    ],

    // The closing email: the business, when its trial ended, the retention window and the day it
    // ends in the tenant's clock, what keeps working until then, that the data is deleted after it
    // and nothing more of what or when, and the Billing screen as the way back, with the zone
    // named once at the foot. The subject and kept are choices by the window's days, :count from
    // the code's constant. The owner's own close from the Settings Danger zone opens with set in
    // place of ended, and a free account, whose Billing screen offers no plan, is pointed to
    // support instead. The console's close opens with console in place of ended, then because and
    // the operator's reason as it was written, and points the way back as the owner's own close
    // does. The retention sweep's close of a lapsed subscriber opens with lapsed in place of
    // ended, naming the day the subscription's last billing period ended, with lapsed_unrecorded
    // where no period was ever recorded, or with lapsed_unpaid where the first payment never went
    // through and no period was ever paid, and points the way back as the owner's own close does.
    'closing' => [
        'subject' => '{1} Ο λογαριασμός σας κλείνει σε μία ημέρα|[2,*] Ο λογαριασμός σας κλείνει σε :count ημέρες',
        'ended' => 'Η δωρεάν δοκιμή της επιχείρησης :business έληξε :end χωρίς να επιλεγεί πακέτο, οπότε ο λογαριασμός κλείνει.',
        'set' => 'Το κλείσιμο του λογαριασμού της επιχείρησης :business ζητήθηκε από τις ρυθμίσεις :at, οπότε ο λογαριασμός κλείνει και δεν θα χρεωθεί τίποτα περισσότερο.',
        'console' => 'Θέσαμε σε διαδικασία κλεισίματος τον λογαριασμό της επιχείρησης :business στο :name. Το κλείσιμο ξεκίνησε :at, οπότε ο λογαριασμός κλείνει και δεν θα χρεωθεί τίποτα περισσότερο.',
        'lapsed' => 'Η συνδρομή της επιχείρησης :business έληξε :end, στο τέλος της τελευταίας περιόδου χρέωσης, και από τότε δεν έχει επιλεγεί πακέτο, οπότε ο λογαριασμός κλείνει.',
        'lapsed_unrecorded' => 'Η συνδρομή της επιχείρησης :business έληξε χωρίς να έχει καταγραφεί περίοδος χρέωσης, και από τότε δεν έχει επιλεγεί πακέτο, οπότε ο λογαριασμός κλείνει.',
        'lapsed_unpaid' => 'Η συνδρομή της επιχείρησης :business έληξε επειδή η πρώτη πληρωμή της δεν ολοκληρώθηκε ποτέ, και από τότε δεν έχει επιλεγεί πακέτο, οπότε ο λογαριασμός κλείνει.',
        'because' => 'Ο λόγος του κλεισίματος:',
        'support' => 'Για να διατηρήσετε τον λογαριασμό σας, γράψτε μας στο :address πριν από τότε.',
        'kept' => '{1} Τον κρατάμε για μία ημέρα, έως :until.|[2,*] Τον κρατάμε για :count ημέρες, έως :until.',
        'what_stays' => 'Μέχρι τότε οι κρατήσεις σας συνεχίζονται και η σελίδα κρατήσεών σας μένει ανοιχτή, ενώ οι αλλαγές στη διαμόρφωσή σας μένουν παγωμένες. Μετά, τα δεδομένα του λογαριασμού σας διαγράφονται.',
        'billing' => 'Για να διατηρήσετε τον λογαριασμό σας, επιλέξτε πακέτο στην οθόνη «Χρέωση» πριν από τότε:',
        'zone' => 'Οι ώρες αναφέρονται στη ζώνη ώρας :zone, αυτή που ακολουθείτε.',
        'reason' => 'Λάβατε αυτό το email ως κάτοχος του λογαριασμού :business στο :name.',
    ],

    // The suspension's notice: the business and the instant in the tenant's clock, the operator's
    // reason as it was written, and what the pause stops and what it leaves running, with the zone
    // named once at the foot.
    'suspension' => [
        'subject' => 'Η σελίδα κρατήσεών σας είναι σε παύση',
        'paused' => 'Θέσαμε σε παύση την επιχείρηση :business στο :name. Η παύση ξεκίνησε :end.',
        'because' => 'Ο λόγος της παύσης:',
        'what_pauses' => 'Μέχρι να λήξει η παύση, η σελίδα κρατήσεών σας και το διαχειριστικό σας δείχνουν ότι οι κρατήσεις είναι σε παύση, και οι πελάτες δεν μπορούν να κλείσουν, να ακυρώσουν ή να αλλάξουν ραντεβού online.',
        'what_keeps' => 'Τα μηνύματα για κρατήσεις που έχουν ήδη γίνει, μαζί με τις υπενθυμίσεις, συνεχίζουν να φτάνουν στους πελάτες σας, και θα σας στείλουμε email όταν λήξει η παύση.',
        'zone' => 'Οι ώρες αναφέρονται στη ζώνη ώρας :zone, αυτή που ακολουθείτε.',
        'reason' => 'Λάβατε αυτό το email ως κάτοχος του λογαριασμού :business στο :name.',
    ],

    // The resumption's notice: the business and the instant the pause ended in the tenant's clock,
    // and the booking page's address, with the zone named once at the foot.
    'resumption' => [
        'subject' => 'Η σελίδα κρατήσεών σας είναι ξανά ανοιχτή',
        'ended' => 'Η παύση της επιχείρησης :business έληξε :end. Η σελίδα κρατήσεών σας και το διαχειριστικό σας είναι ξανά ανοιχτά, και οι πελάτες μπορούν να κλείνουν ραντεβού όπως πριν:',
        'zone' => 'Οι ώρες αναφέρονται στη ζώνη ώρας :zone, αυτή που ακολουθείτε.',
        'reason' => 'Λάβατε αυτό το email ως κάτοχος του λογαριασμού :business στο :name.',
    ],

    // The reopen's notice: the business and the instant the console cancelled its closing in the
    // tenant's clock, that the account stays open and nothing in it is deleted, what works again,
    // and the admin's sign-in page, with the zone named once at the foot.
    'reopened' => [
        'subject' => 'Ο λογαριασμός σας παραμένει ανοιχτός',
        'kept' => 'Το κλείσιμο του λογαριασμού της επιχείρησης :business ακυρώθηκε :at. Ο λογαριασμός παραμένει ανοιχτός στο :name και δεν θα διαγραφεί τίποτα από αυτόν.',
        'what_returns' => 'Μπορείτε ξανά να κάνετε αλλαγές στη διαμόρφωσή σας, και οι κρατήσεις σας και η σελίδα κρατήσεών σας συνεχίζουν όπως πριν. Συνδεθείτε στο διαχειριστικό σας εδώ:',
        'zone' => 'Οι ώρες αναφέρονται στη ζώνη ώρας :zone, αυτή που ακολουθείτε.',
        'reason' => 'Λάβατε αυτό το email ως κάτοχος του λογαριασμού :business στο :name.',
    ],

    // The set-password link: the business and its sign-in page, the link, what it does and for how
    // long, and what to do if the reader was not expecting it. The reader is any user of the tenant
    // the console sent a link to, named by the greeting's :owner. The window reads for a reset, the
    // link the detail sends, and window_first for a first password, the link a free tenant's new
    // owner is sent, who has no password to keep; each is a choice by the link's hours, :count from
    // PasswordLinks::hours().
    'set_password' => [
        'subject' => 'Ορίστε τον κωδικό σας',
        'choose' => 'Ορίστε κωδικό για να συνδέεστε στην επιχείρηση :business στη διεύθυνση :address. Ανοίξτε αυτόν τον σύνδεσμο και επιλέξτε έναν:',
        'window' => '{1} Ο σύνδεσμος λειτουργεί μία φορά, μέσα σε μία ώρα. Μέχρι να χρησιμοποιηθεί, δεν αλλάζει τίποτα: όποιος κωδικός έχετε ήδη εξακολουθεί να λειτουργεί.|[2,*] Ο σύνδεσμος λειτουργεί μία φορά, μέσα σε :count ώρες. Μέχρι να χρησιμοποιηθεί, δεν αλλάζει τίποτα: όποιος κωδικός έχετε ήδη εξακολουθεί να λειτουργεί.',
        'window_first' => '{1} Ο σύνδεσμος λειτουργεί μία φορά, μέσα σε μία ώρα.|[2,*] Ο σύνδεσμος λειτουργεί μία φορά, μέσα σε :count ώρες.',
        'ignore' => 'Αν δεν περιμένατε αυτό το email, μπορείτε να το αγνοήσετε.',
        'reason' => 'Λάβατε αυτό το email επειδή το :name σας έστειλε σύνδεσμο για να ορίσετε τον κωδικό σας στην επιχείρηση :business.',
    ],

    // The welcome email: sent once when the verification click builds the trial. The business, the
    // trial's end in the tenant's clock, the sign-in address and the booking page's, three first
    // steps, the set-up offer naming the contact address, and the zone named once at the foot. The
    // hours are the second step and name no screen: during the trial every feature is open, so the
    // opening hours are set on the Staff screen, and on a plan without staff in Settings.
    'welcome' => [
        'subject' => 'Καλώς ήρθατε στο :name: η επιχείρηση :business δημιουργήθηκε',
        'ready' => 'Η επιχείρηση :business δημιουργήθηκε. Η δωρεάν δοκιμή σας διαρκεί έως :end, με όλες τις δυνατότητες και χωρίς κάρτα.',
        'sign_in' => 'Συνδεθείτε στο διαχειριστικό σας εδώ:',
        'page' => 'Η σελίδα κρατήσεών σας, η διεύθυνση που θα δώσετε στους πελάτες σας:',
        'steps' => 'Τρία βήματα για να ξεκινήσετε:',
        'step_services' => '1. Προσθέστε τις υπηρεσίες σας, την καθεμία με τη διάρκεια και την τιμή της.',
        'step_hours' => '2. Ορίστε το ωράριο λειτουργίας σας.',
        'step_share' => '3. Στείλτε στους πελάτες σας τον σύνδεσμο της σελίδας κρατήσεών σας, ή επικολλήστε στην ιστοσελίδα σας τον κώδικα από τις «Ρυθμίσεις».',
        'offer' => 'Θέλετε να το στήσουμε μαζί; Γράψτε μας στο :contact και θα το κανονίσουμε.',
        'zone' => 'Οι ώρες αναφέρονται στη ζώνη ώρας :zone, αυτή που ακολουθείτε.',
        'reason' => 'Λάβατε αυτό το email επειδή η επιχείρηση :business δημιουργήθηκε στο :name με αυτή τη διεύθυνση.',
    ],
];
