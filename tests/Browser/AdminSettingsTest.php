<?php

use App\Http\Controllers\Admin\CoverController;
use App\Http\Controllers\Admin\GalleryController;
use App\Http\Controllers\Admin\LogoController;
use App\Http\Controllers\Admin\SettingsController;
use App\Infrastructure\Db\TextArray;
use App\Infrastructure\Storage\TenantImages;
use App\Tenancy\Settings;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use App\Tenancy\Theme;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Storage;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\FlowScripts;
use Tests\Browser\Support\ForeignSite;
use Tests\Browser\Support\MultipartBodies;
use Tests\Support\TemporaryFiles;

/*
 * The Settings screen in a real browser: sign in, choose a theme, upload a logo, choose how often
 * starts are offered, save, see the admin's body take the theme, open luna's hosted booking page
 * and see both, the Book now pill painted in the theme's primary on the white header and the logo
 * loaded from the tenant's own origin; see the embed on a foreign page carry the theme and no
 * logo; then switch one message off and save. Each success toast is dismissed as seen, so no
 * assertion rides on the auto-clear timer. The logo lands on the real public disk and is removed
 * afterwards; the multipart body reaches the form request through MultipartBodies, since the
 * plugin's own server parses none. The cover and the gallery walk the same way: a cover and two
 * photos uploaded, one photo removed, and the hosted page showing the cover in the photo grid's
 * first cell and the one photo left.
 */

const LOGO_LOADED = <<<'JS'
    new Promise((resolve) => {
        const started = Date.now();
        const poll = () => {
            const image = document.querySelector('.hosted-logo');
            if (image instanceof HTMLImageElement && image.complete && image.naturalWidth > 0) {
                resolve(new URL(image.currentSrc || image.src).origin === location.origin);
            } else if (Date.now() - started > 5000) {
                resolve(image === null ? 'no logo' : 'not loaded');
            } else {
                setTimeout(poll, 50);
            }
        };
        poll();
    })
    JS;

beforeAll(fn () => ForeignSite::start());
afterAll(fn () => ForeignSite::stop());

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
    Storage::disk(TenantImages::DISK)->deleteDirectory('tenants/luna');
    // The plugin's server hands the kernel no files; see MultipartBodies.
    $this->app->make(Kernel::class)->prependMiddleware(MultipartBodies::class);
});

afterEach(function () {
    dropDemoSchemas();
    Storage::disk(TenantImages::DISK)->deleteDirectory('tenants/luna');
});

/**
 * The cover on the hosted page: in the photo grid under the title block, filling the grid's
 * first cell, loaded from the tenant's own origin.
 */
const COVER_LOADED = <<<'JS'
    new Promise((resolve) => {
        const started = Date.now();
        const poll = () => {
            const cell = document.querySelector('.hosted-intro .hosted-grid > .hosted-cell:first-child');
            const image = cell === null ? null : cell.firstElementChild;
            if (image instanceof HTMLImageElement && image.className === 'hosted-cover' && image.complete && image.naturalWidth > 0) {
                resolve(new URL(image.currentSrc || image.src).origin === location.origin && Math.abs(image.getBoundingClientRect().width - cell.getBoundingClientRect().width) < 1);
            } else if (Date.now() - started > 5000) {
                resolve(image instanceof HTMLImageElement ? 'not loaded' : 'no cover in the grid');
            } else {
                setTimeout(poll, 50);
            }
        };
        poll();
    })
    JS;

/** A small real PNG, drawn here so no binary fixture is tracked. */
function logoFile(): string
{
    $path = TemporaryFiles::make('kr-logo', '.png');
    $image = imagecreatetruecolor(120, 40);
    assert($image !== false);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
    imagepng($image, $path);
    imagedestroy($image);

    return $path;
}

