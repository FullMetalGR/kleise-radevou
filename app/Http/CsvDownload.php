<?php

declare(strict_types=1);

namespace App\Http;

use Closure;
use NumberFormatter;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The admin's one CSV writer, Customers' and Reports' Download CSV; CustomerCsv and ReportCsv
 * build the rows, this writes them. UTF-8 behind a byte order mark so a spreadsheet reads the
 * Greek, a comma between cells, a double quote around a cell that needs one and no escape
 * character, attached under the name given as text/csv and never cached. A cell a spreadsheet
 * would read as a formula (=, +, -, @, a tab or a carriage return first, and not a plain number)
 * is written behind an apostrophe, every cell of every row, so no builder can forget it. A count
 * in either file is written by one rule, counts: ICU's decimal formatter in the request's
 * language, grouped from a thousand up as the language groups it.
 */
final readonly class CsvDownload
{
    /**
     * @param  list<list<string>>  $rows
     */
    public static function of(array $rows, string $name): StreamedResponse
    {
        return new StreamedResponse(function () use ($rows): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, "\u{FEFF}");

            foreach ($rows as $row) {
                fputcsv($out, array_map(self::safe(...), $row), ',', '"', '');
            }

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name),
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * The writer of a file's counts in the locale given, its formatter built once for the file:
     * 1,234 in English, 1.234 in Greek.
     *
     * @return Closure(int): string
     */
    public static function counts(string $locale): Closure
    {
        $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);

        return static function (int $count) use ($formatter): string {
            $text = $formatter->format($count);

            return is_string($text) ? $text : (string) $count;
        };
    }

    /** A cell a spreadsheet would read as a formula, kept as text behind an apostrophe. */
    public static function safe(string $cell): string
    {
        return $cell !== '' && in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($cell) ? "'".$cell : $cell;
    }
}
