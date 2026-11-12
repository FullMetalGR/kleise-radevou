<?php

/*
 * The Billing screen's tax lines in English, on its payment card: read by BillingController
 * through the translator in the language of the request, the user's, and sent filled in the
 * page's props, never as keys. The file was written complete in both languages at once, so it
 * was never on the pending list.
 *
 * vat is the line true in every state of the provider's tax setup, beside the portal wherever a
 * subscriptions row exists. number, none and unreadable are the tax id line, one of the three,
 * which the page loads after its render from the provider's answer: the first tax id the
 * provider holds for the customer as :tax_id, a placeholder this file adds, the value as the
 * business gave it; none points to the card's button by what it opens; unreadable names no
 * cause, which is the log's.
 */

return [
    'vat' => 'Prices are shown without VAT, and each invoice adds any VAT that is due.',
    'number' => 'Your VAT number on file is :tax_id.',
    'none' => 'No VAT number is on file; you can add one on the billing provider\'s pages, which the button below opens.',
    'unreadable' => 'Your VAT number could not be read just now.',
];
