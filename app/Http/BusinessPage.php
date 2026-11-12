<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Clock;
use App\Application\Map\Coordinates;
use App\Billing\EntitlementResolver;
use App\Billing\Feature;
use App\Infrastructure\Db\CatalogReader;
use App\Infrastructure\Db\StaffRecords;
use App\Infrastructure\Storage\TenantImages;
use App\Site\SiteLanguage;
use App\Tenancy\MapState;
use App\Tenancy\Profile;
use App\Tenancy\Settings;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Translation\Translator;
use IntlDateFormatter;
use InvalidArgumentException;
use RuntimeException;

/**
 * The business page's share of the hosted page: the tenant's profile around the flow, the title
 * block and the photo grid above it and the rest below, and the LocalBusiness structured data,
 * all built here from one read of the profile, the hours and the catalogue, and rendered on GET /
 * alone. The manage page renders through the same view and carries none of it.
 *
 * The hours are the staff rows' weekly windows: the implicit member's where the staff feature is
 * absent or the roster holds no other active row, an all-inactive roster falling back to them as
 * the floor reads them, so the page carries the business's own hours wherever the implicit member
 * holds any, else the union per weekday of the active members' windows, merged where they overlap
 * or touch. A week holding no window renders no hours, no open state and no hours in the
 * structured data. They are wall times spelled with no conversion, in the tenant's time format
 * for the table and in 24-hour form for the structured data, so the two read one computation, the
 * title block's open state with them.
 *
 * A field left empty renders nothing. A country alone is no address, since every row defaults to
 * one: the address renders when a street line, a city or a postal code is set. The address block
 * prints the country's name from countries.php in the request's language (Profile::displayLines),
 * a code outside the file as the code it is; the maps search and the structured data keep its
 * code.
 *
 * Every word the page says is a key of pages.php read in the request's language: the view reads
 * the headings and the links through the translator, and this class the open state, the closed
 * day, Get directions and the contact pills' labels; the hours table's weekdays are ICU's names
 * in that language, while the structured data keeps schema.org's English day identifiers, which
 * no reader meets.
 *
 * The cover and the gallery are URLs on the tenant's own origin, TenantUrls::image(), the gallery
 * in the order the row stores it; the structured data names the cover as its image.
 *
 * The team, is the online, active, non-implicit members of the same read the hours come from, in
 * the roster's order, as the booking page's staff step lists them: each with their photo on the
 * tenant's origin or their initials where none, their name and their title. The bio is the staff
 * step's alone and never reaches this page.
 *
 * The map, is drawn where its state is ready and an address stands to hang it on: the stored
 * image on the tenant's origin, a link to directions by the stored point, which the Get
 * directions link then uses too, and the point as the structured data's geo. Any other state
 * leaves the address with its search link, whatever the row last held.
 *
 * The title block's meta line is the open state, the short address and Get directions, each part
 * only where it has something to say. The open state reads the same week the hours list spells
 * against the current instant, read through the Clock port and placed on the tenant's clock:
 * open until the end of today's window that holds the instant, else opening at the start of the
 * next window today, else closed today; a week with no window states none. The hours list marks
 * the tenant's today off the same instant.
 *
 * The photo grid is the cover and the first gallery photos, three cells at most, the first
 * gallery photo taking the cover's cell where no cover is set; the count of the whole gallery
 * rides beside it where the grid shows fewer photos than the gallery holds, for the link to the
 * full gallery further down.
 */
