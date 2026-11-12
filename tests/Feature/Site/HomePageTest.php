<?php

use App\Site\SiteLanguage;
use App\Site\SitePages;
use App\Site\Vertical;
use App\Tenancy\TenantUrls;
use Dom\Attr;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/*
 * The home page, as served and read with no script run: the hero, its headline over its four
 * claims and its two actions, the free trial to the signup in the page's language and a second
 * to the demo section below; what the business gets; how it starts in three steps; the
 * verticals strip, linking the six vertical pages in the page's language; the demo section, the
 * hero's second action's target, mounting the embed from the demo's origin beside its sentence
 * and a link to the demo's own page; and a closing call to the signup. Every sentence is
 * home.php's in the page's language, the strip's labels site.php's.
 *
 * The Greek page's signup actions link the Greek form, el.signup, through the one helper every
 * signup action of the site reads.
 *
 * A Greek sentence is read through the translator with the locale named, never spelled here.
 */

beforeEach(fn () => $this->withoutVite());

/**
 * The home page in a language as served.
 */
function homeDocument(TestCase $test, SiteLanguage $language): HTMLDocument
{
    $response = $test->get(app(TenantUrls::class)->central($language->uri('/')));

    $response->assertOk();

    return siteDocument($response);
}

it('heads the home with its headline over its four claims, in each language', function (SiteLanguage $language) {
    $document = homeDocument($this, $language);

    $claims = array_map(fn (string $claim): string => siteSentence('home.hero.claims.'.$claim, $language, siteTrialDays()), siteCopyKeys('home.hero.claims', $language));

    expect(siteTexts($document, 'main .site-hero h1'))->toBe([siteSentence('home.heading', $language)])
        ->and($claims)->toHaveCount(4)
        ->and(siteTexts($document, 'main .site-hero .site-hero-claims li'))->toBe($claims);
})->with([
    'English' => [SiteLanguage::English],
    'Greek' => [SiteLanguage::Greek],
]);

it('carries the hero\'s two actions: "Start your free trial" to the signup form, and a second to the demo section below', function (SiteLanguage $language) {
    $document = homeDocument($this, $language);
    $start = $document->querySelector('main .site-hero a.site-hero-start');
    $demo = $document->querySelector('main .site-hero a.site-hero-demo');

    expect(__('home.hero.start', [], 'en'))->toBe('Start your free trial')
        ->and(trim((string) $start?->textContent))->toBe(siteSentence('home.hero.start', $language))
        ->and($start?->getAttribute('href'))->toBe(app(SitePages::class)->signup($language))
        ->and(trim((string) $demo?->textContent))->toBe(siteSentence('home.hero.demo', $language))
        ->and($demo?->getAttribute('href'))->toBe('#demo');
})->with([
    'English' => [SiteLanguage::English],
    'Greek' => [SiteLanguage::Greek],
]);

it('lists what the business gets: its booking page, the website embed, the staff calendar, the emails and reminders, and payments through its own provider', function (SiteLanguage $language) {
    $document = homeDocument($this, $language);
    $items = __('home.gets.items', [], $language->value);

    assert(is_array($items));

    expect(array_keys($items))->toBe(['page', 'embed', 'calendar', 'emails', 'payments'])
        ->and(siteTexts($document, 'main .site-home-gets h2'))->toBe([siteSentence('home.gets.heading', $language)])
        ->and(siteTexts($document, 'main .site-home-gets li h3'))->toBe(array_map(fn (string $item): string => siteSentence('home.gets.items.'.$item.'.title', $language), array_keys($items)))
        ->and(siteTexts($document, 'main .site-home-gets li p'))->toBe(array_map(fn (string $item): string => siteSentence('home.gets.items.'.$item.'.body', $language), array_keys($items)));
})->with([
    'English' => [SiteLanguage::English],
    'Greek' => [SiteLanguage::Greek],
]);

it('tells how it starts in three steps, in order, each a heading over a sentence', function (SiteLanguage $language) {
    $document = homeDocument($this, $language);
    $steps = __('home.steps.items', [], $language->value);

    assert(is_array($steps));

    expect($steps)->toHaveCount(3)
        ->and(siteTexts($document, 'main .site-home-steps h2'))->toBe([siteSentence('home.steps.heading', $language)])
        ->and($document->querySelectorAll('main .site-home-steps ol > li'))->toHaveCount(3)
        ->and(siteTexts($document, 'main .site-home-steps ol > li h3'))->toBe(array_map(fn (int|string $step): string => siteSentence('home.steps.items.'.$step.'.title', $language), array_keys($steps)))
        ->and(siteTexts($document, 'main .site-home-steps ol > li p'))->toBe(array_map(fn (int|string $step): string => siteSentence('home.steps.items.'.$step.'.body', $language), array_keys($steps)));
})->with([
    'English' => [SiteLanguage::English],
    'Greek' => [SiteLanguage::Greek],
]);

it('closes with a call to the signup form', function (SiteLanguage $language) {
    $document = homeDocument($this, $language);
    $action = $document->querySelector('main .site-home-closing a.site-home-signup');

    expect(siteTexts($document, 'main .site-home-closing h2'))->toBe([siteSentence('home.closing.heading', $language, siteTrialDays())])
        ->and(siteTexts($document, 'main .site-home-closing .site-home-closing-body'))->toBe([siteSentence('home.closing.body', $language)])
        ->and(trim((string) $action?->textContent))->toBe(siteSentence('home.closing.action', $language))
        ->and($action?->getAttribute('href'))->toBe(app(SitePages::class)->signup($language));
})->with([
    'English' => [SiteLanguage::English],
    'Greek' => [SiteLanguage::Greek],
]);

