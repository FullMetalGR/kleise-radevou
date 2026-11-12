<?php

use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\SettingsController;

/*
 * Two figures the admin's server fills into a sentence. The closing window's days are spelled
 * in the gender of the noun they count, feminine in Greek, by ICU's own rule set, and English
 * keeps its default spelling; the window is thirty days today, which no gender bends, so the
 * cases run at figures that bend. The erasure's refusal fills its count of live bookings in the
 * language's number format while the integer chooses the form. Every expected figure is
 * computed by the formatter, never typed.
 */

it('spells the closing window\'s days feminine in Greek where the figure bends', function (int $days): void {
    app()->setLocale('el');
    $feminine = new NumberFormatter('el', NumberFormatter::SPELLOUT);
    $feminine->setTextAttribute(NumberFormatter::DEFAULT_RULESET, '%spellout-cardinal-feminine');
    $default = (new NumberFormatter('el', NumberFormatter::SPELLOUT))->format($days);

    expect(SettingsController::windowSpelled($days))->toBe($feminine->format($days))
        ->and($feminine->format($days))->not->toBe($default);
})->with([3, 4, 13, 21]);

it('spells the closing window\'s days in English by the default rule', function (int $days): void {
    app()->setLocale('en');

    expect(SettingsController::windowSpelled($days))->toBe((new NumberFormatter('en', NumberFormatter::SPELLOUT))->format($days));
})->with([3, 21, 30]);

it('fills the live bookings\' count in the language\'s number format', function (string $locale): void {
    app()->setLocale($locale);
    $formatted = (new NumberFormatter($locale, NumberFormatter::DECIMAL))->format(1234);

    expect(CustomerController::liveSentence(1234))->toBe(trans_choice(CustomerController::LIVE, 1234, ['count' => $formatted]))
        ->and(CustomerController::liveSentence(1234))->toContain($formatted)
        ->and(CustomerController::liveSentence(1234))->not->toContain('1234');
})->with(['en', 'el']);
