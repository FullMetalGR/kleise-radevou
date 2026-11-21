<?php

use App\Http\Controllers\Admin\CoverController;
use App\Http\Controllers\Admin\GalleryController;
use App\Http\Controllers\Admin\LogoController;
use App\Http\Controllers\Admin\SettingsController;
use App\Infrastructure\Db\TextArray;
use App\Infrastructure\Storage\TenantImages;
use App\Site\SiteLanguage;
use App\Tenancy\Settings;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use App\Tenancy\Theme;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\FlowScripts;
use Tests\Browser\Support\ForeignSite;
use Tests\Browser\Support\Look;
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
 * first cell and the one photo left. The row of section links is measured on a desktop and on a
 * phone in Greek: a press lands its section's card under the row, marks its link and hands the
 * section's heading the keyboard's focus, the scroll marks the section crossing the line, and on
 * the phone the row scrolls inside itself, following the current link, while the page does not
 * scroll sideways. The row covers no field the keyboard reaches going back up the form, a link
 * focused at the row's edge keeps room for its outline, each link and each theme pill answers a
 * touch 44 pixels tall, and a stored cover leaves its file's name whole at a phone's width.
 */

/**
 * The row of section links as it stands, two frames and a moment after whatever moved the page:
 * the address's fragment, the link marked current, the row's top and bottom, the named section's
 * card and heading tops, each from the top of the screen, how far under the row's bottom that card
 * starts, whether its heading holds the keyboard's focus and the outline the heading is drawn
 * with, whether the named section's link stands inside the row from side to side, and whether the
 * row and the page scroll sideways.
 */
const SECTION_ROW = <<<'JS'
    new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => setTimeout(() => {
        const nav = document.querySelector('.admin-settings-tabs');
        const list = nav.querySelector('ul');
        const heading = document.getElementById('%1$s');
        const link = nav.querySelector('a[href="#%1$s"]').getBoundingClientRect();
        const row = list.getBoundingClientRect();
        resolve({
            hash: location.hash,
            current: nav.querySelector('[aria-current="true"]')?.getAttribute('href') ?? null,
            rowTop: Math.round(nav.getBoundingClientRect().top),
            rowBottom: Math.round(nav.getBoundingClientRect().bottom),
            cardTop: Math.round(heading.closest('section').getBoundingClientRect().top),
            cardGap: Math.round(heading.closest('section').getBoundingClientRect().top - nav.getBoundingClientRect().bottom),
            headingTop: Math.round(heading.getBoundingClientRect().top),
            focused: document.activeElement === heading,
            ring: getComputedStyle(heading).outlineStyle,
            linkInRow: link.left >= row.left - 1 && link.right <= row.right + 1,
            rowScrolls: list.scrollWidth > list.clientWidth + 1,
            pageScrolls: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
        });
    }, 50))))
    JS;

/** Scrolls the page so the named section's card stands the given pixels from the top of the screen. */
const SCROLL_CARD_TO = "(() => { const card = document.getElementById('%s').closest('section'); window.scrollBy(0, card.getBoundingClientRect().top - %d); return true; })()";

/** The section links' targets in their order, and the page's section headings' ids in theirs. */
const SECTION_LINKS_AND_HEADINGS = "JSON.stringify([[...document.querySelectorAll('.admin-settings-tabs a')].map((link) => link.getAttribute('href').slice(1)), [...document.querySelectorAll('.admin-settings-page section h2')].map((heading) => heading.id)])";

/** The heading naming the section the keyboard's focus stands inside, or null outside every section. */
const FOCUS_SECTION = "document.activeElement?.closest('section')?.getAttribute('aria-labelledby') ?? null";

/**
 * Stands the first named field under the row of section links, 20 pixels from the top of the
 * screen, and puts the keyboard's focus on the second, the field after it, moving nothing.
 */
const FIELD_UNDER_THE_ROW = "(() => { window.scrollBy(0, document.getElementById('%s').getBoundingClientRect().top - 20); document.getElementById('%s').focus({ preventScroll: true }); return true; })()";

/**
 * The focused field against the row of section links, two frames after the key that moved the
 * focus: its id, and how far under the row's bottom the field and its label each start.
 */