it('chooses Violet, uploads a logo, saves, sees the theme on the admin\'s body, both on the hosted page and the theme alone on a foreign page, then switches one message off', function () {
    settingsOf('luna')->update(['theme' => 'neutral']);
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->click(navLabel('settings'))
        ->waitForText(adminLine('admin/settings.booking_page.snippet'))
        ->assertPathIs('/admin/settings')
        ->assertValue('#settings-currency', 'EUR')
        ->assertCount('#settings-accent', 0)
        ->assertAttribute('.admin-settings-theme[data-value="neutral"]', 'aria-pressed', 'true')
        ->assertScript("document.querySelector('#settings-snippet').value.includes('data-theme=\"neutral\"')", true)
        ->assertCount('.admin-settings-logo__image', 0)
        ->click('.admin-settings-theme[data-value="violet"]')
        ->assertAttribute('.admin-settings-theme[data-value="violet"]', 'aria-pressed', 'true')
        ->attach('#settings-logo', logoFile())
        ->click('.admin-settings-logo-upload')
        ->waitForText(adminLine(LogoController::UPLOADED))
        ->click('.admin-toast-dismiss')
        ->assertCount('.admin-toast', 0)
        ->assertCount('.admin-settings-logo__image', 1)
        ->assertAttribute('.admin-settings-theme[data-value="violet"]', 'aria-pressed', 'true')
        ->assertValue('#settings-start-interval', '15')
        ->select('#settings-start-interval', '30')
        ->click('.admin-settings-save')
        ->waitForText(adminLine(SettingsController::SAVED))
        ->click('.admin-toast-dismiss')
        ->assertCount('.admin-toast', 0)
        ->assertValue('#settings-start-interval', '30')
        ->assertScript("document.querySelector('#settings-snippet').value.includes('<div class=\"kr-booking\" data-theme=\"violet\"></div>')", true)
        ->assertNoJavaScriptErrors();

    $this->visit(Admin::url('luna', '/settings'))
        ->waitForText(adminLine('admin/settings.booking_page.snippet'))
        ->assertScript("document.body.getAttribute('data-theme')", 'violet')
        // The violet block's primary on the body as the build serves it: app.css spells it in
        // oklch, the build lowers that to a hex, a display-p3 and a lab rule under @supports, and
        // the browser takes the lab one and hands it back as written.
        ->assertScript("getComputedStyle(document.body).getPropertyValue('--primary').trim()", 'lab(42.1801% 57.129 -80.7421)');

    $path = settingsOf('luna')->value('brand_logo_path');
    expect($path)->toMatch('/\Atenants\/luna\/logo-[0-9a-f]{16}\.png\z/')
        ->and(settingsOf('luna')->value('theme'))->toBe('violet')
        ->and(settingsOf('luna')->value('start_interval_min'))->toBe(30);
    assert(is_string($path));
    Storage::disk(TenantImages::DISK)->assertExists($path);

    $this->visit(hostedPage('luna'))
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript("document.body.getAttribute('data-theme')", 'violet')
        ->assertAttribute('.kr-booking', 'data-theme', 'violet')
        ->assertScript("getComputedStyle(document.querySelector('.kr-booking')).getPropertyValue('--kr-primary').trim()", '#7c3aed')
        ->assertScript("getComputedStyle(document.querySelector('.hosted-header')).backgroundColor", 'rgb(255, 255, 255)')
        ->assertScript("getComputedStyle(document.querySelector('.hosted-book')).backgroundColor", 'rgb(124, 58, 237)')
        ->assertScript(LOGO_LOADED, true);

    $foreign = ForeignSite::current()->page('branded.html', sprintf(<<<'HTML'
        <!doctype html>
        <html lang="en"><head><meta charset="utf-8"><title>Somebody else's site</title></head>
        <body><h1>Their page</h1>%s</body></html>
        HTML, app(TenantUrls::class)->embedSnippet(Slug::of('luna'), Theme::Violet)));
    $this->visit($foreign)
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript("getComputedStyle(document.querySelector('.kr-booking')).getPropertyValue('--kr-primary').trim()", '#7c3aed')
        ->assertScript("document.querySelectorAll('.kr-booking img').length", 0);

    $this->visit(Admin::url('luna', '/settings'))
        ->waitForText(adminLine('admin/settings.booking_page.snippet'))
        ->assertChecked('#settings-email-reminder')
        ->uncheck('#settings-email-reminder')
        ->click('.admin-settings-save')
        ->waitForText(adminLine(SettingsController::SAVED))
        ->click('.admin-toast-dismiss')
        ->assertNotChecked('#settings-email-reminder')
        ->assertNoJavaScriptErrors();

    expect(settingsOf('luna')->selectRaw('emails_off::text as off')->value('off'))->toBe('{reminder}');
});

