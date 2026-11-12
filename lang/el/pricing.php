<?php

/*
 * The pricing page in Greek: translated from the English of lang/en/pricing.php; the keys and the
 * placeholders are that file's. Its title and meta description are the head's and its heading the
 * page's h1. No plan's name, no amount and no count of plans is spelled here: they are the plan
 * catalogue's, in config/billing.php, and the amount is formatted in Greek, with a decimal comma
 * and the euro sign after it (App\Site\SiteMoney). Every amount is followed by card.period, which
 * says it is a month's price and ex VAT, with the Greek VAT term. The FAQ answers the six
 * objections to the price, each true of the product as built; the commission answer keeps the
 * narrowed form the home's hero carries, word for word, and the trial's end is answered as faq.php
 * answers it, word for word. The admin's screens are named by the labels the admin shows, between
 * Greek quotation marks. The title, the description, the heading, the lead and the FAQ's items are
 * read with the public name filled in as :name; the cards, the floor's lines, the features and the
 * table are read with nothing filled in, so none of those names :name. The trial's length is :count
 * wherever a value names it, its days from the code's constant, so a changed trial changes the page
 * with no edit to this file.
 */

return [
    'title' => 'Τιμές - :name',
    'description' => 'Μηνιαίες τιμές για το :name, χωρίς ΦΠΑ, χωρίς προμήθεια στις κρατήσεις και χωρίς δέσμευση. :count ημέρες δωρεάν, χωρίς κάρτα.',
    'heading' => 'Τιμές',
    'lead' => 'Μία σταθερή τιμή τον μήνα για την επιχείρησή σας, όσες κρατήσεις κι αν δέχεστε. Ξεκινήστε με :count ημέρες δωρεάν. Χωρίς κάρτα.',
    // Each plan's card: its name and amount are the catalogue's; this line follows the amount.
    'card' => [
        'period' => 'τον μήνα, χωρίς ΦΠΑ',
        'trial' => ':count ημέρες δωρεάν, χωρίς κάρτα.',
        'includes' => 'Τι περιλαμβάνει',
        'action' => 'Ξεκινήστε τη δωρεάν δοκιμή',
    ],
    // What every plan includes, the floor: each card's first lines, and the table's first rows.
    'includes' => [
        'Υπηρεσίες, κρατήσεις και ημερολόγιο',
        'Δική σας σελίδα κρατήσεων, και το ίδιο σύστημα στην ιστοσελίδα σας',
        'Επιβεβαιώσεις και υπενθυμίσεις με email',
        'Online πληρωμές ή πληρωμή επί τόπου',
    ],
    // Each feature a plan may grant, by its slug: its row in the table, with what a plan with it
    // and a plan without it reads there, and the line a card carries with it and without it.
    'features' => [
        'staff' => [
            'label' => 'Μέλη της ομάδας',
            'has' => 'Όσα θέλετε',
            'lacks' => 'Ένα',
            'with' => 'Όσα μέλη της ομάδας θέλετε, το καθένα με το δικό του ωράριο, τις υπηρεσίες του και το ημερολόγιό του',
            'without' => 'Ένα μέλος της ομάδας',
        ],
    ],
    // The table of what each plan has: its heading, the head of the rows' column, and the cell a
    // plan reads under a row every plan has.
    'table' => [
        'heading' => 'Σύγκριση πακέτων',
        'item' => 'Τι παίρνετε',
        'included' => 'Περιλαμβάνεται',
    ],
    // The questions a business asks about the price, each a heading over its answer's paragraphs,
    // the key its anchor.
    'faq' => [
        'heading' => 'Ερωτήσεις για την τιμή',
        'items' => [
            'commission' => [
                'question' => 'Παίρνετε προμήθεια;',
                'answer' => [
                    'Χωρίς προμήθεια στις κρατήσεις, ούτε στις πληρωμές μέσω του δικού σας παρόχου. Πληρώνετε τη μηνιαία τιμή του πακέτου σας, την ίδια όσες κρατήσεις κι αν δέχεστε.',
                ],
            ],
            // Whether a cancellation runs to the end of the paid month is the billing provider's
            // portal setting, not this application's, so the answer says no more than any
            // setting makes true.
            'contract' => [
                'question' => 'Υπάρχει δέσμευση;',
                'answer' => [
                    'Όχι. Πληρώνετε μήνα με μήνα και μπορείτε να ακυρώσετε όποτε θέλετε.',
                ],
            ],
            'card' => [
                'question' => 'Χρειάζομαι κάρτα για τη δωρεάν δοκιμή;',
                'answer' => [
                    'Όχι. Οι :count ημέρες είναι δωρεάν και δεν χρειάζεται κάρτα. Προσθέτετε κάρτα μόνο όταν επιλέξετε πακέτο.',
                ],
            ],
            // The checkout collects the business's VAT number and asks the billing provider for
            // automatic tax where billing.provider.automatic_tax is on, so whether the invoice
            // carries VAT rests on the provider's own tax setup; the answer names the invoice
            // alone.
            'invoice' => [
                'question' => 'Παίρνω τιμολόγιο;',
                'answer' => [
                    'Ναι. Για κάθε πληρωμή της συνδρομής σας εκδίδεται τιμολόγιο, που το ανοίγετε από την οθόνη «Χρέωση» του διαχειριστικού σας.',
                ],
            ],
            'trial' => [
                'question' => 'Τι γίνεται όταν λήξει η δοκιμή;',
                'answer' => [
                    'Σας στέλνουμε email τρεις ημέρες πριν από τη λήξη και ξανά την τελευταία ημέρα. Για να συνεχίσετε, επιλέξτε πακέτο και προσθέστε κάρτα στην οθόνη «Χρέωση» του διαχειριστικού σας.',
                    'Αν δεν το κάνετε, οι αλλαγές στη διαμόρφωση παγώνουν: η σελίδα κρατήσεών σας συνεχίζει να δέχεται κρατήσεις, αλλά δεν μπορείτε να αλλάξετε τις υπηρεσίες, την ομάδα, το ωράριο λειτουργίας ή τις ρυθμίσεις σας. Τριάντα ημέρες μετά τη λήξη της δοκιμής, ξεκινάμε το κλείσιμο του λογαριασμού. Σας στέλνουμε email, και έχετε άλλες τριάντα ημέρες για να επιλέξετε πακέτο και να τον διατηρήσετε.',
                ],
            ],
            // Change plan is offered while a subscription runs (active, grace and past_due), never
            // in the trial, whose road is the plan choice.
            'change' => [
                'question' => 'Μπορώ να αλλάξω πακέτο;',
                'answer' => [
                    'Ναι. Όσο τρέχει η συνδρομή σας, αλλάξτε το από την οθόνη «Χρέωση» του διαχειριστικού σας. Η προσθήκη μελών στην ομάδα ισχύει αμέσως. Για να επιστρέψετε σε ένα μέλος, αφήστε πρώτα ενεργό μόνο ένα.',
                ],
            ],
        ],
    ],
];