const FOCUSED_FIELD_AND_ROW = <<<'JS'
    new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => {
        const field = document.activeElement;
        const label = document.querySelector(`label[for="${field.id}"]`);
        const bottom = document.querySelector('.admin-settings-tabs').getBoundingClientRect().bottom;
        resolve({
            field: field.id,
            fieldGap: Math.round(field.getBoundingClientRect().top - bottom),
            labelGap: label === null ? null : Math.round(label.getBoundingClientRect().top - bottom),
        });
    })))
    JS;

/**
 * Scrolls the row until 20 pixels of the first named section's link show at the row's far edge,
 * or at its near edge where the second word is false, puts the focus on the last named link, the
 * one the Tab key leaves for the first, moving nothing, and says whether the first then stands
 * half out of the row.
 */
const LINK_HALF_OUT_OF_THE_ROW = <<<'JS'
    (() => {
        const list = document.querySelector('.admin-settings-tabs ul');
        const link = list.querySelector('a[href="#%1$s"]');
        const row = list.getBoundingClientRect();
        const before = link.getBoundingClientRect();
        list.scrollLeft += %2$s ? before.left - (row.right - 20) : before.right - (row.left + 20);
        list.querySelector('a[href="#%3$s"]').focus({ preventScroll: true });
        const set = link.getBoundingClientRect();
        return %2$s ? set.left < row.right && set.right > row.right : set.right > row.left && set.left < row.left;
    })()
    JS;

/** The focused section link's target, and the room the row leaves at each side of the link. */
const FOCUSED_LINK_IN_THE_ROW = "(() => { const link = document.activeElement; const row = link.closest('ul').getBoundingClientRect(); const box = link.getBoundingClientRect(); return { link: link.getAttribute('href'), near: Math.round(box.left - row.left), far: Math.round(row.right - box.right) }; })()";

/**
 * What a touch lands on just outside each control a selector names, the control brought to the
 * middle of the screen (a link of the row, of the row) first. "drawn" is the heights the controls
 * are drawn at and "area" the heights of the layers that answer for them. "tall" says the
 * outermost whole pixel of a 44 pixel height, over and under each control, answers for that
 * control, so nothing clips or covers its layer. "apart" says the layers of two neighbours on one
 * line do not overlap, and the pixel beside each on the side facing the other answers for its own
 * control. Every point is a whole pixel, since a point is read by the pixel it falls in.
 */
const TOUCH_AREAS = <<<'JS'
    (() => {
        const read = (selector, block) => {
            const controls = [...document.querySelectorAll(selector)];
            const at = (x, y) => document.elementFromPoint(x, y)?.closest(selector) ?? null;
            const each = controls.map((control, index) => {
                control.scrollIntoView({ block, inline: 'center' });
                const box = control.getBoundingClientRect();
                const layer = getComputedStyle(control, '::after');
                const past = -parseFloat(layer.left) || 0;
                const need = (44 - box.height) / 2;
                const middle = Math.round(box.left + box.width / 2);
                const level = Math.round(box.top + box.height / 2);
                const beside = controls[index + 1];
                const next = beside?.getBoundingClientRect();
                const together = next !== undefined && Math.abs(next.top - box.top) < 1;
                return {
                    drawn: Math.round(box.height),
                    area: Math.round(parseFloat(layer.height)) || 0,
                    tall: at(middle, Math.ceil(box.top - need)) === control && at(middle, Math.ceil(box.bottom + need) - 1) === control,
                    apart: !together || (box.right + past <= next.left - past + 0.01 && at(Math.ceil(box.right), level) === control && at(Math.ceil(next.left) - 1, level) === beside),
                };
            });
            const all = (name) => [...new Set(each.map((one) => one[name]))];
            return { count: each.length, drawn: all('drawn'), area: all('area'), tall: each.every((one) => one.tall), apart: each.every((one) => one.apart) };
        };
        return { links: read('.admin-settings-tabs a', 'nearest'), pills: read('.admin-settings-theme', 'center') };
    })()
    JS;

/**
 * The stored cover's row at the width it is read at: the row's, the image's and the file name's
 * widths, the lines the name runs to, whether the name starts under the image and ends inside the
 * row, and whether the page scrolls sideways.
 */
