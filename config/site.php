<?php

/*
 * The marketing site's values, each from the environment with its default. The public name is
 * the one every sentence of the site, the platform's mails' sender name and the legal texts
 * read. The central domain is no setting here: it is the application URL's host, which
 * TenantUrls spells.
 */

return [

    // The public name: every page, the platform mails' sender name (mail.from.name defaults to
    // it), the legal texts.
    'name' => env('SITE_NAME', 'kleiseradevou.gr'),

    // Where the contact form's mail goes and the address the admin's "contact support" sentences,
    // the welcome email and the legal texts name.
    'contact_address' => env('CONTACT_ADDRESS', 'support@kleiseradevou.gr'),

    // The legal entity the legal pages and the footer name: its name and head office address,
    // and its VAT number, empty while it has none, which leaves the number's clause out of the
    // entity line.
    'entity' => [
        'name' => env('ENTITY_NAME', 'ADS Solutions O.E.'),
        'address' => env('ENTITY_ADDRESS', 'Miltiadou 8, Nea Ionia, 142 31, Athens, Greece'),
        'vat' => (string) env('ENTITY_VAT', ''),
    ],

    // Whether the legal texts have been reviewed; until they have, each legal page carries the
    // draft notice.
    'legal_reviewed' => (bool) env('LEGAL_REVIEWED', false),

    // The live demo tenant's slug, a reserved label. No environment value: it is the label the
    // reserved list holds.
    'demo_slug' => 'demo',

];
