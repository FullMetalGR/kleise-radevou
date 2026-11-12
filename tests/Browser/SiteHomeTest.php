<?php

use App\Tenancy\TenantUrls;

/*
 * The home page and the language switch in a real browser: the home in English with its hero and
 * its signup action, which opens the signup form; the language switch to /el, the document's
 * language reading el, and the Greek page's own switch leading back to /, the language reading en
 * again. The plugin cannot switch JavaScript off, so the page read with none is the feature
 * walk's, CentralSurfaceTest and HomePageTest, over the HTML as served. The build gate wrote the
 * stylesheet this suite serves.
 *
 * A Greek sentence is read through the translator with the locale named, never spelled here.
 */

it('opens the home in English with its hero and its signup action, which leads to the signup form', function () {
    $urls = app(TenantUrls::class);

    $page = $this->visit($urls->central('/'))
        ->assertScript('document.documentElement.lang', 'en')
        ->assertSeeIn('main .site-hero h1', __('home.heading', [], 'en'))
        ->assertSeeIn('main .site-hero .site-hero-claims', __('home.hero.claims.trial', siteTrialDays(), 'en'))
        ->assertSeeIn('main .site-hero .site-hero-start', __('home.hero.start', [], 'en'))
        ->assertAttribute('main .site-hero .site-hero-start', 'href', $urls->central('/signup'))
        ->assertVisible('main .site-hero .site-hero-start')
        ->assertNoJavaScriptErrors();

    $page->click('.site-hero-start')
        ->waitForText('Start your booking page')
        ->assertPathIs('/signup')
        ->assertNoJavaScriptErrors();
});

it('switches the home to Greek, the document\'s language reading el, and back to English through the Greek page\'s own switch', function () {
    $urls = app(TenantUrls::class);

    $page = $this->visit($urls->central('/'))
        ->assertScript('document.documentElement.lang', 'en')
        ->assertSeeIn('header .site-switch', __('site.language', [], 'el'));

    $page->click('.site-switch')
        ->waitForEvent('load')
        ->assertPathIs('/el')
        ->assertScript('document.documentElement.lang', 'el')
        ->assertSeeIn('main .site-hero h1', __('home.heading', [], 'el'))
        ->assertSeeIn('header .site-switch', __('site.language', [], 'en'))
        ->assertAttribute('header .site-switch', 'href', $urls->central('/'))
        ->assertNoJavaScriptErrors();

    $page->click('.site-switch')
        ->waitForEvent('load')
        ->assertPathIs('/')
        ->assertScript('document.documentElement.lang', 'en')
        ->assertSeeIn('header .site-switch', __('site.language', [], 'el'))
        ->assertNoJavaScriptErrors();
});