const STORED_COVER_ROW = <<<'JS'
    (() => {
        const row = document.querySelector('.admin-settings-cover__current').getBoundingClientRect();
        const image = document.querySelector('.admin-settings-cover__image').getBoundingClientRect();
        const name = document.querySelector('.admin-settings-cover__name');
        const box = name.getBoundingClientRect();
        return {
            row: Math.round(row.width),
            image: Math.round(image.width),
            name: Math.round(box.width),
            lines: Math.round(box.height / parseFloat(getComputedStyle(name).lineHeight)),
            under: box.top >= image.bottom,
            nameInRow: box.left >= row.left - 1 && box.right <= row.right + 1,
            pageScrolls: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
        };
    })()
    JS;

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
        ->assertAttribute('.admin-settings-theme[data-value="neutral"]', 'aria-checked', 'true')
        ->assertScript("document.querySelector('#settings-snippet').value.includes('data-theme=\"neutral\"')", true)
        ->assertCount('.admin-settings-logo__image', 0)
        ->click('.admin-settings-theme[data-value="violet"]')
        ->assertAttribute('.admin-settings-theme[data-value="violet"]', 'aria-checked', 'true')
        ->attach('#settings-logo', logoFile())
        ->click('.admin-settings-logo-upload')
        ->waitForText(adminLine(LogoController::UPLOADED))
        ->click('.admin-toast-dismiss')
        ->assertCount('.admin-toast', 0)
        ->assertCount('.admin-settings-logo__image', 1)
        ->assertAttribute('.admin-settings-theme[data-value="violet"]', 'aria-checked', 'true')
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

it('jumps to a section from its link in the row above the form, landing the card under the row with the address left alone and the keyboard\'s focus on its heading, unringed after a pointer, marks the section the scroll brings to the line, and marks the last section pressed where the page ends before it', function () {
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->resize(1440, 900)
        ->navigate(Admin::url('luna', '/settings'))
        ->waitForText(adminLine('admin/settings.booking_page.snippet'))
        ->assertAttribute('.admin-settings-tabs', 'aria-label', adminLine('admin/settings.sections.label'));

    $order = $page->script(SECTION_LINKS_AND_HEADINGS);
    assert(is_string($order));
    expect(json_decode($order, true))->toBe([
        ['section-general', 'section-booking-page', 'section-booking-rules', 'section-payments', 'section-emails', 'section-danger'],
        ['section-general', 'section-booking-page', 'section-booking-rules', 'section-payments', 'section-emails', 'section-danger'],
    ]);

    $page->click('.admin-settings-tabs a[href="#section-booking-rules"]');
    $landed = $page->script(sprintf(SECTION_ROW, 'section-booking-rules'));
    assert(is_array($landed));
    expect($landed)->toMatchArray(['hash' => '', 'current' => '#section-booking-rules', 'rowTop' => 12, 'linkInRow' => true, 'pageScrolls' => false])
        ->and($landed['cardGap'] ?? null)->toBeGreaterThanOrEqual(8)
        ->and($landed['cardTop'] ?? null)->toBeLessThanOrEqual(140)
        ->and([$landed['focused'] ?? null, $landed['ring'] ?? null])->toBe([true, 'none']);

    // The line is 140 pixels from the top: a card one pixel under it is not the current one yet.
    $page->script(sprintf(SCROLL_CARD_TO, 'section-payments', 141));
    expect($page->script(sprintf(SECTION_ROW, 'section-payments')))->toMatchArray(['current' => '#section-booking-rules', 'cardTop' => 141, 'rowTop' => 12]);
    $page->script(sprintf(SCROLL_CARD_TO, 'section-payments', 140));
    expect($page->script(sprintf(SECTION_ROW, 'section-payments')))->toMatchArray(['current' => '#section-payments', 'cardTop' => 140, 'rowTop' => 12]);

    // The page ends before the Danger zone's card can reach the line, and its pressed link is
    // the current one all the same.
    $page->click('.admin-settings-tabs a[href="#section-danger"]');
    $end = $page->script(sprintf(SECTION_ROW, 'section-danger'));
    assert(is_array($end));
    expect($end)->toMatchArray(['hash' => '', 'current' => '#section-danger', 'rowTop' => 12, 'pageScrolls' => false, 'focused' => true])
        ->and($end['cardTop'] ?? null)->toBeGreaterThan(140);

    $page->assertDontSee(adminLine(SettingsController::SAVED))->assertNoJavaScriptErrors();
});

