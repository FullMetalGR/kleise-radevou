<?php

declare(strict_types=1);

namespace App\Site;

use App\Application\Clock;
use App\Application\Notification\MessageKey;
use App\Domain\Audit\Actor;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditKey;
use App\Domain\Availability\AvailabilityRule;
use App\Domain\Availability\CalendarDay;
use App\Domain\Availability\ShiftOverride;
use App\Domain\Availability\ShiftPattern;
use App\Domain\Availability\ShiftResolver;
use App\Domain\Availability\WallClockTime;
use App\Domain\Booking\ManageToken;
use App\Domain\Enum\BookingSource;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Infrastructure\Db\SqlText;
use App\Infrastructure\Db\TextArray;
use App\Infrastructure\Db\WeeklyPattern;
use App\Tenancy\Role;
use App\Tenancy\Tenant;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use LogicException;

/**
 * The demo tenant's seed: a hair salon in Athens as a visitor meets it on demo.{central host},
 * written into the fresh schema demo:reset has just built, inside the build's own transaction, so
 * nothing ever serves it half seeded.
 *
 * Four stylists with weekly hours beside the implicit member provisioning made, which keeps its
 * default week and performs nothing, since the public catalog never lists it, and in the sample
 * week, the one holding the reset's day in the tenant's week start, every state Shifts draws: one
 * stylist repeating every two weeks from that week's first day, one day of another changed for that
 * day only and a third stylist's day off; six services with durations and prices, each offered by
 * the stylists who do them; and a week of bookings each way from the reset's day, read off the
 * clock in the tenant's zone: past days' visits completed and one of them a no-show, today's and
 * the coming days' confirmed, and one request awaiting approval on a live hold. The week is a
 * template by weekday, and a visit is written only where its stylist's hours on that date, as
 * ShiftResolver answers them, hold it whole, so no visit sits outside the hours whatever date the
 * reset falls on (the fortnightly stylist's off week and the day off keep none), and every open day
 * keeps free starts. The customers are sample names on a reserved domain, three of them with a
 * profile of tags.
 *
 * The rows are written straight, in the shape the transitions write them, since a past visit
 * cannot be made through the hold path on today's clock: the booking row with its items, the
 * blocking flag cleared where the status releases the slot, and a trail per booking as
 * HoldBooking, ConfirmBooking and MarkBookingOutcome would have left it, each row dated when
 * it would have happened and its payload built through AuditEntry, so the trail carries only
 * the declared keys and never a person. Nothing here publishes an event, queues a job or
 * touches a seam, and every email switch of the tenant is off besides. The settings row names
 * the salon's address and no coordinate, so the map's provider is never asked, and no payment
 * connection is written.
 *
 * Every staff-day of the booking horizon gets its mutex row, keyed by the reset's instant.
 * The mask cache keys an entry by the schema, the staff member, the day and the row's revision,
 * and the deployment's cache outlives the schema: a fresh schema numbers its staff from one
 * again and the same days come round, so a revision no earlier build reached is what keeps
 * yesterday's masks from answering for today's seed.
 */