final readonly class BusinessPage
{
    /**
     * The page's sentences, each a key of lang/{en,el}/pages.php read through the translator in
     * the request's language: the view reads the headings, the links and the alternative texts,
     * and this class the open state, the closed day, Get directions and the contact pills'
     * labels.
     */
    public const string BOOK_NOW = 'pages.business.book_now';

    /** The header's way to My bookings, while the bookings link is on. */
    public const string MY_BOOKINGS = 'pages.business.my_bookings';

    public const string FLOW_TITLE = 'pages.business.flow_title';

    public const string HOURS = 'pages.business.hours';

    /** The heading over the address, the map and the contact pills. */
    public const string FIND_US = 'pages.business.find_us';

    public const string ABOUT = 'pages.business.about';

    /** The full gallery's heading, the section #photos anchors. */
    public const string PHOTOS = 'pages.business.photos';

    /** The grid's way to the full gallery, read as a choice by the gallery's count. */
    public const string SEE_ALL = 'pages.business.see_all';

    /**
     * The open state, from the tenant's week and the current instant on the tenant's clock, :time
     * its time in the tenant's format.
     */
    public const string OPEN_UNTIL = 'pages.business.open_until';

    public const string OPENS_AT = 'pages.business.opens_at';

    public const string CLOSED_TODAY = 'pages.business.closed_today';

    /** The footer's line naming the platform, :name its public name, config site.name. */
    public const string BOOKING_BY = 'pages.business.booking_by';

    public const string TEAM = 'pages.business.team';

    public const string CLOSED = 'pages.business.closed';

    public const string DIRECTIONS = 'pages.business.directions';

    public const string WEBSITE = 'pages.business.website';

    public const string INSTAGRAM = 'pages.business.instagram';

    public const string FACEBOOK = 'pages.business.facebook';

    public const string REVIEWS = 'pages.business.reviews';

    /** A gallery photo's alternative text: :n its place and :count the gallery's count. */
    public const string PHOTO = 'pages.business.photo';

    /** The map image's alternative text, the image being a link to directions. */
    public const string MAP = 'pages.business.map';

    /** A maps search by the address text, where no map is ready. */
    private const string MAPS_SEARCH = 'https://www.google.com/maps/search/?api=1&query=';

    /** Directions to the stored point, where the map is ready. */
    private const string MAPS_DIRECTIONS = 'https://www.google.com/maps/dir/?api=1&destination=';

    /**
     * The structured data's days, schema.org's identifiers by ISO weekday: names no reader meets,
     * so they stay English whatever the page's language.
     */
    private const array SCHEMA_DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    /** A Monday, from which the formatter is handed each weekday to name. */
    private const string A_MONDAY = '2024-01-01';

    /** ICU's pattern for a weekday's full name standing alone, its standalone form. */
    private const string WEEKDAY_ALONE = 'cccc';

    /** The photo grid's cells at most: the large one and the two stacked beside it. */
    private const int GRID_CELLS = 3;

    public function __construct(
        private StaffRecords $staff,
        private EntitlementResolver $entitlements,
        private CatalogReader $catalog,
        private TenantUrls $urls,
        private Clock $clock,
        private Translator $translator,
        private CountryNames $countries,
        #[Config('site.name')] private string $siteName,
    ) {}

    /**
     * What the view renders around the flow, and the structured data as the script element's text,
     * the words this class spells in the language given, the request's.
     *
     * @return array{meta: list<array{text: string, href: string|null, open: bool|null}>, grid: list<array{photo: string, place: int|null}>, more: int|null, gallery: list<string>, about: string|null, address: list<string>, map: string|null, directions: string|null, phone: string|null, tel: string|null, email: string|null, hours: list<array{day: string, times: string, open: bool, today: bool}>, team: list<array{name: string, title: string|null, photo: string|null, initials: string}>, links: list<array{label: string, href: string}>, site: string, jsonLd: string}
     */
    public function for(Tenant $tenant, Settings $settings, SiteLanguage $language): array
    {
        $profile = $settings->profile;
        $members = $this->staff->all($tenant);
        $now = $this->clock->now()->setTimezone($tenant->zone());
        $week = $this->week($tenant, $members, $now->format('Y-m-d'));
        $address = $profile->addressLines();
        $slug = Slug::of($tenant->slug);
        $cover = $settings->coverPath === null ? null : $this->urls->image($slug, TenantImages::fileOf($settings->coverPath));
        $gallery = array_map(fn (string $path): string => $this->urls->image($slug, TenantImages::fileOf($path)), $settings->galleryPaths);
        $mapPath = $settings->mapPath;
        $point = $address === [] || $mapPath === null ? null : self::readyPoint($settings);
        $map = $point === null ? null : $this->urls->image($slug, TenantImages::fileOf($mapPath));
        $directions = match (true) {
            $address === [] => null,
            $point !== null => self::MAPS_DIRECTIONS.$point->spelled(),
            default => self::MAPS_SEARCH.rawurlencode(implode(', ', $address)),
        };
        $grid = self::grid($cover, $gallery);
        $shown = count(array_filter($grid, fn (array $cell): bool => $cell['place'] !== null));

        return [
            'meta' => $this->meta($this->openState($week, $now, $settings, $language), $profile, $directions, $language),
            'grid' => $grid,
            'more' => count($gallery) > $shown ? count($gallery) : null,
            'gallery' => $gallery,
            'about' => $profile->about,
            'address' => $profile->displayLines($this->countries->in($language)),
            'map' => $map,
            'directions' => $directions,
            'phone' => $profile->phone,
            'tel' => $profile->phone === null ? null : 'tel:'.preg_replace('/[^0-9+]/', '', $profile->phone),
            'email' => $profile->publicEmail,
            'hours' => $this->hoursTable($week, $settings, (int) $now->format('N'), $language),
            'team' => $this->team($members, $slug),
            'links' => $this->links($profile, $language),
            'site' => $this->siteName,
            'jsonLd' => $this->jsonLd($tenant, $profile, $week, $cover, $point),
        ];
    }

    /**
     * The meta line's parts in order, each only where it has something to say: the open state
     * (open true or false), the short address of the first street line and the city, and Get
     * directions with the address block's own href.
     *
     * @param  array{text: string, open: bool}|null  $state
     * @return list<array{text: string, href: string|null, open: bool|null}>
     */
    private function meta(?array $state, Profile $profile, ?string $directions, SiteLanguage $language): array
    {
        $parts = [];

        if ($state !== null) {
            $parts[] = ['text' => $state['text'], 'href' => null, 'open' => $state['open']];
        }

        $place = implode(', ', array_filter([$profile->addressLine1, $profile->city], fn (?string $part): bool => $part !== null && $part !== ''));

        if ($place !== '') {
            $parts[] = ['text' => $place, 'href' => null, 'open' => null];
        }

        if ($directions !== null) {
            $parts[] = ['text' => $this->line(self::DIRECTIONS, $language), 'href' => $directions, 'open' => null];
        }

        return $parts;
    }

    /**
     * Where today stands against the week, on the tenant's clock: inside a window, open until its
     * end; before a window still to come today, opening at its start; else closed today. A week
     * with no window at all states nothing.
     *
     * @param  array<int, list<array{0: string, 1: string}>>  $week
     * @return array{text: string, open: bool}|null
     */
    private function openState(array $week, DateTimeImmutable $now, Settings $settings, SiteLanguage $language): ?array
    {
        if ($week === []) {
            return null;
        }

        $wall = $now->format('H:i');

        foreach ($week[(int) $now->format('N')] ?? [] as [$start, $end]) {
            if ($wall < $start) {
                return ['text' => $this->line(self::OPENS_AT, $language, ['time' => self::spell($start, $settings)]), 'open' => false];
            }

            if ($wall < $end) {
                return ['text' => $this->line(self::OPEN_UNTIL, $language, ['time' => self::spell($end, $settings)]), 'open' => true];
            }
        }

        return ['text' => $this->line(self::CLOSED_TODAY, $language), 'open' => false];
    }

    /**
     * The photo grid's cells: the cover where one is set, then the gallery's first photos, at most
     * three in all. A gallery cell carries its place in the gallery, for its alt; the cover
     * carries none, its alt being empty.
     *
     * @param  list<string>  $gallery
     * @return list<array{photo: string, place: int|null}>
     */
    private static function grid(?string $cover, array $gallery): array
    {
        $cells = $cover === null ? [] : [['photo' => $cover, 'place' => null]];

        foreach (array_slice($gallery, 0, self::GRID_CELLS - count($cells)) as $index => $photo) {
            $cells[] = ['photo' => $photo, 'place' => $index + 1];
        }

        return $cells;
    }

    /**
     * The online, active, non-implicit members in the roster's order, each with the photo's URL
     * or null, and the initials the page shows where no photo is.
     *
     * @param  list<array{name: string, status: string, online: bool, is_implicit: bool, title: string|null, photo_path: string|null}>  $members
     * @return list<array{name: string, title: string|null, photo: string|null, initials: string}>
     */
    private function team(array $members, Slug $slug): array
    {
        $team = [];

        foreach ($members as $member) {
            if ($member['online'] && $member['status'] === 'active' && ! $member['is_implicit']) {
                $team[] = [
                    'name' => $member['name'],
                    'title' => $member['title'],
                    'photo' => $member['photo_path'] === null ? null : $this->urls->image($slug, TenantImages::fileOf($member['photo_path'])),
                    'initials' => self::initials($member['name']),
                ];
            }
        }

        return $team;
    }

    /** The first letter of the first and of the last word of a name, upper-cased. */
    private static function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [''];
        $first = mb_substr($words[0], 0, 1);
        $last = count($words) > 1 ? mb_substr($words[count($words) - 1], 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    /**
     * The usual week by ISO weekday, each day's windows merged where they overlap or touch: the
     * windows of the implicit member's pattern on the floor and where no other member is active,
     * else the active members'. The overrides and the cadence are not drawn: the table is the week
     * a customer can expect, the times offered the resolver's. A pattern whose end is before the
     * tenant's today is left out, since the flow offers nothing from it from today on; one that
     * starts later is kept.
     *
     * @param  list<array{status: string, is_implicit: bool, pattern: array{ends_on: string|null, windows: list<array{weekday: int, start: string, end: string}>}|null}>  $members
     * @return array<int, list<array{0: string, 1: string}>>
     */
    private function week(Tenant $tenant, array $members, string $today): array
    {
        $active = array_filter($members, fn (array $member): bool => ! $member['is_implicit'] && $member['status'] === 'active');
        $floor = $active === [] || ! $this->entitlements->resolve($tenant)->allows(Feature::Staff);
        $windows = [];

        foreach ($members as $member) {
            $pattern = $member['pattern'];

            if ($pattern !== null && ($pattern['ends_on'] === null || $pattern['ends_on'] >= $today) && ($floor ? $member['is_implicit'] : $member['status'] === 'active')) {
                array_push($windows, ...$pattern['windows']);
            }
        }

        usort($windows, fn (array $a, array $b): int => [$a['weekday'], $a['start']] <=> [$b['weekday'], $b['start']]);
        $week = [];

        foreach ($windows as $window) {
            $day = $week[$window['weekday']] ?? [];
            $last = array_key_last($day);

            if ($last !== null && $window['start'] <= $day[$last][1]) {
                $day[$last][1] = max($day[$last][1], $window['end']);
            } else {
                $day[] = [$window['start'], $window['end']];
            }

            $week[$window['weekday']] = $day;
        }

        return $week;
    }

    /**
     * Seven rows from the tenant's first weekday, or none for a week with no window at all, each
     * saying whether the day has a window and whether it is the tenant's today. The day is named
     * by ICU in the language given, its standalone form, and spelled nowhere here.
     *
     * @param  array<int, list<array{0: string, 1: string}>>  $week
     * @return list<array{day: string, times: string, open: bool, today: bool}>
     */
    private function hoursTable(array $week, Settings $settings, int $today, SiteLanguage $language): array
    {
        if ($week === []) {
            return [];
        }

        $rows = [];
        $closed = $this->line(self::CLOSED, $language);

        for ($offset = 0; $offset < 7; $offset++) {
            $weekday = ($settings->startOfWeek - 1 + $offset) % 7 + 1;
            $windows = array_map(
                fn (array $window): string => self::spell($window[0], $settings).' - '.self::spell($window[1], $settings),
                $week[$weekday] ?? [],
            );
            $rows[] = [
                'day' => self::weekday($weekday, $language),
                'times' => $windows === [] ? $closed : implode(', ', $windows),
                'open' => $windows !== [],
                'today' => $weekday === $today,
            ];
        }

        return $rows;
    }

    /**
     * An ISO weekday's full name standing alone, as ICU names it in the language given: the day
     * of A_MONDAY's week with that number, read as a calendar date in UTC, so no zone moves it.
     */
    private static function weekday(int $weekday, SiteLanguage $language): string
    {
        $day = (new DateTimeImmutable(self::A_MONDAY, new DateTimeZone('UTC')))->add(new DateInterval(sprintf('P%dD', $weekday - 1)));
        $formatter = new IntlDateFormatter($language->value, IntlDateFormatter::NONE, IntlDateFormatter::NONE, new DateTimeZone('UTC'), IntlDateFormatter::GREGORIAN, self::WEEKDAY_ALONE);
        $name = $formatter->format($day);

        if (! is_string($name)) {
            throw new RuntimeException(sprintf('The formatter could not name weekday %d in %s: %s', $weekday, $language->value, $formatter->getErrorMessage()));
        }

        return $name;
    }

    /**
     * One line of pages.php in the language given, its placeholders filled; a key the file lacks
     * reads as the key, as the translator answers it.
     *
     * @param  array<string, string>  $replace
     */
    private function line(string $key, SiteLanguage $language, array $replace = []): string
    {
        $line = $this->translator->get($key, $replace, $language->value);

        return is_string($line) ? $line : $key;
    }

    /**
     * A wall time in the tenant's format. The zone is named only because a DateTimeImmutable
     * names one; UTC keeps the hour as written, with no conversion and no clock change.
     */
    private static function spell(string $time, Settings $settings): string
    {
        $wall = DateTimeImmutable::createFromFormat('!H:i', $time, new DateTimeZone('UTC'));

        return $wall === false ? $time : $wall->format($settings->timeFormat);
    }

    /** The stored point where the map is ready and whole, else null. */
    private static function readyPoint(Settings $settings): ?Coordinates
    {
        if ($settings->mapState !== MapState::Ready || $settings->lat === null || $settings->lng === null) {
            return null;
        }

        try {
            return new Coordinates($settings->lat, $settings->lng);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** @return list<array{label: string, href: string}> */
    private function links(Profile $profile, SiteLanguage $language): array
    {
        $links = [
            [self::WEBSITE, $profile->website],
            [self::INSTAGRAM, $profile->instagram],
            [self::FACEBOOK, $profile->facebook],
            [self::REVIEWS, $profile->googleReviewsUrl],
        ];

        return array_values(array_map(
            fn (array $link): array => ['label' => $this->line($link[0], $language), 'href' => (string) $link[1]],
            array_filter($links, fn (array $link): bool => $link[1] !== null),
        ));
    }

    /**
     * One LocalBusiness, JSON-encoded so that nothing in it can close the script element: every
     * angle bracket, ampersand and quote is written as a JSON escape.
     *
     * @param  array<int, list<array{0: string, 1: string}>>  $week
     */
    private function jsonLd(Tenant $tenant, Profile $profile, array $week, ?string $cover, ?Coordinates $point): string
    {
        $slug = Slug::of($tenant->slug);
        $data = ['@context' => 'https://schema.org', '@type' => 'LocalBusiness', 'name' => $tenant->name, 'url' => $this->urls->hostedPage($slug)];

        if ($cover !== null) {
            $data['image'] = $cover;
        }

        if ($profile->phone !== null) {
            $data['telephone'] = $profile->phone;
        }

        if ($profile->addressLine1 !== null || $profile->city !== null || $profile->postalCode !== null) {
            $street = implode(', ', array_filter([$profile->addressLine1, $profile->addressLine2], fn (?string $line): bool => $line !== null));
            $data['address'] = array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => $street === '' ? null : $street,
                'addressLocality' => $profile->city,
                'postalCode' => $profile->postalCode,
                'addressCountry' => $profile->country,
            ], fn (?string $value): bool => $value !== null);
        }

        if ($point !== null) {
            $data['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => $point->lat, 'longitude' => $point->lng];
        }

        $hours = [];

        foreach (self::SCHEMA_DAYS as $weekday => $day) {
            foreach ($week[$weekday] ?? [] as [$opens, $closes]) {
                $hours[] = ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'https://schema.org/'.$day, 'opens' => $opens, 'closes' => $closes];
            }
        }

        if ($hours !== []) {
            $data['openingHoursSpecification'] = $hours;
        }

        $sameAs = array_values(array_filter([$profile->website, $profile->instagram, $profile->facebook], fn (?string $url): bool => $url !== null));

        if ($sameAs !== []) {
            $data['sameAs'] = $sameAs;
        }

        $offers = [];

        foreach ($this->catalog->activeServices($tenant) as $service) {
            $offers[] = [
                '@type' => 'Offer',
                'name' => $service->name,
                'price' => $service->price->decimal(),
                'priceCurrency' => $service->price->currency->code,
                'url' => $this->urls->bookingLink($slug, [LinkKind::Service->value => $service->id]),
            ];
        }

        if ($offers !== []) {
            $data['makesOffer'] = $offers;
        }

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}
