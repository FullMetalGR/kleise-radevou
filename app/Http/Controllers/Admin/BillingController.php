<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Billing\BillingNotConfigured;
use App\Billing\BillingProvider;
use App\Billing\BillingProviderFailed;
use App\Billing\CatalogueUnreadable;
use App\Billing\Entitlements;
use App\Billing\Feature;
use App\Billing\Plan;
use App\Billing\PlanCatalogue;
use App\Billing\PlanChange;
use App\Billing\PlanChangeRefused;
use App\Billing\Resubscribe;
use App\Billing\ResubscribeRefused;
use App\Billing\SubscriptionRow;
use App\Billing\SubscriptionState;
use App\Billing\SyncOutcome;
use App\Http\AdminLines;
use App\Http\AdminSession;
use App\Http\AdminStrings;
use App\Http\ErrorEnvelope;
use App\Http\Requests\ChangePlanRequest;
use App\Http\Requests\CheckoutRequest;
use App\Infrastructure\Db\Busy;
use App\Infrastructure\Db\StaffRecords;
use App\Platform\ClosingWindow;
use App\Platform\TenantPlan;
use App\Site\SiteLanguage;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Tenant;
use Closure;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The Billing screen: the one page an owner comes to when something is wrong, so manageBilling
 * alone gates it and the subscription gate never does. It renders from the two rows and the
 * catalogue and asks the provider nothing; the shared props carry the state, the grace end and the
 * narrowing record.
 *
 * The notice carries one whole sentence per state, success for active and a trial and warning
 * otherwise, never a concatenation, because the fix differs in every case. A free tenant, state
 * active with no subscriptions row and no trial end (TenantPlan::free, the one predicate of the
 * shape), reads one success sentence of its own in place of the active notice and the
 * no-subscription sentence, with no plan card, no change and no checkout, the portal absent since
 * no row exists; both writes still refuse it as a row naming no subscription. A trial's says the
 * trial is open with everything on it, and the one for a trial that ended unpaid, a canceled row
 * whose trial_ends_at the lapse kept, says it ended and to subscribe; neither names the instant,
 * and nothing here leaves a placeholder for the client to fill. Off active and the trial a second
 * paragraph says what is paused and what is not; in grace, which pauses nothing, it says what
 * pauses if the window runs out, since saying setup changes are paused while they are open would
 * overstate the freeze. Both paragraphs name six admin actions, where seven are never frozen: the
 * seventh, reschedule, the paragraph's customer half names; the business moves bookings too, and
 * the paragraph names that as "move". Past due's sentence names no grace period, since unpaid and
 * paused write past_due on a tenant that never had one. The grace end and the trial's end are the
 * client's to spell, each in a paragraph of its own from the shared subscription prop alone, as
 * date and time in the tenant's zone and formats.
 *
 * The two writes and the one deferred read of the tax ids are the only calls to the provider, all
 * three through the port. Change plan runs PlanChange and answers a redirect back whose flash is
 * the outcome: the success sentence, the change on its way, or the refusal's own sentence through
 * ErrorEnvelope. A catalogue nobody can read and a provider that could not answer are reported to
 * the log and worded on the page. BillingNotConfigured is a deploy fault: on the two writes, a
 * press, it is left to raise as one; on the deferred read, a visit, it is worded as the tax id
 * line that could not be read and logged, as the provider failing is. The portal answers an
 * external redirect to the provider's hosted page returning here.
 *
 * A closing tenant reads one notice of its own in place of the state's, whatever the state: the
 * day the account closes in the tenant's clock, that everything in it is deleted then, and the way
 * back, the plan choice this screen offers, or support for a free tenant, whose screen offers
 * none; the paragraph of what is paused follows it, the closing freezing setup changes as a lapse
 * does. The account is closed from the Settings Danger zone, AccountCloseController, which lands
 * here.
 *
 * The checkout takes the plan the body names and serves a trial, a tenant whose trial ended
 * unpaid, and the way back from a subscription that ended (Resubscribe), returning here, and
 * answers an external redirect to the provider, as the portal does; the floor's refusal while more
 * than one member is active travels as the plan change's does, in a sentence of the choice's own
 * (PlanChange::choiceRefusal). The screen offers it in each of the three: Choose your plan in a
 * trial, Subscribe after one, with the catalogue's plans to choose from, the floor carrying that
 * sentence while the roster refuses it; and in canceled with a row Subscribe again, posting the
 * plan the row last carried where it is listed and the roster allows it, and offering the same
 * choice otherwise, so no canceled tenant is left without a road to a card. While the checkout the
 * row records is open the notice adds that the new subscription is on its way and the control
 * reads Continue to checkout, since the page cannot know whether the provider's page was finished;
 * the choice stays where it was offered, the open session answering whatever plan is posted and a
 * lapsed one giving way to a new session on the plan chosen. Two presses at once open one session,
 * the second waiting on the first's lock; one that waits past it is worded as a checkout the
 * provider could not open, a retry, and logged with the tenant alone (Resubscribe). The completion
 * lands through the seam and the next page reads active. The payment card carries two tax lines
 * wherever a subscriptions row exists: billing.vat, the VAT sentence, true whatever the provider's
 * tax setup; and the tax id line, the one deferred prop TAX_ID at the page's top level, where
 * Inertia collects a deferred prop, which the client loads after the render, so the render itself
 * still asks the provider nothing. Its closure asks the port for the customer's tax ids and words
 * the first, none, or a provider that could not answer, logged at warning as the portal's failure
 * is. The four lines are lang/{locale}/admin/tax.php's, read in the user's language and sent
 * filled. The catalogue is read once a render, by whichever part of the page first needs it. Every
 * plan the screen names, on the plan card, the change and the choice, carries the name the
 * catalogue gives it, Solo or Team from config/billing.php, and nothing here derives a name from a
 * plan's features.
 */
