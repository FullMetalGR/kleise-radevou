<?php

use App\Site\SiteLanguage;
use App\Site\SitePages;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
 * The missing-key test: every file under lang/en has its twin under lang/el and the reverse, the
 * two files' flattened keys are one set, and each key's placeholders are one set in both. A key
 * present in one language and missing from the other would be read through the translator's
 * fallback, the English sentence on a Greek page, and nothing would say so; this is what says so.
 *
 * The check is a function of two directories, so the cases below hand it mismatched pairs built
 * in a temporary directory and see it name each kind of mismatch, in either direction, and the
 * last case hands it the real ones. A placeholder is the translator's :name; it is compared by
 * its name alone, since the translator fills :name, :Name and :NAME from the one value.
 *
 * The translation test, sits after it, built the same way: every Greek value differs from its
 * English one, but for the few values the two languages share by nature, held in
 * languageSharedValues(). A value the Greek file still holds in English is read on a Greek page as
 * the English, and the missing-key test cannot see it, since the key is there. It covers every
 * file, legal/ included; the function still takes the directories a run leaves out, which its own
 * fabricated case proves. Each Greek page of the site is then rendered once, with no placeholder
 * the translator left unfilled.
 */

/**
 * Every PHP file under a language directory, by its path relative to it.
 *
 * @return list<string>
 */
function languageFilesUnder(string $directory): array
{
    $files = [];

    foreach (File::allFiles($directory) as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = str_replace('\\', '/', $file->getRelativePathname());
        }
    }

    sort($files);

    return $files;
}

/**
 * A language file's values by their flattened key, a list's items and a map's entries alike. An
 * empty list or map holds no key, so it is no entry of its own.
 *
 * @return array<string, mixed>
 */
function languageKeysOf(string $path): array
{
    $values = require $path;

    if (! is_array($values)) {
        throw new LogicException(sprintf('%s returns no array.', $path));
    }

    $keys = [];

    foreach (Arr::dot($values) as $key => $value) {
        if ($value !== []) {
            $keys[(string) $key] = $value;
        }
    }

    return $keys;
}

/**
 * The placeholders a value carries, each by its name lowercased, sorted.
 *
 * @return list<string>
 */
function languagePlaceholdersOf(mixed $value): array
{
    if (! is_string($value)) {
        return [];
    }

    preg_match_all('/(?<![A-Za-z0-9_:]):([A-Za-z][A-Za-z0-9_]*)/', $value, $matches);

    $names = array_values(array_unique(array_map(strtolower(...), $matches[1])));
    sort($names);

    return $names;
}

/**
 * What differs between an English language directory and a Greek one: each file of one without
 * its twin in the other, each key of a twin pair present in one alone, and each key whose
 * placeholders differ. Empty when the two agree.
 *
 * @return list<string>
 */
function languageMismatches(string $english, string $greek): array
{
    $englishFiles = languageFilesUnder($english);
    $greekFiles = languageFilesUnder($greek);
    $mismatches = [];

    foreach (array_diff($englishFiles, $greekFiles) as $file) {
        $mismatches[] = sprintf('el lacks the file %s', $file);
    }

    foreach (array_diff($greekFiles, $englishFiles) as $file) {
        $mismatches[] = sprintf('en lacks the file %s', $file);
    }

    foreach (array_intersect($englishFiles, $greekFiles) as $file) {
        $prefix = Str::beforeLast($file, '.php');
        $englishKeys = languageKeysOf($english.'/'.$file);
        $greekKeys = languageKeysOf($greek.'/'.$file);

        foreach (array_diff_key($englishKeys, $greekKeys) as $key => $value) {
            $mismatches[] = sprintf('el lacks the key %s.%s', $prefix, $key);
        }

        foreach (array_diff_key($greekKeys, $englishKeys) as $key => $value) {
            $mismatches[] = sprintf('en lacks the key %s.%s', $prefix, $key);
        }

        foreach (array_intersect_key($englishKeys, $greekKeys) as $key => $value) {
            $inEnglish = languagePlaceholdersOf($value);
            $inGreek = languagePlaceholdersOf($greekKeys[$key]);

            if ($inEnglish !== $inGreek) {
                $mismatches[] = sprintf('the placeholders of %s.%s differ: en [%s], el [%s]', $prefix, $key, implode(', ', $inEnglish), implode(', ', $inGreek));
            }
        }
    }

    return $mismatches;
}

/**
 * Where this process writes the pairs below, emptied after every case.
 */
function languagePairsRoot(): string
{
    return sys_get_temp_dir().'/kr-lang-'.getmypid();
}

/**
 * A pair of language directories in a temporary directory, each file written as the PHP the
 * translator reads, given as its relative path and the array it returns.
 *
 * @param  array<mixed>  $english
 * @param  array<mixed>  $greek
 * @return array{string, string}
 */
