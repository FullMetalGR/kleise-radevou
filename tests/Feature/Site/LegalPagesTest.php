<?php

use App\Http\Controllers\Site\LegalController;
use App\Http\SignupHolder;
use App\Site\LegalFrame;
use App\Site\SiteLanguage;
use App\Site\SiteRoutes;
use App\Tenancy\TenantUrls;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Illuminate\Translation\Translator;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/*
 * The legal pages' frame, as served and read with no script run: the terms of service, the
 * privacy policy, the cookie notice and the data processing agreement, at /terms, /privacy,
 * /cookies and /dpa and their /el twins, each from one template over its file under
 * lang/{en,el}/legal: the title, the version date, and the sections, each a heading over
 * paragraphs and lists. Each carries the entity line, site.entity's name, address and VAT
 * number, which the footer of every page carries too, and while site.legal_reviewed is false,
 * the default, one line at its top saying the text is a draft under review. The footer links the
 * six pages, the features, the FAQ and the four legal texts, in the page's language, and the
 * sitemap lists them with no edit to it. The page's head and its h1 are CentralSurfaceTest's
 * walk.
 *
 * The files hold the English texts and, under lang/el, their Greek translation. The frame's
 * cases read no clause: they hold any file to the frame's shape, whatever its sections and
 * blocks.
 *
 * A Greek sentence is read through the translator with the locale named, never spelled here.
 */

beforeEach(fn () => $this->withoutVite());

/** The four legal pages, each its path and its file under lang/{en,el}. */
const LEGAL_PAGES = [
    'terms' => ['/terms', 'legal/terms'],
    'privacy' => ['/privacy', 'legal/privacy'],
    'cookies' => ['/cookies', 'legal/cookies'],
    'dpa' => ['/dpa', 'legal/dpa'],
];

/**
 * The processors: hosting, mail, the subscription and a tenant's payments, a tenant's
 * payments, Turnstile, and Analytics and Maps, by the names the texts give them.
 */
const LEGAL_PROCESSORS = ['Linode', 'RunCloud', 'Qboxmail', 'Stripe', 'Viva', 'Cloudflare', 'Google'];

/**
 * The legal pages by language, each its path, its file and its language: the dataset of every
 * case that walks them.
 *
 * @return array<string, array{string, string, SiteLanguage}>
 */
function legalPagesByLanguage(): array
{
    $cases = [];

    foreach (LEGAL_PAGES as $page => [$path, $copy]) {
        foreach (SiteLanguage::cases() as $language) {
            $cases[$page.' in '.$language->name] = [$path, $copy, $language];
        }
    }

    return $cases;
}

/**
 * A page of the site in a language as served, at 200.
 *
 * @return TestResponse<Response>
 */
function legalPageResponse(TestCase $test, string $path, SiteLanguage $language): TestResponse
{
    $response = $test->get(app(TenantUrls::class)->central($language->uri($path)));

    $response->assertOk();

    return $response;
}

/**
 * A section's blocks as its legal file holds them, the public name filled in: a paragraph is a
 * string and a list is a list of its items.
 *
 * @return list<string|list<string>>
 */
function legalFileBlocks(string $copy, string $section, SiteLanguage $language): array
{
    $blocks = __($copy.'.sections.'.$section.'.body', ['name' => siteName()], $language->value);

    assert(is_array($blocks) && $blocks !== [] && array_is_list($blocks), sprintf('%s.sections.%s.body is no list in %s.', $copy, $section, $language->value));

    return array_map(function (mixed $block) use ($copy, $section): string|array {
        if (is_string($block)) {
            return $block;
        }

        assert(is_array($block) && $block !== [] && array_is_list($block), sprintf('%s.sections.%s holds a block that is neither a paragraph nor a list.', $copy, $section));

        return array_map(fn (mixed $item): string => is_string($item) ? $item : throw new LogicException(sprintf('%s.sections.%s holds a list item that is no string.', $copy, $section)), $block);
    }, $blocks);
}

/**
 * A section's blocks as the page renders them, in document order: a paragraph's text, or a
 * list's items.
 *
 * @return list<string|list<string>>
 */
