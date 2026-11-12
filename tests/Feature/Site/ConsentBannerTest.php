<?php

use App\Site\SiteLanguage;
use App\Site\SitePages;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use App\Tenancy\TenantUrls;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The consent banner and the analytics tag's loader as served, read with no script run. While the
 * measurement id is unset no central page carries the banner, the loader or the footer's
 * cookie-settings control. While it is set every central page does, every page of the site in
 * both languages, the 404 and the signup's two pages in its frame: the banner hidden in the
 * markup with the tag's id and address on it, its one sentence and its link to the cookie notice
 * in the page's language, Accept and Reject as the same element with the same classes, side by
 * side, and the consent script, an entry of the admin build; and the footer's control, a link to
 * the cookie notice. No tenant host's root view and not the console's ever carries either,
 * whatever the configuration says.
 *
 * The root views link their entries through the framework's Vite helper, which reads the build's
 * manifest, and the feature suite runs before the build. The helper is pointed here at a hot file
 * of this file's own instead, so every entry a view names is printed as a script or a stylesheet
 * at that address with no manifest read, and the consent script is seen by its entry's path.
 *
 * A Greek sentence is read through the translator with the locale named, never spelled here.
 */

/** The address the hot file names, which every entry a view links is printed under. */
const CONSENT_DEV_SERVER = 'http://vite.consent.test';

/** The consent script's entry as the views name it. */
const CONSENT_ENTRY = 'resources/js/site/consent.ts';

/** A measurement id of the shape GA4 prints, standing in for the property's. */
const CONSENT_MEASUREMENT_ID = 'G-CONSENT123';

/**
 * The hot file this file points the Vite helper at, one per process.
 */
function consentHotFile(): string
{
    return sys_get_temp_dir().'/kr-consent-'.getmypid().'.hot';
}

beforeEach(function () {
    file_put_contents(consentHotFile(), CONSENT_DEV_SERVER);
    app(Vite::class)->useHotFile(consentHotFile());
});

afterEach(function () {
    if (is_file(consentHotFile())) {
        unlink(consentHotFile());
    }
});

/**
 * Every central page by its address, each with the language it is served in: every page of the
 * site in both languages, a page served once per key under each, the 404 in both, and the
 * signup's form and pending page. The pending page needs a signup waiting, so one is posted.
 *
 * @return array<string, SiteLanguage>
 */
function consentCentralPages(TestCase $test): array
{
    $registry = app(SitePages::class);
    $urls = app(TenantUrls::class);
    $pages = [];

    foreach ($registry->names() as $page) {
        foreach ($registry->instances($page) as $parameters) {
            foreach (SiteLanguage::cases() as $language) {
                $pages[$registry->url($page, $language, $parameters)] = $language;
            }
        }
    }

    $pages[$urls->central('/no-such-page')] = SiteLanguage::English;
    $pages[$urls->central('/el/no-such-page')] = SiteLanguage::Greek;

    $test->post($urls->central('/signup'), signupForm())->assertRedirect($urls->central('/signup/luna/pending'));

    $pages[$urls->central('/signup')] = SiteLanguage::English;
    $pages[$urls->central('/signup/luna/pending')] = SiteLanguage::English;

    return $pages;
}

/**
 * The script elements of a document that load the consent script's entry.
 *
 * @return list<string>
 */
function consentLoaders(HTMLDocument $document): array
{
    $loaders = [];

    foreach ($document->querySelectorAll('script[src]') as $script) {
        $src = (string) $script->getAttribute('src');

        if (str_ends_with($src, '/'.CONSENT_ENTRY)) {
            $loaders[] = $src;
        }
    }

    return $loaders;
}

it('carries no banner, no loader and no cookie-settings control on any central page while the id is unset or empty, the signup\'s included', function (?string $id) {
    config(['services.google_analytics.measurement_id' => $id]);

    $pages = consentCentralPages($this);

    foreach ($pages as $url => $language) {
        $response = $this->get($url);
        $document = siteDocument($response);

        expect($response->status())->toBeIn([200, 404], $url)
            ->and($document->querySelector('.site-consent'))->toBeNull($url)
            ->and($document->querySelector('[data-measurement-id]'))->toBeNull($url)
            ->and($document->querySelector('.site-consent-settings'))->toBeNull($url)
            ->and(consentLoaders($document))->toBe([], $url)
            ->and((string) $response->getContent())->not->toContain('googletagmanager', $url)
            ->and((string) $response->getContent())->not->toContain(__('site.consent.settings', [], $language->value), $url);
    }

    expect(count($pages))->toBeGreaterThan(30);
})->with([
    'unset' => [null],
    'empty, as an empty variable reads' => [''],
]);

