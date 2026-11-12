<?php

/*
 * The site's chrome in English: the header, the nav, the language switch, the footer with its
 * entity line, the legal pages' frame, the consent banner and the 404. Every value may name the
 * public name as :name, which the layout fills from config('site.name'); the entity line names
 * its three values instead.
 */

return [
    // This language's own name, which labels the language switch on the other language's pages.
    'language' => 'English',
    // The accessible names of the header's nav and the footer's.
    'nav' => 'Main',
    'footer' => 'Site',
    // Each page's link in the nav and the footer, by its route's name after site.; a page is
    // linked once its route exists, and a page served once per key under each of its keys.
    'pages' => [
        'features' => 'Features',
        'pricing' => 'Pricing',
        'faq' => 'Questions',
        'contact' => 'Contact',
        'terms' => 'Terms of service',
        'privacy' => 'Privacy policy',
        'cookies' => 'Cookie notice',
        'dpa' => 'Data processing agreement',
        // The six vertical pages, each linked by its key in the footer and in the home's
        // verticals strip, each named for the business the page speaks to.
        'vertical' => [
            'hair-salons' => 'Hair salons',
            'barbers' => 'Barbers',
            'nails' => 'Nail studios',
            'beauty' => 'Beauty salons',
            'massage' => 'Massage therapists',
            'physiotherapy' => 'Physiotherapists',
        ],
    ],
    // The entity line: the legal entity behind the platform, from site.entity's name and
    // address, on each legal page and in the footer of every page. entity_vat is the same line
    // with the VAT number's clause after the address, printed only while site.entity holds a VAT
    // number; with none configured the line is entity, and no number is ever put in its place.
    'entity' => ':entity, :address',
    'entity_vat' => ':entity, :address, VAT number: :vat',
    // The legal pages' frame: the draft notice each carries at its top while the texts are
    // unreviewed (site.legal_reviewed), and the line naming its version's date.
    'legal' => [
        'draft' => 'This text is a draft under review.',
        'version' => 'Version of :date',
    ],
    // The consent banner, shown while the analytics tag's id is set: its accessible name, the
    // sentence naming what is asked, the link to the cookie notice after it, the two choices of the
    // same weight, and the footer's control that reopens the banner, a link to the cookie notice
    // where no script runs.
    'consent' => [
        'label' => 'Cookie choice',
        'sentence' => ':name would like to use Google Analytics cookies to count visits and see which pages are read. None are set unless you accept.',
        'notice' => 'Read the cookie notice',
        'accept' => 'Accept',
        'reject' => 'Reject',
        'settings' => 'Cookie settings',
    ],
    'not_found' => [
        'title' => 'Page not found - :name',
        'description' => 'There is no page at this address on :name.',
        'heading' => 'There is no page here',
        'body' => 'The address may be mistyped, or the page may have moved.',
        'home' => 'Go to the home page',
    ],
];
