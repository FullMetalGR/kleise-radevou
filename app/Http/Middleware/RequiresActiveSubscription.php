<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Billing\SubscriptionState;
use App\Http\BookingPayload;
use App\Http\ErrorEnvelope;
use App\Platform\ClosingWindow;
use App\Platform\TenantPlan;
use App\Site\SiteLanguage;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Tenant;
use Closure;
use DateTimeImmutable;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The subscription gate, carried by configuration writes and nothing else.
 *
 * It reads the tenant row the resolver loaded and touches no network. It reads the status beside
 * the state: a closing tenant, closed by its owner or by the platform after an unpaid trial, is
 * refused with a 403 whose code is account_closing, its sentence naming the day the account
 * closes in the tenant's clock and the way back, and whose data carries the instant the window
 * ends, whatever its subscription state, since an owner who closed with a subscription still
 * active has frozen the account as surely as a lapse would. Otherwise the state is active for
 * exactly active, grace and trial; anything else is refused with a 403 whose code is
 * subscription_required and whose data names the state. One code names one reason.
 *
 * Never on a read, never on the booking lifecycle, never on a public or guest route, never on
 * the billing routes, and never on the Danger zone's export and close: a billing lapse or a
 * closing costs a tenant its configuration writes and nothing else. The surface walk asserts that
 * no admin GET carries it. The closing sentence's way back reads whether the tenant is in the
 * free shape, one question to the subscriptions table asked on a closing tenant's write alone.
 */
final readonly class RequiresActiveSubscription
{
    public const string NAME = 'subscription';

    /** The 403's sentence, a key of lang/{locale}/admin/billing.php read in the user's language. */
    public const string DETAIL = 'admin/billing.refusals.subscription';

    public const string CLOSING = 'account_closing';

    public function __construct(
        private CurrentTenant $current,
        private DatabaseManager $db,
        private Translator $translator,
        #[Config('site.contact_address')] private string $support,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->current->get();
        $state = SubscriptionState::from($tenant->subscription_state);
        $closingAt = ClosingWindow::of($tenant);

        if ($closingAt !== null) {
            return $this->closing($tenant, $state, $closingAt);
        }

        if ($state->isActive()) {
            return $next($request);
        }

        $detail = $this->translator->get(self::DETAIL);

        return (new ErrorEnvelope('subscription_required', 403, is_string($detail) ? $detail : self::DETAIL, ['state' => $state->value]))->toResponse();
    }

    private function closing(Tenant $tenant, SubscriptionState $state, DateTimeImmutable $closingAt): Response
    {
        $subscribed = $this->db->connection()->table('subscriptions')->where('tenant_id', $tenant->id)->exists();
        $free = TenantPlan::free($state, $tenant->trial_ends_at !== null, $subscribed);
        // The day in the language the refusal is read in, the user's, the locale SetTenantLocale
        // set.
        $detail = ClosingWindow::frozen(ClosingWindow::dayOf($closingAt, $tenant->zone(), SiteLanguage::from($this->translator->getLocale())), $free ? $this->support : null);

        return (new ErrorEnvelope(self::CLOSING, 403, $detail, ['closes_at' => BookingPayload::instant(ClosingWindow::endOf($closingAt))]))->toResponse();
    }
}
