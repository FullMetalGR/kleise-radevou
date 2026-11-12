<?php

/*
 * The Billing screen's tax lines in Greek, the twin of lang/en/admin/tax.php, whose header says
 * what each line is. The polite plural throughout; VAT and the VAT number are the site's own
 * two terms; :tax_id is the provider's value as the business gave it.
 */

return [
    'vat' => 'Οι τιμές εμφανίζονται χωρίς ΦΠΑ και κάθε τιμολόγιο προσθέτει όποιον ΦΠΑ οφείλεται.',
    'number' => 'Το καταχωρισμένο ΑΦΜ σας είναι :tax_id.',
    'none' => 'Δεν έχετε καταχωρίσει ΑΦΜ· μπορείτε να το προσθέσετε στις σελίδες του παρόχου χρεώσεων, που ανοίγουν με το παρακάτω κουμπί.',
    'unreadable' => 'Δεν ήταν δυνατή η ανάγνωση του ΑΦΜ σας αυτή τη στιγμή.',
];
