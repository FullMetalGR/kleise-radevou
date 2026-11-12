<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Notification\MessageKey;
use App\Billing\EntitlementResolver;
use App\Billing\Entitlements;
use App\Billing\Narrowing;
use App\Billing\SubscriptionState;
use App\Infrastructure\Db\BookingRecords;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Db\WizardCatalog;
use App\Platform\TenantPlan;
use App\Site\Brand;
use App\Site\SiteLanguage;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Languages;
use App\Tenancy\Permission;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use LogicException;

/**
 * The shared props, built in this one place and handed to every page by the Inertia middleware.
 *
 * The sign-in page is outside the shell and receives the tenant, the empty user, the flash, the
 * brand, the language, the theme and the strings alone, the brand being the public name the title
 * template and the top bar's wordmark read. The language, is the one every page is answered in and
 * the admin's root view writes as its lang: the signed-in user's users.locale, else the tenant
 * default, the tenant default where nobody is signed in or support acts, the resolver's answer for
 * the principal as the locale middleware asked it. The theme, settings.theme, is the word the root
 * view writes as the body's data-theme, so the page is painted in the tenant's colour from its
 * first frame and the portals Radix renders into the body take it too. Every page inside the shell
 * receives the whole set: the tenant, the user, the support session, the permissions resolved here
 * on the server, the entitlements from the resolver, the subscription as the row holds it, the
 * settings the screens format with, the rail already filtered, the flash, the brand, the language
 * and the strings. The strings, are the shell's section of lang/{locale}/admin on every page and
 * beside it each section the page's controller named through AdminStrings::need, read in the
 * request's language when the page renders, so a redirect reads none; the rail's labels are read in
 * the same when the rail is. A user who may book by hand also receives the catalog the manual
 * booking wizard chooses from, because the wizard opens over whatever screen is showing and no page
 * can be asked to carry a prop for a modal it does not own; beside it, by the same rule, the
 * wizard's words, the wizard group of lang/{locale}/admin/customers.php in the request's language;
 * every other user receives null for both and no button.
 *
 * The top bar's language control: its labels, languages, each language's code and its own name,
 * read from site.language in that language as the site's switch reads it, are shared to a signed-in
 * user alone; support, which has no user row to write, is offered none.
 *
 * The narrowing record rides beside them, the row's list filtered to the slugs this build knows,
 * for the shell's banner. It costs nothing: the row is loaded. So does free, whether the tenant is
 * in the free shape, its plan the console's, read through the one predicate the console and the
 * Billing screen read, TenantPlan::free, so the banner and the Staff screen's notice name platform
 * support rather than a Billing screen with nothing to add; it asks the subscriptions table one
 * question, so it is a closure as the settings are.
 *
 * Every one of them is read off the principal the session answers, never off a user: a signed-in
 * user's auth.user is their id, name and role and the support prop is null, and support's auth.user
 * is named for the operator with no id and the role support, the support prop carrying the
 * operator's name and the session's end for the shell's banner. The permissions are the
 * principal's, and so are the catalog's and the pending count's checks.
 *
 * The entitlements, the settings, the rail with its pending count and the catalog are handed over
 * as closures, which Inertia resolves when a page is rendered and never otherwise, so a request
 * that renders no page pays nothing for them: an action's redirect, a partial reload that did not
 * ask for them, and the availability read, which the wizard makes on every day change while holding
 * what it was given with the page. The entitlements are resolved once per render however many props
 * read them. The rail lights the entry of the request's route, read when the rail is, so a page
 * that answered the 404 inside the shell from a route of its own, NotFoundController marking the
 * request, lights none.
 */