final readonly class BillingController
{
    public const string PAGE = 'Billing';

    public const string PATH = AdminSession::PREFIX.'/billing';

    /** The section of lang/{locale}/admin the screen and the Danger zone read. */
    public const string SECTION = 'billing';

    /*
     * Every sentence below is a key of the billing section, the four tax lines excepted (their own
     * comment names their file), worded through the translator in the request's language, the
     * user's; TAX_ID is a prop's name, no sentence, and the *_LOGGED lines are log lines, read by
     * no user, and stay English.
     */

    public const string ACTIVE = 'admin/billing.notices.active';

    /**
     * The one sentence of a free tenant, state active with no subscriptions row and no trial end,
     * the shape the console's free tenant and tenant:provision write, read through the one
     * predicate the console reads, TenantPlan::free.
     */
    public const string FREE = 'admin/billing.notices.free';

    public const string GRACE = 'admin/billing.notices.grace';

    public const string PAST_DUE = 'admin/billing.notices.past_due';

    public const string CANCELED = 'admin/billing.notices.canceled';

    public const string INCOMPLETE = 'admin/billing.notices.incomplete';

    /** A trial's notice; the client spells the trial's end beneath it from the shared subscription prop. */
    public const string TRIAL = 'admin/billing.notices.trial';

    /** The notice of a trial that ended unpaid, the row canceled with trial_ends_at kept; its end as TRIAL's. */
    public const string TRIAL_ENDED = 'admin/billing.notices.trial_ended';

    /** The plan card's name in a trial, which carries the full set and no price. */
    public const string TRIAL_PLAN = 'admin/billing.plan.trial';

    public const string PAUSED = 'admin/billing.notices.paused';

    public const string PAUSES_AFTER_GRACE = 'admin/billing.notices.after_grace';

    public const array FLOOR_INCLUDES = ['admin/billing.plan.includes.services', 'admin/billing.plan.includes.page'];

    public const string ONE_MEMBER = 'admin/billing.plan.includes.one_member';

    public const string STAFF_INCLUDES = 'admin/billing.plan.includes.staff';

    public const string STAFF_ADDED = 'admin/billing.flashes.staff_added';

    public const string STAFF_REMOVED = 'admin/billing.flashes.staff_removed';

    public const string PLAN_CHANGED = 'admin/billing.flashes.plan_changed';

    public const string CHANGE_PENDING = 'admin/billing.flashes.change_pending';

    /**
     * The two sentences asking the owner to contact support name the support address: each
     * with :address, filled with site.contact_address where it is answered.
     */
    public const string CHANGE_UNREADABLE = 'admin/billing.flashes.change_unreadable';

    public const string CHANGE_UNMATCHED = 'admin/billing.flashes.change_unmatched';

    public const string ALREADY_ON = 'admin/billing.flashes.already_on';

    public const string PROVIDER_FAILED = 'admin/billing.flashes.provider_failed';

    public const string PORTAL_FAILED = 'admin/billing.flashes.portal_failed';

    public const string CHOOSE_PLAN = 'admin/billing.checkout.choose_plan';

    public const string SUBSCRIBE = 'admin/billing.checkout.subscribe';

    public const string SUBSCRIBE_AGAIN = 'admin/billing.checkout.subscribe_again';

    public const string CONTINUE_CHECKOUT = 'admin/billing.checkout.continue';

    public const string ON_ITS_WAY = 'admin/billing.checkout.on_its_way';

    public const string CHECKOUT_FAILED = 'admin/billing.flashes.checkout_failed';

    public const string CATALOGUE_UNREADABLE = 'admin/billing.plan.unreadable';

    /** The deferred prop carrying the tax id line, loaded by the client after the render. */
    public const string TAX_ID = 'tax_id';

    /*
     * The four tax lines are keys of lang/{locale}/admin/tax.php, not of the billing section.
     */

    /** The VAT sentence on the payment card, true whatever the provider's tax setup. */
    public const string VAT_LINE = 'admin/tax.vat';

    /** The tax id line with the first tax id the provider holds, as :tax_id. */
    public const string TAX_ID_NUMBER = 'admin/tax.number';

    /** The tax id line where the provider holds none for the customer. */
    public const string TAX_ID_NONE = 'admin/tax.none';

    /** The tax id line where the provider could not answer. */
    public const string TAX_ID_UNREADABLE = 'admin/tax.unreadable';

    public const string PROVIDER_LOGGED = 'The billing provider could not answer a request from the Billing screen';

    public const string CATALOGUE_LOGGED = 'The Billing screen could not read the plan catalogue';

    public const string TAX_ID_LOGGED = 'The billing provider could not read the tax ids for the Billing screen';

    /** A checkout press that waited past Resubscribe::LOCK_TIMEOUT for another of the tenant's. */
    public const string CHECKOUT_BUSY_LOGGED = 'A Billing screen checkout waited too long for another checkout of the same tenant and opened nothing';

    public const string UNREADABLE_LOGGED = 'A plan change landed at the billing provider and the sync could not resolve its answer; the provider\'s change stands and the webhook cannot land it either until the cause is fixed';

    public const string UNMATCHED_LOGGED = 'A plan change landed at the billing provider and its answer names a customer no tenant answers to; the provider\'s change stands and nothing here will land it';

    public function __construct(
        private CurrentTenant $current,
        private DatabaseManager $db,
        private PlanCatalogue $catalogue,
        private StaffRecords $staff,
        private Resubscribe $resubscribe,
        private LoggerInterface $log,
        private Translator $translator,
        #[Config('site.contact_address')] private string $support,
    ) {}

    public function show(Request $request, BillingProvider $provider): Response
    {
        AdminStrings::need($request, self::SECTION);
        $tenant = $this->current->get();
        $row = SubscriptionRow::of($this->db, $tenant);

        return Inertia::render(self::PAGE, [
            'billing' => fn (): array => $this->billingOf($tenant, $row),
            ...($row === null ? [] : [self::TAX_ID => Inertia::defer(fn (): string => $this->taxIdOf($provider, $tenant, $row))]),
        ]);
    }

    public function plan(ChangePlanRequest $request, PlanChange $change): RedirectResponse
    {
        $tenant = $this->current->get();

        try {
            ['from' => $from, 'plan' => $plan, 'outcome' => $outcome] = $change->change($tenant, $request->plan());
            $flash = match ($outcome) {
                null => ['kind' => 'info', 'message' => AdminLines::line(self::ALREADY_ON)],
                SyncOutcome::Applied => ['kind' => 'success', 'message' => AdminLines::line(self::movedOf($from, $plan))],
                SyncOutcome::Stale => ['kind' => 'info', 'message' => AdminLines::line(self::CHANGE_PENDING)],
                SyncOutcome::Unresolved => $this->standing($tenant, $plan, self::UNREADABLE_LOGGED, AdminLines::line(self::CHANGE_UNREADABLE, ['address' => $this->support])),
                SyncOutcome::UnknownTenant => $this->standing($tenant, $plan, self::UNMATCHED_LOGGED, AdminLines::line(self::CHANGE_UNMATCHED, ['address' => $this->support])),
            };
        } catch (PlanChangeRefused $refused) {
            $flash = ['kind' => 'error', 'message' => ErrorEnvelope::for($refused)->detail];
        } catch (CatalogueUnreadable $cause) {
            $this->log->error(self::CATALOGUE_LOGGED, ['tenant' => $tenant->slug, 'message' => $cause->getMessage()]);
            $flash = ['kind' => 'error', 'message' => AdminLines::line(self::CATALOGUE_UNREADABLE)];
        } catch (BillingProviderFailed $cause) {
            $this->log->warning(self::PROVIDER_LOGGED, ['tenant' => $tenant->slug, 'message' => $cause->getMessage()]);
            $flash = ['kind' => 'error', 'message' => AdminLines::line(self::PROVIDER_FAILED)];
        }

        return $this->back($request, $flash);
    }

    public function portal(Request $request, BillingProvider $provider): HttpResponse
    {
        $tenant = $this->current->get();
        $row = SubscriptionRow::of($this->db, $tenant);

        if ($row === null) {
            return $this->back($request, ['kind' => 'error', 'message' => PlanChangeRefused::noSubscription()->sentence()]);
        }

        try {
            $url = $provider->openPortal($row->customerId, AdminSession::at($request, self::PATH));
        } catch (BillingProviderFailed $cause) {
            $this->log->warning(self::PROVIDER_LOGGED, ['tenant' => $tenant->slug, 'message' => $cause->getMessage()]);

            return $this->back($request, ['kind' => 'error', 'message' => AdminLines::line(self::PORTAL_FAILED)]);
        }

        return Inertia::location($url);
    }

    public function checkout(CheckoutRequest $request): HttpResponse
    {
        $tenant = $this->current->get();
        $email = $request->user('web')?->getAttribute('email');

        try {
            $url = $this->resubscribe->start($tenant, $request->plan(), is_string($email) ? $email : '', AdminSession::at($request, self::PATH));
        } catch (ResubscribeRefused|PlanChangeRefused $refused) {
            return $this->back($request, ['kind' => 'error', 'message' => ErrorEnvelope::for($refused)->detail]);
        } catch (CatalogueUnreadable $cause) {
            $this->log->error(self::CATALOGUE_LOGGED, ['tenant' => $tenant->slug, 'message' => $cause->getMessage()]);

            return $this->back($request, ['kind' => 'error', 'message' => AdminLines::line(self::CATALOGUE_UNREADABLE)]);
        } catch (BillingProviderFailed $cause) {
            $this->log->warning(self::PROVIDER_LOGGED, ['tenant' => $tenant->slug, 'message' => $cause->getMessage()]);

            return $this->back($request, ['kind' => 'error', 'message' => AdminLines::line(self::CHECKOUT_FAILED)]);
        } catch (Busy) {
            $this->log->warning(self::CHECKOUT_BUSY_LOGGED, ['tenant' => $tenant->slug]);

            return $this->back($request, ['kind' => 'error', 'message' => AdminLines::line(self::CHECKOUT_FAILED)]);
        }

        return Inertia::location($url);
    }

    /**
     * The page's `billing` prop: the notice, the plan card, the portal, the VAT line and the way
     * back. A closure in show() hands it to the framework, which works it out on a full visit and
     * never on the router's partial request for the tax id line alone.
     *
     * @return array<string, mixed>
     */
    private function billingOf(Tenant $tenant, ?SubscriptionRow $row): array
    {
        $state = SubscriptionState::from($tenant->subscription_state);
        $plans = $this->catalogueOnce($tenant);
        $free = TenantPlan::free($state, $tenant->trial_ends_at !== null, $row !== null);
        $closingAt = ClosingWindow::of($tenant);

        return [
            'notice' => match (true) {
                $closingAt !== null => ['kind' => 'warning', 'sentence' => ClosingWindow::notice(ClosingWindow::dayOf($closingAt, $tenant->zone(), SiteLanguage::from($this->translator->getLocale())), $free ? $this->support : null), 'paused' => AdminLines::line(self::PAUSED)],
                $free => ['kind' => 'success', 'sentence' => AdminLines::line(self::FREE), 'paused' => null],
                default => self::noticeOf($state, $tenant->trial_ends_at !== null),
            },
            ...($free ? ['plan' => null, 'change' => null, 'unavailable' => null] : $this->plansOf($tenant, $state, $row, $plans)),
            'portal' => $row !== null,
            'vat' => $row !== null ? AdminLines::line(self::VAT_LINE) : null,
            'resubscribe' => $this->resubscribeOf($tenant, $state, $row, $plans),
        ];
    }

    /**
     * The tax id line, worded: the first tax id the provider's answer carries for the row's
     * customer, none, or the line that could not be read, for a provider that could not answer
     * or a configuration missing a key (BillingNotConfigured), each logged once at warning with
     * the tenant and the cause's message, so a visit never fails on it.
     */
    private function taxIdOf(BillingProvider $provider, Tenant $tenant, SubscriptionRow $row): string
    {
        try {
            $first = $provider->taxIds($row->customerId)[0] ?? null;
        } catch (BillingProviderFailed|BillingNotConfigured $cause) {
            $this->log->warning(self::TAX_ID_LOGGED, ['tenant' => $tenant->slug, 'message' => $cause->getMessage()]);

            return AdminLines::line(self::TAX_ID_UNREADABLE);
        }

        return $first === null ? AdminLines::line(self::TAX_ID_NONE) : AdminLines::line(self::TAX_ID_NUMBER, ['tax_id' => $first->value]);
    }

    /**
     * What an applied change moved, the plan before compared with the plan after: the feature it
     * added or removed, or, between two plans carrying the same features, the plan alone.
     */
    private static function movedOf(Plan $from, Plan $to): string
    {
        $had = $from->features->allows(Feature::Staff);
        $has = $to->features->allows(Feature::Staff);

        return match (true) {
            ! $had && $has => self::STAFF_ADDED,
            $had && ! $has => self::STAFF_REMOVED,
            default => self::PLAN_CHANGED,
        };
    }

    /**
     * A change the provider took whose answer the sync could not land: the provider's change
     * stands, so the on-its-way sentence would promise a landing that is not coming on its own.
     * Logged beside the sync's own line naming the cause, and worded as its own error.
     *
     * @return array{kind: string, message: string}
     */
    private function standing(Tenant $tenant, Plan $plan, string $logged, string $sentence): array
    {
        $this->log->error($logged, ['tenant' => $tenant->slug, 'plan' => $plan->key]);

        return ['kind' => 'error', 'message' => $sentence];
    }

    /**
     * The catalogue's plans read on the first call and handed back on every later one, or null,
     * logged once, where nobody can read the catalogue.
     *
     * @return Closure(): (list<Plan>|null)
     */
    private function catalogueOnce(Tenant $tenant): Closure
    {
        $read = false;
        $plans = null;

        return function () use ($tenant, &$read, &$plans): ?array {
            if (! $read) {
                $read = true;

                try {
                    $plans = $this->catalogue->plans();
                } catch (CatalogueUnreadable $cause) {
                    $this->log->error(self::CATALOGUE_LOGGED, ['tenant' => $tenant->slug, 'message' => $cause->getMessage()]);
                }
            }

            return $plans;
        };
    }

    /**
     * The checkout's control, offered in the three states the checkout serves
     * (Resubscribe::serves): its label, the on-its-way sentence while a recorded checkout is open,
     * and either the plan its press posts or the plan choice it offers, never both. The way back
     * from a subscription posts the plan the row last carried where it is listed and the roster
     * allows it; a trial, a trial that ended unpaid, and a way back whose plan is unlisted or
     * refused offer the choice, each catalogue plan with its name and price as the plan card spells
     * them and the floor carrying the sentence its press would answer while more than one member is
     * active, worded for the choice in this state: in a trial staff can be deactivated, and once
     * setup changes are paused they cannot. A catalogue nobody can read offers no control, the log
     * already holding the cause.
     *
     * @param  Closure(): (list<Plan>|null)  $plans
     * @return array{label: string, pending: string|null, plan: string|null, choice: list<array{plan: string, name: string, amount: int, currency: string, blocked: string|null}>|null}|null
     */
    private function resubscribeOf(Tenant $tenant, SubscriptionState $state, ?SubscriptionRow $row, Closure $plans): ?array
    {
        $trialBehind = $tenant->trial_ends_at !== null;

        if (! Resubscribe::serves($state, $row !== null, $trialBehind)) {
            return null;
        }

        $catalogue = $plans();

        if ($catalogue === null) {
            return null;
        }

        $active = $this->staff->activeCount($tenant);
        $wayBack = $trialBehind ? null : PlanChange::currentOf($catalogue, $tenant->plan_ref);
        $posts = $wayBack !== null && PlanChange::choiceRefusal($wayBack, $active, $state) === null ? $wayBack->key : null;
        $open = $this->resubscribe->openCheckout($tenant) !== null;

        return [
            'label' => AdminLines::line(match (true) {
                $open => self::CONTINUE_CHECKOUT,
                $state === SubscriptionState::Trial => self::CHOOSE_PLAN,
                $trialBehind => self::SUBSCRIBE,
                default => self::SUBSCRIBE_AGAIN,
            }),
            'pending' => $open ? AdminLines::line(self::ON_ITS_WAY) : null,
            'plan' => $posts,
            'choice' => $posts !== null ? null : array_map(fn (Plan $plan): array => [
                'plan' => $plan->key,
                'name' => $plan->name,
                'amount' => $plan->price->minor,
                'currency' => $plan->price->currency->code,
                'blocked' => PlanChange::choiceRefusal($plan, $active, $state)?->sentence(),
            ], $catalogue),
        ];
    }

    /**
     * @return array{kind: string, sentence: string, paused: string|null}
     */
    private static function noticeOf(SubscriptionState $state, bool $trialBehind): array
    {
        [$kind, $sentence, $paused] = match ($state) {
            SubscriptionState::Active => ['success', self::ACTIVE, null],
            SubscriptionState::Grace => ['warning', self::GRACE, self::PAUSES_AFTER_GRACE],
            SubscriptionState::PastDue => ['warning', self::PAST_DUE, self::PAUSED],
            SubscriptionState::Canceled => ['warning', $trialBehind ? self::TRIAL_ENDED : self::CANCELED, self::PAUSED],
            SubscriptionState::Incomplete => ['warning', self::INCOMPLETE, self::PAUSED],
            SubscriptionState::Trial => ['success', self::TRIAL, null],
        };

        return ['kind' => $kind, 'sentence' => AdminLines::line($sentence), 'paused' => $paused === null ? null : AdminLines::line($paused)];
    }

    /**
     * The plan card, the Change plan control and, where either is missing, the one sentence
     * saying why, in the order PlanChange refuses: no subscription on file, a catalogue nobody
     * can read, a plan the catalogue does not list, or a state that takes no plan change. The
     * control moves to the plan that differs from the current one in the staff feature, and
     * carries the downgrade refusal's own sentence while it would be refused, so the page says
     * what the press would answer. A trial reads the full set as its plan, Free trial with no
     * price and no Change plan, its plan chosen at the checkout the notice offers; the catalogue's
     * sentence stands in for that choice where nobody can read the plans.
     *
     * @param  Closure(): (list<Plan>|null)  $catalogue
     * @return array{plan: array<string, mixed>|null, change: array<string, mixed>|null, unavailable: string|null}
     */
    private function plansOf(Tenant $tenant, SubscriptionState $state, ?SubscriptionRow $row, Closure $catalogue): array
    {
        if ($state === SubscriptionState::Trial) {
            return [
                'plan' => ['name' => AdminLines::line(self::TRIAL_PLAN), 'amount' => null, 'currency' => null, 'includes' => self::includesOf(Entitlements::all())],
                'change' => null,
                'unavailable' => $catalogue() === null ? AdminLines::line(self::CATALOGUE_UNREADABLE) : null,
            ];
        }

        if ($row === null || $row->subscriptionId === null) {
            return ['plan' => null, 'change' => null, 'unavailable' => PlanChangeRefused::noSubscription()->sentence()];
        }

        $plans = $catalogue();

        if ($plans === null) {
            return ['plan' => null, 'change' => null, 'unavailable' => AdminLines::line(self::CATALOGUE_UNREADABLE)];
        }

        $current = PlanChange::currentOf($plans, $tenant->plan_ref);

        if ($current === null) {
            return ['plan' => null, 'change' => null, 'unavailable' => PlanChangeRefused::unlisted()->sentence()];
        }

        $refused = PlanChange::refusalIn($state);

        if ($refused !== null) {
            return ['plan' => self::planView($current), 'change' => null, 'unavailable' => $refused->sentence()];
        }

        $staffed = $current->features->allows(Feature::Staff);
        $target = array_values(array_filter($plans, fn (Plan $plan): bool => $plan->features->allows(Feature::Staff) !== $staffed))[0] ?? null;

        return [
            'plan' => self::planView($current),
            'change' => $target === null ? null : [
                'plan' => $target->key,
                'adds' => ! $staffed,
                'name' => $target->name,
                'amount' => $target->price->minor,
                'currency' => $target->price->currency->code,
                'blocked' => PlanChange::downgradeRefusal($target, $this->staff->activeCount($tenant))?->sentence(),
            ],
            'unavailable' => null,
        ];
    }

    /**
     * @return array{name: string, amount: int, currency: string, includes: list<string>}
     */
    private static function planView(Plan $plan): array
    {
        return [
            'name' => $plan->name,
            'amount' => $plan->price->minor,
            'currency' => $plan->price->currency->code,
            'includes' => self::includesOf($plan->features),
        ];
    }

    /**
     * @return list<string>
     */
    private static function includesOf(Entitlements $features): array
    {
        return array_map(AdminLines::line(...), [...self::FLOOR_INCLUDES, $features->allows(Feature::Staff) ? self::STAFF_INCLUDES : self::ONE_MEMBER]);
    }

    /**
     * @param  array{kind: string, message: string}  $flash
     */
    private function back(Request $request, array $flash): RedirectResponse
    {
        $request->session()->flash('flash', $flash);

        return redirect()->back(fallback: AdminSession::at($request, self::PATH));
    }
}