function legalRenderedBlocks(HTMLDocument $document, string $section): array
{
    $blocks = [];

    foreach ($document->querySelectorAll('main .site-legal-section#'.$section.' > p, main .site-legal-section#'.$section.' > ul') as $element) {
        if ($element->localName === 'ul') {
            $items = [];

            foreach ($element->childNodes as $item) {
                if ($item instanceof Element && $item->localName === 'li') {
                    $items[] = trim((string) $item->textContent);
                }
            }

            $blocks[] = $items;
        } else {
            $blocks[] = trim((string) $element->textContent);
        }
    }

    return $blocks;
}

/**
 * A legal page's text as served in a language: its article's text, every run of white space one
 * space.
 */
function legalPageText(TestCase $test, string $path, SiteLanguage $language): string
{
    $article = siteDocument(legalPageResponse($test, $path, $language))->querySelector('main .site-legal');

    return trim((string) preg_replace('/\s+/', ' ', (string) $article?->textContent));
}

/**
 * The legal entity as configuration holds it: its name, its address and its VAT number.
 *
 * @return array{string, string, string}
 */
function legalEntityValues(): array
{
    $entity = config('site.entity');

    assert(is_array($entity) && is_string($entity['name'] ?? null) && is_string($entity['address'] ?? null) && is_string($entity['vat'] ?? null));

    return [$entity['name'], $entity['address'], $entity['vat']];
}

/**
 * The entity line in a language, filled from configuration as it stands: the VAT number's clause
 * where configuration holds one, and the name and the address alone where it holds none.
 */
function legalEntityLine(SiteLanguage $language): string
{
    [$name, $address, $vat] = legalEntityValues();

    return trim($vat) === ''
        ? siteSentence('site.entity', $language, ['entity' => $name, 'address' => $address])
        : siteSentence('site.entity_vat', $language, ['entity' => $name, 'address' => $address, 'vat' => $vat]);
}

/**
 * Overrides, in the translator alone, every line of the legal files that still holds a gap with a
 * line that holds none, in both languages, but the one file of one language named, whose gaps
 * stay as written.
 *
 * @param  array{SiteLanguage, string}|null  $keep
 */
function legalGapsFilled(?array $keep = null): void
{
    $translator = app('translator');

    foreach (SiteLanguage::cases() as $language) {
        foreach (LegalFrame::FILES as $file) {
            if ($keep === [$language, $file]) {
                continue;
            }

            $lines = $translator->get($file, [], $language->value);
            assert(is_array($lines));
            $filled = [];

            foreach (Arr::dot($lines) as $path => $line) {
                if (is_string($line) && str_replace(LegalFrame::GAPS, '', $line) !== $line) {
                    $filled[$file.'.'.$path] = 'A settled line.';
                }
            }

            $translator->addLines($filled, $language->value);
        }
    }
}

it('renders each legal page from the one template over its file: the heading, the version date and the sections in the file\'s order, each a heading over its paragraphs and lists', function (string $path, string $copy, SiteLanguage $language) {
    $response = legalPageResponse($this, $path, $language);

    $response->assertViewIs('site.legal')->assertViewHas('copy', $copy);

    $document = siteDocument($response);
    $sections = siteCopyKeys($copy.'.sections', $language);

    expect($sections)->not->toBeEmpty()
        ->and(siteTexts($document, 'main h1'))->toBe([siteSentence($copy.'.heading', $language)])
        ->and(siteTexts($document, 'main .site-legal-version'))->toBe([siteSentence('site.legal.version', $language, ['date' => siteSentence($copy.'.version', $language)])])
        ->and(siteTexts($document, 'main .site-legal-section > h2'))->toBe(array_map(fn (string $section): string => siteSentence($copy.'.sections.'.$section.'.heading', $language), $sections));

    foreach ($sections as $section) {
        expect(legalRenderedBlocks($document, $section))->toBe(legalFileBlocks($copy, $section, $language), $section);
    }
})->with(legalPagesByLanguage());

