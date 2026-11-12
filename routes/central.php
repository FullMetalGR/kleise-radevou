<?php

use App\Http\Controllers\BillingWebhookController;
use App\Http\Controllers\Central\SignupController;
use App\Http\Controllers\Central\SignupPendingController;
use App\Http\Controllers\Central\SignupVerifyController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\PlatformWebhookController;
use App\Http\Controllers\Site\ContactController;
use App\Http\Controllers\Site\FaqController;
use App\Http\Controllers\Site\FeaturesController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\LegalController;
use App\Http\Controllers\Site\NotFoundController;
use App\Http\Controllers\Site\PricingController;
use App\Http\Controllers\Site\SitemapController;
use App\Http\Controllers\Site\VerticalController;
use App\Http\Middleware\HandleCentralInertiaRequests;
use App\Http\Middleware\PublicRoute;
use App\Http\Middleware\RequiresBillingSignature;
use App\Http\Middleware\RequiresPaymentSignature;
use App\Http\Middleware\RequiresPlatformSignature;
use App\Site\SignupLinks;
use App\Site\SitePages;
use App\Site\SiteRoutes;
use App\Site\Vertical;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

/*
 * Routes on the central host: the marketing site, signup, the billing webhooks and the platform's
 * own pages, as they arrive. Tenant resolution never runs here. A request for the central host
 * resolves no tenant, and a tenant route asked for on it is not found.
 *
 * The health check lives here rather than behind the application bootstrap's health option,
 * which registers it with no domain and so on every host, a suspended tenant's included. On the
 * central host alone it says whether the deployment is up; a tenant host answers 404 for it, as
 * for any path it has no route for. It does what the framework's own registration does: it
 * dispatches the diagnosing event so a listener can check what it likes, and answers down when
 * one throws.
 */
Route::get('/up', function (Dispatcher $events): JsonResponse {
    try {
        $events->dispatch(new DiagnosingHealth);
    } catch (Throwable $e) {
        if (app()->hasDebugModeEnabled()) {
            throw $e;
        }

        report($e);

        return new JsonResponse(['status' => 'down'], 500);
    }

    return new JsonResponse(['status' => 'up']);
})->name('health');

/*
 * The platform's billing provider's one door: machine to machine, answering the provider and
 * never a browser. Its one access check is the provider's signature over the raw body, which
 * the port verifies. It leaves the web group behind, so it has no session to start and no
 * cookie to set, and no cross-site token to ask a provider for; no tenant is resolved on this
 * host, and the subscription gates it never.
 */
Route::post('/webhooks/billing', BillingWebhookController::class)
    ->middleware(RequiresBillingSignature::NAME)
    ->withoutMiddleware('web')
    ->name('webhooks.billing');

/*
 * A tenant's own payment provider's door, own mode: machine to machine like billing's, outside
 * the web group, answering the envelope. The token names the tenant's connection through the
 * central routing rows; its one access check is the provider's signature over the raw body,
 * declared as payment-signature and verified by the adapter of the provider in the path with that
 * connection's own secret. Never gated by the subscription: a lapsed tenant's customers still
 * pay.
 */
Route::post('/webhooks/{provider}/{token}', PaymentWebhookController::class)
    ->where('provider', '[a-z][a-z0-9_]*')
    ->middleware(RequiresPaymentSignature::NAME)
    ->withoutMiddleware('web')
    ->name('webhooks.payments');

/*
 * The platform-mode payment webhook: a provider telling the platform about its connected
 * accounts, the tenant found by the account the payload names. Its access check is the
 * signature under the platform's own signing secret, declared as platform-signature; outside
 * the web group and never gated by the subscription.
 */
Route::post('/webhooks/{provider}', PlatformWebhookController::class)
    ->where('provider', '[a-z][a-z0-9_]*')
    ->middleware(RequiresPlatformSignature::NAME)
    ->withoutMiddleware('web')
    ->name('webhooks.platform');

/*
 * The marketing site: server-rendered pages in English at the root and in Greek under /el, each
 * page registered by SiteRoutes as two routes, the English one named for the page and the Greek
 * one el., each declaring public on the route and setting its locale, and running without the
 * session. The registry reads the pages back from the router, so a page added here is in the nav,
 * the footer, the sitemap and CentralSurfaceTest's walk with no other edit. Nothing here is gated
 * by the subscription: there is no tenant.
 */
SiteRoutes::page('/', HomeController::class, 'site.home', 'home');
SiteRoutes::page('/features', FeaturesController::class, 'site.features', 'features');
SiteRoutes::page('/pricing', PricingController::class, 'site.pricing', 'pricing');
SiteRoutes::page('/faq', FaqController::class, 'site.faq', 'faq');

/*
 * The six vertical pages: one template, one page served once per key, the parameter constrained
 * to Vertical's six keys, so any other value, an uppercase key among them, falls to the 404
 * below. Its copy is the key's entry in verticals.php, and the footer, the sitemap and the walk
 * list it once per key.
 */
