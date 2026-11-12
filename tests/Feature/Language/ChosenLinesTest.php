<?php

use App\Site\SiteLanguage;

/*
 * The chosen lines of the status chip's count, the catalog's processing gap and the booking
 * bundle's minutes: each Greek line read through the translator at one, at two and at twenty-one
 * reaches the form it is expected to. Which lines bend is a list of keys typed here, never read off
 * a value: every counted key but pending, the gap and the minutes. A line expected to bend holds
 * exactly two cases bounded {1} and [2,*] and answers two different lines at one and two once the
 * figure is taken out; pending holds no bar and answers the same line at both. Twenty-one answers
 * what two does, and no answer keeps a brace, a bracket or a bar of the choice syntax. The dataset
 * reads its counted keys off the Greek file's kit.counted, and the first case holds both files'
 * counted groups to the English file's kit.statuses, the nine booking statuses the chip names.
 */

/**
 * The keys of the shell's counted group in a language's file, read off the file itself, since a
 * dataset is built before the application boots.
 *
 * @return list<string>
 */
function clCountedKeysIn(SiteLanguage $language): array
{
    /** @var array{kit: array{counted: array<string, string>}} $shell */
    $shell = require dirname(__DIR__, 3)."/lang/{$language->value}/admin/shell.php";

    return array_keys($shell['kit']['counted']);
}

dataset('chosen Greek lines', function (): array {
    $counted = array_map(fn (string $status): string => "admin/shell.kit.counted.{$status}", clCountedKeysIn(SiteLanguage::Greek));
    $bending = [
        ...array_values(array_diff($counted, ['admin/shell.kit.counted.pending'])),
        'admin/services.table.minutes_gap',
        'booking.duration.minutes',
    ];
    $keys = [...$counted, 'admin/services.table.minutes_gap', 'booking.duration.minutes'];

    return array_combine($keys, array_map(fn (string $key): array => [$key, in_array($key, $bending, true)], $keys));
});

it('holds a line per booking status in the shell\'s counted group, in both languages', function () {
    /** @var array{kit: array{statuses: array<string, string>}} $english */
    $english = require dirname(__DIR__, 3).'/lang/'.SiteLanguage::English->value.'/admin/shell.php';
    $statuses = array_keys($english['kit']['statuses']);

    expect($statuses)->toHaveCount(9)
        ->and(clCountedKeysIn(SiteLanguage::English))->toBe($statuses)
        ->and(clCountedKeysIn(SiteLanguage::Greek))->toBe($statuses)
        ->and($statuses)->toContain('pending');
});

it('reaches the form its Greek value is expected to at one, at two and at twenty-one', function (string $key, bool $bends) {
    $line = trans($key, [], SiteLanguage::Greek->value);
    assert(is_string($line));
    $values = ['count' => 'count-token', 'n' => 'n-token'];
    $one = trans_choice($key, 1, $values, SiteLanguage::Greek->value);
    $two = trans_choice($key, 2, $values, SiteLanguage::Greek->value);
    $many = trans_choice($key, 21, $values, SiteLanguage::Greek->value);

    expect($line)->not->toBe($key)
        ->and($one)->toContain('count-token')
        ->and($two)->toContain('count-token')
        ->and($many)->toBe($two);

    foreach ([$one, $two, $many] as $answer) {
        expect($answer)->not->toMatch('/[{}\[\]|]/');
    }

    if ($bends) {
        expect($line)->toMatch('/^\{1\}[^{}\[\]|]*\|\[2,\*\][^{}\[\]|]*$/u')
            ->and($one)->not->toBe($two);
    } else {
        expect($line)->not->toContain('|')
            ->and($one)->toBe($two);
    }
})->with('chosen Greek lines');
