<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Clock;
use App\Billing\SubscriptionRow;
use App\Billing\SubscriptionState;
use App\Http\AdminLines;
use App\Http\AdminProps;
use App\Http\AdminSession;
use App\Http\AdminStrings;
use App\Http\BookingLinks;
use App\Http\CountryNames;
use App\Http\PaymentsSection;
use App\Http\Requests\ColourByRequest;
use App\Http\Requests\CoverRequest;
use App\Http\Requests\GalleryPhotoRequest;
use App\Http\Requests\ImageUploadRequest;
use App\Http\Requests\LogoRequest;
use App\Http\Requests\OpeningHoursRequest;
use App\Http\Requests\SettingsRequest;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Db\SettingsRecords;
use App\Infrastructure\Db\StaffRecords;
use App\Infrastructure\Map\GoogleMapsKey;
use App\Infrastructure\Storage\TenantImages;
use App\Jobs\PrepareMap;
use App\Platform\ClosingWindow;
use App\Platform\TenantPlan;
use App\Site\SiteLanguage;
use App\Tenancy\CurrentTenant;
use App\Tenancy\MapState;
use App\Tenancy\Settings;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use NumberFormatter;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The Settings screen, and its two writes: the whole patch to PUT /admin/settings, and the
 * implicit member's week to PUT /admin/opening-hours, a route carrying no staff id. They are two
 * writes with two buttons because they can fail independently, and one button reporting two
 * outcomes could toast success over a week that never landed. The logo's upload and removal are
 * LogoController's, for the same reason, and the cover's and the gallery's are CoverController's
 * and GalleryController's.
 *
 * The read is never gated by the subscription: it shares its permission with the write, and gating
 * it would lock a lapsed owner out of the screen they came for. Both writes sit behind
 * manageSettings and the gate, declared on their routes. The press of the snippet's copy button is
 * recorded here too, under manageSettings alone: it configures nothing.
 *
 * The page carries the values the form edits, the logo and its URL, the cover and the gallery with
 * theirs on the images route in the order the row stores them, the map's state with its image on
 * that route where it is ready, the hosted page's URL and the embed snippet generated from the
 * slug and the saved theme, the Links card's links and QR paths, BookingLinks, and, for Opening
 * hours, the implicit member's week beside the roster of active members. The client decides
 * between the editor and the read-only roster from the rows loaded, never from a lapse flag, and a
 * roster that could not be read is handed over as null so that branch falls back to the editor:
 * freezing needs positive evidence.
 *
 * The Payments section, reads its prop from the provider registry and the stored connection row
 * alone, PaymentsSection, so opening Settings calls no provider. Its writes are
 * PaymentsController's, and its words the payments section, named here beside the settings
 * section.
 *
 * The screen's words are the settings section of lang/{locale}/admin, named here beside the
 * shell's, and the image helps' sizes ride as limits, spelled on the server. The three flashes are
 * keys of the section's flashes group, worded in the request's language. The country select's
 * names ride as countries, every code of lang/{locale}/countries.php with its name in the user's
 * language, read by CountryNames, the file's one reader, which the business page reads too;
 * countries.php sits outside lang/{locale}/admin, so it is a prop of its own rather than a section
 * of the strings prop.
 *
 * The Danger zone's close reads its prop here, the day and the way back its dialog names and the
 * closing sentence while the account is closing; the close itself is AccountCloseController's, and
 * the export TenantExportController's. Its words are the billing section's, named here beside the
 * settings section.
 *
 * EMAILS names the strings section the Settings page's Emails list reads, lang/{locale}/admin/
 * emails.php, its audience tags and its locked sentence; each message's label rides in the shared
 * settings prop, read in the request's language by AdminProps.
 */