final readonly class DemoSalon
{
    public const string NAME = 'Kallisti Hair Studio';

    public const string ZONE = 'Europe/Athens';

    public const string CURRENCY = 'EUR';

    public const string OWNER_NAME = 'Eleni Papadopoulou';

    /** The owner's address on a reserved domain, which no mail reaches and nobody signs in under. */
    public const string OWNER_EMAIL = 'owner@demo.example';

    /** How many days each way from the reset's day the week of bookings covers. */
    public const int WEEK_EACH_WAY = 6;

    /** The staff-days that get a mutex row: a week behind the day and the services' horizon ahead. */
    public const int MUTEX_DAYS_BEHIND = 7;

    public const int MUTEX_DAYS_AHEAD = 62;

    /** The one service that requires approval, whose request is the week's pending one. */
    public const string APPROVAL_SERVICE = 'Bridal hair';

    public const string APPROVAL_STYLIST = 'Eleni Papadopoulou';

    public const string APPROVAL_START = '13:00';

    /** The request was made this long before the reset, and its hold runs the settings' default 48 hours from then. */
    private const string REQUEST_AGE = 'PT2H';

    private const string APPROVAL_TTL = 'PT48H';

    /** A checkout hold's length, the settings' default, for the hold row of each trail. */
    private const string CHECKOUT_TTL = 'PT15M';

    /** A visit was booked three days before it, or two hours before the reset where that is sooner. */
    private const string BOOKED_AHEAD = 'P3D';

    private const string BOOKED_BEFORE_NOW = 'PT2H';

    /** The customer confirmed a minute and a half after the hold; the owner marked an outcome ten minutes after the end, a no-show twenty after the start. */
    private const string CONFIRM_LAG = 'PT90S';

    private const string OUTCOME_LAG = 'PT10M';

    private const string NO_SHOW_LAG = 'PT20M';

    /**
     * The stylists in sort order: a title, a bio, and the week as ISO weekday to [start, end].
     *
     * @var array<string, array{title: string, bio: string, hours: array<int, array{string, string}>}>
     */
    public const array STYLISTS = [
        'Eleni Papadopoulou' => [
            'title' => 'Owner and senior stylist',
            'bio' => 'Opened the studio in 2009 after fifteen years in salons across Athens. Precision cuts and colour.',
            'hours' => [2 => ['09:00', '17:00'], 3 => ['09:00', '15:00'], 4 => ['09:00', '17:00'], 5 => ['09:00', '19:00'], 6 => ['09:00', '15:00']],
        ],
        'Giorgos Antoniou' => [
            'title' => 'Barber',
            'bio' => 'Classic and modern cuts for men, beard shaping and hot-towel shaves.',
            'hours' => [1 => ['10:00', '18:00'], 2 => ['10:00', '18:00'], 4 => ['12:00', '20:00'], 5 => ['12:00', '20:00'], 6 => ['09:00', '15:00']],
        ],
        'Maria Konstantinou' => [
            'title' => 'Colourist',
            'bio' => 'Balayage, highlights and colour corrections; trained in Thessaloniki and Milan.',
            'hours' => [1 => ['09:00', '15:00'], 2 => ['13:00', '21:00'], 3 => ['09:00', '15:00'], 4 => ['13:00', '21:00'], 5 => ['13:00', '21:00']],
        ],
        'Katerina Nikolaou' => [
            'title' => 'Stylist',
            'bio' => 'Cuts and blow-dries for every hair type, and the fastest hands in the studio.',
            'hours' => [1 => ['09:00', '17:00'], 3 => ['09:00', '17:00'], 4 => ['09:00', '17:00'], 5 => ['09:00', '17:00'], 6 => ['09:00', '15:00']],
        ],
    ];

    /** The stylist whose pattern repeats every two weeks from the sample week's first day. */
    public const string FORTNIGHTLY = 'Maria Konstantinou';

    public const int FORTNIGHTLY_CADENCE = 2;

    /**
     * The changed day: a stylist, the ISO weekday of the sample week, and that date's windows in
     * place of the pattern's, written as the date's override ("This day only").
     *
     * @var array{string, int, list<array{string, string}>}
     */
    public const array CHANGED_DAY = ['Eleni Papadopoulou', 3, [['09:00', '13:00'], ['14:00', '18:00']]];

    /**
     * The day off: a stylist and the ISO weekday of the sample week they work by their pattern
     * and do not that date, an override with no window ("Not working").
     *
     * @var array{string, int}
     */
    public const array DAY_OFF = ['Giorgos Antoniou', 4];

    /**
     * The services in sort order, each with the stylists who do it in their sort order. Every
     * duration sits on the quarter hour, the tenant's start interval, and is paid on site: the
     * demo connects no payment provider.
     *
     * @var array<string, array{category: string, description: string, duration_min: int, price_minor: int, requires_approval: bool, staff: list<string>}>
     */
    public const array SERVICES = [
        'Women\'s haircut' => ['category' => 'Cuts', 'description' => 'Consultation, wash, cut and blow-dry finish.', 'duration_min' => 45, 'price_minor' => 2500, 'requires_approval' => false, 'staff' => ['Eleni Papadopoulou', 'Katerina Nikolaou']],
        'Men\'s haircut' => ['category' => 'Cuts', 'description' => 'Scissor or clipper cut, washed and styled.', 'duration_min' => 30, 'price_minor' => 1500, 'requires_approval' => false, 'staff' => ['Giorgos Antoniou', 'Katerina Nikolaou']],
        'Blow-dry' => ['category' => 'Styling', 'description' => 'Wash and blow-dry, straight or with waves.', 'duration_min' => 30, 'price_minor' => 1500, 'requires_approval' => false, 'staff' => ['Eleni Papadopoulou', 'Katerina Nikolaou']],
        'Root colour' => ['category' => 'Colour', 'description' => 'Regrowth colour on the roots, with a gloss to finish.', 'duration_min' => 90, 'price_minor' => 4500, 'requires_approval' => false, 'staff' => ['Eleni Papadopoulou', 'Maria Konstantinou']],
        'Highlights' => ['category' => 'Colour', 'description' => 'Foil highlights or balayage, toned and blow-dried.', 'duration_min' => 120, 'price_minor' => 7000, 'requires_approval' => false, 'staff' => ['Maria Konstantinou']],
        'Bridal hair' => ['category' => 'Styling', 'description' => 'A trial run of the wedding-day style. We confirm each request by hand.', 'duration_min' => 90, 'price_minor' => 6000, 'requires_approval' => true, 'staff' => ['Eleni Papadopoulou']],
    ];

    /**
     * The sample customers, names on a reserved domain.
     *
     * @var list<array{string, string}>
     */
    public const array CUSTOMERS = [
        ['Anna Georgiou', 'anna.georgiou@example.com'],
        ['Dimitris Alexiou', 'dimitris.alexiou@example.com'],
        ['Sofia Ioannou', 'sofia.ioannou@example.com'],
        ['Petros Vasileiou', 'petros.vasileiou@example.com'],
        ['Ioanna Lambrou', 'ioanna.lambrou@example.com'],
        ['Christos Pappas', 'christos.pappas@example.com'],
        ['Despina Triantafyllou', 'despina.triantafyllou@example.com'],
        ['Alexandros Karras', 'alexandros.karras@example.com'],
        ['Vasiliki Oikonomou', 'vasiliki.oikonomou@example.com'],
        ['Nikos Makris', 'nikos.makris@example.com'],
    ];

    /**
     * The one sample customer: the details every booking a visitor makes on the demo is
     * written under, in place of whatever they typed, and the ones the demo's hosted page
     * starts its details step filled with. Not one of the ten above, so a visitor's bookings
     * stand apart from the week's and the prefill reads as a sample rather than as a person;
     * the week never uses it.
     *
     * @var array{name: string, email: string, phone: string}
     */
    public const array SAMPLE_CUSTOMER = ['name' => 'Demo Visitor', 'email' => 'visitor@example.com', 'phone' => '+30 210 000 0000'];

    /**
     * The sample customers' profiles: tags on three, a birthday and a language on one, so the
     * Customers list and a customer's page show what the Profile card keeps. Each address is one
     * the week books; BLOCKED blocks one of them and NOTES writes notes on another.
     *
     * @var array<string, array{tags: list<string>, birthday: string|null, language: string|null}>
     */
    public const array PROFILES = [
        'anna.georgiou@example.com' => ['tags' => ['VIP', 'colour'], 'birthday' => '1988-04-12', 'language' => 'el'],
        'christos.pappas@example.com' => ['tags' => ['regular'], 'birthday' => null, 'language' => null],
        'sofia.ioannou@example.com' => ['tags' => ['colour'], 'birthday' => null, 'language' => null],
    ];

    /**
     * The one sample customer blocked from booking online, with the staff's reason, so the
     * list's Blocked chip and the Online booking card's blocked state show.
     *
     * @var array<string, string>
     */
    public const array BLOCKED = ['christos.pappas@example.com' => 'Missed three visits without a word.'];

    /**
     * The owner's notes on one sample customer, each with how many hours before the reset
     * it was written, newest first as the Notes card lists them.
     *
     * @var array<string, list<array{string, int}>>
     */
    public const array NOTES = [
        'anna.georgiou@example.com' => [
            ['Prefers Maria for colour. Sensitive scalp, use the gentle line.', 26],
            ['Asked for a quote on a bridal package for May.', 340],
        ],
    ];

    /** The customer whose request awaits approval. */
    private const int REQUESTING_CUSTOMER = 6;

    /**
     * The week's visits by ISO weekday: the stylist, the start on the tenant's wall clock, the
     * service, the customer and the surface the booking came through. Each sits inside its
     * stylist's hours for that weekday and clear of the stylist's other visits and of the
     * pending request's hour, and every open day is left with free starts; Sunday is closed.
     *
     * @var array<int, list<array{string, string, string, int, BookingSource}>>
     */
    public const array WEEK = [
        1 => [
            ['Giorgos Antoniou', '10:00', 'Men\'s haircut', 0, BookingSource::Hosted],
            ['Maria Konstantinou', '09:30', 'Root colour', 1, BookingSource::Hosted],
            ['Katerina Nikolaou', '11:00', 'Women\'s haircut', 2, BookingSource::Admin],
            ['Katerina Nikolaou', '15:00', 'Blow-dry', 3, BookingSource::Hosted],
        ],
        2 => [
            ['Eleni Papadopoulou', '09:00', 'Women\'s haircut', 4, BookingSource::Hosted],
            ['Giorgos Antoniou', '11:00', 'Men\'s haircut', 5, BookingSource::Hosted],
            ['Maria Konstantinou', '14:00', 'Highlights', 6, BookingSource::Hosted],
        ],
        3 => [
            ['Eleni Papadopoulou', '10:00', 'Root colour', 7, BookingSource::Admin],
            ['Maria Konstantinou', '09:00', 'Highlights', 8, BookingSource::Hosted],
            ['Katerina Nikolaou', '12:00', 'Women\'s haircut', 9, BookingSource::Hosted],
        ],
        4 => [
            ['Eleni Papadopoulou', '09:30', 'Women\'s haircut', 1, BookingSource::Hosted],
            ['Giorgos Antoniou', '13:00', 'Men\'s haircut', 3, BookingSource::Hosted],
            ['Maria Konstantinou', '15:00', 'Root colour', 5, BookingSource::Admin],
            ['Katerina Nikolaou', '10:00', 'Blow-dry', 7, BookingSource::Hosted],
        ],
        5 => [
            ['Eleni Papadopoulou', '10:00', 'Women\'s haircut', 0, BookingSource::Hosted],
            ['Eleni Papadopoulou', '16:00', 'Blow-dry', 2, BookingSource::Hosted],
            ['Giorgos Antoniou', '12:30', 'Men\'s haircut', 4, BookingSource::Hosted],
            ['Maria Konstantinou', '13:30', 'Highlights', 6, BookingSource::Hosted],
            ['Katerina Nikolaou', '09:00', 'Women\'s haircut', 8, BookingSource::Admin],
        ],
        6 => [
            ['Eleni Papadopoulou', '09:00', 'Women\'s haircut', 9, BookingSource::Hosted],
            ['Giorgos Antoniou', '10:00', 'Men\'s haircut', 1, BookingSource::Hosted],
            ['Katerina Nikolaou', '11:30', 'Blow-dry', 3, BookingSource::Hosted],
        ],
    ];

    public function __construct(private Clock $clock) {}

    /**
     * Fills the schema just built for the tenant, on the connection of the transaction that
     * built it, and answers what it wrote.
     *
     * @return array{stylists: int, services: int, bookings: int}
     */
    public function plant(Tenant $tenant, Connection $db): array
    {
        $schema = $tenant->schema_name;
        $zone = new DateTimeZone(self::ZONE);
        $now = $this->clock->now();
        $today = $now->setTimezone($zone)->setTime(0, 0);
        $ownerId = $db->table('users')->where('tenant_id', $tenant->id)->where('role', Role::Owner->value)->value('id');

        if (! is_int($ownerId)) {
            throw new LogicException(sprintf('The demo tenant "%s" has no owner to mark its visits; the build writes one before the seed runs.', $tenant->slug));
        }

        $this->settings($db, $schema);
        $startOfWeek = $db->table($schema.'.settings')->where('id', 1)->value('start_of_week');

        if (! is_int($startOfWeek)) {
            throw new LogicException('The demo schema holds no settings row naming its week start.');
        }

        $weekStart = CalendarDay::weekStart($today->format('Y-m-d'), $startOfWeek);
        $shifts = self::shifts($weekStart, $startOfWeek, WeeklyPattern::fromTheStart($db, $schema.'.'));
        $staffIds = $this->stylists($db, $schema, $shifts);
        $services = $this->services($db, $schema, $staffIds);
        $bookings = $this->week($db, $schema, $today, $now, $ownerId, $staffIds, $services, $shifts, $startOfWeek);
        $this->mutexRows($db, $schema, $today, $now);
        $this->profiles($db, $schema, $now, $ownerId);

        return ['stylists' => count($staffIds), 'services' => count($services), 'bookings' => $bookings];
    }

    private function settings(Connection $db, string $schema): void
    {
        $db->table($schema.'.settings')->where('id', 1)->update([
            'emails_off' => TextArray::encode(array_map(fn (MessageKey $key): string => $key->value, MessageKey::cases())),
            'about' => 'A small salon in Pangrati, open since 2009: cuts, colour and styling by appointment, with a coffee while you wait.',
            'address_line1' => '12 Archimidous Street',
            'city' => 'Athens',
            'postal_code' => '11636',
            'country' => 'GR',
            'important_info' => 'This is a sample salon: every booking made here is reset each night.',
            'updated_at' => $db->raw('now()'),
        ]);
    }

    /**
     * Each stylist's hours as the engine holds them: the pattern, weekly from the one start every
     * weekly pattern has (the week holding 2000-01-01, WeeklyPattern::fromTheStart, given here),
     * but the fortnightly stylist's, which repeats every two weeks from the sample week's first
     * day; and the changed day's and the day off's overrides on their dates of the sample week.
     *
     * @return array<string, array{pattern: ShiftPattern, overrides: array<string, ShiftOverride>}> by name
     */
    private static function shifts(string $weekStart, int $startOfWeek, string $weeklyStart): array
    {
        $shifts = [];

        foreach (self::STYLISTS as $name => $stylist) {
            $rules = [];

            foreach ($stylist['hours'] as $weekday => [$start, $end]) {
                $rules[] = new AvailabilityRule($weekday, WallClockTime::of($start), WallClockTime::of($end));
            }

            $fortnightly = $name === self::FORTNIGHTLY;
            $shifts[$name] = [
                'pattern' => new ShiftPattern($fortnightly ? $weekStart : $weeklyStart, $fortnightly ? self::FORTNIGHTLY_CADENCE : 1, null, $rules),
                'overrides' => [],
            ];
        }

        [$changed, $changedWeekday, $windows] = self::CHANGED_DAY;
        $changedOn = self::dateOf($weekStart, $changedWeekday, $startOfWeek);
        $shifts[$changed]['overrides'][$changedOn] = ShiftOverride::of($changedOn, array_map(fn (array $window): array => [WallClockTime::of($window[0]), WallClockTime::of($window[1])], $windows));
        [$off, $offWeekday] = self::DAY_OFF;
        $offOn = self::dateOf($weekStart, $offWeekday, $startOfWeek);
        $shifts[$off]['overrides'][$offOn] = ShiftOverride::of($offOn, []);

        return $shifts;
    }

    /** The date of an ISO weekday in the week starting on the date given. */
    private static function dateOf(string $weekStart, int $weekday, int $startOfWeek): string
    {
        return CalendarDay::plusDays($weekStart, ($weekday - $startOfWeek + 7) % 7);
    }

    /**
     * Whether the stylist's windows on the visit's date, as ShiftResolver answers them, hold the
     * visit from its start to its end.
     *
     * @param  array<string, array{pattern: ShiftPattern, overrides: array<string, ShiftOverride>}>  $shifts
     */
    private static function covers(array $shifts, int $startOfWeek, string $stylist, DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        $hours = $shifts[$stylist] ?? throw new LogicException(sprintf('The roster names no stylist "%s".', $stylist));
        $from = WallClockTime::of($start->format('H:i'))->secondsIntoDay;
        $to = $end->format('Y-m-d') === $start->format('Y-m-d') ? WallClockTime::of($end->format('H:i'))->secondsIntoDay : 86400;

        foreach ((new ShiftResolver)->windowsOn($hours['pattern'], $hours['overrides'], $startOfWeek, $start->format('Y-m-d')) as $window) {
            if ($window->start->secondsIntoDay <= $from && $to <= $window->end->secondsIntoDay) {
                return true;
            }
        }

        return false;
    }

    /**
     * Each stylist with their week as their shift pattern, and the sample week's two overrides.
     *
     * @param  array<string, array{pattern: ShiftPattern, overrides: array<string, ShiftOverride>}>  $shifts
     * @return array<string, int> each stylist's staff id by name, in sort order
     */
    private function stylists(Connection $db, string $schema, array $shifts): array
    {
        $ids = [];
        $sort = 0;

        foreach (self::STYLISTS as $name => $stylist) {
            $id = $db->table($schema.'.staff')->insertGetId([
                'name' => $name,
                'status' => 'active',
                'sort' => ++$sort,
                'title' => $stylist['title'],
                'bio' => $stylist['bio'],
            ]);

            $windows = array_map(
                fn (int $weekday, array $window): array => ['weekday' => $weekday, 'start' => $window[0], 'end' => $window[1]],
                array_keys($stylist['hours']),
                array_values($stylist['hours']),
            );

            $hours = $shifts[$name] ?? throw new LogicException(sprintf('No hours were drawn for the stylist "%s".', $name));
            WeeklyPattern::put($db, $schema.'.', $id, $windows, $hours['pattern']->cadenceWeeks, $hours['pattern']->startsOn);

            foreach ($hours['overrides'] as $date => $override) {
                WeeklyPattern::override($db, $schema.'.', $id, $date, array_map(fn (AvailabilityRule $window): array => ['start' => $window->start->format(), 'end' => $window->end->format()], $override->windows));
            }

            $ids[$name] = $id;
        }

        return $ids;
    }

    /**
     * @param  array<string, int>  $staffIds
     * @return array<string, array{id: int, duration_min: int, price_minor: int, requires_approval: bool}> by name
     */
    private function services(Connection $db, string $schema, array $staffIds): array
    {
        $services = [];
        $sort = 0;

        foreach (self::SERVICES as $name => $service) {
            $id = $db->table($schema.'.services')->insertGetId([
                'name' => $name,
                'description' => $service['description'],
                'category' => $service['category'],
                'duration_min' => $service['duration_min'],
                'price_minor' => $service['price_minor'],
                'currency' => self::CURRENCY,
                'payment_mode' => 'onsite',
                'requires_approval' => $service['requires_approval'],
                'status' => 'active',
                'sort' => ++$sort,
            ]);

            foreach ($service['staff'] as $stylist) {
                $db->table($schema.'.service_staff')->insert(['service_id' => $id, 'staff_id' => $this->idOf($staffIds, $stylist)]);
            }

            $services[$name] = ['id' => $id, 'duration_min' => $service['duration_min'], 'price_minor' => $service['price_minor'], 'requires_approval' => $service['requires_approval']];
        }

        return $services;
    }

    /**
     * The week each way from today, by the template, each visit kept where its stylist's hours on
     * that date hold it whole, and the pending request after it. The no-show is the first visit
     * kept on the most recent past day keeping one.
     *
     * @param  array<string, int>  $staffIds
     * @param  array<string, array{id: int, duration_min: int, price_minor: int, requires_approval: bool}>  $services
     * @param  array<string, array{pattern: ShiftPattern, overrides: array<string, ShiftOverride>}>  $shifts
     * @return int how many bookings were written
     */
    private function week(Connection $db, string $schema, DateTimeImmutable $today, DateTimeImmutable $now, int $ownerId, array $staffIds, array $services, array $shifts, int $startOfWeek): int
    {
        $kept = [];

        for ($offset = -self::WEEK_EACH_WAY; $offset <= self::WEEK_EACH_WAY; $offset++) {
            $day = self::dayOn($today, $offset);

            foreach (self::WEEK[(int) $day->format('N')] ?? [] as [$stylist, $time, $service, $customer, $source]) {
                [$hour, $minute] = array_map(intval(...), explode(':', $time));
                $start = $day->setTime($hour, $minute);
                $booked = $this->serviceOf($services, $service);

                if (self::covers($shifts, $startOfWeek, $stylist, $start, $start->add(new DateInterval(sprintf('PT%dM', $booked['duration_min']))))) {
                    $kept[] = ['offset' => $offset, 'stylist' => $stylist, 'start' => $start, 'service' => $booked, 'customer' => $customer, 'source' => $source];
                }
            }
        }

        $past = array_values(array_filter($kept, fn (array $visit): bool => $visit['offset'] < 0));
        $lastPast = $past === [] ? null : max(array_column($past, 'offset'));
        $noShow = null;

        foreach ($kept as $index => $visit) {
            if ($visit['offset'] === $lastPast) {
                $noShow = $index;

                break;
            }
        }

        if ($noShow === null) {
            throw new LogicException('The week behind the day holds no visit to miss.');
        }

        foreach ($kept as $index => $visit) {
            $status = match (true) {
                $index === $noShow => BookingStatus::NoShow,
                $visit['offset'] < 0 => BookingStatus::Completed,
                default => BookingStatus::Confirmed,
            };

            $this->visit($db, $schema, $now, $ownerId, $status, $visit['source'], $this->idOf($staffIds, $visit['stylist']), $visit['service'], $visit['start'], self::customer($visit['customer']));
        }

        $this->request($db, $schema, $today, $now, $this->idOf($staffIds, self::APPROVAL_STYLIST), $this->serviceOf($services, self::APPROVAL_SERVICE), $shifts, $startOfWeek);

        return count($kept) + 1;
    }

    /**
     * One visit, in the state its day puts it in, with the trail the transitions would have left:
     * a hold and its confirmation by the customer for a booking taken on the hosted page, the
     * confirmation alone by the owner for one made by hand, and the outcome by the owner once
     * the visit's day has passed.
     *
     * @param  array{id: int, duration_min: int, price_minor: int, requires_approval: bool}  $service
     * @param  array{string, string}  $customer
     */
    private function visit(Connection $db, string $schema, DateTimeImmutable $now, int $ownerId, BookingStatus $status, BookingSource $source, int $staffId, array $service, DateTimeImmutable $start, array $customer): void
    {
        $end = $start->add(new DateInterval(sprintf('PT%dM', $service['duration_min'])));
        $bookedAt = min($start->sub(new DateInterval(self::BOOKED_AHEAD)), $now->sub(new DateInterval(self::BOOKED_BEFORE_NOW)));
        $uuid = (string) Str::uuid();
        $trail = [];

        if ($source === BookingSource::Admin) {
            $confirmedAt = $bookedAt;
            $trail[] = [$confirmedAt, new AuditEntry($uuid, AuditAction::Confirmed, Actor::user($ownerId), [
                AuditKey::StatusTo->value => BookingStatus::Confirmed,
                AuditKey::ItemCount->value => 1,
            ])];
        } else {
            $confirmedAt = $bookedAt->add(new DateInterval(self::CONFIRM_LAG));
            $trail[] = [$bookedAt, new AuditEntry($uuid, AuditAction::Held, Actor::customer(), [
                AuditKey::StatusTo->value => BookingStatus::Pending,
                AuditKey::HoldClass->value => HoldClass::Checkout,
                AuditKey::HoldExpiresAt->value => $bookedAt->add(new DateInterval(self::CHECKOUT_TTL)),
                AuditKey::ItemCount->value => 1,
            ])];
            $trail[] = [$confirmedAt, new AuditEntry($uuid, AuditAction::Confirmed, Actor::customer(), [
                AuditKey::StatusFrom->value => BookingStatus::Pending,
                AuditKey::StatusTo->value => BookingStatus::Confirmed,
                AuditKey::HoldClass->value => HoldClass::Checkout,
            ])];
        }

        $settledAt = match ($status) {
            BookingStatus::Completed => $end->add(new DateInterval(self::OUTCOME_LAG)),
            BookingStatus::NoShow => $start->add(new DateInterval(self::NO_SHOW_LAG)),
            default => $confirmedAt,
        };

        if ($status !== BookingStatus::Confirmed) {
            $trail[] = [$settledAt, new AuditEntry($uuid, $status === BookingStatus::NoShow ? AuditAction::NoShow : AuditAction::Completed, Actor::user($ownerId), [
                AuditKey::StatusFrom->value => BookingStatus::Confirmed,
                AuditKey::StatusTo->value => $status,
            ])];
        }

        $this->rows($db, $schema, $uuid, $status, null, null, $service, $staffId, $start, $end, $customer, $source, $bookedAt, $settledAt, $trail);
    }

    /**
     * The request awaiting approval: the first day two or more on from today whose hours, as
     * ShiftResolver answers them, hold it whole, at the hour the template keeps clear, made two
     * hours before the reset on a hold that runs the settings' default from then, so it is live
     * for the sweeper and the owner alike.
     *
     * @param  array{id: int, duration_min: int, price_minor: int, requires_approval: bool}  $service
     * @param  array<string, array{pattern: ShiftPattern, overrides: array<string, ShiftOverride>}>  $shifts
     */
    private function request(Connection $db, string $schema, DateTimeImmutable $today, DateTimeImmutable $now, int $staffId, array $service, array $shifts, int $startOfWeek): void
    {
        [$hour, $minute] = array_map(intval(...), explode(':', self::APPROVAL_START));

        for ($offset = 2; $offset <= self::WEEK_EACH_WAY + 1; $offset++) {
            $start = self::dayOn($today, $offset)->setTime($hour, $minute);
            $end = $start->add(new DateInterval(sprintf('PT%dM', $service['duration_min'])));

            if (! self::covers($shifts, $startOfWeek, self::APPROVAL_STYLIST, $start, $end)) {
                continue;
            }
            $heldAt = $now->sub(new DateInterval(self::REQUEST_AGE));
            $expiresAt = $heldAt->add(new DateInterval(self::APPROVAL_TTL));
            $uuid = (string) Str::uuid();
            $trail = [[$heldAt, new AuditEntry($uuid, AuditAction::Held, Actor::customer(), [
                AuditKey::StatusTo->value => BookingStatus::AwaitingApproval,
                AuditKey::HoldClass->value => HoldClass::Approval,
                AuditKey::HoldExpiresAt->value => $expiresAt,
                AuditKey::ItemCount->value => 1,
            ])]];

            $this->rows($db, $schema, $uuid, BookingStatus::AwaitingApproval, HoldClass::Approval, $expiresAt, $service, $staffId, $start, $end, self::customer(self::REQUESTING_CUSTOMER), BookingSource::Hosted, $heldAt, $heldAt, $trail);

            return;
        }

        throw new LogicException('The approval stylist works no day of the coming week.');
    }

    /**
     * The booking row, its one item and its trail, every instant as UTC text with its offset.
     *
     * @param  array{id: int, duration_min: int, price_minor: int, requires_approval: bool}  $service
     * @param  array{string, string}  $customer
     * @param  list<array{DateTimeImmutable, AuditEntry}>  $trail  oldest first
     */
    private function rows(Connection $db, string $schema, string $uuid, BookingStatus $status, ?HoldClass $holdClass, ?DateTimeImmutable $holdExpiresAt, array $service, int $staffId, DateTimeImmutable $start, DateTimeImmutable $end, array $customer, BookingSource $source, DateTimeImmutable $createdAt, DateTimeImmutable $updatedAt, array $trail): void
    {
        $bookingId = $db->table($schema.'.bookings')->insertGetId([
            'uuid' => $uuid,
            'status' => $status->value,
            'hold_class' => $holdClass?->value,
            'hold_expires_at' => $holdExpiresAt === null ? null : SqlText::instant($holdExpiresAt),
            'customer_name' => $customer[0],
            'customer_email' => $customer[1],
            'total_minor' => $service['price_minor'],
            'currency' => self::CURRENCY,
            'payment_mode' => 'onsite',
            'requires_approval' => $service['requires_approval'],
            'on_approval_timeout' => 'expire',
            'manage_token_hash' => ManageToken::generate()->hash(),
            'source' => $source->value,
            'created_at' => SqlText::instant($createdAt),
            'updated_at' => SqlText::instant($updatedAt),
        ]);

        $db->table($schema.'.booking_items')->insert([
            'booking_id' => $bookingId,
            'sort' => 0,
            'service_id' => $service['id'],
            'staff_id' => $staffId,
            'start_utc' => SqlText::instant($start),
            'end_utc' => SqlText::instant($end),
            'block_start_utc' => SqlText::instant($start),
            'block_end_utc' => SqlText::instant($end),
            'price_minor' => $service['price_minor'],
            'blocks' => ! $status->releasesTheSlot(),
            'created_at' => SqlText::instant($createdAt),
            'updated_at' => SqlText::instant($updatedAt),
        ]);

        foreach ($trail as [$at, $entry]) {
            $db->insert(
                sprintf('insert into %s.audit_log (booking_id, actor, action, payload, created_at) values (?, ?, ?, ?::jsonb, ?::timestamptz)', $schema),
                [$bookingId, $entry->actor->value, $entry->action->value, json_encode($entry->payload, JSON_THROW_ON_ERROR), SqlText::instant($at)],
            );
        }
    }

    /**
     * A mutex row for every staff member and every day of the horizon, at a revision no earlier
     * build reached: the reset's instant in seconds.
     */
    private function mutexRows(Connection $db, string $schema, DateTimeImmutable $today, DateTimeImmutable $now): void
    {
        $rows = [];
        $revision = $now->getTimestamp();

        foreach ($db->table($schema.'.staff')->orderBy('id')->pluck('id') as $staffId) {
            for ($offset = -self::MUTEX_DAYS_BEHIND; $offset <= self::MUTEX_DAYS_AHEAD; $offset++) {
                $rows[] = ['staff_id' => $staffId, 'day' => self::dayOn($today, $offset)->format('Y-m-d'), 'rev' => $revision];
            }
        }

        $db->table($schema.'.staff_days')->insert($rows);
    }

    /**
     * Midnight the given number of whole calendar days from today, behind it where negative:
     * by the calendar and never by elapsed hours, since a clock-change day has twenty-three or
     * twenty-five of them.
     */
    private static function dayOn(DateTimeImmutable $today, int $offset): DateTimeImmutable
    {
        $span = new DateInterval(sprintf('P%dD', abs($offset)));

        return $offset < 0 ? $today->sub($span) : $today->add($span);
    }

    /**
     * The sample customers' profiles, stamped with the reset's instant, the block on one and the
     * owner's notes on another.
     */
    private function profiles(Connection $db, string $schema, DateTimeImmutable $now, int $ownerId): void
    {
        foreach (self::PROFILES as $email => $profile) {
            $db->table($schema.'.customer_profiles')->insert([
                'email' => $email,
                'tags' => TextArray::encode($profile['tags']),
                'birthday' => $profile['birthday'],
                'language' => $profile['language'],
                'created_at' => SqlText::instant($now),
                'updated_at' => SqlText::instant($now),
            ]);
        }

        foreach (self::BLOCKED as $email => $reason) {
            $db->table($schema.'.customer_profiles')->where('email', $email)->update(['blocked_at' => SqlText::instant($now), 'blocked_reason' => $reason]);
        }

        $owner = $db->table('users')->where('id', $ownerId)->value('name');

        if (! is_string($owner)) {
            throw new LogicException('The demo owner has no name to sign the notes with.');
        }

        foreach (self::NOTES as $email => $notes) {
            foreach (array_reverse($notes) as [$body, $hours]) {
                $db->table($schema.'.customer_notes')->insert([
                    'email' => $email,
                    'body' => $body,
                    'author_actor' => (string) Actor::user($ownerId),
                    'author_name' => $owner,
                    'created_at' => SqlText::instant($now->sub(new DateInterval(sprintf('PT%dH', $hours)))),
                ]);
            }
        }
    }

    /**
     * @return array{string, string} the customer's name and address
     */
    private static function customer(int $index): array
    {
        return self::CUSTOMERS[$index] ?? throw new LogicException(sprintf('The week names customer %d, which the list does not hold.', $index));
    }

    /**
     * @param  array<string, int>  $staffIds
     */
    private function idOf(array $staffIds, string $stylist): int
    {
        return $staffIds[$stylist] ?? throw new LogicException(sprintf('The roster names no stylist "%s".', $stylist));
    }

    /**
     * @param  array<string, array{id: int, duration_min: int, price_minor: int, requires_approval: bool}>  $services
     * @return array{id: int, duration_min: int, price_minor: int, requires_approval: bool}
     */
    private function serviceOf(array $services, string $name): array
    {
        return $services[$name] ?? throw new LogicException(sprintf('The catalog names no service "%s".', $name));
    }
}
