<?php

use App\Application\Notification\Audience;
use App\Application\Notification\MessageKey;
use Tests\Support\LangLines;

/*
 * The catalog: the one list of the messages this product sends, and the only list the admin
 * reads to draw the Emails section of Settings.
 *
 * Two properties carry the weight. Every key is a distinct string, because emails_off is a flat
 * text[] and a key shared by two messages would be one checkbox silencing two audiences at once.
 * And every key knows its audience, because the customer and the business hear about the same
 * transition in different words and a tenant switching one off must not switch off the other.
 *
 * The labels are the words of lang/{en,el}/admin/emails.php: each case answers its key there,
 * derived from its own value, and the file holds a label for every case and for nothing else.
 */

beforeEach(function () {
    $this->lines = LangLines::bind();
});

afterEach(function () {
    LangLines::release();
});

it('is the seventeen keys, the fourteen booking messages, the late payment, the bookings link and the thank-you, and nothing else, because nothing may send a key the catalog does not carry', function () {
    expect(array_map(fn (MessageKey $key): string => $key->value, MessageKey::cases()))->toBe([
        'acknowledged',
        'confirmed',
        'approved',
        'rejected',
        'cancelled',
        'moved',
        'reminder',
        'request_expired',
        'new_booking',
        'approval_request',
        'approval_nag',
        'auto_approved',
        'cancellation_notice',
        'move_notice',
        'late_payment',
        'my_bookings_link',
        'thank_you',
    ]);
});

it('spells every key distinctly, because one switch must never silence two audiences', function () {
    $keys = array_map(fn (MessageKey $key): string => $key->value, MessageKey::cases());

    expect(array_unique($keys))->toHaveCount(count($keys));
});

it('gives every key an audience and a label key the settings screen reads beside its checkbox, a line of the English file itself and never its fallback', function (MessageKey $key) {
    // Asking is the assertion: a key the audience match does not cover throws here rather
    // than reaching the screen as a message belonging to nobody.
    expect($key->audience()->value)->toBeIn([Audience::Customer->value, Audience::Business->value])
        ->and($key->labelKey())->toBe(MessageKey::LABELS.'.'.$key->value)
        ->and($this->lines->has($key->labelKey(), 'en', false))->toBeTrue()
        ->and(LangLines::line($key->labelKey()))->toBe(trim(LangLines::line($key->labelKey())));
})->with(fn () => MessageKey::cases());

it('holds the labels of lang/en/admin/emails.php to exactly the seventeen cases, one each, in any order, read in the walk', function () {
    $labels = $this->lines->get(MessageKey::LABELS, [], 'en', false);
    assert(is_array($labels));
    $walked = array_map(fn (MessageKey $key): string => $key->value, MessageKey::cases());

    // Without order: the file may list its labels as it likes, but every case has one and every
    // label is a case's, each direction held by the canonical comparison of the two lists.
    expect($walked)->toHaveCount(17)
        ->and(array_keys($labels))->toEqualCanonicalizing($walked)
        ->and($walked)->toEqualCanonicalizing(array_keys($labels));
});

it('labels every key distinctly, so two checkboxes never read the same', function () {
    $labels = array_map(fn (MessageKey $key): string => LangLines::line($key->labelKey()), MessageKey::cases());

    expect($labels)->toHaveCount(17)
        ->and(array_unique($labels))->toHaveCount(count($labels));
});

it('sends ten of them to the customer and seven to the business', function () {
    $byAudience = [Audience::Customer->value => [], Audience::Business->value => []];

    foreach (MessageKey::cases() as $key) {
        $byAudience[$key->audience()->value][] = $key->value;
    }

    expect($byAudience[Audience::Customer->value])->toBe([
        'acknowledged', 'confirmed', 'approved', 'rejected', 'cancelled', 'moved', 'reminder', 'request_expired', 'my_bookings_link', 'thank_you',
    ])->and($byAudience[Audience::Business->value])->toBe([
        'new_booking', 'approval_request', 'approval_nag', 'auto_approved', 'cancellation_notice', 'move_notice', 'late_payment',
    ]);
});

it('keeps every key and its label key in the ASCII the repository is written in', function (MessageKey $key) {
    expect(preg_match('/\A[\x20-\x7e]+\z/', $key->labelKey()))->toBe(1)
        ->and(preg_match('/\A[a-z_]+\z/', $key->value))->toBe(1);
})->with(fn () => MessageKey::cases());

it('gives the bookings link to the customer, its label key in emails.php, switchable like every key and sent by no transition', function () {
    expect(MessageKey::MyBookingsLink->value)->toBe('my_bookings_link')
        ->and(MessageKey::MyBookingsLink->audience())->toBe(Audience::Customer)
        ->and(MessageKey::MyBookingsLink->labelKey())->toBe('admin/emails.labels.my_bookings_link');
});

it('gives the thank-you to the customer, its label key in emails.php, switchable like every key', function () {
    expect(MessageKey::ThankYou->value)->toBe('thank_you')
        ->and(MessageKey::ThankYou->audience())->toBe(Audience::Customer)
        ->and(MessageKey::ThankYou->labelKey())->toBe('admin/emails.labels.thank_you');
});

it('sends one business key to the owners alone, the late payment, and every other to the business recipients', function () {
    expect(array_values(array_filter(MessageKey::cases(), fn (MessageKey $key): bool => $key->ownersAlone())))->toBe([MessageKey::LatePayment])
        ->and(MessageKey::LatePayment->audience())->toBe(Audience::Business);
});
