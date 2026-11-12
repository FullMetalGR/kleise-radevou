<?php

use App\Tenancy\InvalidSlug;
use App\Tenancy\Slug;
use App\Tenancy\SlugProblem;

it('lowercases mixed-case input', function () {
    expect(Slug::of('Acme-Salon')->value)->toBe('acme-salon');
});

it('keeps letters, digits and hyphens as they are', function () {
    expect(Slug::of('salon-42')->value)->toBe('salon-42');
});

it('derives a schema name that is a valid PostgreSQL identifier', function () {
    expect(Slug::of('42-Acme-Salon')->schemaName())->toBe('tenant_42_acme_salon');
});

it('refuses {input} because it is {problem}', function (string $input, SlugProblem $problem) {
    try {
        Slug::of($input);
    } catch (InvalidSlug $e) {
        expect($e->problem)->toBe($problem);

        return;
    }

    $this->fail('No InvalidSlug was thrown.');
})->with([
    'empty input' => ['', SlugProblem::Empty],
    'a leading hyphen' => ['-acme', SlugProblem::LeadingHyphen],
    'a trailing hyphen' => ['acme-', SlugProblem::TrailingHyphen],
    'an underscore' => ['acme_salon', SlugProblem::InvalidCharacter],
    'a space' => ['acme salon', SlugProblem::InvalidCharacter],
    'a dot' => ['acme.salon', SlugProblem::InvalidCharacter],
    'a Cyrillic a in place of the Latin one' => ["s\u{0430}lon", SlugProblem::InvalidCharacter],
    'a fullwidth digit' => ["salon\u{FF14}", SlugProblem::InvalidCharacter],
    'a Latin small letter with a diacritic' => ["caf\u{00E9}", SlugProblem::InvalidCharacter],
    'the punycode form' => ['xn--acme', SlugProblem::PunycodeForm],
    'fifty-seven characters' => [str_repeat('a', 57), SlugProblem::TooLong],
]);

it('accepts fifty-six characters, the longest slug whose schema name fits an identifier', function () {
    $slug = Slug::of(str_repeat('a', 56));

    expect(strlen($slug->schemaName()))->toBe(63);
});

it('refuses a lookalike rather than folding it into somebody else\'s slug', function () {
    // Unicode case folding would turn the Kelvin sign into a Latin k. Refusing it keeps "kelvin"
    // and "\u{212A}elvin" from ever meeting in the same row.
    expect(fn () => Slug::of("\u{212A}elvin"))->toThrow(InvalidSlug::class);
});

it('names the problem in the exception message', function () {
    expect(fn () => Slug::of('-acme'))->toThrow(InvalidSlug::class, SlugProblem::LeadingHyphen->detail());
});

it('compares by value', function () {
    expect(Slug::of('Acme')->equals(Slug::of('acme')))->toBeTrue()
        ->and(Slug::of('acme')->equals(Slug::of('acme-2')))->toBeFalse();
});
