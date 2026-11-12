<?php

use App\Site\SiteLanguage;
use App\Tenancy\TenantUrls;

/*
 * The features page, as served and read with no script run: the product's features in groups,
 * each a heading over short paragraphs from features.php in the page's language, in the file's
 * order: the booking page and the embed, the calendar and the staff, the emails and reminders,
 * payments, and the admin on a phone. The page's head and its h1 are CentralSurfaceTest's walk,
 * which lists the page with no edit to it.
 *
 * A Greek sentence is read through the translator with the locale named, never spelled here.
 */

beforeEach(fn () => $this->withoutVite());

it('sets out the features in their groups, each its heading over its paragraphs from features.php, in the file\'s order', function (SiteLanguage $language) {
    $response = $this->get(app(TenantUrls::class)->central($language->uri('/features')));

    $response->assertOk()->assertViewIs('site.features');

    $document = siteDocument($response);
    $groups = siteCopyKeys('features.groups', $language);

    expect($groups)->toBe(['page', 'calendar', 'emails', 'payments', 'phone'])
        ->and(siteTexts($document, 'main h1'))->toBe([siteSentence('features.heading', $language)])
        ->and(siteTexts($document, 'main .site-features-lead'))->toBe([siteSentence('features.lead', $language)])
        ->and(siteTexts($document, 'main .site-features-group > h2'))->toBe(array_map(fn (string $group): string => siteSentence('features.groups.'.$group.'.heading', $language), $groups));

    foreach ($groups as $group) {
        expect(siteTexts($document, 'main .site-features-group#'.$group.' > p'))->toBe(siteSentences('features.groups.'.$group.'.body', $language), $group);
    }
})->with([
    'English' => [SiteLanguage::English],
    'Greek' => [SiteLanguage::Greek],
]);

it('carries no form and no script', function (SiteLanguage $language) {
    $response = $this->get(app(TenantUrls::class)->central($language->uri('/features')));

    $response->assertOk();

    $document = siteDocument($response);

    expect($document->querySelectorAll('form'))->toHaveCount(0)
        ->and($document->querySelectorAll('script'))->toHaveCount(0);
})->with([
    'English' => [SiteLanguage::English],
    'Greek' => [SiteLanguage::Greek],
]);
