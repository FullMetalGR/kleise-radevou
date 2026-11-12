<?php

use App\Application\Notification\Audience;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Application\Notification\Recipients;
use App\Notifications\BusinessRecipients;
use App\Notifications\StaffOnBooking;
use App\Notifications\TenantUser;
use App\Site\SiteLanguage;
use App\Tenancy\Role;
use Tests\Support\FixedDirectory;

/*
 * Who at the business hears about a booking, as a rule over rows rather than a query: three
 * limbs unioned and one address mailed once however many of them name it.
 *
 * The limbs are not interchangeable. An owner and a manager run the whole diary and hear about
 * every booking; a staff user hears about the bookings on their own row and no others; and a
 * staff row's own address is where a person who never signs in is told, which is why it is
 * read at all. The implicit row is excluded from that last limb because it stands for the
 * business itself rather than for a person, and the business is already being told through its
 * owner.
 *
 * A recipient that is a user is known by its id as well as by its address, because a decision
 * link is signed per user and an address with no identity behind it has nobody to act as. That
 * is what decides the de-duplication: the earlier limb wins, so an address a user holds is never
 * demoted to one that cannot act.
 *
 * Each of them also carries what they may be shown: manageBookings, read from the role here
 * because this is the layer where a role may be read at all. Owner and manager hold it; a staff
 * user does not, and a staff row's own address is nobody and holds nothing. The third element of
 * every expectation below is that answer.
 *
 * And the language each reads: a user their own, the language they chose, else the tenant
 * default, and a staff row's address the tenant default, each the resolver's answer, which the
 * rule asks with what the user chose and null for the row. The resolver here answers as
 * Languages::ofUser does, the choice else the default given; LanguagesTest holds the resolver
 * itself, and the jobs' proof over real rows is BusinessMailLanguageTest.
 */

/**
 * The rule over the rows given, its resolver answering a user's choice else the default given.
 */
function ruleOver(FixedDirectory $directory, SiteLanguage $default = SiteLanguage::English): BusinessRecipients
{
    return new BusinessRecipients($directory, fn (?SiteLanguage $chosen): SiteLanguage => $chosen ?? $default);
}

/**
 * @param  list<TenantUser>  $users
 * @param  list<StaffOnBooking>  $staff
 * @return list<array{string, int|null, bool}>
 */
function resolved(array $users, array $staff): array
{
    $recipients = ruleOver(new FixedDirectory($users, $staff))->forBooking('e1f4a9d0-0000-4000-8000-000000000001');

    return array_map(fn (Recipient $to): array => [$to->address, $to->userId, $to->managesBookings], $recipients->all);
}

it('names every owner and every manager, whoever the booking is on', function () {
    expect(resolved([
        new TenantUser(1, 'Owner', 'owner@example.test', Role::Owner),
        new TenantUser(2, 'Manager', 'manager@example.test', Role::Manager),
        new TenantUser(3, 'Stylist', 'stylist@example.test', Role::Staff),
    ], []))->toBe([
        ['owner@example.test', 1, true],
        ['manager@example.test', 2, true],
    ]);
});

it('names a staff user when the booking has an item on their own row, and not otherwise', function () {
    $users = [
        new TenantUser(1, 'Owner', 'owner@example.test', Role::Owner),
        new TenantUser(3, 'Elena', 'elena@example.test', Role::Staff),
        new TenantUser(4, 'Nikos', 'nikos@example.test', Role::Staff),
    ];

    expect(resolved($users, [new StaffOnBooking(11, null, 3, false)]))->toBe([
        ['owner@example.test', 1, true],
        // A staff role has no manageBookings, so the notice she is sent carries the
        // booking without the customer's address or phone.
        ['elena@example.test', 3, false],
    ]);
});

it('names the staff row own address where one is set, for a person who never signs in', function () {
    expect(resolved(
        [new TenantUser(1, 'Owner', 'owner@example.test', Role::Owner)],
        [new StaffOnBooking(12, 'chair@example.test', null, false)],
    ))->toBe([
        ['owner@example.test', 1, true],
        // A mailbox with nobody behind it holds no permission at all.
        ['chair@example.test', null, false],
    ]);
});

it('never mails the implicit row by its own address, because that row is the business and the business is the owner', function () {
    expect(resolved(
        [new TenantUser(1, 'Owner', 'owner@example.test', Role::Owner)],
        [new StaffOnBooking(13, 'business@example.test', null, true)],
    ))->toBe([
        ['owner@example.test', 1, true],
    ]);
});

it('passes over a staff row that has neither an address nor a user, because there is nowhere to write to', function () {
    expect(resolved(
        [new TenantUser(1, 'Owner', 'owner@example.test', Role::Owner)],
        [new StaffOnBooking(14, null, null, false)],
    ))->toBe([
        ['owner@example.test', 1, true],
    ]);
});

it('mails an address once however many limbs name it, and keeps the identity that can act', function () {
    expect(resolved(
        [
            new TenantUser(1, 'Owner', 'owner@example.test', Role::Owner),
            new TenantUser(3, 'Elena', 'elena@example.test', Role::Staff),
        ],
        [new StaffOnBooking(11, 'ELENA@example.test', 3, false)],
    ))->toBe([
        ['owner@example.test', 1, true],
        ['elena@example.test', 3, false],
    ]);
});

