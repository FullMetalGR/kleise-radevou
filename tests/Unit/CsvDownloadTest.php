<?php

use App\Http\CsvDownload;

/*
 * The one CSV writer of the admin's downloads, Customers' and Reports': UTF-8 behind a byte order
 * mark, a comma between cells, a double quote around a cell that needs one and no escape
 * character, every cell a spreadsheet would read as a formula behind an apostrophe, attached
 * under the name given and never cached; and the one rule both files write a count by, in the
 * request's language.
 */

/**
 * The streamed body of a download.
 *
 * @param  list<list<string>>  $rows
 */
function csvDownloadBody(array $rows): string
{
    ob_start();
    CsvDownload::of($rows, 'figures.csv')->sendContent();

    return (string) ob_get_clean();
}

it('keeps a cell a spreadsheet would read as a formula as text behind an apostrophe: {0}', function (string $cell, string $written) {
    expect(CsvDownload::safe($cell))->toBe($written);
})->with([
    'an equals sign' => ['=SUM(A1:A2)', "'=SUM(A1:A2)"],
    'a plus before words' => ['+cmd', "'+cmd"],
    'a minus before words' => ['-2+3 people', "'-2+3 people"],
    'an at sign' => ['@home', "'@home"],
    'a tab' => ["\tlead", "'\tlead"],
    'a carriage return' => ["\rlead", "'\rlead"],
    'a phone number' => ['+30 210 7654321', "'+30 210 7654321"],
    'a negative number' => ['-12.50', '-12.50'],
    'a signed number' => ['+5', '+5'],
    'a plain word' => ['Haircut', 'Haircut'],
    'an empty cell' => ['', ''],
    'an equals sign inside' => ['a=b', 'a=b'],
]);

it('writes UTF-8 behind a byte order mark, quoting only where a cell needs it and guarding every cell', function () {
    expect(csvDownloadBody([['Title'], ['Name', 'Note'], ['Anna "A"', '=1+1'], ["Ren\u{00E9}e", 'a,b']]))
        ->toBe("\u{FEFF}Title\nName,Note\n\"Anna \"\"A\"\"\",'=1+1\nRen\u{00E9}e,\"a,b\"\n");
});

it('answers text/csv in UTF-8, attached under the name given and never cached', function () {
    $response = CsvDownload::of([], 'customers-2026-11-02.csv');

    expect($response->headers->get('Content-Type'))->toBe('text/csv; charset=UTF-8')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename=customers-2026-11-02.csv')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('writes a count by one rule for both files, ICU grouping it from a thousand up as the language does', function (string $locale, string $written) {
    $count = CsvDownload::counts($locale);

    expect($count(1234))->toBe($written)
        ->and($count(7))->toBe('7');
})->with([
    'English' => ['en', '1,234'],
    'Greek' => ['el', '1.234'],
]);
