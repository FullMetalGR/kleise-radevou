<?php

use Database\Seeders\DemoTenantsSeeder;

/*
 * Two provisioned tenants opened at their subdomains in a real browser, showing two brands from
 * one application. The demo seeder provisions them, so the seeder is under test here too. The
 * theme is checked as the browser computed it, on the mount element's primary and on the Book
 * now pill's background on the white header, because an attribute that is written but not
 * applied would pass a source check and fail the tenant. The business page's layout is read the
 * same way, as the browser laid it out: the white header stuck to the top on a wide screen, the
 * title block, the flow at the column's full width, the sections after it and the footer, and on
 * a phone one column with the header scrolling away and the fixed Book now bar.
 */

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

it('shows each demo tenant its own name and theme at its own subdomain, luna violet and pier blue', function () {
    $this->visit(hostedPage('luna'))
        ->assertTitle('Luna Hair Studio')
        ->assertSeeIn('.hosted-header', 'Luna Hair Studio')
        ->assertDontSee('Pier Physiotherapy')
        ->assertScript("document.body.getAttribute('data-theme')", 'violet')
        ->assertAttribute('.kr-booking', 'data-theme', 'violet')
        ->assertScript("getComputedStyle(document.querySelector('.kr-booking')).getPropertyValue('--kr-primary').trim()", '#7c3aed')
        ->assertScript("getComputedStyle(document.querySelector('header')).backgroundColor", 'rgb(255, 255, 255)')
        ->assertScript("getComputedStyle(document.querySelector('.hosted-book')).backgroundColor", 'rgb(124, 58, 237)');

    $this->visit(hostedPage('pier'))
        ->assertTitle('Pier Physiotherapy')
        ->assertSeeIn('.hosted-header', 'Pier Physiotherapy')
        ->assertDontSee('Luna Hair Studio')
        ->assertScript("document.body.getAttribute('data-theme')", 'blue')
        ->assertAttribute('.kr-booking', 'data-theme', 'blue')
        ->assertScript("getComputedStyle(document.querySelector('.kr-booking')).getPropertyValue('--kr-primary').trim()", '#2563eb')
        ->assertScript("getComputedStyle(document.querySelector('header')).backgroundColor", 'rgb(255, 255, 255)')
        ->assertScript("getComputedStyle(document.querySelector('.hosted-book')).backgroundColor", 'rgb(37, 99, 235)');
});

it('lays the business page out in one 1120px column, its 40px sides inside, on a wide screen and in one column on a phone, the Book now bar fixed there', function () {
    // Wide: the white header 72px tall and stuck to the top, the brand disc in the primary, the
    // title block's h1 at 40px, the sections in their order under the flow, the flow taking the
    // column's full width, the footer last.
    $wide = <<<'JS'
        (() => {
            const header = document.querySelector('.hosted-header');
            const main = document.querySelector('.hosted-main');
            const mount = document.querySelector('.kr-booking');
            const top = (selector) => document.querySelector(selector).getBoundingClientRect().top;
            const inner = main.getBoundingClientRect().width - parseFloat(getComputedStyle(main).paddingLeft) - parseFloat(getComputedStyle(main).paddingRight);
            return [
                getComputedStyle(header).position,
                header.getBoundingClientRect().height,
                getComputedStyle(document.querySelector('.hosted-disc')).backgroundColor,
                getComputedStyle(document.querySelector('.hosted-title h1')).fontSize,
                main.getBoundingClientRect().width,
                Math.abs(mount.getBoundingClientRect().width - inner) < 1,
                top('.hosted-title h1') < top('.kr-booking') && top('.kr-booking') < top('.hosted-team') && top('.hosted-team') < top('.hosted-hours') && top('.hosted-hours') < top('.hosted-footer'),
                getComputedStyle(document.querySelector('.hosted-bar')).display,
                getComputedStyle(document.querySelector('#book')).display !== 'none' && document.querySelector('#book').getBoundingClientRect().width <= 1,
            ].join('|');
        })()
        JS;
    // Phone: the header scrolls away, its Book now gives way to the fixed bar at the foot of the
    // screen, the column takes the screen less its 16px gutters, nothing scrolls sideways.
    $phone = <<<'JS'
        (() => {
            const header = document.querySelector('.hosted-header');
            const main = document.querySelector('.hosted-main');
            const bar = document.querySelector('.hosted-bar').getBoundingClientRect();
            return [
                getComputedStyle(header).position,
                getComputedStyle(document.querySelector('.hosted-header .hosted-book')).display,
                getComputedStyle(document.querySelector('.hosted-bar')).position,
                Math.abs(bar.bottom - window.innerHeight) < 1,
                parseFloat(getComputedStyle(main).paddingLeft),
                document.documentElement.scrollWidth <= window.innerWidth,
            ].join('|');
        })()
        JS;

    $this->visit(hostedPage('luna'))
        ->resize(1440, 900)
        ->assertScript($wide, 'sticky|72|rgb(124, 58, 237)|40px|1120|true|true|none|true')
        ->resize(390, 844)
        ->assertScript($phone, 'static|none|fixed|true|16|true')
        ->assertNoJavaScriptErrors();
});

it('answers 404 at an unknown subdomain', function () {
    $this->visit(hostedPage('nobody'))
        ->assertTitle('Not Found')
        ->assertScript("fetch(location.href, {cache: 'no-store'}).then(response => response.status)", 404);
});
