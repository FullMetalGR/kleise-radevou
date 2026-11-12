<?php

namespace App\Providers;

use App\Application\Clock;
use App\Application\Event\BookingApproved;
use App\Application\Event\BookingCancelled;
use App\Application\Event\BookingCompleted;
use App\Application\Event\BookingConfirmed;
use App\Application\Event\BookingExpired;
use App\Application\Event\BookingHeld;
use App\Application\Event\BookingPaidLate;
use App\Application\Event\BookingRejected;
use App\Application\Event\BookingRescheduled;
use App\Application\Event\TransitionEvent;
use App\Application\Map\Geocoder;
use App\Application\Map\StaticMap;
use App\Application\MyBookings\BookingsLinkRequested;
use App\Application\Notification\PlatformMessenger;
use App\Application\Payment\PaymentAdapter;
use App\Application\Payment\PlatformConfiguration;
use App\Application\Payment\PlatformMode;
use App\Application\Payment\ProviderRegistry;
use App\Application\Payment\RegistrationRefused;
use App\Application\Turnstile\TurnstileCheck;
use App\Billing\BillingNotConfigured;
use App\Billing\BillingProvider;
use App\Billing\CheckoutCompletion;
use App\Billing\EntitlementResolver;
use App\Billing\SignupCompletion;
use App\Billing\StoredEntitlementResolver;
use App\Billing\StripeBillingProvider;
use App\Http\Controllers\Admin\LoginController;
use App\Http\Controllers\Central\SignupController;
use App\Http\Controllers\Console\LoginController as ConsoleLoginController;
use App\Http\Controllers\MyBookingsController;
use App\Http\Controllers\Site\ContactController;
use App\Http\ErrorEnvelope;
use App\Http\ManagedBooking;
use App\Http\Middleware\HandleCentralInertiaRequests;
use App\Http\Middleware\SetTenantLocale;
use App\Http\PresentedLink;
use App\Infrastructure\Db\ConcurrencyErrorDetector;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Db\StaffRecords;
use App\Infrastructure\Map\GoogleGeocoder;
use App\Infrastructure\Map\GoogleStaticMap;
use App\Infrastructure\Notification\DemoDroppedPlatformMessages;
use App\Infrastructure\Payment\TellTheProvider;
use App\Infrastructure\Storage\ImageKind;
use App\Infrastructure\Storage\ImageLookup;
use App\Infrastructure\SystemClock;
use App\Infrastructure\Turnstile\HttpTurnstileCheck;
use App\Notifications\AcknowledgeApprovalHold;
use App\Notifications\AnnounceAutoApproval;
use App\Notifications\AnnounceCancellation;
use App\Notifications\AnnounceLatePayment;
use App\Notifications\AnnounceMove;
use App\Notifications\AnnounceNewBooking;
use App\Notifications\ApproveToTheCustomer;
use App\Notifications\AskTheBusinessToDecide;
use App\Notifications\CancelToTheCustomer;
use App\Notifications\ConfirmToTheCustomer;
use App\Notifications\ExpireToTheCustomer;
use App\Notifications\MailTheBookingsLink;
use App\Notifications\MoveToTheCustomer;
use App\Notifications\NagTheBusiness;
use App\Notifications\RejectToTheCustomer;
use App\Notifications\RemindTheCustomer;
use App\Notifications\ThankTheCustomer;
use App\Platform\ConsoleTime;
use App\Site\ChromeComposer;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Tenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector as ConcurrencyErrorDetectorContract;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * The limit for the API routes that carry no limit of their own: 300 a minute per
     * IP. The routes with their own limits bring them.
     */
    public const int API_REQUESTS_PER_MINUTE = 300;

    /**
     * The limit for GET /availability: 120 a minute per IP, carried by that route in
     * place of the general one.
     */
    public const int AVAILABILITY_REQUESTS_PER_MINUTE = 120;

    /**
     * The limit for GET /bookings/{uuid}/availability: 120 a minute per IP, carried by that
     * route in place of the general one. The same number as the public read's and a budget of
     * its own beside it: a reschedule dialog must not spend what the tenant's booking page is
     * using.
     */
    public const int MOVE_AVAILABILITY_REQUESTS_PER_MINUTE = 120;

    /**
     * The limit for POST /holds: 10 a minute per IP per tenant, carried by that route in
     * place of the general one. Keyed on both, so one address hammering one tenant never
     * spends another tenant's budget, and a household or an office behind one address still
     * books at two salons in one minute.
     */
    public const int HOLD_REQUESTS_PER_MINUTE = 10;

    /**
     * The limit for the POST /bookings/{uuid}/* family: 20 a minute per IP, carried by each
     * of those routes in place of the general one and shared between them, so a customer
     * confirming, cancelling and rescheduling spends one budget rather than three.
     */
    public const int BOOKING_WRITE_REQUESTS_PER_MINUTE = 20;

    /**
     * The admin sign-in POST's own limit: 10 a minute per address. The console's sign-in and the
     * signup's POSTs borrow the count under limiters of their own; of the admin's other routes
     * only the three billing writes and the Payments section's two live calls carry a limit,
     * below (routes/tenant.php).
     */
    public const int LOGIN_REQUESTS_PER_MINUTE = 10;

    /**
     * The admin's three billing writes, the plan change, the portal and the way back's checkout,
     * each a call to the provider: 10 a minute per address and tenant, counted across the three,
     * the sign-in's count, answered in the envelope's shape where the sign-in answers its form.
     */
    public const int BILLING_WRITE_REQUESTS_PER_MINUTE = 10;

    /**
     * The Payments section's two live calls to the provider, Save and test and Test connection:
     * 10 a minute per address and tenant, counted across the two, in the billing writes' shape.
     * The section's other writes ask the provider nothing and carry no limit.
     */
    public const int PAYMENT_CHECK_REQUESTS_PER_MINUTE = 10;

    /**
     * The My bookings link POST's own limit: five a minute per address asked for, on one
     * tenant's host. The host's other limits, the sign-in's, the public API's and the billing
     * writes' among them, count the client; this one counts the mailbox asked for, since every
     * request to it may mail somebody.
     */
    public const int MY_BOOKINGS_LINK_REQUESTS_PER_MINUTE = 5;

    /**
     * The contact form's own limit: five messages an hour per address, each of which
     * mails the support mailbox.
     */
    public const int CONTACT_MESSAGES_PER_HOUR = 5;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The framework's transaction retry asks this binding whether an error is worth a
        // retry. Its own detector finds a deadlock through English message text; this one
        // reads the SQLSTATE, so the retry survives a translated lc_messages.
        $this->app->singleton(ConcurrencyErrorDetectorContract::class, ConcurrencyErrorDetector::class);

        // One per request: the middleware binds it and releases it, and a long-lived worker
        // forgets a scoped instance between requests besides.
        $this->app->scoped(CurrentTenant::class);

        // The same arrangement for the booking a manage token unlocked: the guard binds it in
        // the middleware and the controller behind it reads it, for one request only.
        $this->app->scoped(ManagedBooking::class);

        // And for the decision link a request presented: the guard verifies it in the
        // middleware and the page behind it reads what was verified, for one request only.
        $this->app->scoped(PresentedLink::class);

        // The one clock outside a test. A use case that needs the current instant takes the
        // port, and a test binds an instant of its own in its place.
        $this->app->bind(Clock::class, SystemClock::class);

        // The platform's own mail seam: one adapter for the whole deployment behind the demo's
        // drop, resolved per use so a test that swaps the log or the transport is read.
        $this->app->bind(PlatformMessenger::class, DemoDroppedPlatformMessages::class);

        // The signup form's anti-spam check: Cloudflare Turnstile over the framework's client,
        // resolved per use so a test that binds its own check is read.
        $this->app->bind(TurnstileCheck::class, HttpTurnstileCheck::class);

        // The business page's map: Google's Geocoding and Static Maps APIs over the framework's
        // client, resolved per use so a test that binds its own is read.
        $this->app->bind(Geocoder::class, GoogleGeocoder::class);
        $this->app->bind(StaticMap::class, GoogleStaticMap::class);

        // The payment providers a tenant may connect: every adapter config/payments.php names,
        // registered in that order, and the one the environment names beside them, which is the
        // fake under test and nothing outside one. A descriptor that leaves a field out is refused
        // here, when the registry is first asked for, and not on the screen or the payment that
        // would have needed it. One registry and one adapter of each class per application, so what
        // a test asked of the fake is on the adapter it reads.
        $bindings = $this->app->make('config')->get('payments.bindings');

        foreach (is_array($bindings) ? $bindings : [] as $port => $class) {
            if (is_string($port) && is_string($class)) {
                $this->app->bindIf($port, $class);
            }
        }

        // Platform mode's keys, its fee and whether the mode is offered, read from configuration
        // each time it is asked for, through the port the adapters read, so a test that sets
        // them meets them.
        $this->app->bind(PlatformMode::class, fn (Application $app): PlatformMode => $app->make(PlatformConfiguration::class)->read());

        $this->app->singleton(ProviderRegistry::class, function (Application $app): ProviderRegistry {
            $config = $app->make('config');
            $listed = $config->get('payments.providers');
            $registry = new ProviderRegistry;

            foreach ([...(is_array($listed) ? $listed : []), $config->get('payments.provider_beside')] as $class) {
                if (! is_string($class) || $class === '') {
                    continue;
                }

                $adapter = $app->make($class);
                $registry->register($adapter instanceof PaymentAdapter ? $adapter : throw RegistrationRefused::notAnAdapter($class));
                $app->instance($class, $adapter);
            }

            return $registry;
        });

        // The tenant row alone: the stored slugs while the cached state is active or grace, the
        // floor otherwise. A test wanting a fixed answer binds a resolver of its own.
        $this->app->bind(EntitlementResolver::class, StoredEntitlementResolver::class);

        // The platform's billing provider, the class billing.provider.class names. Named by
        // nothing, the binding resolves the Stripe adapter, which is built from its configured
        // keys and refuses the first question that needs a missing one with one sentence naming
        // it: the application boots and serves without them, and billing fails where it is asked
        // for. The test environment names the fake there, as it names the array mail transport,
        // so every process a gate starts meets it, a worker booted from nothing included. One
        // per application, so what a test asked of the fake is still on its record when the
        // test reads it.
        $this->app->singleton(BillingProvider::class, function (Application $app): BillingProvider {
            $class = $app->make('config')->get('billing.provider.class');

            if (! is_string($class) || $class === '') {
                return $app->make(StripeBillingProvider::class);
            }

            $port = $app->make($class);

            return $port instanceof BillingProvider ? $port : throw BillingNotConfigured::notAPort($class);
        });

        // What a completed checkout sets going, the seam the billing webhook hands it to: the
        // completion lands the subscription the Billing screen's checkout opened on a built
        // tenant's row and builds nothing, the signup's row being built by its link's click.
        $this->app->bind(CheckoutCompletion::class, SignupCompletion::class);

        // The images each kind's rows hold, which the routes serving them ask: the logo, the cover,
        // the gallery and the map from the settings row, and the staff photos from the staff rows.
        // The kind a later column adds registers its reader here, one line each, and a kind with no
        // reader is served nowhere.
        $this->app->singleton(ImageLookup::class, function (Application $app): ImageLookup {
            $lookup = new ImageLookup;
            $lookup->register(ImageKind::Logo, fn (Tenant $tenant): array => array_filter([$app->make(SettingsReader::class)->read($tenant)->brandLogoPath]));
            $lookup->register(ImageKind::Cover, fn (Tenant $tenant): array => array_filter([$app->make(SettingsReader::class)->read($tenant)->coverPath]));
            $lookup->register(ImageKind::Gallery, fn (Tenant $tenant): array => $app->make(SettingsReader::class)->read($tenant)->galleryPaths);
            $lookup->register(ImageKind::Map, fn (Tenant $tenant): array => array_filter([$app->make(SettingsReader::class)->read($tenant)->mapPath]));
            $lookup->register(ImageKind::StaffPhoto, fn (Tenant $tenant): array => $app->make(StaffRecords::class)->photoPaths($tenant));

            return $lookup;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The console's zone is read once here, before anything else, so a zone the
        // configuration misspells fails every boot, the deploy's own commands among them, rather
        // than the console door's first request.
        $this->app->make(ConsoleTime::class);

        // The notification listeners, registered by hand rather than discovered: they live
        // beside the messages they send rather than under a directory the framework scans, and
        // a subscription that exists only because of where a file sits is a subscription nobody
        // can find from the event. Each subscribes to one record, which is what the records are
        // nine classes for, except the reminder's, which is scheduled by the three records that
        // land or move a confirmed booking and is one listener so that when a reminder is
        // queued is a rule written once; it is registered beside each of its three records, so
        // a record's listeners read as one block.
        Event::listen(BookingHeld::class, [AcknowledgeApprovalHold::class, 'handle']);
        Event::listen(BookingHeld::class, [AskTheBusinessToDecide::class, 'handle']);
        Event::listen(BookingHeld::class, [NagTheBusiness::class, 'handle']);
        Event::listen(BookingConfirmed::class, [ConfirmToTheCustomer::class, 'handle']);
        Event::listen(BookingConfirmed::class, [AnnounceNewBooking::class, 'handle']);
        Event::listen(BookingConfirmed::class, [RemindTheCustomer::class, 'handle']);
        Event::listen(BookingApproved::class, [ApproveToTheCustomer::class, 'handle']);
        Event::listen(BookingApproved::class, [AnnounceAutoApproval::class, 'handle']);
        Event::listen(BookingApproved::class, [RemindTheCustomer::class, 'handle']);
        Event::listen(BookingRejected::class, [RejectToTheCustomer::class, 'handle']);
        Event::listen(BookingExpired::class, [ExpireToTheCustomer::class, 'handle']);
        // The cancel and not the release: BookingReleased is the checkout hold handed back
        // mid-flow, which is not news to anybody, and nothing subscribes to it.
        Event::listen(BookingCancelled::class, [CancelToTheCustomer::class, 'handle']);
        Event::listen(BookingCancelled::class, [AnnounceCancellation::class, 'handle']);
        Event::listen(BookingRescheduled::class, [MoveToTheCustomer::class, 'handle']);
        Event::listen(BookingRescheduled::class, [AnnounceMove::class, 'handle']);
        Event::listen(BookingRescheduled::class, [RemindTheCustomer::class, 'handle']);
        // A booking the business marks completed thanks its customer, with the reviews link and
        // Book again; nothing subscribes to a no-show.
        Event::listen(BookingCompleted::class, [ThankTheCustomer::class, 'handle']);
        // A late payment moves nothing; the payment webhook announces it after it marks the
        // event, and the owners alone hear that it bought nothing.
        Event::listen(BookingPaidLate::class, [AnnounceLatePayment::class, 'handle']);
        // The one message no transition sends: a My bookings link minted at the customer's request
        // on the hosted page, announced after its row is written, and mailed as every message is.
        Event::listen(BookingsLinkRequested::class, [MailTheBookingsLink::class, 'handle']);
        // What the payment provider is told after a transition is no message: one listener on
        // the marker, beside and not among the notifications' above. It queues a session's
        // expiry where a hold ended and a payment's flag where a settled booking was
        // cancelled.
        Event::listen(TransitionEvent::class, [TellTheProvider::class, 'handle']);

        // The admin's bundle is linked root-relative, so it loads from the tenant's own origin
        // whatever root URL the application is configured or forced to: a module script from
        // another origin is a cross-origin request the page would have to be granted.
        Vite::createAssetPathsUsing(fn (string $path): string => '/'.$path);

        // The site's layout reads its chrome from the request's route: a page's controller renders
        // its own view, and the head, the header and the footer around it are the composer's. The
        // central root view is the signup's frame, the same header and footer around the Inertia
        // root.
        View::composer(['site.layout', HandleCentralInertiaRequests::ROOT_VIEW], ChromeComposer::class);

        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(self::LOGIN_REQUESTS_PER_MINUTE)
            ->by((string) $request->ip())
            ->response(LoginController::tooMany(...)));
        // The console's door: a budget of its own, keyed on the address as the admin's door is,
        // and past it the console's form at 429.
        RateLimiter::for('console-login', fn (Request $request): Limit => Limit::perMinute(self::LOGIN_REQUESTS_PER_MINUTE)
            ->by((string) $request->ip())
            ->response(ConsoleLoginController::tooMany(...)));
        RateLimiter::for('signup', fn (Request $request): Limit => Limit::perMinute(self::LOGIN_REQUESTS_PER_MINUTE)
            ->by((string) $request->ip())
            ->response(SignupController::tooMany(...)));
        // The contact form's POST: a budget of its own, five messages an hour keyed on the address
        // alone, since every message is a mail to the support mailbox, the two languages on one
        // count. Past it the contact page at 429 with its refusal, never the envelope.
        RateLimiter::for('contact', fn (Request $request): Limit => Limit::perHour(self::CONTACT_MESSAGES_PER_HOUR)
            ->by((string) $request->ip())
            ->response(ContactController::tooMany(...)));
        // The API's limits answer the envelope at 429 in the request's language, which
        // tooManyEnvelope reads before tenant resolution has run.
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(self::API_REQUESTS_PER_MINUTE)->by((string) $request->ip())
            ->response(self::tooManyEnvelope(...)));
        RateLimiter::for('availability', fn (Request $request): Limit => Limit::perMinute(self::AVAILABILITY_REQUESTS_PER_MINUTE)->by((string) $request->ip())
            ->response(self::tooManyEnvelope(...)));
        // A limiter of its own rather than the one above under another name: the framework keys
        // a named limiter's count on the name as well as on the key, so this is a second budget.
        RateLimiter::for('booking-availability', fn (Request $request): Limit => Limit::perMinute(self::MOVE_AVAILABILITY_REQUESTS_PER_MINUTE)->by((string) $request->ip())
            ->response(self::tooManyEnvelope(...)));
        // The tenant is the host, not the resolved row: the framework sorts the throttle
        // ahead of every middleware it does not know, so this limiter runs before tenant
        // resolution has bound anything. One tenant answers on one immutable subdomain, so
        // the host names it exactly.
        RateLimiter::for('holds', fn (Request $request): Limit => Limit::perMinute(self::HOLD_REQUESTS_PER_MINUTE)
            ->by($request->ip().'|'.$request->getHost())
            ->response(self::tooManyEnvelope(...)));
        // Keyed on the address and the host as the holds are, the host naming the tenant before
        // resolution has run; past it the envelope at 429, which the Billing screen words as a
        // warning notice, never the framework's page.
        RateLimiter::for('billing', fn (Request $request): Limit => Limit::perMinute(self::BILLING_WRITE_REQUESTS_PER_MINUTE)
            ->by($request->ip().'|'.$request->getHost())
            ->response(self::tooManyEnvelope(...)));
        // The billing writes' shape for the Payments section's two live calls: the address and the
        // host, past it the envelope at 429, which the section shows as the refusal's sentence.
        RateLimiter::for('payment-checks', fn (Request $request): Limit => Limit::perMinute(self::PAYMENT_CHECK_REQUESTS_PER_MINUTE)
            ->by($request->ip().'|'.$request->getHost())
            ->response(self::tooManyEnvelope(...)));
        // Keyed on the address the form asks for, lower-cased and trimmed as citext would compare
        // it, and the host, which names the tenant before resolution has run: five links a
        // minute to one mailbox from one business, whoever asks. Past it the form at 429 with its
        // refusal, never the envelope.
        RateLimiter::for('my-bookings', fn (Request $request): Limit => Limit::perMinute(self::MY_BOOKINGS_LINK_REQUESTS_PER_MINUTE)
            ->by(self::addressAskedFor($request).'|'.$request->getHost())
            ->response(MyBookingsController::tooMany(...)));
        // Keyed on the address alone: the family is reached with a credential for one
        // booking, so there is no tenant's budget for an address to spend.
        RateLimiter::for('bookings', fn (Request $request): Limit => Limit::perMinute(self::BOOKING_WRITE_REQUESTS_PER_MINUTE)->by((string) $request->ip())
            ->response(self::tooManyEnvelope(...)));
    }

    /**
     * A limit's answer as the envelope at 429, its Retry-After and its counts among the headers, in
     * the request's language: the limit answers before tenant resolution runs, so the language is
     * read here as the tenant group would read it, on the API the lang parameter, else the default
     * of the tenant the host names, under /admin the signed-in user's, else that default, and
     * English for a host naming no tenant.
     *
     * @param  array<string, mixed>  $headers
     */
    private static function tooManyEnvelope(Request $request, array $headers): JsonResponse
    {
        return app(SetTenantLocale::class)->beforeResolution(
            $request,
            fn (): JsonResponse => ErrorEnvelope::for(new ThrottleRequestsException(headers: $headers))->toResponse(),
        );
    }

    /**
     * The address a My bookings request names, as the limiter counts it: lower-cased and trimmed,
     * and the empty string for no address at all, which is one count of its own.
     */
    private static function addressAskedFor(Request $request): string
    {
        $email = $request->input('email');

        return is_string($email) ? mb_strtolower(trim($email)) : '';
    }
}
