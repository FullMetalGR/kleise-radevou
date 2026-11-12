<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Dto\BookingSnapshot;
use App\Application\Dto\Customer;
use App\Application\Notification\MessageKey;
use App\Domain\Enum\BookingChannel;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Storage\TenantImages;
use App\Site\SiteLanguage;
use App\Tenancy\Languages;
use App\Tenancy\Settings;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantPages;
use App\Tenancy\TenantUrls;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The page a tenant's customers are served: the tenant's name, the theme, settings.theme, as
 * data-theme on the body and on the mount element, the boot config inlined so the first render
 * needs no round trip, and the one bundle.
 *
 * The logo is the hosted page's alone: it renders in the header beside the name, loaded from the
 * tenant's own origin, and the embed never carries one. Where none is uploaded the header draws a
 * disc in the theme's primary holding the name's first letter instead.
 *
 * Two routes render it and there is one of it, because the difference between them is four
 * attributes on the mount element. The booking page names none; the manage page names the mode and
 * the credentials, and beside the config, where a redirect flashed one, the sentence the booking
 * app shows above the booking. Everything else about the two is identical by construction rather
 * than by two views being kept in step.
 *
 * The booking page alone carries the business page above the flow, BusinessPage: the manage page
 * keeps the header of the logo and the name and nothing more.
 *
 * My bookings is a third: the mount in list mode, and beside the config a second JSON script
 * carrying the session's bookings in the guest view's shape, upcoming and past, or null where no
 * session is open and the app renders the form; the sentence a redirect flashed; and the form's
 * cross-site token, which the app writes into its two forms. Neither the link's token nor the
 * session's secret is ever in it: the one was spent on the way here and the other rides an
 * HttpOnly cookie no page prints.
 *
 * The page's lang and the config's language are the request's language, the prefix of its path
 * else the tenant default, and the config's default language beside it the tenant default.
 *
 * What the page does not do is judge those credentials. A page that refused a token it thought
 * was wrong would be a second oracle beside the API's, and the two would answer differently the
 * first day one of them changed. The mount carries what it was given and the API decides.
 */
