<?php

use App\Http\Controllers\Console\LoginController;
use Illuminate\Support\Facades\Artisan;
use Tests\Browser\Support\Console;

/*
 * The platform console in a real browser: the console's host answers its door, the sign-in form
 * rendered from the admin bundle through the console's own root view and adapter, outside the
 * console's shell; an operator the console command added signs in through it over a session that
 * lives across requests, lands on the home, the tenant list, inside the shell naming them, and
 * signs out again to a toast. The rail's Audit opens the platform audit log, where the sign-in
 * just made is the newest row, its instant on the clock the first row names.
 */

// The rail's Audit entry, reached by its target: the rail carries Tenants before it.
const CONSOLE_SHELL_AUDIT = '.admin-rail a[href="/audit"]';

/**
 * True where the console's name fits the sidebar's head: the head scrolls nothing sideways, the
 * name is whole, the word console ends inside the head, on the name's line or under the name and
 * never under the mark, the word is set in the page's own face at its plain weight and spacing,
 * not the brand's, and the whole of it is the title a pointer reads.
 */
const CONSOLE_SHELL_HEAD_FITS = <<<'JS'
(() => {
    const head = document.querySelector('.admin-sidebar__head');
    const whole = head.querySelector('.admin-console-wordmark');
    const name = head.querySelector('.brand-wordmark__name');
    const mark = head.querySelector('.brand-wordmark__mark').getBoundingClientRect();
    const label = head.querySelector('.admin-console-label').getBoundingClientRect();
    const face = getComputedStyle(head.querySelector('.admin-console-label'));

    return head.scrollWidth <= head.clientWidth
        && name.scrollWidth === name.clientWidth
        && label.width > 0
        && label.left >= mark.right
        && label.right <= head.getBoundingClientRect().right + 0.5
        && face.fontFamily === getComputedStyle(document.body).fontFamily
        && face.fontWeight === '400'
        && face.letterSpacing === 'normal'
        && whole.getAttribute('title') === whole.textContent;
})()
JS;

/**
 * Adds the operator the cases sign in as, through the command, the one way an operator is made.
 */
function consoleShellOperator(): void
{
    expect(Artisan::call('console:add-operator', ['--name' => 'Pat Operator', '--email' => 'pat@inbox.example', '--password' => 'operator password']))->toBe(0);
}

it('reaches the console host\'s door and sees the sign-in form, outside the console\'s shell', function () {
    $this->visit(Console::url('/login'))
        ->waitForText(adminLine('admin/shell.login.submit'))
        ->assertSeeIn('.admin-console-login-name .brand-wordmark__name', siteName())
        ->assertPathIs('/login')
        ->assertSee(adminLine('admin/shell.login.submit'))
        ->assertPresent('input[name="email"]')
        ->assertAttribute('input[name="password"]', 'type', 'password')
        ->assertPresent('.admin-console-login-submit')
        ->assertNotPresent('.admin-shell')
        ->assertTitle('Console sign in - '.siteName())
        ->assertNoJavaScriptErrors();
});

it('signs in an operator the command added, lands on the home inside the shell naming them, and signs out to a toast', function () {
    consoleShellOperator();

    $page = Console::signIn($this, 'pat@inbox.example', 'operator password')
        ->waitForText('No tenants yet')
        ->assertPathIs('/')
        ->assertPresent('.admin-shell')
        ->assertSeeIn('.admin-sidebar .admin-console-wordmark .brand-wordmark__name', siteName())
        ->assertSeeIn('.admin-sidebar .admin-console-label', 'console')
        ->resize(1440, 900)
        ->assertScript(CONSOLE_SHELL_HEAD_FITS, true)
        ->assertSeeIn('.admin-topbar', 'Pat Operator')
        ->assertTitle('Tenants - '.siteName())
        ->assertNoJavaScriptErrors();

    $page->click('.admin-signout')
        ->waitForText(LoginController::SIGNED_OUT)
        ->assertPathIs('/login')
        ->assertSeeIn('.admin-toast', LoginController::SIGNED_OUT)
        ->assertNotPresent('.admin-shell');

    $this->visit(Console::url('/'))->assertPathIs('/login');
});

it('answers a wrong password with the one sentence on the email field and stays on the door', function () {
    consoleShellOperator();

    Console::signIn($this, 'pat@inbox.example', 'not the password')
        ->waitForText(LoginController::REFUSED)
        ->assertPathIs('/login')
        ->assertSeeIn('#login-email-error', LoginController::REFUSED)
        ->assertAttribute('#login-email', 'aria-invalid', 'true')
        ->assertButtonEnabled(adminLine('admin/shell.login.submit'))
        ->assertNoJavaScriptErrors();

    $this->visit(Console::url('/'))->assertPathIs('/login');
});

it('opens the Audit screen from the rail, the sign-in just made its newest row, and finds the sign-out there after signing in again', function () {
    consoleShellOperator();

    $page = Console::signIn($this, 'pat@inbox.example', 'operator password')
        ->waitForText('No tenants yet')
        ->click(CONSOLE_SHELL_AUDIT)
        ->waitForText('1 of 1')
        ->assertPathIs('/audit')
        ->assertTitle('Audit - '.siteName())
        ->assertAttribute(CONSOLE_SHELL_AUDIT, 'aria-current', 'page')
        ->assertSeeIn('.admin-audit__operator', 'Pat Operator')
        ->assertSeeIn('.admin-audit__action', 'Signed in')
        ->assertSeeIn('.admin-topbar .admin-console-zone', 'Times are in Europe/Athens')
        ->assertDontSeeIn('.admin-audit__at', 'Europe/Athens')
        ->assertNoJavaScriptErrors();

    $page->click('.admin-signout')->waitForText(LoginController::SIGNED_OUT);

    Console::signIn($this, 'pat@inbox.example', 'operator password')
        ->waitForText('No tenants yet')
        ->click(CONSOLE_SHELL_AUDIT)
        ->waitForText('3 of 3')
        ->assertSeeIn('.admin-audit__row:nth-child(1) .admin-audit__action', 'Signed in')
        ->assertSeeIn('.admin-audit__row:nth-child(2) .admin-audit__action', 'Signed out')
        ->assertSeeIn('.admin-audit__row:nth-child(3) .admin-audit__action', 'Signed in')
        ->assertNoJavaScriptErrors();
});
