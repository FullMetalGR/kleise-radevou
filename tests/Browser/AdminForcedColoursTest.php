<?php

use Database\Seeders\DemoTenantsSeeder;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\ContrastScripts;
use Tests\Browser\Support\Look;

/*
 * The admin where the person's system forces its own colours, and the native controls in each
 * mode. A forced palette drops every shadow and repaints every fill as the page, so a part whose
 * only edge is a ring needs a real line there, a chosen option whose fill is its only sign needs a
 * colour the system keeps, and a box drawn by hand goes back to the browser. Outside a forced
 * palette none of it shows. A native control is painted for the page's mode, and a locked email
 * box that is ticked goes on reading as ticked in every theme and mode.
 */

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

/** A script answering the width of the top edge of the first element the selector finds. */
function forcedEdge(string $selector): string
{
    return sprintf('getComputedStyle(document.querySelector(%s)).borderTopWidth', json_encode($selector, JSON_THROW_ON_ERROR));
}

/**
 * A script answering how the chosen option of the range on Home is filled: whether it keeps its
 * own colours, and whether its fill and its words are the system's highlight pair, read off a
 * probe the system leaves alone.
 */
function forcedChosen(): string
{
    return <<<'JS'
        (() => {
            const probe = document.createElement('div');
            probe.style.backgroundColor = 'Highlight';
            probe.style.color = 'HighlightText';
            probe.style.forcedColorAdjust = 'none';
            document.body.appendChild(probe);
            const system = getComputedStyle(probe);
            const pair = [system.backgroundColor, system.color];
            probe.remove();
            const chosen = getComputedStyle(document.querySelector('.admin-home-range [aria-pressed="true"]'));
            return [chosen.forcedColorAdjust, chosen.backgroundColor === pair[0], chosen.color === pair[1]];
        })()
        JS;
}

/**
 * A script answering, with the keyboard's focus put on the chosen option of the range on Home,
 * whether it shows that focus and whether its outline is drawn in the system's colour for text,
 * read off a probe the system leaves alone.
 */
function forcedChosenRing(): string
{
    return <<<'JS'
        (() => {
            const probe = document.createElement('div');
            probe.style.color = 'CanvasText';
            probe.style.forcedColorAdjust = 'none';
            document.body.appendChild(probe);
            const text = getComputedStyle(probe).color;
            probe.remove();
            const chosen = document.querySelector('.admin-home-range [aria-pressed="true"]');
            chosen.focus();
            return [chosen.matches(':focus-visible'), getComputedStyle(chosen).outlineColor === text];
        })()
        JS;
}

/** A script answering how the locked email box is drawn, and whether its drawn tick shows. */
function forcedTick(): string
{
    return "(() => { const tick = document.querySelector('#settings-email-approved'); return [getComputedStyle(tick).appearance, getComputedStyle(tick, '::before').display]; })()";
}

it('draws a real line round a field and the main and plain buttons, fills the chosen option with the system highlight, rings it on the keyboard\'s focus in the system\'s colour for text and gives the locked box back to the browser, where the system forces its own colours', function () {
    connectPaymentFake();

    $page = $this->visit(Admin::url('luna', '/login'), ['forcedColors' => 'active']);

    expect($page->script(forcedEdge('.admin-input')))->toBe('1px')
        ->and($page->script(forcedEdge('.admin-login-submit')))->toBe('1px');

    $page->fill('email', Admin::owner('luna'))
        ->fill('password', Admin::DEMO_PASSWORD)
        ->click('.admin-login-submit')
        ->assertVisible(Admin::HOME);

    expect($page->script(forcedChosen()))->toBe(['none', true, true]);

    $page->keys('.admin-home-range [aria-pressed="true"]', 'Shift+Tab');

    expect($page->script(forcedChosenRing()))->toBe([true, true]);

    $page->click(navLabel('settings'))
        ->waitForText(adminLine('admin/settings.booking_page.snippet'))
        ->assertChecked('#settings-email-approved')
        ->assertDisabled('#settings-email-approved');

    expect($page->script(forcedEdge('.admin-button--secondary')))->toBe('1px')
        ->and($page->script(forcedTick()))->toBe(['auto', 'none']);

    $page->assertNoJavaScriptErrors();
});

it('draws none of it in the ordinary palette, paints the native controls for the page\'s mode and keeps the locked, ticked email box seen in every theme and mode', function () {
    connectPaymentFake();

    $page = $this->visit(Admin::url('luna', '/login'));

    expect($page->script(forcedEdge('.admin-input')))->toBe('0px')
        ->and($page->script(forcedEdge('.admin-login-submit')))->toBe('0px');

    $page->fill('email', Admin::owner('luna'))
        ->fill('password', Admin::DEMO_PASSWORD)
        ->click('.admin-login-submit')
        ->assertVisible(Admin::HOME);

    $chosen = $page->script(forcedChosen());

    assert(is_array($chosen));

    expect(array_slice($chosen, 0, 2))->toBe(['auto', false])
        ->and($page->script('getComputedStyle(document.documentElement).colorScheme'))->toBe('light')
        ->and($page->script(Look::wear('neutral', true)))->toBe('neutral')
        ->and($page->script('getComputedStyle(document.documentElement).colorScheme'))->toBe('dark')
        ->and($page->script(Look::wear('neutral', false)))->toBe('neutral');

    $page->click(navLabel('settings'))
        ->waitForText(adminLine('admin/settings.booking_page.snippet'))
        ->assertChecked('#settings-email-approved')
        ->assertDisabled('#settings-email-approved');

    expect($page->script(forcedEdge('.admin-button--secondary')))->toBe('0px')
        ->and($page->script(forcedTick()))->toBe(['none', 'block'])
        ->and($page->script(ContrastScripts::markFailures('#settings-email-approved')))->toBe([]);

    $page->assertNoJavaScriptErrors();
});
