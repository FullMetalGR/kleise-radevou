<?php

use App\Domain\Money\Currency;
use App\Domain\Money\InvalidCurrency;

it('holds exactly three uppercase ASCII letters as given', function () {
    expect(Currency::of('EUR')->code)->toBe('EUR');
});

it('refuses {input} with a typed error carrying the input', function (string $input) {
    try {
        Currency::of($input);
    } catch (InvalidCurrency $e) {
        expect($e->input)->toBe($input);

        return;
    }

    $this->fail('No InvalidCurrency was thrown.');
})->with([
    'empty input' => [''],
    'two letters' => ['EU'],
    'four letters' => ['EURO'],
    'lower case' => ['eur'],
    'mixed case' => ['Eur'],
    'a digit' => ['EU1'],
    'a space inside' => ['E U'],
    'a leading space' => [' EUR'],
    'a trailing space' => ['EUR '],
    'a trailing newline' => ["EUR\n"],
    'a Cyrillic E in place of the Latin one' => ["\u{0415}UR"],
    'fullwidth letters' => ["\u{FF25}\u{FF35}\u{FF32}"],
    'a symbol' => ["\u{20AC}"],
]);

it('names the rejected input in the message', function () {
    expect(fn () => Currency::of('eur'))
        ->toThrow(InvalidCurrency::class, 'A currency code is exactly three uppercase ASCII letters, not "eur".');
});

it('answers {digits} minor-unit digits for {code}', function (string $code, int $digits) {
    expect(Currency::of($code)->digits())->toBe($digits);
})->with([
    'JPY, whole units' => ['JPY', 0],
    'KRW, whole units' => ['KRW', 0],
    'KWD, thousandths' => ['KWD', 3],
    'BHD, thousandths' => ['BHD', 3],
    'CLF, ten-thousandths' => ['CLF', 4],
    'EUR, hundredths' => ['EUR', 2],
    'USD, hundredths' => ['USD', 2],
    'a code the table does not list, hundredths' => ['XYZ', 2],
]);

/*
 * The table pinned entry by entry: every three-letter code answers two digits except exactly
 * these, which are the ISO 4217 currencies whose minor unit is not a hundredth. The sweep runs
 * over all 17576 codes, so an entry added to the table, dropped from it or moved between digits
 * fails here until this list moves with it. The booking app's formatter keeps its own scale
 * table, and its test should hold this same list.
 */
it('answers two digits for every code except the pinned entries', function () {
    $pinned = [
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0,
        'KRW' => 0, 'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'UYI' => 0, 'VND' => 0, 'VUV' => 0,
        'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
        'CLF' => 4, 'UYW' => 4,
    ];
    ksort($pinned);

    $letters = range('A', 'Z');
    $found = [];

    foreach ($letters as $first) {
        foreach ($letters as $second) {
            foreach ($letters as $third) {
                $code = $first.$second.$third;
                $digits = Currency::of($code)->digits();

                if ($digits !== 2) {
                    $found[$code] = $digits;
                }
            }
        }
    }

    expect($found)->toBe($pinned);
});

it('equals another instance of the same code and no other', function () {
    expect(Currency::of('EUR')->equals(Currency::of('EUR')))->toBeTrue()
        ->and(Currency::of('EUR')->equals(Currency::of('USD')))->toBeFalse();
});
