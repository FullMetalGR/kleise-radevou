<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Dto\CustomerProfile;
use App\Application\Dto\CustomerRecord;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The Customers list's Download CSV as rows: what the list shows under its search, every matching
 * address and not the page on screen, in the list's order. Reports' file is the shape (ReportCsv):
 * a title row, then a header row, then a row per address; the title is the screen's, naming the
 * search where one narrows the list.
 *
 * The columns are the table's, its name cell spread over four: Name (the most recent), Also booked
 * as (the other names seen, most recent first), Tags, Blocked; then Email and Phone where the
 * viewer is shown the ways to reach a customer (the CONTACT rule, as the table's columns), First
 * visit, Last visit and Bookings. Every word is a key of the customers section in the request's
 * language. A visit is the date and the time in the tenant's date and time formats, in the
 * tenant's zone, as the list shows one, a last visit not yet made the table's own words; the
 * Bookings count by CsvDownload::counts in the request's language, as Reports' counts are.
 * CsvDownload writes the file, every cell a spreadsheet would read as a formula behind an
 * apostrophe, as Reports' is.
 */
final readonly class CustomerCsv
{
    /**
     * @param  list<CustomerRecord>  $customers  in the list's order
     * @param  array<string, CustomerProfile>  $profiles  by address, as CustomerProfiles::of answers
     * @return list<list<string>>
     */
    public static function rows(array $customers, array $profiles, bool $contact, ?string $search, DateTimeZone $zone, string $dateFormat, string $timeFormat): array
    {
        $count = CsvDownload::counts(app()->getLocale());
        $head = [
            self::word('table.name'), self::word('csv.also_booked_as'), self::word('profile.tags'), self::word('table.blocked'),
            ...($contact ? [self::word('table.email'), self::word('table.phone')] : []),
            self::word('table.first_visit'), self::word('table.last_visit'), self::word('table.bookings'),
        ];
        $rows = [
            [$search === null ? self::word('page.title') : self::word('csv.searched', ['search' => $search])],
            $head,
        ];

        foreach ($customers as $customer) {
            $profile = $profiles[$customer->email] ?? CustomerProfile::none();
            $rows[] = [
                $customer->names[0],
                implode(', ', array_slice($customer->names, 1)),
                implode(', ', $profile->tags),
                self::word($profile->blocked ? 'csv.yes' : 'csv.no'),
                ...($contact ? [$customer->email, implode(', ', $customer->phones)] : []),
                self::visit($customer->firstVisit, $zone, $dateFormat, $timeFormat),
                $customer->lastVisit === null ? self::word('table.no_visit') : self::visit($customer->lastVisit, $zone, $dateFormat, $timeFormat),
                $count($customer->bookings),
            ];
        }

        return $rows;
    }

    /**
     * An instant as the list writes a visit: the date and the time in the tenant's formats, one
     * space between, in the tenant's zone.
     */
    private static function visit(DateTimeImmutable $instant, DateTimeZone $zone, string $dateFormat, string $timeFormat): string
    {
        return $instant->setTimezone($zone)->format($dateFormat.' '.$timeFormat);
    }

    /**
     * A key of the customers section in the request's language.
     *
     * @param  array<string, string>  $replace
     */
    private static function word(string $key, array $replace = []): string
    {
        $text = __('admin/customers.'.$key, $replace);

        return is_string($text) ? $text : $key;
    }
}