it('renders a legal file block by block, a paragraph as a paragraph and a list as a list, in the file\'s order, the public name filled in', function () {
    $this->allowMissingKey('site.pages.fixture');
    foreach (SiteLanguage::cases() as $language) {
        app(Translator::class)->addLines([
            'legal/fixture.title' => 'Fixture - :name',
            'legal/fixture.description' => 'A fixture of :name.',
            'legal/fixture.heading' => 'The fixture of :name',
            'legal/fixture.version' => 'the fixture\'s version',
            'legal/fixture.sections' => [
                'first' => ['heading' => 'First', 'body' => ['Before the list, at :name.', ['one', 'two of :name'], 'After the list.']],
                'second' => ['heading' => 'Second', 'body' => [['alone']]],
            ],
        ], $language->value);
    }

    SiteRoutes::page('/fixture', LegalController::class, 'site.fixture', 'legal/fixture');
    Route::getRoutes()->refreshNameLookups();

    $document = siteDocument(legalPageResponse($this, '/fixture', SiteLanguage::English));

    expect(siteTexts($document, 'main h1'))->toBe(['The fixture of '.siteName()])
        ->and(siteTexts($document, 'main .site-legal-version'))->toBe([siteSentence('site.legal.version', SiteLanguage::English, ['date' => 'the fixture\'s version'])])
        ->and(siteTexts($document, 'main .site-legal-section > h2'))->toBe(['First', 'Second'])
        ->and(legalRenderedBlocks($document, 'first'))->toBe(['Before the list, at '.siteName().'.', ['one', 'two of '.siteName()], 'After the list.'])
        ->and(legalRenderedBlocks($document, 'second'))->toBe([['alone']]);
});

it('prints the entity line from configuration on each legal page, below its heading', function (string $path, string $copy, SiteLanguage $language) {
    $document = siteDocument(legalPageResponse($this, $path, $language));

    expect(siteTexts($document, 'main .site-legal-entity'))->toBe([legalEntityLine($language)])
        ->and(legalEntityLine($language))->toContain(...legalEntityValues());
})->with(legalPagesByLanguage());

it('prints the entity line in the footer of every page, the legal pages, the others and the 404', function (string $path, SiteLanguage $language) {
    $response = $this->get(app(TenantUrls::class)->central($language->uri($path)));

    expect(siteTexts(siteDocument($response), 'footer .site-entity'))->toBe([legalEntityLine($language)]);
})->with([
    'the home' => ['/', SiteLanguage::English],
    'the Greek home' => ['/', SiteLanguage::Greek],
    'the features' => ['/features', SiteLanguage::English],
    'the Greek FAQ' => ['/faq', SiteLanguage::Greek],
    'the terms' => ['/terms', SiteLanguage::English],
    'the Greek agreement' => ['/dpa', SiteLanguage::Greek],
    'the 404' => ['/no-such-page', SiteLanguage::English],
]);

it('follows a changed entity in the legal pages and the footer alike', function () {
    config(['site.entity' => ['name' => 'Example Ltd', 'address' => '1 Example Street, Athens', 'vat' => 'EL000000000']]);

    foreach (SiteLanguage::cases() as $language) {
        $line = siteSentence('site.entity_vat', $language, ['entity' => 'Example Ltd', 'address' => '1 Example Street, Athens', 'vat' => 'EL000000000']);

        expect(siteTexts(siteDocument(legalPageResponse($this, '/privacy', $language)), 'main .site-legal-entity'))->toBe([$line])
            ->and(siteTexts(siteDocument(legalPageResponse($this, '/', $language)), 'footer .site-entity'))->toBe([$line])
            ->and($line)->toContain('Example Ltd', '1 Example Street, Athens', 'EL000000000');
    }
});

it('leaves the VAT number\'s clause out of the entity line, on each legal page and in the footer, while configuration holds no VAT number', function (string $vat) {
    config(['site.entity' => ['name' => 'Example Ltd', 'address' => '1 Example Street, Athens', 'vat' => $vat]]);

    foreach (SiteLanguage::cases() as $language) {
        $line = siteSentence('site.entity', $language, ['entity' => 'Example Ltd', 'address' => '1 Example Street, Athens']);

        expect(siteTexts(siteDocument(legalPageResponse($this, '/terms', $language)), 'main .site-legal-entity'))->toBe([$line])
            ->and(siteTexts(siteDocument(legalPageResponse($this, '/', $language)), 'footer .site-entity'))->toBe([$line])
            ->and($line)->toBe('Example Ltd, 1 Example Street, Athens')
            ->and(app(LegalFrame::class)->entity($language))->toBe($line);
    }
})->with([
    'empty' => [''],
    'blank' => ['  '],
]);