it('carries the banner hidden on every central page while the id is set, the signup\'s included: the id and the address on it, one sentence and the cookie notice\'s link in the page\'s language, Accept and Reject of one weight, the loader, and the footer\'s control', function () {
    config([
        'services.google_analytics.measurement_id' => CONSENT_MEASUREMENT_ID,
        'services.google_analytics.script' => 'https://www.googletagmanager.com/gtag/js',
    ]);

    $registry = app(SitePages::class);

    foreach (consentCentralPages($this) as $url => $language) {
        $response = $this->get($url);
        $document = siteDocument($response);
        $banners = $document->querySelectorAll('.site-consent');
        $banner = $document->querySelector('.site-consent');
        $notice = $registry->url(SitePages::COOKIES, $language);
        $choices = [];

        foreach ($document->querySelectorAll('.site-consent button') as $button) {
            $choices[] = [$button->getAttribute('type'), $button->getAttribute('class'), $button->getAttribute('data-consent-choice'), trim((string) $button->textContent)];
        }

        expect($banners)->toHaveCount(1, $url)
            ->and($banner?->tagName)->toBe('SECTION', $url)
            ->and($banner?->hasAttribute('hidden'))->toBeTrue($url)
            ->and($banner?->parentElement?->tagName)->toBe('BODY', $url)
            ->and($banner?->getAttribute('aria-label'))->toBe(__('site.consent.label', [], $language->value), $url)
            ->and($banner?->getAttribute('data-measurement-id'))->toBe(CONSENT_MEASUREMENT_ID, $url)
            ->and($banner?->getAttribute('data-script'))->toBe('https://www.googletagmanager.com/gtag/js', $url)
            ->and(siteTexts($document, '.site-consent .site-consent-sentence'))->toBe([siteSentence('site.consent.sentence', $language).' '.siteSentence('site.consent.notice', $language)], $url)
            ->and($document->querySelector('.site-consent .site-consent-sentence a')?->getAttribute('href'))->toBe($notice, $url)
            ->and($document->querySelectorAll('.site-consent .site-consent-choices > button'))->toHaveCount(2, $url)
            ->and(array_column($choices, 2))->toBe(['granted', 'denied'], $url)
            ->and(array_column($choices, 3))->toBe([siteSentence('site.consent.accept', $language), siteSentence('site.consent.reject', $language)], $url)
            ->and(array_column($choices, 0))->toBe(['button', 'button'], $url)
            ->and($choices[0][1] ?? null)->toBeString()->toBe($choices[1][1] ?? null, $url)
            ->and(consentLoaders($document))->toBe([CONSENT_DEV_SERVER.'/'.CONSENT_ENTRY], $url)
            ->and(siteTexts($document, 'body > footer .site-consent-settings'))->toBe([siteSentence('site.consent.settings', $language)], $url)
            ->and($document->querySelector('body > footer .site-consent-settings')?->tagName)->toBe('A', $url)
            ->and($document->querySelector('body > footer .site-consent-settings')?->getAttribute('href'))->toBe($notice, $url);
    }
});

it('prints the id and the address configuration holds, so a changed id changes every page with no edit', function () {
    config([
        'services.google_analytics.measurement_id' => 'G-OTHER4567',
        'services.google_analytics.script' => 'https://analytics.example.test/gtag/js',
    ]);

    $document = siteDocument($this->get(centralUrl('/')));

    expect($document->querySelector('.site-consent')?->getAttribute('data-measurement-id'))->toBe('G-OTHER4567')
        ->and($document->querySelector('.site-consent')?->getAttribute('data-script'))->toBe('https://analytics.example.test/gtag/js');
});

it('carries neither the banner nor the loader on any tenant host\'s root view or the console\'s while the id is set', function () {
    config(['services.google_analytics.measurement_id' => CONSENT_MEASUREMENT_ID]);
    provisionTenant('luna');
    provisionTenant('paused', ['ownerEmail' => 'owner@paused.example']);
    DB::table('tenants')->where('slug', 'paused')->update(['status' => TenantStatus::Suspended->value]);

    $uuid = '00000000-0000-4000-8000-000000000000';
    $views = [
        'the hosted booking page' => [hostedPage('luna'), 200],
        'the admin\'s sign-in page' => [tenantUrl('luna', '/admin/login'), 200],
        'a decision page' => [tenantUrl('luna', '/decide/'.$uuid.'/approve'), 403],
        'a pay page' => [tenantUrl('luna', '/pay/'.$uuid.'/link'), 403],
        'the suspended page' => [tenantUrl('paused', '/'), 503],
        'the console\'s sign-in page' => [consoleUrl('/login'), 200],
    ];

    foreach ($views as $name => [$url, $status]) {
        $response = $this->get($url)->assertStatus($status);
        $document = siteDocument($response);
        $content = (string) $response->getContent();

        expect($document->querySelector('.site-consent'))->toBeNull($name)
            ->and($document->querySelector('[data-measurement-id]'))->toBeNull($name)
            ->and($document->querySelector('.site-consent-settings'))->toBeNull($name)
            ->and(consentLoaders($document))->toBe([], $name)
            ->and($content)->not->toContain(CONSENT_MEASUREMENT_ID, $name)
            ->and($content)->not->toContain('googletagmanager', $name);
    }
});

it('includes the banner from the site\'s layout and the signup\'s frame alone, and names the consent script nowhere else', function () {
    $including = [];
    $naming = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        $source = $file->getContents();
        $path = str_replace('\\', '/', $file->getRelativePathname());

        if (str_contains($source, "@include('site.partials.consent')")) {
            $including[] = $path;
        }

        if (str_contains($source, CONSENT_ENTRY)) {
            $naming[] = $path;
        }
    }

    foreach (['admin', 'booking'] as $bundle) {
        foreach (File::allFiles(resource_path('js/'.$bundle)) as $file) {
            expect($file->getContents())->not->toContain('site/consent', $file->getPathname());
        }
    }

    sort($including);

    expect($including)->toBe(['central/app.blade.php', 'site/layout.blade.php'])
        ->and($naming)->toBe(['site/partials/consent.blade.php']);
});

it('reads the consent cookie in no server code: its name is the consent script\'s alone', function () {
    $script = File::get(resource_path('js/site/consentBanner.ts'));

    if (preg_match("/export const CONSENT_COOKIE = '([a-z_]+)';/", $script, $match) !== 1) {
        throw new LogicException('The consent script names no cookie of its own.');
    }

    $name = $match[1];

    foreach (['app', 'bootstrap', 'config', 'routes', 'resources/views'] as $directory) {
        foreach (File::allFiles(base_path($directory)) as $file) {
            expect($file->getContents())->not->toContain($name, $file->getPathname());
        }
    }
});