function languagePair(array $english, array $greek): array
{
    $root = languagePairsRoot().'/'.Str::random(12);

    foreach (['en' => $english, 'el' => $greek] as $language => $files) {
        File::ensureDirectoryExists($root.'/'.$language);

        foreach ($files as $path => $values) {
            assert(is_string($path) && is_array($values));

            File::ensureDirectoryExists(dirname($root.'/'.$language.'/'.$path));
            File::put($root.'/'.$language.'/'.$path, '<?php return '.var_export($values, true).';');
        }
    }

    return [$root.'/en', $root.'/el'];
}

afterEach(fn () => File::deleteDirectory(languagePairsRoot()));

it('passes a pair that agrees: the same files, the same keys, the same placeholders, a list and a nested file included', function () {
    $files = [
        'site.php' => ['title' => 'Hello :name', 'nav' => ['pricing' => 'Pricing'], 'steps' => ['One', 'Two']],
        'legal/terms.php' => ['title' => 'Terms'],
    ];

    expect(languageMismatches(...languagePair($files, $files)))->toBe([]);
});

it('fails on a file one language has and the other lacks, {direction}', function (array $english, array $greek, string $mismatch) {
    expect(languageMismatches(...languagePair($english, $greek)))->toBe([$mismatch]);
})->with([
    'missing from el' => [['site.php' => ['a' => 'A'], 'home.php' => ['b' => 'B']], ['site.php' => ['a' => 'A']], 'el lacks the file home.php'],
    'missing from en' => [['site.php' => ['a' => 'A']], ['site.php' => ['a' => 'A'], 'legal/terms.php' => ['b' => 'B']], 'en lacks the file legal/terms.php'],
]);

it('fails on a key one language has and the other lacks, {direction}', function (array $english, array $greek, string $mismatch) {
    expect(languageMismatches(...languagePair($english, $greek)))->toBe([$mismatch]);
})->with([
    'missing from el' => [['site.php' => ['a' => 'A', 'nav' => ['b' => 'B']]], ['site.php' => ['a' => 'A', 'nav' => []]], 'el lacks the key site.nav.b'],
    'missing from en' => [['site.php' => ['a' => 'A']], ['site.php' => ['a' => 'A', 'c' => 'C']], 'en lacks the key site.c'],
    'a list one item short in el' => [['home.php' => ['steps' => ['One', 'Two']]], ['home.php' => ['steps' => ['One']]], 'el lacks the key home.steps.1'],
]);

it('fails on a key whose placeholders differ, {direction}', function (array $english, array $greek, string $mismatch) {
    expect(languageMismatches(...languagePair($english, $greek)))->toBe([$mismatch]);
})->with([
    'one el lacks' => [['site.php' => ['title' => ':name at :count']], ['site.php' => ['title' => ':name']], 'the placeholders of site.title differ: en [count, name], el [name]'],
    'one en lacks' => [['site.php' => ['title' => 'Welcome']], ['site.php' => ['title' => 'Welcome :name']], 'the placeholders of site.title differ: en [], el [name]'],
    'one renamed' => [['site.php' => ['title' => 'Hi :name']], ['site.php' => ['title' => 'Hi :who']], 'the placeholders of site.title differ: en [name], el [who]'],
]);

it('reads a placeholder by its name whatever its case, and a colon in a time or an address as no placeholder', function () {
    $english = ['site.php' => ['title' => 'Open at 09:30 on https://example.test, :Name']];
    $greek = ['site.php' => ['title' => ':NAME, 09:30, https://example.test']];

    expect(languageMismatches(...languagePair($english, $greek)))->toBe([]);
});

it('passes on the real files: lang/en and lang/el hold the same files, keys and placeholders', function () {
    expect(languageFilesUnder(lang_path('en')))->not->toBeEmpty()
        ->and(languageFilesUnder(lang_path('el')))->toContain('countries.php')
        ->and(languageMismatches(lang_path('en'), lang_path('el')))->toBe([]);
});

/**
 * What a Greek language directory leaves in English: each key of a twin pair whose Greek value is
 * its English one, unless the value is one of the shared, or its file sits under a directory not
 * yet translated, given by its path relative to the language directory with a trailing slash.
 * Empty when every Greek value is its own. A file without its twin is the missing-key test's.
 *
 * @param  list<string>  $shared
 * @param  list<string>  $untranslated
 * @return list<string>
 */
function languageUntranslated(string $english, string $greek, array $shared, array $untranslated = []): array
{
    $files = array_filter(
        array_intersect(languageFilesUnder($english), languageFilesUnder($greek)),
        fn (string $file): bool => ! Str::startsWith($file, $untranslated),
    );
    $kept = [];

    foreach ($files as $file) {
        $prefix = Str::beforeLast($file, '.php');
        $greekKeys = languageKeysOf($greek.'/'.$file);

        foreach (array_intersect_key(languageKeysOf($english.'/'.$file), $greekKeys) as $key => $value) {
            if ($greekKeys[$key] === $value && ! in_array($value, $shared, true)) {
                $kept[] = sprintf('el keeps the English of %s.%s', $prefix, $key);
            }
        }
    }

    return $kept;
}

