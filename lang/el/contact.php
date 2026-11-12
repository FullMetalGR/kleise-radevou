<?php

/*
 * The contact page in Greek: translated from the English of lang/en/contact.php; the keys and
 * the placeholders are that file's. Its title and meta description are the head's and its
 * heading the page's h1; the lead sits under it. The labels name the form's three fields and the
 * trap's, which no person sees; the button sends the form. The thank-you is the one sentence the
 * flash carries after a message is sent, and the refusal past the limit sits above the form at
 * 429; the limit counts the messages from one network address, which the refusal calls the
 * connection. Each error is the refusal named under its field, the anti-spam check's under the
 * widget, each length spelled with the Greek thousands separator. The title, the description,
 * the heading and the lead are read with the public name filled in as :name.
 */

return [
    'title' => 'Επικοινωνία - :name',
    'description' => 'Γράψτε στο :name για μια ερώτηση σχετικά με το προϊόν, την τιμή ή το ξεκίνημα. Απαντάμε με email, στα ελληνικά ή στα αγγλικά.',
    'heading' => 'Γράψτε μας',
    'lead' => 'Ρωτήστε μας για το προϊόν, την τιμή ή πώς να ξεκινήσετε. Απαντάμε με email, στα ελληνικά ή στα αγγλικά.',
    'fields' => [
        'name' => 'Το όνομά σας',
        'email' => 'Η διεύθυνση email σας',
        'message' => 'Το μήνυμά σας',
    ],
    // The label of the field a person never fills, hidden from sight and from assistive
    // technology; a form that arrives with it filled was posted by a machine.
    'trap' => 'Αφήστε αυτό το πεδίο κενό',
    'send' => 'Αποστολή μηνύματος',
    'sent' => 'Ευχαριστούμε. Το μήνυμά σας στάλθηκε, και θα σας απαντήσουμε με email.',
    'too_many' => 'Πάρα πολλά μηνύματα από αυτή τη σύνδεση. Παρακαλούμε περιμένετε μία ώρα και δοκιμάστε ξανά.',
    'errors' => [
        'name_required' => 'Γράψτε το όνομά σας.',
        'name_long' => 'Το όνομά σας μπορεί να έχει έως 200 χαρακτήρες.',
        'email_required' => 'Γράψτε τη διεύθυνση email σας.',
        'email_invalid' => 'Γράψτε μια διεύθυνση email, για παράδειγμα you@example.com.',
        'email_long' => 'Η διεύθυνση email σας μπορεί να έχει έως 254 χαρακτήρες.',
        'message_required' => 'Γράψτε το μήνυμά σας.',
        'message_long' => 'Το μήνυμά σας μπορεί να έχει έως 5.000 χαρακτήρες.',
        'check' => 'Ο έλεγχος κατά του spam δεν ολοκληρώθηκε. Παρακαλούμε δοκιμάστε ξανά.',
    ],
];