it('holds the approved message on while a payment connection works, checked and disabled beside its sentence, tags the late payment to the owners, and a save leaves the approved key off the stored list', function () {
    connectPaymentFake();
    settingsOf('luna')->update(['emails_off' => TextArray::encode(['approved', 'reminder'])]);

    Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->click(navLabel('settings'))
        ->waitForText(adminLine('admin/settings.booking_page.snippet'))
        ->assertChecked('#settings-email-approved')
        ->assertDisabled('#settings-email-approved')
        ->assertSeeIn('.admin-settings-email__locked', adminLine('admin/emails.locked'))
        ->assertCount('.admin-settings-email__locked', 1)
        ->assertAttribute('#settings-email-approved', 'aria-describedby', 'settings-email-approved-locked')
        ->assertNotChecked('#settings-email-reminder')
        ->assertSeeIn('label[for="settings-email-late_payment"] .admin-settings-email__audience', adminLine('admin/emails.audience.owners'))
        ->assertSeeIn('label[for="settings-email-new_booking"] .admin-settings-email__audience', adminLine('admin/emails.audience.business'))
        ->click('.admin-settings-save')
        ->waitForText(adminLine(SettingsController::SAVED))
        ->click('.admin-toast-dismiss')
        ->assertChecked('#settings-email-approved')
        ->assertNoJavaScriptErrors();

    expect(settingsOf('luna')->selectRaw('emails_off::text as off')->value('off'))->toBe('{reminder}');
});

it('writes a profile in the Booking page section, saves it with the settings, and reads it back after a reload', function () {
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->click(navLabel('settings'))
        ->waitForText(adminLine('admin/settings.profile.important_info'))
        ->assertValue('#settings-country', 'GR')
        ->fill('#settings-about', 'A small studio by the sea.')
        ->fill('#settings-address-line1', '12 Odos Ermou')
        ->fill('#settings-city', 'Athens')
        ->select('#settings-country', 'CY')
        ->fill('#settings-phone', '+30 210 000 0000')
        ->fill('#settings-website', 'https://luna.example')
        ->fill('#settings-google-reviews-url', 'https://g.page/r/luna/review')
        ->fill('#settings-important-info', 'Please arrive five minutes early.')
        ->click('.admin-settings-save')
        ->waitForText(adminLine(SettingsController::SAVED))
        ->click('.admin-toast-dismiss')
        ->assertNoJavaScriptErrors();

    expect(settingsOf('luna')->value('about'))->toBe('A small studio by the sea.')
        ->and(settingsOf('luna')->value('address_line2'))->toBeNull()
        ->and(settingsOf('luna')->value('country'))->toBe('CY');

    $this->visit(Admin::url('luna', '/settings'))
        ->waitForText(adminLine('admin/settings.profile.important_info'))
        ->assertValue('#settings-about', 'A small studio by the sea.')
        ->assertValue('#settings-address-line1', '12 Odos Ermou')
        ->assertValue('#settings-address-line2', '')
        ->assertValue('#settings-country', 'CY')
        ->assertValue('#settings-website', 'https://luna.example')
        ->assertValue('#settings-important-info', 'Please arrive five minutes early.')
        ->assertNoJavaScriptErrors();
});