it('sets the page in order, the hero, what the business gets, the three steps, the verticals strip, the demo section the hero\'s second action anchors to, and the closing call', function (SiteLanguage $language) {
    $document = homeDocument($this, $language);
    $sections = [];

    foreach ($document->querySelectorAll('main > section') as $section) {
        $sections[] = explode(' ', (string) $section->getAttribute('class'))[0];
    }

    expect($sections)->toBe(['site-hero', 'site-home-gets', 'site-home-steps', 'site-home-verticals', 'site-home-demo', 'site-home-closing'])
        ->and($document->querySelectorAll('[id="demo"]'))->toHaveCount(1)
        ->and($document->getElementById('demo')?->tagName)->toBe('SECTION')
        ->and($document->getElementById('demo')?->parentElement?->tagName)->toBe('MAIN')
        ->and(explode(' ', (string) $document->getElementById('demo')?->getAttribute('class'))[0])->toBe('site-home-demo')
        ->and($document->querySelector('main .site-hero a.site-hero-demo')?->getAttribute('href'))->toBe('#demo')
        ->and($document->querySelectorAll('main h1'))->toHaveCount(1);
})->with([
    'English' => [SiteLanguage::English],
    'Greek' => [SiteLanguage::Greek],
]);

it('mounts the demo\'s embed in the demo section, the script from the demo\'s origin and the embed\'s mount element, under its sentence and beside a link to the demo\'s own page in the page\'s language', function (SiteLanguage $language, string $path) {
    $urls = app(TenantUrls::class);
    $demo = siteDemoSlug();
    $document = homeDocument($this, $language);
    $section = $document->querySelector('main > section.site-home-demo');
    $mounts = $document->querySelectorAll('.kr-booking');
    $mount = $section?->querySelector('.kr-booking');
    $scripts = [];

    foreach ($document->querySelectorAll('script') as $script) {
        $scripts[] = [(string) $script->getAttribute('src'), $script->hasAttribute('async'), $script->closest('section')?->getAttribute('id')];
    }

    expect($urls->embedScript($demo))->toBe($urls->origin($demo).'/embed.js')
        ->and($urls->origin($demo))->not->toBe($urls->central(''))
        ->and(siteTexts($document, 'main .site-home-demo h2'))->toBe([siteSentence('home.demo.heading', $language)])
        ->and(siteTexts($document, 'main .site-home-demo .site-home-demo-body'))->toBe([siteSentence('home.demo.body', $language)])
        // The mount element, the page's one, with no data attribute: book mode, nothing
        // preselected, no channel. Its one child is the noscript sentence, which the React root
        // replaces on its first render.
        ->and($mounts)->toHaveCount(1)
        ->and($mount?->tagName)->toBe('DIV')
        ->and($mount?->getAttribute('class'))->toBe('kr-booking')
        ->and(array_map(fn (Attr $attribute): string => $attribute->name, iterator_to_array($mount->attributes ?? [], false)))->toBe(['class'])
        ->and($mount?->childNodes)->toHaveCount(1)
        ->and($mount?->firstElementChild?->tagName)->toBe('NOSCRIPT')
        ->and(trim((string) $mount?->textContent))->toBe(siteSentence('home.demo.noscript', $language))
        // The page's one script, the embed's from the demo's origin, async, in the section after
        // the mount, as the snippet a tenant pastes has it (TenantUrls::embedSnippet).
        ->and($scripts)->toBe([[$urls->embedScript($demo), true, 'demo']])
        ->and($mount?->nextElementSibling?->tagName)->toBe('SCRIPT')
        ->and(siteTexts($document, 'main .site-home-demo a.site-home-demo-page'))->toBe([siteSentence('home.demo.page', $language)])
        // The hosted page in the page's language: the bare path, the Greek demo's default, from
        // /el, and the /en path from the English home.
        ->and($section?->querySelector('a.site-home-demo-page')?->getAttribute('href'))->toBe($urls->origin($demo).$path);
})->with([
    'English' => [SiteLanguage::English, '/en'],
    'Greek' => [SiteLanguage::Greek, '/'],
]);

it('links the six verticals from its strip, each in the page\'s language and labelled from site.php, in Vertical\'s order', function (SiteLanguage $language) {
    $urls = app(TenantUrls::class);
    $document = homeDocument($this, $language);
    $links = [];

    foreach ($document->querySelectorAll('main .site-home-verticals li a') as $anchor) {
        $links[] = [(string) $anchor->getAttribute('href'), trim((string) $anchor->textContent)];
    }

    expect(siteTexts($document, 'main .site-home-verticals h2'))->toBe([siteSentence('home.verticals.heading', $language)])
        ->and($links)->toBe(array_map(fn (Vertical $vertical): array => [
            $urls->central($language->uri('/for/'.$vertical->value)),
            siteSentence('site.pages.vertical.'.$vertical->value, $language),
        ], Vertical::cases()));
})->with([
    'English' => [SiteLanguage::English],
    'Greek' => [SiteLanguage::Greek],
]);

it('links every signup action to the form in the page\'s language through the one helper, the Greek form under /el now its route exists', function () {
    $urls = app(TenantUrls::class);
    $hrefs = fn (SiteLanguage $language): array => array_map(
        fn (Element $action): string => (string) $action->getAttribute('href'),
        iterator_to_array(homeDocument($this, $language)->querySelectorAll('main a.site-home-signup'), false),
    );

    expect(Route::has('el.signup'))->toBeTrue()
        ->and($hrefs(SiteLanguage::English))->toBe([$urls->central('/signup'), $urls->central('/signup')])
        ->and($hrefs(SiteLanguage::Greek))->toBe([$urls->central('/el/signup'), $urls->central('/el/signup')]);
});
