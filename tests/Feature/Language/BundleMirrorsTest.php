<?php

use App\Site\SiteLanguage;
use App\Tenancy\TenantPages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

/*
 * The server's half of the two rules the booking bundle mirrors, each over a fixture under
 * tests/Support the bundle's own test reads too, so the server and the mirror are held to the
 * same answers and cannot drift apart: the translator's choice syntax, chosen in both languages,
 * whose plural rule is one; and the language switch's address, TenantPages::addresses over the
 * same paths the bundle's switch computes its link from. resources/js/booking/words.test.ts and
 * language.test.ts are the other half.
 */

/**
 * A fixture under tests/Support, decoded.
 *
 * @return list<array<mixed>>
 */
function mirroredCases(string $file): array
{
    $cases = json_decode(File::get(base_path('tests/Support/'.$file)), true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($cases)) {
        throw new LogicException(sprintf('%s holds no list of cases.', $file));
    }

    $checked = [];

    foreach ($cases as $case) {
        if (! is_array($case)) {
            throw new LogicException(sprintf('%s holds a case that is no object.', $file));
        }

        $checked[] = $case;
    }

    return $checked;
}

it('chooses each fixture line by each count as the fixture says, in English and in Greek alike', function (string $language) {
    $selector = app('translator')->getSelector();

    foreach (mirroredCases('choices.json') as $case) {
        assert(is_string($case['line']) && is_array($case['chosen']));

        foreach ($case['chosen'] as $count => $chosen) {
            expect($selector->choose($case['line'], (int) $count, $language))->toBe($chosen, sprintf('%s by %s', $case['line'], $count));
        }
    }
})->with(['en', 'el']);

it('leads the switch of each fixture path to the address the fixture says, the request\'s language as it says', function () {
    $root = 'http://luna.localhost';

    foreach (mirroredCases('language-switch.json') as $case) {
        assert(is_string($case['path']) && is_string($case['default']) && is_string($case['language']) && is_string($case['switch']));

        $addresses = TenantPages::addresses(Request::create($root.$case['path']), SiteLanguage::from($case['default']));

        expect($addresses->language->value)->toBe($case['language'], $case['path'])
            ->and($addresses->switchHref)->toBe($root.$case['switch'], $case['path']);
    }
});
