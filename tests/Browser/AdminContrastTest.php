<?php

use App\Tenancy\Theme;
use Database\Seeders\DemoTenantsSeeder;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\ContrastScripts;
use Tests\Browser\Support\Look;

/*
 * The admin's colours as a browser resolves them. Every surface, tint, ink and line is mixed from
 * the theme's primary in the stylesheet, so whether a text colour still reads on its ground is a
 * question for each of the seven themes in each mode: the walk signs in, dresses Home in every
 * theme and mode in turn and measures each pair against its floor.
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