it('prints the VAT number\'s clause after the address where configuration holds one, in either language', function () {
    config(['site.entity' => ['name' => 'Example Ltd', 'address' => '1 Example Street, Athens', 'vat' => 'EL000000000']]);

    foreach (SiteLanguage::cases() as $language) {
        $line = app(LegalFrame::class)->entity($language);

        expect($line)->toStartWith('Example Ltd, 1 Example Street, Athens, ')
            ->and($line)->toEndWith(': EL000000000')
            ->and($line)->toBe(siteSentence('site.entity_vat', $language, ['entity' => 'Example Ltd', 'address' => '1 Example Street, Athens', 'vat' => 'EL000000000']));
    }

    expect(app(LegalFrame::class)->entity(SiteLanguage::English))->toBe('Example Ltd, 1 Example Street, Athens, VAT number: EL000000000');
});

it('carries the draft notice as the first line of each legal page while site.legal_reviewed is false, the default', function (string $path, string $copy, SiteLanguage $language) {
    expect(config('site.legal_reviewed'))->toBeFalse();

    $document = siteDocument(legalPageResponse($this, $path, $language));
    $first = $document->querySelector('main > *');

    expect($first?->getAttribute('class'))->toContain('site-legal-draft')
        ->and(trim((string) $first?->textContent))->toBe(siteSentence('site.legal.draft', $language))
        ->and($document->querySelectorAll('main .site-legal-draft'))->toHaveCount(1);
})->with(legalPagesByLanguage());

it('drops the draft notice once site.legal_reviewed is true and no legal file of either language holds a gap', function (string $path, string $copy, SiteLanguage $language) {
    config(['site.legal_reviewed' => true]);
    legalGapsFilled();

    $document = siteDocument(legalPageResponse($this, $path, $language));

    expect($document->querySelectorAll('.site-legal-draft'))->toHaveCount(0)
        ->and(siteTexts($document, 'main h1'))->toBe([siteSentence($copy.'.heading', $language)]);
})->with(legalPagesByLanguage());

it('keeps the draft notice with site.legal_reviewed true while a legal file of either language still holds a gap', function (?SiteLanguage $kept, ?string $file) {
    config(['site.legal_reviewed' => true]);

    if ($kept !== null && $file !== null) {
        legalGapsFilled([$kept, $file]);
    }

    expect(app(LegalFrame::class)->reviewed())->toBeFalse();

    foreach (SiteLanguage::cases() as $language) {
        expect(siteDocument(legalPageResponse($this, '/terms', $language))->querySelectorAll('main .site-legal-draft'))->toHaveCount(1);
    }
})->with([
    'the files as written' => [null, null],
    'a gap left in the Greek agreement alone' => [SiteLanguage::Greek, 'legal/dpa'],
    'a gap left in the English terms alone' => [SiteLanguage::English, 'legal/terms'],
]);

it('counts the texts unreviewed with site.legal_reviewed false, every gap filled or not', function () {
    legalGapsFilled();

    expect(app(LegalFrame::class)->reviewed())->toBeFalse();

    config(['site.legal_reviewed' => true]);

    expect(app(LegalFrame::class)->reviewed())->toBeTrue();
});

it('reads every legal file of both languages for its gaps, the files on disk and the frame\'s list being the same four', function () {
    foreach (SiteLanguage::cases() as $language) {
        $files = array_map(fn (string $path): string => 'legal/'.pathinfo($path, PATHINFO_FILENAME), glob(lang_path($language->value.'/legal/*.php')) ?: []);
        sort($files);
        $listed = LegalFrame::FILES;
        sort($listed);

        expect($files)->toBe($listed);
    }

    expect(LegalFrame::GAPS)->toContain('to be confirmed]')
        ->and(__('legal/dpa', [], 'el'))->toBeArray();
});

it('carries the draft notice on no page but the legal ones', function (string $path) {
    expect(siteDocument(legalPageResponse($this, $path, SiteLanguage::English))->querySelectorAll('.site-legal-draft'))->toHaveCount(0);
})->with(['/', '/features', '/faq']);