final readonly class BookingPage
{
    /**
     * The mount attributes this page writes. The mode is written only where it is manage,
     * since anything else, absent included, already means book.
     */
    public const string MODE = 'data-mode';

    public const string UUID = 'data-uuid';

    public const string TOKEN = 'data-token';

    public const string MANAGE = 'manage';

    /** The third mode, My bookings, written on the mount of GET /my-bookings alone. */
    public const string LIST = 'list';

    /** The id of the second JSON script the list mode reads, beside the config's. */
    public const string LIST_ID = 'kr-my-bookings';

    /**
     * The id of the manage page's second JSON script, beside the config's: the sentence a
     * redirect flashed, written only where one came.
     */
    public const string NOTICE_ID = 'kr-notice';

    /**
     * The preselection and the link tag a hosted-page link carries: the query string's service,
     * staff and ch, written onto the mount as the attributes an embed's tenant writes by hand, so
     * the flow reads one contract on both surfaces.
     */
    public const string SERVICE = 'data-service';

    public const string STAFF = 'data-staff';

    public const string CHANNEL = 'data-channel';

    /** The largest id the client's reader keeps, its Number.MAX_SAFE_INTEGER. */
    private const int LARGEST_ID = 9007199254740991;

    /** How a second JSON script is spelled: escaped as the config is, so no value closes the script. */
    private const int INLINE_FLAGS = JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    public function __construct(
        private SettingsReader $settings,
        private TenantUrls $urls,
        private ViewFactory $views,
        private BusinessPage $business,
        private Languages $languages,
        private Request $request,
    ) {}

    /**
     * GET /: the mount in book mode under the tenant's profile and its structured data, carrying
     * the link's preselection and tag where the query string named them, and, where a My bookings
     * session is open, the customer the flow's details step starts from.
     *
     * @param  array<mixed>  $query  the request's query string as parsed
     * @param  Customer|null  $customer  the session's most recent booking's customer, null where no session is open
     */
    public function business(Tenant $tenant, array $query = [], ?Customer $customer = null): View
    {
        $settings = $this->settings->read($tenant);
        // The header's way to My bookings, there while the tenant sends the bookings link and
        // absent while it is off, when the page behind it could mail nobody, under the prefix this
        // page was asked under, bare for none, so the page in one language leads to My bookings in
        // the same.
        $myBookings = $settings->sends(MessageKey::MyBookingsLink) ? $this->urls->myBookings(Slug::of($tenant->slug), TenantPages::prefixOf($this->request->path())) : null;
        $language = $this->languages->ofRequest($this->request, $tenant);

        return $this->render($tenant, $settings, self::linkMount($query), $this->business->for($tenant, $settings, $language), myBookings: $myBookings, customer: $customer, language: $language);
    }

    /**
     * The mount attributes a link's query string names: service and staff where each is a
     * positive whole number the client's reader would keep, trimmed as that reader trims, and ch
     * where it is one of the fixed five. Anything else is no attribute at all, so the page judges
     * nothing and the flow's judge-once check of 1.6 decides what a dead id means; a channel
     * outside the five is dropped, never refused.
     *
     * @param  array<mixed>  $query
     * @return array<string, string|null>
     */
    public static function linkMount(array $query): array
    {
        $channel = is_string($query['ch'] ?? null) ? BookingChannel::tryFrom(trim($query['ch'])) : null;

        return [
            self::SERVICE => self::idOf($query[LinkKind::Service->value] ?? null),
            self::STAFF => self::idOf($query[LinkKind::Staff->value] ?? null),
            self::CHANNEL => $channel?->value,
        ];
    }

    private static function idOf(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^[0-9]{1,16}$/', trim($value)) !== 1) {
            return null;
        }

        $id = (int) trim($value);

        return $id > 0 && $id <= self::LARGEST_ID ? (string) $id : null;
    }

    /**
     * The manage page: the mount carrying the mode and the credentials, and, where a redirect
     * flashed one, the sentence beside the config in a script of its own, the booking app's to
     * show above the booking. The script holds the sentence and nothing else; the credentials
     * stay on the mount alone.
     *
     * @param  array<string, string|null>  $mount  extra mount attributes; a null value is not written
     * @param  string|null  $notice  the sentence a redirect flashed, or null
     */
    public function view(Tenant $tenant, array $mount = [], ?string $notice = null): View
    {
        $inlined = $notice === null ? null : json_encode(['notice' => $notice], self::INLINE_FLAGS);

        return $this->render($tenant, $this->settings->read($tenant), $mount, null, notice: $inlined);
    }

    /**
     * GET /my-bookings: the mount in list mode and the second script beside the config.
     *
     * @param  array{upcoming: list<BookingSnapshot>, past: list<BookingSnapshot>}|null  $listed  the session's bookings, null where no session is open
     * @param  string|null  $notice  the sentence a redirect flashed, or the limiter's refusal
     * @param  string  $csrf  the form's cross-site token, for the app's two forms
     */
    public function myBookings(Tenant $tenant, ?array $listed, ?string $notice, string $csrf): View
    {
        $inlined = self::listed($listed, $notice, $csrf);

        return $this->render($tenant, $this->settings->read($tenant), [self::MODE => self::LIST], null, json_encode($inlined, self::INLINE_FLAGS));
    }

    /**
     * My bookings' second script as data: the shape the page inlines, and the shape GET
     * /my-bookings answers a JSON read with, so the list mode reads one shape both ways.
     *
     * @param  array{upcoming: list<BookingSnapshot>, past: list<BookingSnapshot>}|null  $listed
     * @return array{bookings: array{upcoming: list<array<string, mixed>>, past: list<array<string, mixed>>}|null, notice: string|null, csrf: string}
     */
    public static function listed(?array $listed, ?string $notice, string $csrf): array
    {
        return [
            'bookings' => $listed === null ? null : [
                'upcoming' => array_map(BookingPayload::guest(...), $listed['upcoming']),
                'past' => array_map(BookingPayload::guest(...), $listed['past']),
            ],
            'notice' => $notice,
            'csrf' => $csrf,
        ];
    }

    /**
     * @param  array<string, string|null>  $mount
     * @param  array<string, mixed>|null  $business  the business page's data, null on the manage page
     * @param  string|null  $listed  My bookings' second script, null on every other page
     * @param  string|null  $myBookings  the header's link to My bookings, on the business page alone while the key is on
     * @param  Customer|null  $customer  the prefill the business page inlines into the config under a session, null everywhere else
     * @param  string|null  $notice  the manage page's second script, the flashed sentence, null everywhere else
     * @param  SiteLanguage|null  $language  the request's language where the caller read it already, read here otherwise
     */
    private function render(Tenant $tenant, Settings $settings, array $mount, ?array $business, ?string $listed = null, ?string $myBookings = null, ?Customer $customer = null, ?string $notice = null, ?SiteLanguage $language = null): View
    {
        $slug = Slug::of($tenant->slug);
        $logo = $settings->brandLogoPath;
        $language ??= $this->languages->ofRequest($this->request, $tenant);

        return $this->views->make('booking.hosted', [
            'language' => $language->value,
            'tenant' => $tenant,
            'theme' => $this->settings->theme($tenant)->value,
            'logo' => $logo === null ? null : $this->urls->logo($slug, TenantImages::fileOf($logo)),
            // The brand disc's letter where no logo is uploaded: the name's first, upper-cased.
            'initial' => mb_strtoupper(mb_substr(trim($tenant->name), 0, 1)),
            'config' => BootConfig::for($tenant, $settings, $this->languages->tenantDefault($tenant), $language)->toInlineJson($customer),
            'embedScript' => $this->urls->embedScript($slug),
            'mount' => $mount,
            'business' => $business,
            'listed' => $listed,
            'listId' => self::LIST_ID,
            'notice' => $notice,
            'noticeId' => self::NOTICE_ID,
            'myBookings' => $myBookings,
        ]);
    }
}
