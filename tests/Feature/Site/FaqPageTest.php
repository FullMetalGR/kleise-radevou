<?php

use App\Site\SiteLanguage;
use App\Tenancy\TenantUrls;

/*
 * The FAQ, as served and read with no script run: the questions of faq.php in the page's
 * language, a list rendered as headings over their answers, every answer in the HTML as served
 * and none behind a script: what the trial includes, what happens when it ends, whether a card
 * is needed, the commission, where the data lives, how the embed is installed, and how
 * customers book without an account. The page's head and its h1 are CentralSurfaceTest's walk,
 * which lists the page with no edit to it.
 *
 * A Greek sentence is read through the translator with the locale named, never spelled here.
 */

beforeEach(fn () => $this->withoutVite());

it('renders faq.php\'s questions as headings over their answers, every answer in the HTML as served, in the file\'s order', function (SiteLanguage $language) {
    $response = $this->get(app(TenantUrls::class)->central($language->uri('/faq')));

    $response->assertOk()->assertViewIs('site.faq');

    $document = siteDocument($response);
    $items = siteCopyKeys('faq.items', $language);

    expect($items)->toBe(['trial', 'ends', 'card', 'commission', 'data', 'embed', 'accounts'])
        ->and(siteTexts($document, 'main h1'))->toBe([siteSentence('faq.heading', $language)])
        ->and(siteTexts($document, 'main .site-faq-lead'))->toBe([siteSentence('faq.lead', $language)])
        ->and(siteTexts($document, 'main .site-faq-item > h2'))->toBe(array_map(fn (string $item): string => siteSentence('faq.items.'.$item.'.question', $language), $items));

    foreach ($items as $item) {
        expect(siteTexts($document, 'main .site-faq-item#'.$item.' > p'))->toBe(siteSentences('faq.items.'.$item.'.answer', $language, siteTrialDays()), $item);
    }
})->with([
    'English' => [SiteLanguage::English],
    'Greek' => [SiteLanguage::Greek],
]);

it('carries no form, no script and no control that hides an answer', function (SiteLanguage $language) {
    $response = $this->get(app(TenantUrls::class)->central($language->uri('/faq')));

    $response->assertOk();

    $document = siteDocument($response);

    expect($document->querySelectorAll('form'))->toHaveCount(0)
        ->and($document->querySelectorAll('script'))->toHaveCount(0)
        ->and($document->querySelectorAll('main details, main [hidden], main button'))->toHaveCount(0);
})->with([
    'English' => [SiteLanguage::English],
    'Greek' => [SiteLanguage::Greek],
]);