it('passes a pair whose every Greek value is its own, a list, a map and a nested file included', function () {
    [$english, $greek] = languagePair(
        ['site.php' => ['title' => 'Hello :name', 'nav' => ['pricing' => 'Pricing'], 'steps' => ['One', 'Two']], 'legal/terms.php' => ['title' => 'Terms']],
        ['site.php' => ['title' => 'Hola :name', 'nav' => ['pricing' => 'Precios'], 'steps' => ['Uno', 'Dos']], 'legal/terms.php' => ['title' => 'Condiciones']],
    );

    expect(languageUntranslated($english, $greek, []))->toBe([]);
});

it('fails on a Greek value equal to its English one, {where}', function (array $inEnglish, array $inGreek, string $kept) {
    [$english, $greek] = languagePair($inEnglish, $inGreek);

    expect(languageUntranslated($english, $greek, []))->toBe([$kept]);
})->with([
    'a value at the top' => [['site.php' => ['title' => 'Pricing', 'lead' => 'Hello']], ['site.php' => ['title' => 'Pricing', 'lead' => 'Hola']], 'el keeps the English of site.title'],
    'an entry of a map' => [['site.php' => ['nav' => ['pricing' => 'Pricing', 'faq' => 'Questions']]], ['site.php' => ['nav' => ['pricing' => 'Precios', 'faq' => 'Questions']]], 'el keeps the English of site.nav.faq'],
    'an item of a list' => [['home.php' => ['steps' => ['One', 'Two']]], ['home.php' => ['steps' => ['Uno', 'Two']]], 'el keeps the English of home.steps.1'],
    'a value with a placeholder' => [['home.php' => ['title' => ':name - booking']], ['home.php' => ['title' => ':name - booking']], 'el keeps the English of home.title'],
    'a file under a directory' => [['legal/terms.php' => ['title' => 'Terms']], ['legal/terms.php' => ['title' => 'Terms']], 'el keeps the English of legal/terms.title'],
]);

it('passes a value equal in both languages that the shared list holds, and fails the same value where the list does not hold it', function () {
    [$english, $greek] = languagePair(['legal/privacy.php' => ['processor' => 'Stripe', 'title' => 'Privacy']], ['legal/privacy.php' => ['processor' => 'Stripe', 'title' => 'Privacidad']]);

    expect(languageUntranslated($english, $greek, ['Stripe']))->toBe([])
        ->and(languageUntranslated($english, $greek, ['Google']))->toBe(['el keeps the English of legal/privacy.processor']);
});

it('leaves out a directory not yet translated, and no file outside it', function () {
    [$english, $greek] = languagePair(
        ['legal/terms.php' => ['title' => 'Terms'], 'site.php' => ['title' => 'Pricing'], 'legalese.php' => ['title' => 'Notice']],
        ['legal/terms.php' => ['title' => 'Terms'], 'site.php' => ['title' => 'Pricing'], 'legalese.php' => ['title' => 'Notice']],
    );

    expect(languageUntranslated($english, $greek, [], ['legal/']))->toBe(['el keeps the English of legalese.title', 'el keeps the English of site.title']);
});

it('passes on the real files: every Greek value differs from its English one but the shared', function () {
    expect(languageFilesUnder(lang_path('el')))->toContain('site.php', 'home.php', 'features.php', 'faq.php', 'pricing.php', 'verticals.php', 'contact.php', 'signup.php', 'mail.php', 'countries.php', 'legal/terms.php', 'legal/privacy.php', 'legal/cookies.php', 'legal/dpa.php', 'booking.php', 'pages.php', 'api.php')
        ->and(languageUntranslated(lang_path('en'), lang_path('el'), languageSharedValues()))->toBe([]);
});

it('renders every Greek page of the site and the Greek 404 with every placeholder filled, a page served once per key under each', function () {
    $this->withoutVite();
    $pages = app(SitePages::class);
    $walk = [];

    foreach ($pages->names() as $page) {
        foreach ($pages->instances($page) as $parameters) {
            $walk[$pages->url($page, SiteLanguage::Greek, $parameters)] = 200;
        }
    }

    $walk[centralUrl('/el/nowhere')] = 404;

    foreach ($walk as $url => $status) {
        $response = $this->get($url);
        $document = siteDocument($response);
        $text = implode(' ', [$document->title, $document->querySelector('meta[name="description"]')?->getAttribute('content'), $document->body?->textContent]);

        preg_match_all('/(?<![A-Za-z0-9_:\/]):[A-Za-z][A-Za-z0-9_]*/', $text, $unfilled);

        $response->assertStatus($status);
        expect($document->documentElement?->getAttribute('lang'))->toBe('el', $url)
            ->and($unfilled[0])->toBe([], $url);
    }
});
