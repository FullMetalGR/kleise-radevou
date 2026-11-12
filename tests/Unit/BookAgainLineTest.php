<?php

use App\Notifications\BookAgainLine;
use App\Site\SiteLanguage;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Tests\Support\LangLines;
use Tests\Support\SnapshotFixture;

/*
 * Book again on the server: the hosted page with the booking's first item preselected, the staff
 * left out where the item runs on the implicit member. SnapshotFixture numbers the items'
 * services from 7 and their staff from 3 in chain order, so a chain's second item is service 8 on
 * staff 4.
 *
 * The page is under the prefix of the booking's language, /en or /el, as the manage and payment
 * links of the same messages are, and the line is customer-mail.php's book_again in the message's
 * language, read through a translator over the lang directory.
 */

beforeEach(function () {
    LangLines::bind();
});

afterEach(function () {
    LangLines::release();
});

function bagUrls(): TenantUrls
{
    return new TenantUrls('https://kleiseradevou.gr');
}

it('preselects the first item\'s service and its staff member on the tenant\'s hosted page, under the prefix of the booking\'s language', function () {
    expect(BookAgainLine::for(bagUrls(), Slug::of('luna'), SnapshotFixture::booking()))
        ->toBe('https://luna.kleiseradevou.gr/en?service=7&staff=3')
        ->and(BookAgainLine::for(bagUrls(), Slug::of('luna'), SnapshotFixture::booking(language: 'el')))
        ->toBe('https://luna.kleiseradevou.gr/el?service=7&staff=3');
});

it('leaves the staff out where the first item runs on the implicit member', function () {
    $implicit = SnapshotFixture::booking(items: [['Haircut', 'Luna Hair Studio', true, 10]]);

    expect(BookAgainLine::for(bagUrls(), Slug::of('luna'), $implicit))->toBe('https://luna.kleiseradevou.gr/en?service=7');
});

it('reads the first item of a chain and no other', function () {
    $chain = SnapshotFixture::booking(items: [['Colour', 'Elena Papadaki', false, 9], ['Haircut', 'Nikos Georgiou', false, 11]]);
    $second = SnapshotFixture::booking(items: [['Colour', 'Luna Hair Studio', true, 9], ['Haircut', 'Nikos Georgiou', false, 11]]);

    expect(BookAgainLine::for(bagUrls(), Slug::of('luna'), $chain))->toBe('https://luna.kleiseradevou.gr/en?service=7&staff=3')
        // The second item's named member does not stand in for the first's implicit one.
        ->and(BookAgainLine::for(bagUrls(), Slug::of('luna'), $second))->toBe('https://luna.kleiseradevou.gr/en?service=7');
});

it('is the link TenantUrls spells for the hosted page with that query under the booking\'s language, so the page reads the one it hands out', function () {
    expect(BookAgainLine::for(bagUrls(), Slug::of('pier'), SnapshotFixture::booking()))
        ->toBe(bagUrls()->bookingLink(Slug::of('pier'), ['service' => 7, 'staff' => 3], SiteLanguage::English))
        ->and(BookAgainLine::for(bagUrls(), Slug::of('pier'), SnapshotFixture::booking(language: 'el')))
        ->toBe(bagUrls()->bookingLink(Slug::of('pier'), ['service' => 7, 'staff' => 3], SiteLanguage::Greek));
});

it('spells the line once, its constant holding its key, after a blank line', function () {
    expect(BookAgainLine::LINE)->toBe('customer-mail.book_again')
        ->and(BookAgainLine::line('https://luna.kleiseradevou.gr/en?service=7', SiteLanguage::English))
        ->toBe("\n\n".LangLines::line(BookAgainLine::LINE, ['link' => 'https://luna.kleiseradevou.gr/en?service=7']));
});