it('counts one address once when two users hold it, keeping the first', function () {
    expect(resolved([
        new TenantUser(1, 'Shared', 'shared@example.test', Role::Owner),
        new TenantUser(2, 'Shared', 'Shared@example.test', Role::Manager),
    ], []))->toBe([
        ['shared@example.test', 1, true],
    ]);
});

it('leaves a linked user out of the staff limb when their role is not staff, since the first limb already has them', function () {
    expect(resolved(
        [new TenantUser(1, 'Owner', 'owner@example.test', Role::Owner)],
        [new StaffOnBooking(11, null, 1, false)],
    ))->toBe([
        ['owner@example.test', 1, true],
    ]);
});

it('answers nobody where a tenant has no user and the booking is on the implicit row alone', function () {
    expect(resolved([], [new StaffOnBooking(13, 'business@example.test', null, true)]))->toBe([]);
});

it('refuses an address that is not one, because a recipient with no address is a message with nowhere to go', function () {
    expect(fn () => new Recipient('  '))->toThrow(InvalidArgumentException::class, 'address');
});

it('is empty when nothing named anybody, and countable either way', function () {
    expect(Recipients::of())->toHaveCount(0)
        ->and(Recipients::of(new Recipient('one@example.test'), new Recipient('one@example.test'))->all)->toHaveCount(1);
});

it('names the owners alone under the one key the catalog says goes to them, whoever else runs the diary and whoever the booking is on, and the booking\'s party under every other business key', function () {
    $directory = new FixedDirectory([
        new TenantUser(1, 'Owner', 'owner@example.test', Role::Owner),
        new TenantUser(2, 'Manager', 'manager@example.test', Role::Manager),
        new TenantUser(3, 'Stylist', 'stylist@example.test', Role::Staff),
        new TenantUser(4, 'Partner', 'partner@example.test', Role::Owner),
    ], [new StaffOnBooking(12, 'chair@example.test', 3, false)]);
    $uuid = 'e1f4a9d0-0000-4000-8000-000000000001';
    $rule = ruleOver($directory);
    $named = fn (Recipients $recipients): array => array_map(fn (Recipient $to): array => [$to->address, $to->userId, $to->managesBookings], $recipients->all);
    $party = array_values(array_filter(MessageKey::cases(), fn (MessageKey $key): bool => $key->audience() === Audience::Business && ! $key->ownersAlone()));

    expect($named($rule->under(MessageKey::LatePayment, $uuid)))->toBe([
        ['owner@example.test', 1, true],
        ['partner@example.test', 4, true],
    ])->and($named(ruleOver(new FixedDirectory([], [new StaffOnBooking(13, 'business@example.test', null, true)]))->under(MessageKey::LatePayment, $uuid)))->toBe([])
        ->and(count($party))->toBe(6)
        ->and(array_map(fn (MessageKey $key): array => $named($rule->under($key, $uuid)), $party))->toBe(array_fill(0, count($party), $named($rule->forBooking($uuid))));
});

it('gives each recipient the language they read: a user their own, a user who chose none and a staff row\'s address the tenant default', function (SiteLanguage $default) {
    $directory = new FixedDirectory([
        new TenantUser(1, 'Owner', 'owner@example.test', Role::Owner, SiteLanguage::Greek),
        new TenantUser(2, 'Manager', 'manager@example.test', Role::Manager, SiteLanguage::English),
        new TenantUser(3, 'Elena', 'elena@example.test', Role::Staff),
    ], [new StaffOnBooking(11, null, 3, false), new StaffOnBooking(12, 'chair@example.test', null, false)]);
    $read = fn (Recipients $recipients): array => array_map(fn (Recipient $to): array => [$to->address, $to->language], $recipients->all);

    expect($read(ruleOver($directory, $default)->forBooking('e1f4a9d0-0000-4000-8000-000000000001')))->toBe([
        ['owner@example.test', 'el'],
        ['manager@example.test', 'en'],
        ['elena@example.test', $default->value],
        ['chair@example.test', $default->value],
    ]);
})->with([
    'a Greek default' => [SiteLanguage::Greek],
    'an English default' => [SiteLanguage::English],
]);

it('gives the owners alone their own languages under the late payment\'s key, two owners two languages', function () {
    $directory = new FixedDirectory([
        new TenantUser(1, 'Owner', 'owner@example.test', Role::Owner, SiteLanguage::Greek),
        new TenantUser(4, 'Partner', 'partner@example.test', Role::Owner, SiteLanguage::English),
    ]);

    expect(array_map(fn (Recipient $to): array => [$to->address, $to->language], ruleOver($directory, SiteLanguage::Greek)->under(MessageKey::LatePayment, 'e1f4a9d0-0000-4000-8000-000000000001')->all))->toBe([
        ['owner@example.test', 'el'],
        ['partner@example.test', 'en'],
    ]);
});
