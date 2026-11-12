<?php

/*
 * The site's quotes: the Greek texts of the site name the admin's screens, sections and buttons
 * between Greek quotation marks, and since the admin speaks Greek each quote is the label the
 * admin shows, read from the label's own key. The first case holds the five files to no Latin
 * letter between the quotation marks, the English label a quote held while the admin was
 * English; the quotes that are no label (the terms' two pronouns and its quote of a section's
 * own title) hold no Latin letter either, so no case needs to except them. The second holds
 * each quoted label to the Greek value of its key, so a label renamed later fails here until
 * the site's sentence follows it.
 */

/**
 * The Greek site files that quote the admin's labels, by their path under lang/el.
 *
 * @return list<string>
 */
function quotedLabelsFiles(): array
{
    return ['faq.php', 'pricing.php', 'mail.php', 'legal/terms.php', 'legal/dpa.php'];
}

it('holds no Latin-letter label between Greek quotation marks in the site files', function (): void {
    $latin = [];

    foreach (quotedLabelsFiles() as $file) {
        $lines = file(lang_path('el/'.$file));
        assert($lines !== false);

        foreach ($lines as $index => $line) {
            preg_match_all('/\x{00AB}([^\x{00BB}]*)\x{00BB}/u', $line, $matches);

            foreach ($matches[1] as $quote) {
                if (preg_match('/[A-Za-z]/', $quote) === 1) {
                    $latin[] = $file.':'.($index + 1).' '.$quote;
                }
            }
        }
    }

    expect($latin)->toBe([]);
});

it('quotes each admin label as the Greek value of its key', function (string $file, string $key): void {
    $label = __($key, [], 'el');

    expect($label)->not->toBe($key)
        ->and(file_get_contents(lang_path('el/'.$file)))->toContain("\u{00AB}".$label."\u{00BB}");
})->with([
    ['faq.php', 'admin/shell.nav.billing'],
    ['faq.php', 'admin/shell.nav.settings'],
    ['faq.php', 'admin/settings.sections.booking_page'],
    ['faq.php', 'admin/settings.links.title'],
    ['faq.php', 'booking.my_bookings.title'],
    ['pricing.php', 'admin/shell.nav.billing'],
    ['mail.php', 'admin/shell.nav.billing'],
    ['mail.php', 'admin/shell.nav.settings'],
    ['legal/terms.php', 'admin/shell.nav.billing'],
    ['legal/terms.php', 'admin/shell.nav.settings'],
    ['legal/terms.php', 'admin/settings.sections.payments'],
    ['legal/terms.php', 'admin/billing.danger.title'],
    ['legal/terms.php', 'admin/billing.danger.export.button'],
    ['legal/terms.php', 'admin/billing.danger.close.button'],
    ['legal/dpa.php', 'admin/shell.nav.billing'],
    ['legal/dpa.php', 'admin/shell.nav.settings'],
    ['legal/dpa.php', 'admin/shell.nav.customers'],
    ['legal/dpa.php', 'admin/customers.export.label'],
    ['legal/dpa.php', 'admin/customers.erasure.erase'],
    ['legal/dpa.php', 'admin/billing.danger.title'],
    ['legal/dpa.php', 'admin/billing.danger.export.button'],
    ['legal/dpa.php', 'admin/billing.danger.close.button'],
]);