it('uploads a cover and two gallery photos, removes one, and sees the cover first and the photo left on the hosted page', function () {
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->click(navLabel('settings'))
        ->waitForText(adminLine('admin/settings.booking_page.cover'))
        ->assertCount('.admin-settings-cover__image', 0)
        ->assertSeeIn('.admin-settings-gallery__count', adminLine('admin/settings.gallery.count', ['count' => '0', 'capacity' => (string) Settings::GALLERY_CAPACITY]))
        ->attach('#settings-cover', drawnPng(800, 300, 14, 116, 144))
        ->click('.admin-settings-cover-upload')
        ->waitForText(adminLine(CoverController::UPLOADED))
        ->click('.admin-toast-dismiss')
        ->assertCount('.admin-toast', 0)
        ->assertCount('.admin-settings-cover__image', 1)
        ->attach('#settings-gallery', drawnPng(400, 400, 200, 40, 40))
        ->click('.admin-settings-gallery-add')
        ->waitForText(adminLine(GalleryController::ADDED))
        ->click('.admin-toast-dismiss')
        ->assertCount('.admin-toast', 0)
        ->assertSeeIn('.admin-settings-gallery__count', adminLine('admin/settings.gallery.count', ['count' => '1', 'capacity' => (string) Settings::GALLERY_CAPACITY]))
        ->attach('#settings-gallery', drawnPng(400, 400, 40, 160, 40))
        ->click('.admin-settings-gallery-add')
        ->waitForText(adminLine(GalleryController::ADDED))
        ->click('.admin-toast-dismiss')
        ->assertCount('.admin-toast', 0)
        ->assertSeeIn('.admin-settings-gallery__count', adminLine('admin/settings.gallery.count', ['count' => '2', 'capacity' => (string) Settings::GALLERY_CAPACITY]))
        ->assertCount('.admin-settings-gallery__image', 2)
        ->assertNoJavaScriptErrors();

    /** @return list<string> */
    $gallery = function (): array {
        $literal = settingsOf('luna')->selectRaw('gallery_paths::text as paths')->value('paths');
        assert(is_string($literal));

        return TextArray::decode($literal);
    };
    $cover = settingsOf('luna')->value('cover_path');
    $held = $gallery();
    expect($cover)->toMatch('/\Atenants\/luna\/cover-[0-9a-f]{16}\.[a-z]+\z/')
        ->and($held)->toHaveCount(2);
    assert(is_string($cover) && isset($held[0], $held[1]));
    [$first, $second] = $held;

    $page->click('.admin-settings-gallery__photo:first-child .admin-settings-gallery-remove')
        ->waitForText(adminLine(GalleryController::REMOVED))
        ->click('.admin-toast-dismiss')
        ->assertCount('.admin-toast', 0)
        ->assertSeeIn('.admin-settings-gallery__count', adminLine('admin/settings.gallery.count', ['count' => '1', 'capacity' => (string) Settings::GALLERY_CAPACITY]))
        ->assertCount('.admin-settings-gallery__image', 1)
        ->assertNoJavaScriptErrors();

    expect($gallery())->toBe([$second]);
    Storage::disk(TenantImages::DISK)->assertExists([$cover, $second]);
    Storage::disk(TenantImages::DISK)->assertMissing($first);
    $urls = app(TenantUrls::class);

    $this->visit(hostedPage('luna'))
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript(COVER_LOADED, true)
        ->assertScript("document.querySelector('.hosted-cover').getAttribute('src')", $urls->image(Slug::of('luna'), basename($cover)))
        ->assertScript("[...document.querySelectorAll('.hosted-gallery a')].map((link) => link.getAttribute('href')).join(' ')", $urls->image(Slug::of('luna'), basename($second)))
        ->assertScript("[...document.querySelectorAll('.hosted-gallery img')].map((image) => image.getAttribute('src')).join(' ')", $urls->image(Slug::of('luna'), basename($second)));
});