SiteRoutes::page('/for/{vertical}', VerticalController::class, SitePages::VERTICAL, 'verticals', Vertical::keys());

/*
 * The legal pages: the terms of service, the privacy policy, the cookie notice and the data
 * processing agreement, each a page of the site like the rest, rendered by the one template over
 * its file under lang/{en,el}/legal, with the entity line and, until the texts are reviewed, the
 * draft notice.
 */
SiteRoutes::page('/terms', LegalController::class, SitePages::TERMS, 'legal/terms');
SiteRoutes::page('/privacy', LegalController::class, SitePages::PRIVACY, 'legal/privacy');
SiteRoutes::page('/cookies', LegalController::class, SitePages::COOKIES, 'legal/cookies');
SiteRoutes::page('/dpa', LegalController::class, SitePages::DPA, 'legal/dpa');

/*
 * The contact page: the page's GET and its POST at the same address, both public and in both
 * languages, keeping the session the form needs for its cross-site token, its flash and its
 * errors, the one page of the site that does. The POST is the one central route under a limit
 * beside the signup's, five an hour per address under a limiter of its own, contact, past which
 * it answers the page at 429. The registry lists the GET alone: the nav, the footer and the
 * sitemap link the page, never its POST.
 */
SiteRoutes::form('/contact', [ContactController::class, 'show'], [ContactController::class, 'send'], 'site.contact', 'contact', ['throttle:contact']);

/*
 * The verified signup: Inertia over the session, rendered by the admin bundle from pages of its
 * own through the central host's adapter, which hands the flash, the brand, the language and the
 * signup's strings. There is no JSON endpoint here; the pending page polls by partial reload, and
 * the verification link is a plain GET from the owner's mail.
 *
 * Each route is registered in both languages through SiteRoutes, English and its Greek twin under
 * /el named el., each declaring its own access, never the group: all are public, anyone signing
 * up. Each carries its locale, the language the row keeps and every sentence is read in. The two
 * POSTs, the form and the pending page's resend, share a limit of their own across both
 * languages, as many a minute per address as the sign-in POST, and answer past it with the form
 * at 429 and a worded refusal in the route's language. The link's token is judged at this
 * boundary: 64 hex characters reach the click, and any other shape reaches the route after it,
 * which answers as for a link nobody holds. The two POSTs, which answer past their limit with the
 * form, switch language to the form itself, a POST's path being no address a link can name; the
 * link's two routes switch to the same path in the other language, as a page does. Nothing here
 * is gated by the subscription: there is no tenant yet. Registered after the site's pages, so the
 * registry lists the form after them in the sitemap.
 *
 * The adapter is carried on each route after its locale, never on a group around them: a group's
 * middleware runs before the route's own, and the adapter shares the language and the strings on
 * its way in, so it must run inside the locale middleware to share the route's language.
 */
$pages = [
    ...SiteRoutes::twin('GET', '/signup', [SignupController::class, 'show'], SignupLinks::FORM),
    ...SiteRoutes::twin('GET', '/signup/{slug}/pending', SignupPendingController::class, SignupLinks::PENDING),
];

$posts = [
    ...SiteRoutes::twin('POST', '/signup', [SignupController::class, 'store'], SignupLinks::STORE),
    ...SiteRoutes::twin('POST', '/signup/{slug}/resend', [SignupController::class, 'resend'], SignupLinks::RESEND),
];

foreach ($posts as $post) {
    $post->middleware('throttle:signup');
}

$clicks = SiteRoutes::twin('GET', '/signup/verify/{token}', SignupVerifyController::class, SignupLinks::VERIFY);

foreach ($clicks as $click) {
    $click->where('token', SignupVerifyController::TOKEN);
}

$links = [...$clicks, ...SiteRoutes::twin('GET', '/signup/verify/{malformed}', [SignupVerifyController::class, 'malformed'], SignupLinks::VERIFY_MALFORMED)];

foreach ($posts as $refusing) {
    $refusing->setAction([...$refusing->action, SiteRoutes::SWITCH => SignupLinks::FORM]);
}

foreach ([...$pages, ...$posts, ...$links] as $route) {
    $route->middleware(HandleCentralInertiaRequests::class);
}

/*
 * The sitemap: one route, no Greek twin, public and without the session as the pages are, built
 * from the same registry.
 */
Route::get('/sitemap.xml', SitemapController::class)->middleware(PublicRoute::NAME)->withoutMiddleware(SiteRoutes::SESSION)->name('site.sitemap');

/*
 * Last: any other GET path on this host is the site's 404 in the path's language, but the
 * error envelope's paths, which keep their envelope. The fallback carries this host's domain,
 * so the tenant and console hosts keep their own.
 */
SiteRoutes::fallback(NotFoundController::class);