it('keeps the row of section links scrolling inside itself on a phone in Greek, the page never sideways, scrolls the row to the link of a section the page\'s own scroll brings to the line, and lands a pressed section\'s card under the row', function () {
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => SiteLanguage::Greek->value]);

    $page = Admin::signInOnPhone($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME);
    $page->navigate(Admin::url('luna', '/settings'))
        ->waitForText(adminLine('admin/settings.sections.booking_rules', [], SiteLanguage::Greek))
        ->assertAttribute('.admin-settings-tabs', 'aria-label', adminLine('admin/settings.sections.label', [], SiteLanguage::Greek));

    expect($page->script(sprintf(SECTION_ROW, 'section-general')))->toMatchArray(['current' => '#section-general', 'linkInRow' => true, 'rowScrolls' => true, 'pageScrolls' => false]);
    // The Emails link starts past the row's edge, and nothing is pressed: the page's own scroll
    // brings the section to the line, and the row scrolls itself to the link it marks.
    expect($page->script(sprintf(SECTION_ROW, 'section-emails')))->toMatchArray(['current' => '#section-general', 'linkInRow' => false]);
    $page->script(sprintf(SCROLL_CARD_TO, 'section-emails', 100));
    expect($page->script(sprintf(SECTION_ROW, 'section-emails')))->toMatchArray(['hash' => '', 'current' => '#section-emails', 'cardTop' => 100, 'rowTop' => 12, 'linkInRow' => true, 'rowScrolls' => true, 'pageScrolls' => false]);

    $page->script('window.scrollTo(0, 0)');
    expect($page->script(sprintf(SECTION_ROW, 'section-emails')))->toMatchArray(['current' => '#section-general', 'linkInRow' => false]);
    $page->click('.admin-settings-tabs a[href="#section-emails"]');
    $landed = $page->script(sprintf(SECTION_ROW, 'section-emails'));
    assert(is_array($landed));
    expect($landed)->toMatchArray(['hash' => '', 'current' => '#section-emails', 'rowTop' => 12, 'linkInRow' => true, 'rowScrolls' => true, 'pageScrolls' => false, 'focused' => true])
        ->and($landed['cardGap'] ?? null)->toBeGreaterThanOrEqual(8)
        ->and($landed['cardTop'] ?? null)->toBeLessThanOrEqual(140);

    $page->assertNoJavaScriptErrors();
});

it('sends the keyboard to the heading of a section whose link is pressed with Enter, the heading ringed as a control is and landed where a pointer lands it, and goes on inside that section at the next Tab, the heading no stop of the key again', function () {
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->resize(1440, 900)
        ->navigate(Admin::url('luna', '/settings'))
        ->waitForText(adminLine('admin/settings.booking_page.snippet'))
        ->keys('.admin-settings-tabs a[href="#section-booking-rules"]', 'Enter');

    $landed = $page->script(sprintf(SECTION_ROW, 'section-booking-rules'));
    assert(is_array($landed));
    expect($landed)->toMatchArray(['hash' => '', 'current' => '#section-booking-rules', 'focused' => true, 'ring' => 'solid', 'rowTop' => 12, 'pageScrolls' => false])
        ->and($landed['cardGap'] ?? null)->toBeGreaterThanOrEqual(8)
        ->and($landed['cardTop'] ?? null)->toBeLessThanOrEqual(140);

    $page->keys('#section-booking-rules', 'Tab')
        ->assertScript(FOCUS_SECTION, 'section-booking-rules')
        ->assertScript("document.getElementById('section-booking-rules').hasAttribute('tabindex')", false)
        ->assertNoJavaScriptErrors();
});

