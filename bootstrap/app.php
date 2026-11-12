<?php

use App\Http\ErrorEnvelope;
use App\Http\Middleware\KeepLinkTokensOutOfTheSession;
use App\Http\Middleware\PublicRoute;
use App\Http\Middleware\RequiresActiveSubscription;
use App\Http\Middleware\RequiresBillingSignature;
use App\Http\Middleware\RequiresDecisionLink;
use App\Http\Middleware\RequiresManageToken;
use App\Http\Middleware\RequiresOperator;
use App\Http\Middleware\RequiresPayLink;
use App\Http\Middleware\RequiresPaymentSignature;
use App\Http\Middleware\RequiresPermission;
use App\Http\Middleware\RequiresPlatformSignature;
use App\Http\Middleware\RequiresSignIn;
use App\Http\Middleware\SetSiteLocale;
use App\Http\MissingManageToken;
use App\Http\PayPages;
use App\Http\TurnstileGate;
use App\Site\SiteLanguage;
use App\Tenancy\TenantPages;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The four authorization declarations a tenant route may carry. Each is the check it names:
        // public admits everyone by design, manage-token runs the customer's guard, decision-link
        // runs the signature and its three checks, and pay-link, below, runs the payment link's
        // signature and outer life, so a route cannot claim any of the last three without the check
        // that makes it true.
        //
        // The admin adds two more: permission runs the session and the one permission a route
        // names, and signed-in runs the session alone for the 404 page inside the shell and the top
        // bar's language control, each every signed-in user's and no permission's. The subscription
        // gate is not an access declaration but a second check a configuration write carries beside
        // its permission.
        //
        // The billing webhook on the central host carries one of its own, billing-signature: the
        // provider's signature over the body, verified by the billing port. The payment webhook
        // carries payment-signature: the tenant's provider's signature, verified by that
        // provider's adapter with the secret of the connection its token names.
        //
        // pay-link runs the signature of the approved message's payment link and its outer life:
        // the credential a customer holds when an approval left them a payment to make and no
        // manage token.
        //
        // The console's host carries operator on every route but the door: the console guard's
        // operator, or the door.
        //
        // site-locale is no access declaration: it sets the language a site route answers in,
        // and SiteRoutes carries it beside public on every route it registers.
        $middleware->alias([
            PublicRoute::NAME => PublicRoute::class,
            RequiresManageToken::NAME => RequiresManageToken::class,
            RequiresDecisionLink::NAME => RequiresDecisionLink::class,
            RequiresPayLink::NAME => RequiresPayLink::class,
            RequiresPermission::NAME => RequiresPermission::class,
            RequiresSignIn::NAME => RequiresSignIn::class,
            RequiresActiveSubscription::NAME => RequiresActiveSubscription::class,
            RequiresBillingSignature::NAME => RequiresBillingSignature::class,
            RequiresPaymentSignature::NAME => RequiresPaymentSignature::class,
            RequiresPlatformSignature::NAME => RequiresPlatformSignature::class,
            RequiresOperator::NAME => RequiresOperator::class,
            SetSiteLocale::NAME => SetSiteLocale::class,
        ]);

        // The health check is registered in routes/central.php, on the central host alone,
        // rather than through the health option, which would answer on every host. It stays
        // reachable while the application is down for maintenance, as the option would have it.
        $middleware->preventRequestsDuringMaintenance(except: ['up']);

        // The public API takes no CSRF token. It is unauthenticated, it carries no cookie that
        // could be ridden, and it is meant to be called cross-origin from the embed on a tenant's
        // own site, which has no token to send and no session to take one from. Leaving it in would
        // refuse every hold from an embed with a 419 that no test could see, because the token
        // middleware stands down under the test runner.
        $middleware->validateCsrfTokens(except: ['api/*']);

        // The session the web group starts, less the previous URL on a route whose URL is a
        // credential, the list KeepLinkTokensOutOfTheSession::ROUTES holds: the My bookings link
        // and its open, the set-password link and the support session's, each with its malformed
        // sibling, and the manage page, the pay hop, the manage view's two reads and the payment
        // link's status page, so no token and no link's signature is stored in a sessions row.
        $middleware->web(replace: [StartSession::class => KeepLinkTokensOutOfTheSession::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The Turnstile token, the signup's and the contact form's, is posted once and never
        // flashed back with the input.
        $exceptions->dontFlash(TurnstileGate::FIELD);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('admin/api/*') || $request->is('webhooks/*') || $request->expectsJson(),
        );

        // The manage guard's refusal on the pay hop, a page a person navigates to: the guard's
        // statuses, 404 for every failed credential and 400 for none, with one worded body, at the
        // bare path and under either prefix alike.
        $exceptions->render(fn (NotFoundHttpException|MissingManageToken $e, Request $request): ?Response => $request->routeIs(...TenantPages::names('pay'))
            ? app(PayPages::class)->refused($e instanceof MissingManageToken ? Response::HTTP_BAD_REQUEST : Response::HTTP_NOT_FOUND)
            : null);

        // Every error under /api/v1 is the error envelope, whatever threw it and whatever the debug
        // setting, and so is every error under the admin's one JSON endpoint, which answers the
        // public route's shape with its sentences, and every error the billing webhook answers the
        // provider, which must never keep a framework page or a trace in its delivery log. An
        // exception carrying a response of its own is code that already decided what to answer, and
        // is let through. The detail is in the request's language, the locale the tenant group set,
        // and in English on the webhooks, whose reader is a provider's log.
        $exceptions->render(fn (Throwable $e, Request $request): ?JsonResponse => ($request->is('api/*') || $request->is('admin/api/*') || $request->is('webhooks/*')) && ! $e instanceof HttpResponseException
            ? ErrorEnvelope::for($e, $request->is('webhooks/*') ? SiteLanguage::English : null)->toResponse()
            : null);
    })->create();