final readonly class AdminProps
{
    /** What the shell calls support acting in a session: the operator named in brackets, as the trail names it, :operator the name. */
    public const string SUPPORT_NAME = 'admin/shell.shared.support.named';

    /** The section of lang/{locale}/admin that holds the wizard's words, and their group in it. */
    private const string WIZARD_SECTION = 'customers';

    private const string WIZARD_GROUP = 'wizard';

    public function __construct(
        private CurrentTenant $current,
        private AdminSession $session,
        private EntitlementResolver $entitlements,
        private SettingsReader $settings,
        private BookingRecords $bookings,
        private WizardCatalog $catalog,
        private Brand $brand,
        private DatabaseManager $db,
        private Languages $languages,
        private Translator $translator,
        private AdminStrings $strings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Request $request): array
    {
        $tenant = $this->current->get();
        $principal = $this->session->principal($request);

        $shared = [
            'tenant' => [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'timezone' => $tenant->timezone,
                'currency' => $tenant->currency,
            ],
            'auth' => ['user' => $principal === null ? null : self::userOf($principal, $this->translator)],
            'flash' => self::flashOf($request->session()->get('flash')),
            'brand' => $this->brand->prop(),
            'language' => $this->languages->ofUser($tenant, $principal?->chosenLanguage())->value,
            'theme' => fn (): string => $this->settings->theme($tenant)->value,
            'strings' => fn (): array => $this->strings->for($request),
        ];

        if ($principal === null) {
            return $shared;
        }

        $route = $request->route()?->getName();
        $resolved = null;
        $entitlements = function () use ($tenant, &$resolved): Entitlements {
            return $resolved ??= $this->entitlements->resolve($tenant);
        };

        return [
            ...$shared,
            ...($principal->kind === Principal::USER ? ['languages' => $this->languageLabels()] : []),
            'support' => self::supportOf($principal),
            'permissions' => array_map(fn (Permission $permission): string => $permission->value, $principal->permissions),
            'entitlements' => fn (): array => $entitlements()->toSlugs(),
            'narrowed' => Narrowing::of($tenant),
            'subscription' => self::subscriptionOf($tenant),
            'free' => fn (): bool => $this->freeOf($tenant),
            'settings' => fn (): array => $this->settingsOf($tenant),
            'catalog' => $principal->holds(Permission::ManageBookings) ? fn (): array => $this->catalog->read($tenant) : null,
            'wizard' => $principal->holds(Permission::ManageBookings) ? fn (): array => $this->wizardWords() : null,
            'nav' => fn (): array => AdminNav::for(
                $principal->permissions,
                $entitlements(),
                $request->attributes->get(AdminNav::NO_ENTRY) === true ? null : $route,
                $principal->holds(Permission::ViewBookings) ? $this->bookings->pendingCount($tenant) : 0,
                $this->translator,
            ),
        ];
    }

    /**
     * The manual booking wizard's words, the wizard group of the customers section in the request's
     * language, which rides beside the catalog.
     *
     * @return array<mixed>
     */
    private function wizardWords(): array
    {
        $wizard = $this->strings->section(self::WIZARD_SECTION)[self::WIZARD_GROUP] ?? [];

        return is_array($wizard) ? $wizard : [];
    }

    /**
     * auth.user: a user's id, name and role; for support no id, since no user of the tenant is
     * behind it, the operator named as the shell shows them, read through the translator in the
     * request's language, and the role support.
     *
     * @return array{id: int|null, name: string, role: string}
     */
    public static function userOf(Principal $principal, Translator $translator): array
    {
        if ($principal->kind !== Principal::SUPPORT) {
            return ['id' => $principal->ownStaffUserId(), 'name' => $principal->name, 'role' => $principal->role];
        }

        $named = $translator->get(self::SUPPORT_NAME, ['operator' => $principal->name]);

        return ['id' => null, 'name' => is_string($named) ? $named : self::SUPPORT_NAME, 'role' => $principal->role];
    }

    /**
     * The support prop: null for a user, and for support the operator's name and the session's
     * end for the banner, a UTC instant with its offset written out, sixty minutes from the
     * console's press.
     *
     * @return array{operator: string, ends_at: string}|null
     */
    public static function supportOf(Principal $principal): ?array
    {
        $endsAt = $principal->supportEndsAt();

        return $principal->kind === Principal::SUPPORT && $endsAt !== null ? ['operator' => $principal->name, 'ends_at' => BookingPayload::instant($endsAt)] : null;
    }

    /**
     * The top bar control's choices: each language's code and its own name, site.language read in
     * that language, as the site's switch is labelled (ChromeComposer); the Settings screen's
     * default language reads the same list.
     *
     * @return list<array{value: string, name: string}>
     */
    public function languageLabels(): array
    {
        return array_map(function (SiteLanguage $language): array {
            $name = $this->translator->get('site.language', [], $language->value);

            return ['value' => $language->value, 'name' => is_string($name) ? $name : $language->value];
        }, SiteLanguage::cases());
    }

    /**
     * Whether the tenant is in the free shape: the state active, no subscriptions row and no
     * trial end, as TenantPlan::free decides it for the console and the Billing screen.
     */
    private function freeOf(Tenant $tenant): bool
    {
        $subscribed = $this->db->connection()->table('subscriptions')->where('tenant_id', $tenant->id)->exists();

        return TenantPlan::free(SubscriptionState::from($tenant->subscription_state), $tenant->trial_ends_at !== null, $subscribed);
    }

    /**
     * The settings the screens format with, and the notification catalog the Emails section ticks
     * through: each key with its label, read in the request's language from the line of
     * admin/emails.php MessageKey names, its audience, whether it goes to the owners alone, which
     * the section tags, and whether it is held on whatever the off list says, the approved key
     * while a working payment connection exists, which the section renders checked and disabled
     * with its sentence.
     *
     * @return array<string, mixed>
     */
    private function settingsOf(Tenant $tenant): array
    {
        $settings = $this->settings->read($tenant);

        return [
            'granularity' => $settings->granularityMin,
            'week_start' => $settings->startOfWeek,
            'date_format' => $settings->dateFormat,
            'time_format' => $settings->timeFormat,
            'colour_by' => $settings->colourBy,
            'messages' => array_map(fn (MessageKey $key): array => [
                'key' => $key->value,
                'label' => $this->line($key->labelKey()),
                'audience' => $key->audience()->value,
                'owners_alone' => $key->ownersAlone(),
                'locked' => $settings->locked($key),
            ], MessageKey::cases()),
        ];
    }

    /**
     * One line read in the request's language, the application's locale the locale middleware
     * set; a key with no line reads as the key, as the translator answers it.
     */
    private function line(string $key): string
    {
        $line = $this->translator->get($key);

        return is_string($line) ? $line : $key;
    }

    /**
     * The subscription as the tenant row holds it: the state; grace_ends_at inside grace and only
     * there; and trial_ends_at in a trial and in canceled after one, a tenant that never subscribed,
     * the lapse keeping the end on the row and a landed subscription clearing it; each a UTC instant
     * with its offset written out. Never null-as-unknown: a state outside the six raises, and so do
     * a grace and a trial with no end.
     *
     * @return array{state: string, grace_ends_at?: string, trial_ends_at?: string}
     */
    public static function subscriptionOf(Tenant $tenant): array
    {
        $state = SubscriptionState::from($tenant->subscription_state);

        return match (true) {
            $state === SubscriptionState::Grace => ['state' => $state->value, 'grace_ends_at' => BookingPayload::instant($tenant->grace_ends_at ?? throw new LogicException(sprintf('Tenant "%s" is in grace with no end to it.', $tenant->slug)))],
            $state === SubscriptionState::Trial => ['state' => $state->value, 'trial_ends_at' => BookingPayload::instant($tenant->trial_ends_at ?? throw new LogicException(sprintf('Tenant "%s" is in a trial with no end to it.', $tenant->slug)))],
            $state === SubscriptionState::Canceled && $tenant->trial_ends_at !== null => ['state' => $state->value, 'trial_ends_at' => BookingPayload::instant($tenant->trial_ends_at)],
            default => ['state' => $state->value],
        };
    }

    /**
     * A one-shot message as a redirect flashed it, or null. Anything not shaped as a kind and a
     * message is dropped here rather than handed to a toast that cannot say it.
     *
     * @return array{kind: string, message: string}|null
     */
    public static function flashOf(mixed $flash): ?array
    {
        if (! is_array($flash) || ! is_string($flash['kind'] ?? null) || ! is_string($flash['message'] ?? null)) {
            return null;
        }

        return ['kind' => $flash['kind'], 'message' => $flash['message']];
    }
}