it('keeps a field the keyboard reaches going back up the form clear of the row of section links, its label in sight under the row', function () {
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->resize(1440, 900)
        ->navigate(Admin::url('luna', '/settings'))
        ->waitForText(adminLine('admin/settings.booking_page.snippet'));

    // Address line 2 stands under the row and the focus on City, the field after it: Shift+Tab
    // goes back to a field the row would cover.
    $page->script(sprintf(FIELD_UNDER_THE_ROW, 'settings-address-line2', 'settings-city'));
    $page->keys('#settings-city', 'Shift+Tab');
    $stood = $page->script(FOCUSED_FIELD_AND_ROW);
    assert(is_array($stood));

    // A field's focus outline reaches 4 pixels past it.
    expect($stood['field'] ?? null)->toBe('settings-address-line2')
        ->and($stood['fieldGap'] ?? null)->toBeGreaterThanOrEqual(4)
        ->and($stood['labelGap'] ?? null)->toBeInt()->toBeGreaterThanOrEqual(0);

    $page->assertNoJavaScriptErrors();
});

it('leaves a section link the keyboard reaches at either edge of the row on a phone in Greek room for its focus outline', function () {
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => SiteLanguage::Greek->value]);

    $page = Admin::signInOnPhone($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME);
    $page->navigate(Admin::url('luna', '/settings'))
        ->waitForText(adminLine('admin/settings.sections.booking_rules', [], SiteLanguage::Greek));

    // The outline is 3 pixels wide and stands 2 pixels off the link. Tab reaches Payments from
    // Booking rules, Shift+Tab from Emails.
    $page->assertScript(sprintf(LINK_HALF_OUT_OF_THE_ROW, 'section-payments', 'true', 'section-booking-rules'), true)
        ->keys('.admin-settings-tabs a[href="#section-booking-rules"]', 'Tab');
    $forward = $page->script(FOCUSED_LINK_IN_THE_ROW);
    assert(is_array($forward));
    expect($forward['link'] ?? null)->toBe('#section-payments')
        ->and($forward['far'] ?? null)->toBeGreaterThanOrEqual(5);

    $page->assertScript(sprintf(LINK_HALF_OUT_OF_THE_ROW, 'section-payments', 'false', 'section-emails'), true)
        ->keys('.admin-settings-tabs a[href="#section-emails"]', 'Shift+Tab');
    $back = $page->script(FOCUSED_LINK_IN_THE_ROW);
    assert(is_array($back));
    expect($back['link'] ?? null)->toBe('#section-payments')
        ->and($back['near'] ?? null)->toBeGreaterThanOrEqual(5);

    $page->assertNoJavaScriptErrors();
});

it('answers a touch 44 pixels tall on every section link and every theme pill on a phone, each drawn smaller, no area reaching into its neighbour\'s and the row clipping none', function () {
    $page = Admin::signInOnPhone($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME);
    $page->navigate(Admin::url('luna', '/settings'))
        ->waitForText(adminLine('admin/settings.booking_page.snippet'));

    expect($page->script(TOUCH_AREAS))->toBe([
        'links' => ['count' => 6, 'drawn' => [38], 'area' => [44], 'tall' => true, 'apart' => true],
        'pills' => ['count' => 7, 'drawn' => [40], 'area' => [44], 'tall' => true, 'apart' => true],
    ]);

    $page->assertNoJavaScriptErrors();
});

it('shows a stored cover on a phone as wide as its row with its file\'s name whole on one line under it, inside the card, the page never sideways', function () {
    $page = Admin::signInOnPhone($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME);
    $page->navigate(Admin::url('luna', '/settings'))
        ->waitForText(adminLine('admin/settings.booking_page.cover'))
        ->assertCount('.admin-settings-cover__image', 0)
        ->attach('#settings-cover', drawnPng(800, 300, 14, 116, 144))
        ->click('.admin-settings-cover-upload')
        ->waitForText(adminLine(CoverController::UPLOADED))
        ->click('.admin-toast-dismiss')
        ->assertCount('.admin-toast', 0)
        ->assertCount('.admin-settings-cover__image', 1);

    $row = $page->script(STORED_COVER_ROW);
    assert(is_array($row));
    Look::shot($page, 'settings-cover-390');

    expect($row)->toMatchArray(['lines' => 1, 'under' => true, 'nameInRow' => true, 'pageScrolls' => false])
        ->and($row['image'] ?? null)->toBe($row['row'] ?? null);

    $page->assertNoJavaScriptErrors();
});
