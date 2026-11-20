<?php

use App\Application\Notification\PlatformMessenger;
use App\Tenancy\Theme;
use Database\Seeders\DemoTenantsSeeder;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\ContrastScripts;
use Tests\Browser\Support\Help;
use Tests\Browser\Support\Look;
use Tests\Support\RecordingPlatformMessages;

/*
 * The admin's colours as a browser resolves them. Every surface, tint, ink and line is mixed from
 * the theme's primary in the stylesheet, so whether a text colour still reads on its ground is a
 * question for each of the seven themes in each mode: the walk signs in, dresses Home in every
 * theme and mode in turn and measures each pair against its floor. One mark stands on two grounds:
 * the dot on the help panel's Tickets tab, on the row's tint while another tab is chosen and on
 * the ink the tab is filled with once it is. It is measured where it is drawn, in each state.
 */

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

it('holds every text, line and mark of the admin above its contrast floor in all seven themes, light and dark', function () {
    expect(Look::ALL_THEMES)->toBe(Theme::words());

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME);

    expect($page->script(ContrastScripts::failures()))->toBe([]);

    $page->assertNoJavaScriptErrors();
});

it('reports a token that holds no colour, which would otherwise be measured as the ink its probe inherits', function () {
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME);

    $reported = array_merge(...array_map(
        fn (string $theme): array => [$theme.' light: --radius resolves to no colour', $theme.' dark: --radius resolves to no colour'],
        Look::ALL_THEMES,
    ));

    expect($page->script(ContrastScripts::failures([['--radius', '--background', 4.5]])))->toBe($reported);
});

it('paints the page\'s own ground from the theme, so Blue\'s differs from Neutral\'s in either mode', function () {
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME);

    foreach ([false, true] as $dark) {
        $page->assertScript(Look::wear('neutral', $dark), 'neutral');
        $neutral = $page->script(ContrastScripts::BODY_BACKGROUND);
        $page->assertScript(Look::wear('blue', $dark), 'blue');

        expect($page->script(ContrastScripts::BODY_BACKGROUND))->toBeString()->not->toBe($neutral);
    }
});

it('grounds the dark page in the theme\'s own hue, a navy under Blue and a grey with no colour under Neutral', function () {
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME);

    $page->assertScript(Look::wear('blue', true), 'blue');
    [$red, , $blue] = ContrastScripts::bodyGround($page);

    expect($blue)->toBeGreaterThan($red)->toBeGreaterThanOrEqual(2 * $red);

    $page->assertScript(Look::wear('neutral', true), 'neutral');
    [$red, $green, $blue] = ContrastScripts::bodyGround($page);

    expect([$green, $blue])->toBe([$red, $red]);
});

it('shows the unread dot on the help panel\'s Tickets tab against the tab, chosen or not, in all seven themes, light and dark', function () {
    app()->instance(PlatformMessenger::class, new RecordingPlatformMessages);
    Help::answer(Help::ticket(Admin::owner('luna'), 'Hours for the summer', 'Where are they set?'), addOperator(), 'Under Settings.');

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME);

    $page->click(Help::BUBBLE)
        ->assertVisible(Help::SEARCH)
        ->assertAttribute(Help::TICKETS_TAB, 'aria-selected', 'false')
        ->assertVisible(Help::TAB_DOT);

    expect($page->script(ContrastScripts::markFailures(Help::TAB_DOT)))->toBe([]);

    // The reply stays unread while the list shows, so the dot stays on the tab once it is the
    // chosen one, filled in the ink: light in dark mode, where the dot's own red is light too.
    $page->click(Help::TICKETS_TAB)
        ->assertSeeIn(Help::ROW, 'Hours for the summer')
        ->assertAttribute(Help::TICKETS_TAB, 'aria-selected', 'true')
        ->assertVisible(Help::TAB_DOT);

    expect($page->script(ContrastScripts::markFailures(Help::TAB_DOT)))->toBe([]);

    $page->assertNoJavaScriptErrors();
});
