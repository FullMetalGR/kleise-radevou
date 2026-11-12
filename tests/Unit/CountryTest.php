<?php

use App\Tenancy\Profile;

/*
 * The country as the business page prints it and the Settings screen's select offers it: the name
 * of each of the 39 codes, from lang/{en,el}/countries.php and never from Intl, whose region names
 * follow the ICU build. The codes are spelled once, as the keys of the English file: the select
 * reads the file in the user's language through the Settings page's countries prop,
 * App\Http\CountryNames the server's one reader of it, so the client holds no list of its own. The
 * first case ties the two files' codes and proves the client spells none; the page hands Profile
 * the file of its language.
 */

/** The country select's source, which reads the names from the page and spells no code. */
function countrySelectSource(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/admin/settings/ProfileFields.tsx');
}

/**
 * A language's country names by code, as the file under lang holds them.
 *
 * @return array<string, string>
 */
function countryNames(string $language): array
{
    $file = require dirname(__DIR__, 2).'/lang/'.$language.'/countries.php';
    $names = [];

    foreach (is_array($file) ? $file : [] as $code => $name) {
        $names[(string) $code] = is_string($name) ? $name : '';
    }

    return $names;
}

it('holds the very codes in both files, in the same order, 39 of them, and the client\'s select spells none of them nor asks Intl for a name', function () {
    $source = countrySelectSource();

    expect(countryNames('en'))->toHaveCount(39)
        ->and(array_keys(countryNames('el')))->toBe(array_keys(countryNames('en')))
        ->and($source)->not->toContain('COUNTRIES')
        ->and($source)->not->toContain('DisplayNames');

    foreach (array_keys(countryNames('en')) as $code) {
        expect($source)->not->toContain("'".$code."'");
    }
});

it('spells every English name in plain ASCII letters and spaces, no two alike', function () {
    foreach (countryNames('en') as $name) {
        expect($name)->toMatch('/\A[A-Z][A-Za-z]*(?: [A-Z][A-Za-z]*)*\z/');
    }

    expect(array_unique(countryNames('en')))->toHaveCount(39);
});

it('prints the address with the country\'s name in the language handed it and geocodes it with the code, the two lines otherwise one', function (string $language) {
    $profile = Profile::of(['address_line1' => '12 Ermou Street', 'address_line2' => 'Second floor', 'city' => 'Athens', 'postal_code' => '10563', 'country' => 'GR']);
    $names = countryNames($language);

    expect($profile->displayLines($names))->toBe(['12 Ermou Street', 'Second floor', '10563 Athens', $names['GR'] ?? null])
        ->and($profile->addressLines())->toBe(['12 Ermou Street', 'Second floor', '10563 Athens', 'GR']);
})->with(['en', 'el']);

it('prints no country line for a null country and no lines for a country alone, as the geocoding lines do, and a code outside the names as the code it is', function () {
    expect(Profile::of(['city' => 'Athens'])->displayLines(countryNames('en')))->toBe(['Athens'])
        ->and(Profile::of(['country' => 'GR'])->displayLines(countryNames('en')))->toBe([])
        ->and(Profile::of(['city' => 'Osaka', 'country' => 'JP'])->displayLines(countryNames('en')))->toBe(['Osaka', 'JP'])
        ->and(Profile::of(['city' => 'Osaka', 'country' => 'JP'])->displayLines(countryNames('el')))->toBe(['Osaka', 'JP']);
});