final readonly class SettingsController
{
    public const string PAGE = 'Settings';

    /** The section of lang/{locale}/admin the screen reads. */
    public const string SECTION = 'settings';

    /**
     * The section the Emails section reads, lang/{locale}/admin/emails.php: the audience tags and
     * the locked sentence, the labels riding along.
     */
    public const string EMAILS = 'emails';

    /** The writes' flashes, keys of settings.php worded in the request's language. */
    public const string SAVED = 'admin/settings.flashes.saved';

    public const string HOURS_SAVED = 'admin/settings.flashes.hours_saved';

    public const string COLOUR_BY_SAVED = 'admin/settings.flashes.colour_by_saved';

    /** The helps that name an image's largest size, by which the size is spelled in words. */
    public const string LOGO_HELP = 'admin/settings.booking_page.logo_help';

    public const string COVER_HELP = 'admin/settings.booking_page.cover_help';

    public const string GALLERY_HELP = 'admin/settings.booking_page.gallery_help';

    /** The Danger zone's line that counts the retention window, by which its days are spelled. */
    public const string CLOSE_WHAT = 'admin/billing.danger.close.what';

    /** The ICU rule set that spells a count of days, a feminine noun in Greek. */
    private const string DAYS_GENDER = '%spellout-cardinal-feminine';

    public function __construct(
        private CurrentTenant $current,
        private SettingsReader $reader,
        private SettingsRecords $records,
        private StaffRecords $staff,
        private TenantUrls $urls,
        private PaymentsSection $payments,
        private LoggerInterface $log,
        private GoogleMapsKey $mapsKey,
        private BookingLinks $links,
        private DatabaseManager $db,
        private Clock $clock,
        private Translator $translator,
        private CountryNames $countries,
        #[Config('site.contact_address')] private string $support,
    ) {}

    public function show(Request $request): Response
    {
        AdminStrings::need($request, self::SECTION, PaymentsController::SECTION, BillingController::SECTION, self::EMAILS);
        $tenant = $this->current->get();
        $settings = $this->reader->read($tenant);
        $slug = Slug::of($tenant->slug);
        $logo = $settings->brandLogoPath;
        $theme = $this->reader->theme($tenant);

        return Inertia::render(self::PAGE, [
            'values' => [
                'currency' => $tenant->currency,
                'theme' => $theme->value,
                // The default language, the saved one else the tenant's own, and its choices as the
                // top bar names them.
                'language' => ($this->reader->language($tenant) ?? $tenant->locale)->value,
                'checkout_ttl_min' => $settings->checkoutTtlMin,
                'approval_ttl_hours' => $settings->approvalTtlHours,
                'payment_ttl_hours' => $settings->paymentTtlHours,
                'reminder_lead_hours' => $settings->reminderLeadHours,
                'start_interval_min' => $settings->startIntervalMin,
                'emails_off' => $settings->emailsOff,
                ...$settings->profile->toArray(),
            ],
            'languages' => app(AdminProps::class)->languageLabels(),
            'logo' => $logo === null ? null : ['name' => TenantImages::fileOf($logo), 'url' => $this->urls->logo($slug, TenantImages::fileOf($logo))],
            'cover' => $settings->coverPath === null ? null : $this->image($slug, $settings->coverPath),
            'gallery' => array_map(fn (string $path): array => $this->image($slug, $path), $settings->galleryPaths),
            'map' => $this->map($slug, $settings),
            'urls' => [
                'hosted' => $this->urls->hostedPage($slug),
                'snippet' => $this->urls->embedSnippet($slug, $theme),
            ],
            'links' => $this->links->card($tenant),
            'week' => $this->staff->week($tenant),
            'roster' => $this->roster(),
            'payments' => $this->payments->prop($tenant),
            'close' => fn (): array => $this->closeOf($tenant),
            'limits' => fn (): array => self::limits(),
            'countries' => fn (): array => $this->countries->in(),
        ]);
    }

    /**
     * The sizes the image helps name: each image's largest file in whole megabytes, from its
     * request's MAX_KILOBYTES, and the gallery's capacity, from Settings::GALLERY_CAPACITY, each
     * beside its spelling in words by ICU in the request's language, which the client cannot spell
     * (AdminLines::spelled).
     *
     * @return array{logo: array{megabytes: int, spelled: string}, cover: array{megabytes: int, spelled: string}, gallery: array{megabytes: int, spelled: string}, capacity: array{count: int, spelled: string}}
     */
    private static function limits(): array
    {
        $size = function (string $help, int $kilobytes): array {
            $megabytes = ImageUploadRequest::megabytesOf($kilobytes);

            return ['megabytes' => $megabytes, 'spelled' => AdminLines::spelled($help, $megabytes)];
        };

        return [
            'logo' => $size(self::LOGO_HELP, LogoRequest::MAX_KILOBYTES),
            'cover' => $size(self::COVER_HELP, CoverRequest::MAX_KILOBYTES),
            'gallery' => $size(self::GALLERY_HELP, GalleryPhotoRequest::MAX_KILOBYTES),
            'capacity' => ['count' => Settings::GALLERY_CAPACITY, 'spelled' => AdminLines::spelled(self::GALLERY_HELP, Settings::GALLERY_CAPACITY)],
        ];
    }

    /**
     * The Danger zone's close: the day the account would close if closed now, the Clock port's now
     * plus the retention window in the tenant's clock and never the browser's, for the dialog; the
     * way back the dialog names; and, while the account is closing, the sentence the Billing
     * screen's notice carries, which stands in for the control. A free tenant's way back names
     * support, its Billing screen offering no plan.
     *
     * The window's length rides as window, its days and their spelling in words by ICU in the
     * language the line is read in, the reader's, which the dialog's two sentences count as :count.
     *
     * @return array{day: string, way_back: string, notice: string|null, window: array{count: int, spelled: string}}
     */
    private function closeOf(Tenant $tenant): array
    {
        $state = SubscriptionState::from($tenant->subscription_state);
        $support = TenantPlan::free($state, $tenant->trial_ends_at !== null, SubscriptionRow::of($this->db, $tenant) !== null) ? $this->support : null;
        $closingAt = ClosingWindow::of($tenant);
        // The day in the language the user reads the admin in, the locale SetTenantLocale set.
        $language = SiteLanguage::from($this->translator->getLocale());

        return [
            'day' => ClosingWindow::dayOf($this->clock->now(), $tenant->zone(), $language),
            'way_back' => ClosingWindow::wayBack($support),
            'notice' => $closingAt === null ? null : ClosingWindow::notice(ClosingWindow::dayOf($closingAt, $tenant->zone(), $language), $support),
            'window' => ['count' => ClosingWindow::days(), 'spelled' => self::windowSpelled(ClosingWindow::days())],
        ];
    }

    /**
     * The closing window's days spelled in words by ICU in the language the dialog's line is
     * read in, by the rule set of the days' gender where the language has one: Greek days are
     * feminine, and a figure ending in one, three or four bends by gender. English has no such
     * rule set and keeps its default.
     */
    public static function windowSpelled(int $days): string
    {
        $translator = app(Translator::class);
        $locale = $translator->getLocale();
        $readIn = $translator->hasForLocale(self::CLOSE_WHAT, $locale) ? $locale : $translator->getFallback();
        $formatter = new NumberFormatter($readIn, NumberFormatter::SPELLOUT);

        if (str_contains((string) $formatter->getTextAttribute(NumberFormatter::PUBLIC_RULESETS), self::DAYS_GENDER.';')) {
            $formatter->setTextAttribute(NumberFormatter::DEFAULT_RULESET, self::DAYS_GENDER);
        }

        $spelled = $formatter->format($days);

        return is_string($spelled) ? $spelled : (string) $days;
    }

    /**
     * The map as the screen's type has it: ready with its stored image, or any other state with
     * none. A ready row naming no path, which only a hand's write leaves, reads as none, so a ready
     * state never crosses with a null image.
     *
     * @return array{state: string, image: array{name: string, url: string}|null}
     */
    private function map(Slug $slug, Settings $settings): array
    {
        if ($settings->mapState === MapState::Ready) {
            return $settings->mapPath === null
                ? ['state' => MapState::None->value, 'image' => null]
                : ['state' => MapState::Ready->value, 'image' => $this->image($slug, $settings->mapPath)];
        }

        return ['state' => $settings->mapState->value, 'image' => null];
    }

    /**
     * One stored image as the screen shows it: the file name the removal route names and the URL
     * the page loads it from.
     *
     * @return array{name: string, url: string}
     */
    private function image(Slug $slug, string $path): array
    {
        $file = TenantImages::fileOf($path);

        return ['name' => $file, 'url' => $this->urls->image($slug, $file)];
    }

    public function update(SettingsRequest $request): RedirectResponse
    {
        $tenant = $this->current->get();

        // The save has committed when update() answers, so the map's job is queued after it.
        if ($this->records->update($tenant, $request->currency(), $request->columns(), $this->mapsKey->isSet())) {
            PrepareMap::dispatch($tenant->id);
        }

        $request->session()->flash('flash', ['kind' => 'success', 'message' => SettingsRequest::line(self::SAVED)]);

        return redirect()->back(fallback: AdminSession::at($request, self::path()));
    }

    public function updateOpeningHours(OpeningHoursRequest $request): RedirectResponse
    {
        $this->staff->replaceWeek($this->current->get(), $request->windows());
        $request->session()->flash('flash', ['kind' => 'success', 'message' => SettingsRequest::line(self::HOURS_SAVED)]);

        return redirect()->back(fallback: AdminSession::at($request, self::path()));
    }

    /**
     * The press of a copy button that copies the booking link: the link marker stamped and a
     * redirect back with no flash, since the button already said what it did. Body-less, so
     * it takes no form request; ungated, since it configures nothing.
     */
    public function linkCopied(Request $request): RedirectResponse
    {
        $this->records->markLinkCopied($this->current->get());

        return redirect()->back(fallback: AdminSession::at($request, self::path()));
    }

    /**
     * Colour by from the Calendar's filter card: the one word written to the settings row, read
     * by every grid for every user of the tenant, and a redirect back with its flash. Under
     * manageSettings and the gate, declared on its route.
     */
    public function colourBy(ColourByRequest $request): RedirectResponse
    {
        $this->records->setColourBy($this->current->get(), $request->colourBy());
        $request->session()->flash('flash', ['kind' => 'success', 'message' => SettingsRequest::line(self::COLOUR_BY_SAVED)]);

        return redirect()->back(fallback: AdminSession::at($request, self::path()));
    }

    /**
     * Every active member with their usual week, the windows of their pattern, or null when the
     * roster could not be read.
     *
     * @return list<array{id: int, name: string, windows: list<array{weekday: int, start: string, end: string}>}>|null
     */
    private function roster(): ?array
    {
        try {
            $members = $this->staff->all($this->current->get());
        } catch (Throwable $failure) {
            $this->log->error(sprintf('The staff roster could not be read for the Settings screen: %s', $failure->getMessage()));

            return null;
        }

        return array_values(array_map(
            fn (array $member): array => ['id' => $member['id'], 'name' => $member['name'], 'windows' => $member['pattern']['windows'] ?? []],
            array_filter($members, fn (array $member): bool => $member['status'] === 'active'),
        ));
    }

    public static function path(): string
    {
        return AdminSession::PREFIX.'/settings';
    }
}