it('links the six pages, the features, the FAQ and the four legal texts, from the footer in the page\'s language, in their order', function (string $path, SiteLanguage $language) {
    $urls = app(TenantUrls::class);
    $pages = ['features', 'faq', 'terms', 'privacy', 'cookies', 'dpa'];
    $links = [];

    foreach (siteDocument(legalPageResponse($this, $path, $language))->querySelectorAll('footer .site-footer-nav a') as $anchor) {
        $links[(string) $anchor->getAttribute('href')] = trim((string) $anchor->textContent);
    }

    $expected = [];

    foreach ($pages as $page) {
        $expected[$urls->central($language->uri('/'.$page))] = siteSentence('site.pages.'.$page, $language);
    }

    expect(array_intersect_key($links, $expected))->toBe($expected);
})->with([
    'the English home' => ['/', SiteLanguage::English],
    'the Greek home' => ['/', SiteLanguage::Greek],
    'the English terms' => ['/terms', SiteLanguage::English],
    'the Greek cookie notice' => ['/cookies', SiteLanguage::Greek],
]);

it('lists the six pages in the sitemap in both languages, each with its three alternates, with no edit to it', function () {
    $urls = app(TenantUrls::class);
    $document = new DOMDocument;
    $document->loadXML((string) $this->get(centralUrl('/sitemap.xml'))->getContent());
    $listed = [];

    foreach ($document->getElementsByTagNameNS('http://www.sitemaps.org/schemas/sitemap/0.9', 'url') as $url) {
        $alternates = [];

        foreach ($url->getElementsByTagNameNS('http://www.w3.org/1999/xhtml', 'link') as $link) {
            $alternates[$link->getAttribute('hreflang')] = $link->getAttribute('href');
        }

        $listed[trim((string) $url->getElementsByTagNameNS('http://www.sitemaps.org/schemas/sitemap/0.9', 'loc')->item(0)?->textContent)] = $alternates;
    }

    foreach (['/features', '/faq', '/terms', '/privacy', '/cookies', '/dpa'] as $path) {
        $alternates = ['en' => $urls->central($path), 'el' => $urls->central('/el'.$path), 'x-default' => $urls->central($path)];

        expect($listed[$urls->central($path)] ?? null)->toBe($alternates, $path)
            ->and($listed[$urls->central('/el'.$path)] ?? null)->toBe($alternates, '/el'.$path);
    }
});

it('names on the cookie notice every cookie the central host sets, by the name the code sets it under, the session cookie alone described rather than named', function (SiteLanguage $language) {
    $set = [];

    foreach (['/contact', '/signup'] as $path) {
        foreach ($this->get(centralUrl($path))->assertOk()->headers->getCookies() as $cookie) {
            $set[$cookie->getName()] = $cookie->getName();
        }
    }

    // The session cookie's name is configuration's, the application's name unless the deployment
    // names one, so the notice describes the cookie rather than spell a name a deployment moves.
    $session = config()->string('session.cookie');

    expect($set)->toHaveKey($session);

    unset($set[$session]);

    // The consent script's own cookie and the tag's two it expires on a denial, read from the
    // script as ConsentBannerTest reads the first, so a rename there moves the notice with it.
    $script = File::get(resource_path('js/site/consentBanner.ts'));

    if (preg_match("/export const CONSENT_COOKIE = '([a-z_]+)';/", $script, $consent) !== 1
        || preg_match("/name === '(_[a-z]+)' \|\| name\.startsWith\('(_[a-z]+_)'\)/", $script, $tag) !== 1) {
        throw new LogicException('The consent script names no cookie of its own, or none of the tag\'s.');
    }

    expect($set)->not->toBeEmpty()
        ->and(legalPageText($this, '/cookies', $language))->toContain(...array_values($set), ...[SignupHolder::COOKIE, $consent[1], $tag[1], $tag[2]]);
})->with(SiteLanguage::cases());

it('names the processors: all seven as the agreement\'s sub-processors, and on the privacy policy every one that handles the platform\'s own data, Viva, a provider not yet built, alone left to the agreement', function (SiteLanguage $language) {
    expect(legalPageText($this, '/dpa', $language))->toContain(...LEGAL_PROCESSORS)
        ->and(legalPageText($this, '/privacy', $language))->toContain(...array_values(array_diff(LEGAL_PROCESSORS, ['Viva'])));
})->with(SiteLanguage::cases());
