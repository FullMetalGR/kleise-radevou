<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Dto\FigureFilter;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Site\SiteLanguage;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MessageFormatter;
use NumberFormatter;

/**
 * Reports' Download CSV as rows: what the screen shows under its range and its filters, read from
 * the very props the screen receives (RangeTiles and ReportPayload), so the file and the screen
 * cannot disagree.
 *
 * A section a card, in the screen's order: the filters, the six tiles, Over time, Bookings by
 * status, Revenue, the No-show rate, Cancelled by, Occupancy by staff and the four By tables. Each
 * is its title, its header row and its rows, and an empty row stands between two. Every word is a
 * key of the reports section, a status, a source or a channel the shell's own, in the request's
 * language. Dates are in the tenant's date format, as every tenant screen writes them; every
 * count, amount and percentage is written by one rule, ICU's NumberFormatter in the request's
 * language: an amount its decimal at exactly its currency's digits, grouped, the tenant's currency
 * named in its column's header and any other written after its amount; a change against the period
 * before its sentence with the signed percentage as the screen writes it; minutes hours and
 * minutes as the screen writes them. CsvDownload writes the file, every cell a spreadsheet would
 * read as a formula behind an apostrophe.
 */
final readonly class ReportCsv
{
    /** The statuses Bookings by status always lists, in the order the screen shows them. */
    private const array ALWAYS = ['confirmed', 'completed', 'awaiting_approval', 'cancelled', 'no_show'];

    /** The statuses it lists beneath them only where a booking holds one. */
    private const array WHEN_ANY = ['awaiting_payment', 'pending', 'rejected', 'expired'];

    /** The kind of range each named range's comparison names its period before by. */
    private const array COMPARED = [
        'today' => 'day', 'yesterday' => 'day', 'week' => 'week', 'last_7_days' => 'last_7_days',
        'last_30_days' => 'last_30_days', 'month' => 'month', 'last_month' => 'month', 'year' => 'year',
    ];

    /**
     * @param  array<string, mixed>  $tiles  RangeTiles::of
     * @param  array<string, mixed>  $report  ReportPayload::of
     * @param  array{staff: list<array{id: int, name: string}>, services: list<array{id: int, name: string}>}  $options
     * @return list<list<string>>
     */
    public static function rows(array $tiles, array $report, FigureFilter $filter, array $options, string $currency, string $dateFormat): array
    {
        $csv = new self($currency, app()->getLocale(), $dateFormat);
        $range = self::map($tiles['range'] ?? []);
        $from = self::text($range['from'] ?? '');
        $to = self::text($range['to'] ?? '');

        $sections = [
            [
                self::word('csv.filters'),
                [self::word('filters.range'), self::word('filters.from'), self::word('filters.to'), self::word('filters.staff'), self::word('filters.service')],
                [[
                    self::word('filters.words.'.self::text($range['word'] ?? 'week')),
                    $csv->date($from),
                    $csv->date($to),
                    self::chosen($options['staff'], $filter->staffId) ?? self::word('filters.all_staff'),
                    self::chosen($options['services'], $filter->serviceId) ?? self::word('filters.all_services'),
                ]],
            ],
            [
                self::word('csv.figures'),
                [self::word('csv.figure'), self::word('csv.value'), self::word('csv.before'), self::word('csv.change'), self::word('csv.detail')],
                $csv->figures(self::map($tiles['tiles'] ?? []), self::text($range['word'] ?? 'week'), $from, $to),
            ],
            $csv->overTime(self::list(self::map($report['over_time'] ?? [])['rows'] ?? [])),
            ...$csv->cards($report),
            ...$csv->breakdowns($report),
        ];

        $rows = [];

        foreach ($sections as $index => [$title, $head, $lines]) {
            if ($index > 0) {
                $rows[] = [];
            }

            $rows[] = [$title];
            $rows[] = $head;

            foreach ($lines as $line) {
                $rows[] = $line;
            }
        }

        return $rows;
    }

    /** @var Closure(int): string the file's counts, CsvDownload's one rule */
    private Closure $counts;

    /** The file's one decimal formatter, its digits set to each amount's currency in turn. */
    private NumberFormatter $decimals;

    private function __construct(private string $currency, private string $locale, private string $dateFormat)
    {
        $this->counts = CsvDownload::counts($locale);
        $this->decimals = new NumberFormatter($locale, NumberFormatter::DECIMAL);
    }

    /**
     * The six tiles: the figure, the period before's, the change in the screen's sentence and
     * the line the tile writes beneath.
     *
     * @param  array<string, mixed>  $tiles
     * @return list<list<string>>
     */
    private function figures(array $tiles, string $word, string $from, string $to): array
    {
        $count = fn (string $key, string $title, string $detail = ''): array => [
            self::word($title),
            $this->count(self::map($tiles[$key] ?? [])['value'] ?? 0),
            $this->count(self::map($tiles[$key] ?? [])['previous'] ?? 0),
            $this->change(self::map(self::map($tiles[$key] ?? [])['change'] ?? []), $word, $from, $to),
            $detail,
        ];
        $revenue = self::map($tiles['revenue'] ?? []);
        $occupancy = self::map($tiles['occupancy'] ?? []);
        $value = $occupancy['value'] ?? null;
        $previous = $occupancy['previous'] ?? null;
        $customers = self::map($tiles['new_customers'] ?? []);
        $returning = (int) self::text($customers['returning'] ?? 0);

        return [
            $count('bookings', 'tiles.bookings'),
            [
                $this->inCurrency(self::word('tiles.revenue')),
                $this->amount([self::map($revenue['value'] ?? [])]),
                $this->amount([self::map($revenue['previous'] ?? [])]),
                $this->change(self::map($revenue['change'] ?? []), $word, $from, $to),
                implode('; ', array_map(fn (mixed $line): string => self::word('tiles.elsewhere', ['amount' => $this->amount([self::map($line)])]), self::list($revenue['elsewhere'] ?? []))),
            ],
            is_numeric($value) ? [
                self::word('tiles.occupancy'),
                $this->percent((float) $value, 1),
                is_numeric($previous) ? $this->percent((float) $previous, 1) : '',
                is_numeric($previous) ? $this->change(self::map($occupancy['change'] ?? []), $word, $from, $to) : '',
                self::word('tiles.occupancy_of', ['filled' => $this->hours((int) self::text($occupancy['filled_minutes'] ?? 0)), 'offered' => $this->hours((int) self::text($occupancy['offered_minutes'] ?? 0))]),
            ] : [self::word('tiles.occupancy'), self::word('tiles.no_hours'), '', '', ''],
            $count('new_customers', 'tiles.new_customers', trans_choice('admin/reports.tiles.returning', $returning, ['count' => $this->count($returning)])),
            $count('cancelled', 'tiles.cancelled'),
            $count('no_shows', 'tiles.no_shows'),
        ];
    }

    /**
     * @param  list<mixed>  $rows
     * @return array{0: string, 1: list<string>, 2: list<list<string>>}
     */
    private function overTime(array $rows): array
    {
        return [
            self::word('over_time.title'),
            [self::word('over_time.from'), self::word('over_time.to'), self::word('over_time.bookings_head'), $this->inCurrency(self::word('over_time.revenue_head'))],
            array_map(function (mixed $row): array {
                $row = self::map($row);

                return [$this->date(self::text($row['from'] ?? '')), $this->date(self::text($row['to'] ?? '')), $this->count($row['bookings'] ?? 0), $this->amount([self::map($row['revenue'] ?? [])])];
            }, $rows),
        ];
    }

    /**
     * The status row's four cards, then Occupancy by staff.
     *
     * @param  array<string, mixed>  $report
     * @return list<array{0: string, 1: list<string>, 2: list<list<string>>}>
     */
    private function cards(array $report): array
    {
        $counts = [];

        foreach (self::list($report['statuses'] ?? []) as $row) {
            $row = self::map($row);
            $counts[self::text($row['status'] ?? '')] = (int) self::text($row['bookings'] ?? 0);
        }

        $statuses = array_merge(self::ALWAYS, array_values(array_filter(self::WHEN_ANY, fn (string $status): bool => ($counts[$status] ?? 0) > 0)));
        $revenue = self::map($report['revenue'] ?? []);
        $noShow = self::map($report['no_show'] ?? []);
        $noShows = (int) self::text($noShow['no_show'] ?? 0);
        $completed = (int) self::text($noShow['completed'] ?? 0);
        $cancelled = self::map($report['cancelled_by'] ?? []);
        $unrecorded = (int) self::text($cancelled['unrecorded'] ?? 0);

        return [
            [
                self::word('tables.statuses'),
                [self::word('csv.status'), self::word('tables.bookings')],
                array_map(fn (string $status): array => [self::shell('kit.statuses.'.$status, $status), $this->count($counts[$status] ?? 0)], $statuses),
            ],
            [
                self::word('tables.revenue'),
                [self::word('csv.status'), $this->inCurrency(self::word('tables.revenue_head'))],
                [
                    [self::word('tables.confirmed'), $this->amount(self::list($revenue['confirmed'] ?? []))],
                    [self::word('tables.completed'), $this->amount(self::list($revenue['completed'] ?? []))],
                ],
            ],
            [
                self::word('tables.no_show_rate'),
                [self::word('tables.no_show_rate'), self::word('tables.no_shows'), self::word('tables.completed')],
                [[$noShows + $completed === 0 ? self::word('tables.no_completed') : $this->percent($noShows / ($noShows + $completed) * 100, 0), $this->count($noShows), $this->count($completed)]],
            ],
            [
                self::word('tables.cancelled_by'),
                [self::word('tables.cancelled_by'), self::word('tables.bookings')],
                [
                    [self::word('tables.by_customer'), $this->count($cancelled['customer'] ?? 0)],
                    [self::word('tables.by_business'), $this->count($cancelled['business'] ?? 0)],
                    ...($unrecorded > 0 ? [[self::word('tables.unrecorded'), $this->count($unrecorded)]] : []),
                ],
            ],
            [
                self::word('occupancy.title'),
                [self::word('tables.staff'), self::word('occupancy.booked'), self::word('occupancy.available'), self::word('tiles.occupancy')],
                array_map(function (mixed $row): array {
                    $row = self::map($row);
                    $rate = $row['occupancy'] ?? null;

                    return [
                        self::text($row['name'] ?? ''),
                        $this->hours((int) self::text($row['booked_minutes'] ?? 0)),
                        $this->hours((int) self::text($row['available_minutes'] ?? 0)),
                        is_numeric($rate) ? $this->percent((float) $rate, 1) : self::word('occupancy.no_hours'),
                    ];
                }, self::list($report['occupancy'] ?? [])),
            ],
        ];
    }

    /**
     * By service, by staff, by source and by channel.
     *
     * @param  array<string, mixed>  $report
     * @return list<array{0: string, 1: list<string>, 2: list<list<string>>}>
     */
    private function breakdowns(array $report): array
    {
        $table = fn (string $title, string $head, string $key, callable $label): array => [
            self::word($title),
            [self::word($head), self::word('tables.bookings'), $this->inCurrency(self::word('tables.revenue_head'))],
            array_map(function (mixed $row) use ($label): array {
                $row = self::map($row);

                return [self::text($label($row)), $this->count($row['bookings'] ?? 0), $this->amount(self::list($row['revenue'] ?? []))];
            }, self::list($report[$key] ?? [])),
        ];

        return [
            $table('tables.by_service', 'tables.service', 'services', fn (array $row): string => self::text($row['name'] ?? '')),
            $table('tables.by_staff', 'tables.staff', 'staff', fn (array $row): string => self::text($row['name'] ?? '')),
            $table('tables.by_source', 'tables.source', 'sources', fn (array $row): string => self::shell('shared.sources.'.self::text($row['source'] ?? ''), self::text($row['source'] ?? ''))),
            $table('tables.by_channel', 'tables.channel', 'channels', fn (array $row): string => ($row['channel'] ?? null) === null ? self::word('tables.no_channel') : self::shell('shared.channels.'.self::text($row['channel']), self::text($row['channel']))),
        ];
    }

    /** A column's name with the tenant's currency beside it. */
    private function inCurrency(string $column): string
    {
        return self::word('csv.in_currency', ['column' => $column, 'currency' => $this->currency]);
    }

    /**
     * Money lines as decimals in the request's language at exactly each currency's digits, the
     * tenant's currency bare and first, any other followed by its code.
     *
     * @param  list<mixed>  $lines
     */
    private function amount(array $lines): string
    {
        $mine = [];
        $others = [];

        foreach ($lines as $line) {
            $line = self::map($line);
            $code = self::text($line['currency'] ?? $this->currency);
            $decimal = $this->decimal(new Money((int) self::text($line['minor'] ?? 0), Currency::of($code)));

            if ($code === $this->currency) {
                $mine[] = $decimal;
            } else {
                $others[] = $decimal.' '.$code;
            }
        }

        return implode('; ', [...$mine, ...$others]);
    }

    /**
     * An amount's decimal in the request's language, ICU's decimal formatter at exactly the
     * currency's digits.
     */
    private function decimal(Money $money): string
    {
        $digits = $money->currency->digits();
        $this->decimals->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $digits);
        $this->decimals->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $digits);
        $text = $this->decimals->format((float) $money->decimal());

        return is_string($text) ? $text : $money->decimal();
    }

    /** A count in the request's language by CsvDownload::counts, the Customers file's rule too. */
    private function count(mixed $value): string
    {
        return ($this->counts)((int) self::text($value));
    }

    /**
     * A change's percentage as the screen writes it in the language: signed always, a fall to
     * nought minus nought. The skeleton's long form, which every ICU reads, not %x100.
     */
    private function signed(float $percent): string
    {
        $text = (new MessageFormatter($this->locale, '{0, number, ::percent scale/100 sign-always}'))->format([$percent / 100]);

        return is_string($text) ? $text : (string) $percent;
    }

    /** Minutes as the tiles write them: whole hours, then the minutes where there are any. */
    private function hours(int $minutes): string
    {
        $hours = $this->unit(intdiv($minutes, 60), 'hour');
        $rest = $minutes % 60;

        return $rest === 0 ? $hours : self::word('tiles.hours_minutes', ['hours' => $hours, 'minutes' => $this->unit($rest, 'minute')]);
    }

    /**
     * A figure in a unit as the screen's unitFigure writes it, by its one rule: ICU's long width
     * (the skeleton's full-name) where the language is Greek, the narrow width in English.
     */
    private function unit(int $value, string $unit): string
    {
        $width = $this->locale === SiteLanguage::Greek->value ? 'full-name' : 'narrow';
        $formatter = new MessageFormatter($this->locale, '{0, number, ::measure-unit/duration-'.$unit.' unit-width-'.$width.'}');
        $text = $formatter->format([$value]);

        return is_string($text) ? $text : (string) $value;
    }

    /** A percentage given in hundredths of one, at the decimals given at most, the occupancy's at least too. */
    private function percent(float $value, int $digits): string
    {
        $formatter = new NumberFormatter($this->locale, NumberFormatter::PERCENT);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $digits === 0 ? 1 : $digits);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $digits);
        $text = $formatter->format($value / 100);

        return is_string($text) ? $text : (string) $value;
    }

    /**
     * A change against the period before in the screen's sentence for its kind of range, a custom
     * range's chosen by its days; nothing to compare reads as no change; a rise or a fall one
     * sentence whose :change is the signed percentage, as the screen writes it.
     *
     * @param  array<string, mixed>  $change
     */
    private function change(array $change, string $word, string $from, string $to): string
    {
        $kind = self::text($change['kind'] ?? 'none');
        $percent = (float) abs((int) self::text($change['percent'] ?? 0));
        $values = match ($kind) {
            'up' => ['change' => $this->signed($percent)],
            'down' => ['change' => $this->signed(-$percent)],
            default => [],
        };
        $kind = match ($kind) {
            'up', 'down' => 'by',
            'none' => 'same',
            default => $kind,
        };

        if ($word === 'custom') {
            $utc = new DateTimeZone('UTC');
            $days = (int) (new DateTimeImmutable($from, $utc))->diff(new DateTimeImmutable($to, $utc))->days + 1;

            return trans_choice('admin/reports.compare.custom.'.$kind, $days, [...$values, 'count' => $this->count($days)]);
        }

        return self::word('compare.'.(self::COMPARED[$word] ?? 'week').'.'.$kind, $values);
    }

    /**
     * The name of the row a filter chose, or null where it chose none.
     *
     * @param  list<array{id: int, name: string}>  $choices
     */
    private static function chosen(array $choices, ?int $id): ?string
    {
        foreach ($choices as $choice) {
            if ($choice['id'] === $id) {
                return $choice['name'];
            }
        }

        return null;
    }

    /**
     * A Y-m-d date in the tenant's date format, as every tenant screen writes it; anything else as
     * it came.
     */
    private function date(string $day): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));

        return $date === false ? $day : $date->format($this->dateFormat);
    }

    /**
     * A key of the reports section in the request's language.
     *
     * @param  array<string, string>  $replace
     */
    private static function word(string $key, array $replace = []): string
    {
        return self::text(__('admin/reports.'.$key, $replace));
    }

    /** A key of the shell's section, or the value itself where the shell has no word for it. */
    private static function shell(string $key, string $fallback): string
    {
        $text = __('admin/shell.'.$key);

        return is_string($text) && $text !== 'admin/shell.'.$key ? $text : $fallback;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
