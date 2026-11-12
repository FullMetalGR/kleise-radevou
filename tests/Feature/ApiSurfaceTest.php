<?php

use App\Application\Dto\BookingItemSnapshot;
use App\Application\Dto\BookingSnapshot;
use App\Application\Dto\Customer;
use App\Domain\Enum\BookingStatus;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Http\BookingPayload;
use App\Http\Middleware\PublicRoute;
use App\Http\Middleware\RequiresActiveSubscription;
use App\Http\Middleware\RequiresDecisionLink;
use App\Http\Middleware\RequiresManageToken;
use App\Http\Middleware\RequiresOperator;
use App\Http\Middleware\RequiresPayLink;
use App\Http\Middleware\RequiresPermission;
use App\Http\Middleware\RequiresSignIn;
use App\Http\Requests\AdminWriteRequest;
use App\Http\Requests\AvailabilityRequest;
use App\Http\Requests\BlackoutRequest;
use App\Http\Requests\BookingListRequest;
use App\Http\Requests\CalendarRequest;
use App\Http\Requests\ChangePlanRequest;
use App\Http\Requests\CheckoutRequest;
use App\Http\Requests\CloseRequest;
use App\Http\Requests\ColourByRequest;
use App\Http\Requests\ConsoleAuditRequest;
use App\Http\Requests\ConsoleMessagesRequest;
use App\Http\Requests\ConsoleTenantsRequest;
use App\Http\Requests\ContactRequest;
use App\Http\Requests\CoverRequest;
use App\Http\Requests\CustomerBlockRequest;
use App\Http\Requests\CustomerNoteRequest;
use App\Http\Requests\CustomerProfileRequest;
use App\Http\Requests\CustomerRequest;
use App\Http\Requests\DecisionRequest;
use App\Http\Requests\GalleryPhotoRequest;
use App\Http\Requests\HomeRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\LogoRequest;
use App\Http\Requests\MoveAvailabilityRequest;
use App\Http\Requests\MoveRequest;
use App\Http\Requests\MyBookingsLinkRequest;
use App\Http\Requests\NewBookingRequest;
use App\Http\Requests\NewHoldRequest;
use App\Http\Requests\NewTenantRequest;
use App\Http\Requests\OpeningHoursRequest;
use App\Http\Requests\OperatorReasonRequest;
use App\Http\Requests\PaymentCredentialsRequest;
use App\Http\Requests\PlatformConnectRequest;
use App\Http\Requests\PublicAvailabilityRequest;
use App\Http\Requests\RejectRequest;
use App\Http\Requests\ReportRequest;
use App\Http\Requests\RescheduleRequest;
use App\Http\Requests\ServiceRequest;
use App\Http\Requests\SetPasswordRequest;
use App\Http\Requests\SettingsRequest;
use App\Http\Requests\ShiftDayRequest;
use App\Http\Requests\ShiftPatternRequest;
use App\Http\Requests\SignupRequest;
use App\Http\Requests\StaffColourRequest;
use App\Http\Requests\StaffPhotoRequest;
use App\Http\Requests\StaffRequest;
use App\Http\Requests\StaffServicesRequest;
use App\Http\Requests\SuspendRequest;
use App\Http\Requests\TeamInviteRequest;
use App\Http\Requests\TeamRoleRequest;
use App\Http\Requests\TenantFeaturesRequest;
use App\Http\Requests\WizardAvailabilityRequest;
use App\Site\SiteLanguage;
use App\Tenancy\Permission;
use App\Tenancy\TenantPages;
use App\Tenancy\TenantUrls;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

/*
 * The surface as it is declared, against the surface as it is built. Three things are asked
 * here and none of them needs a tenant or a schema.
 *
 * Every route declares an explicit authorization check and there is no implicit allow. On a
 * tenant subdomain the four declarations are public, for the routes anyone may reach by design,
 * manage-token, for the routes a customer reaches with the one credential they hold,
 * decision-link, for the two pages an approver reaches with a signed link and nothing else, and
 * pay-link, for the page and the act the approved message's payment link reaches with its
 * signature and nothing else. Under the admin prefix a route declares the one permission it
 * needs, the door, the set-password link's page and the support session's link alone are public,
 * and the 404 page inside the shell declares signed-in. The rule is one declaration on the route
 * and never on a group; a declaration may name more than one permission, as the one admin JSON
 * endpoint's does, and the middleware admits a user holding any of them. The walk below fails on
 * a route declaring none, and it fails on a route carrying two declarations, which would be two
 * answers to one question.
 *
 * The rate limits are walked the same way and for the same reason. A route carrying a limit of
 * its own steps out of the group's rather than being counted by both, and the pages of this host
 * are counted by neither, which is a decision three route comments state and nothing else held.
 *
 * The declaration is carried as middleware rather than as a label, so the manage-token routes
 * cannot be reached without the guard having run. It is declared per route and never on a
 * group: a group declaration would hand its access to the next route somebody registers inside
 * it, which is the implicit allow the rule exists to forbid.
 *
 * The wire shape is held twice, because the two halves can run in different places. The keys of
 * the guest view and of the hold are pinned here as literals, which runs in every checkout: the
 * answer cannot change shape without somebody editing this list on purpose. The last test holds
 * a written table of the same keys against the class, which it can only do where that table is.
 * The table is local and reaches no clone, so that test states its absence and stands down
 * rather than reading an empty file as agreement; what it would have caught in a bare checkout,
 * the pin catches anyway.
 */

/**
 * Every route registered on a tenant subdomain, by name, with the access declarations it
 * carries. Central routes are another surface with another audience and are not walked here.
 *
 * @return array<string, list<string>>
 */
function tenantRouteAccess(): array
{
    $domain = '{tenant}.'.app(TenantUrls::class)->centralHost();
    $declared = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if ($route->getDomain() !== $domain) {
            continue;
        }

        $middleware = array_filter($route->gatherMiddleware(), is_string(...));

        $declared[$route->getName() ?? $route->uri()] = array_values(array_filter(
            $middleware,
            fn (string $name): bool => in_array($name, [PublicRoute::NAME, RequiresManageToken::NAME, RequiresDecisionLink::NAME, RequiresPayLink::NAME, RequiresSignIn::NAME], true)
                || str_starts_with($name, RequiresPermission::NAME.':'),
        ));
    }

    return $declared;
}

it('declares an access on every route of a tenant subdomain, exactly one and never two', function () {
    $access = tenantRouteAccess();

    expect($access)->not->toBeEmpty()
        ->and(array_keys(array_filter($access, fn (array $markers): bool => count($markers) !== 1)))->toBe([]);
});

it('names the public surface, the routes behind the manage token and the pages behind a signed link', function () {
    expect(tenantRouteAccess())->toBe([
        'probe' => [PublicRoute::NAME],
        // A page's twins under /en and /el declare what its bare route declares, so a prefix
        // changes no route's access.
        'hosted' => [PublicRoute::NAME],
        'en.hosted' => [PublicRoute::NAME],
        'el.hosted' => [PublicRoute::NAME],
        'embed' => [PublicRoute::NAME],
        'logo' => [PublicRoute::NAME],
        'images' => [PublicRoute::NAME],
        'manage' => [PublicRoute::NAME],
        'en.manage' => [PublicRoute::NAME],
        'el.manage' => [PublicRoute::NAME],
        'my-bookings' => [PublicRoute::NAME],
        'my-bookings.link' => [PublicRoute::NAME],
        'my-bookings.sign-out' => [PublicRoute::NAME],
        'my-bookings.open' => [PublicRoute::NAME],
        'my-bookings.open.act' => [PublicRoute::NAME],
        'en.my-bookings' => [PublicRoute::NAME],
        'en.my-bookings.link' => [PublicRoute::NAME],
        'en.my-bookings.sign-out' => [PublicRoute::NAME],
        'en.my-bookings.open' => [PublicRoute::NAME],
        'en.my-bookings.open.act' => [PublicRoute::NAME],
        'el.my-bookings' => [PublicRoute::NAME],
        'el.my-bookings.link' => [PublicRoute::NAME],
        'el.my-bookings.sign-out' => [PublicRoute::NAME],
        'el.my-bookings.open' => [PublicRoute::NAME],
        'el.my-bookings.open.act' => [PublicRoute::NAME],
        'pay' => [RequiresManageToken::NAME],
        'en.pay' => [RequiresManageToken::NAME],
        'el.pay' => [RequiresManageToken::NAME],
        'pay.link' => [RequiresPayLink::NAME],
        'pay.link.act' => [RequiresPayLink::NAME],
        'en.pay.link' => [RequiresPayLink::NAME],
        'en.pay.link.act' => [RequiresPayLink::NAME],
        'el.pay.link' => [RequiresPayLink::NAME],
        'el.pay.link.act' => [RequiresPayLink::NAME],
        'decision' => [RequiresDecisionLink::NAME],
        'decision.decide' => [RequiresDecisionLink::NAME],
        'en.decision' => [RequiresDecisionLink::NAME],
        'en.decision.decide' => [RequiresDecisionLink::NAME],
        'el.decision' => [RequiresDecisionLink::NAME],
        'el.decision.decide' => [RequiresDecisionLink::NAME],
        'api.config' => [PublicRoute::NAME],
        'api.services' => [PublicRoute::NAME],
        'api.service' => [PublicRoute::NAME],
        'api.availability' => [PublicRoute::NAME],
        'api.holds' => [PublicRoute::NAME],
        'api.hold.release' => [RequiresManageToken::NAME],
        'api.booking' => [RequiresManageToken::NAME],
        'api.booking.availability' => [RequiresManageToken::NAME],
        'api.booking.confirm' => [RequiresManageToken::NAME],
        'api.booking.cancel' => [RequiresManageToken::NAME],
        'api.booking.reschedule' => [RequiresManageToken::NAME],
        'admin.login' => [PublicRoute::NAME],
        'admin.login.attempt' => [PublicRoute::NAME],
        'admin.logout' => [PublicRoute::NAME],
        'admin.set-password' => [PublicRoute::NAME],
        'admin.set-password.malformed' => [PublicRoute::NAME],
        'admin.set-password.store' => [PublicRoute::NAME],
        'admin.support.open' => [PublicRoute::NAME],
        'admin.support.malformed' => [PublicRoute::NAME],
        'admin.home' => ['permission:viewBookings,viewOwnCalendar'],
        'admin.calendar' => ['permission:viewBookings,viewOwnCalendar'],
        'admin.my-calendar' => [RequiresSignIn::NAME],
        'admin.bookings' => ['permission:viewBookings'],
        'admin.bookings.store' => ['permission:manageBookings'],
        'admin.api.availability' => ['permission:manageBookings,viewOwnCalendar'],
        'admin.bookings.show' => ['permission:viewBookings'],
        'admin.bookings.approve' => ['permission:approveBookings'],
        'admin.bookings.reject' => ['permission:approveBookings'],
        'admin.bookings.cancel' => ['permission:manageBookings'],
        'admin.bookings.no-show' => ['permission:manageBookings'],
        'admin.bookings.complete' => ['permission:manageBookings'],
        'admin.bookings.move' => ['permission:manageBookings'],
        'admin.api.bookings.move' => ['permission:manageBookings'],
        'admin.services' => ['permission:viewBookings'],
        'admin.services.create' => ['permission:viewBookings'],
        'admin.services.show' => ['permission:viewBookings'],
        'admin.services.store' => ['permission:manageSettings'],
        'admin.services.update' => ['permission:manageSettings'],
        'admin.services.destroy' => ['permission:manageSettings'],
        'admin.staff.services' => ['permission:manageSettings'],
        'admin.staff' => ['permission:viewBookings'],
        'admin.staff.create' => ['permission:viewBookings'],
        'admin.staff.show' => ['permission:viewBookings'],
        'admin.staff.store' => ['permission:manageSettings'],
        'admin.staff.update' => ['permission:manageSettings'],
        'admin.staff.destroy' => ['permission:manageSettings'],
        'admin.staff.photo.store' => ['permission:manageSettings'],
        'admin.staff.photo.destroy' => ['permission:manageSettings'],
        'admin.staff.colour' => ['permission:manageSettings'],
        'admin.staff.blackouts.store' => ['permission:manageSettings'],
        'admin.staff.blackouts.destroy' => ['permission:manageSettings'],
        'admin.blackouts.store' => ['permission:manageSettings'],
        'admin.blackouts.destroy' => ['permission:manageSettings'],
        'admin.shifts' => ['permission:manageSettings'],
        'admin.shifts.pattern' => ['permission:manageSettings'],
        'admin.shifts.days.update' => ['permission:manageSettings'],
        'admin.shifts.days.destroy' => ['permission:manageSettings'],
        'admin.customers' => ['permission:manageBookings'],
        'admin.customers.csv' => ['permission:manageBookings'],
        'admin.customers.show' => ['permission:manageBookings'],
        'admin.customers.export' => ['permission:manageBookings'],
        'admin.customers.erase' => ['permission:manageBookings'],
        'admin.customers.profile' => ['permission:manageBookings'],
        'admin.customers.notes.store' => ['permission:manageBookings'],
        'admin.customers.notes.destroy' => ['permission:manageBookings'],
        'admin.customers.block' => ['permission:manageBookings'],
        'admin.customers.unblock' => ['permission:manageBookings'],
        'admin.reports' => ['permission:viewBookings'],
        'admin.reports.csv' => ['permission:viewBookings'],
        'admin.team' => ['permission:manageBilling'],
        'admin.team.store' => ['permission:manageBilling'],
        'admin.team.update' => ['permission:manageBilling'],
        'admin.team.destroy' => ['permission:manageBilling'],
        'admin.team.link' => ['permission:manageBilling'],
        'admin.settings' => ['permission:manageSettings'],
        'admin.settings.export' => ['permission:manageSettings'],
        'admin.settings.close' => ['permission:manageSettings'],
        'admin.settings.update' => ['permission:manageSettings'],
        'admin.settings.colour-by' => ['permission:manageSettings'],
        'admin.opening-hours.update' => ['permission:manageSettings'],
        'admin.settings.logo.store' => ['permission:manageSettings'],
        'admin.settings.logo.destroy' => ['permission:manageSettings'],
        'admin.settings.cover.store' => ['permission:manageSettings'],
        'admin.settings.cover.destroy' => ['permission:manageSettings'],
        'admin.settings.gallery.store' => ['permission:manageSettings'],
        'admin.settings.gallery.destroy' => ['permission:manageSettings'],
        'admin.settings.link-copied' => ['permission:manageSettings'],
        'admin.settings.links.page' => ['permission:manageSettings'],
        'admin.settings.links.show' => ['permission:manageSettings'],
        'admin.settings.payments.store' => ['permission:manageSettings'],
        'admin.settings.payments.platform' => ['permission:manageSettings'],
        'admin.settings.payments.platform.resume' => ['permission:manageSettings'],
        'admin.settings.payments.test' => ['permission:manageSettings'],
        'admin.settings.payments.rotate' => ['permission:manageSettings'],
        'admin.settings.payments.disable' => ['permission:manageSettings'],
        'admin.settings.payments.enable' => ['permission:manageSettings'],
        'admin.settings.payments.destroy' => ['permission:manageSettings'],
        'admin.billing' => ['permission:manageBilling'],
        'admin.billing.plan' => ['permission:manageBilling'],
        'admin.billing.portal' => ['permission:manageBilling'],
        'admin.billing.checkout' => ['permission:manageBilling'],
        'admin.language' => [RequiresSignIn::NAME],
        'admin.not-found' => [RequiresSignIn::NAME],
    ]);
});

/**
 * Whether the subscription gate runs on a route, read from the middleware the router dispatches
 * rather than from the declaration. A route's gathered middleware still holds what
 * withoutMiddleware took off it, which is filtered only at dispatch, so a write that stepped
 * out of the gate would read as gated off its declaration and be refused by nothing. The
 * router's own resolution is asked, the one it runs a request through: aliases resolved, groups
 * expanded and the excluded middleware subtracted, whichever spelling either side uses. The
 * HTTP kernel is built first because building it is what hands the router the aliases and
 * groups of bootstrap/app.php, and a test that sends no request never builds it otherwise: the
 * router would resolve nothing and read every route as ungated.
 */
function gatedAtDispatch(RegisteredRoute $route): bool
{
    app(HttpKernel::class);

    return in_array(RequiresActiveSubscription::class, app(Router::class)->gatherRouteMiddleware($route), true);
}

/**
 * Every route under the admin prefix, by name, with the middleware it gathers, which is where
 * its access is declared, and whether the subscription gate runs on it at dispatch.
 *
 * @return array<string, array{methods: list<string>, middleware: list<string>, gated: bool}>
 */
function adminRoutes(): array
{
    $domain = '{tenant}.'.app(TenantUrls::class)->centralHost();
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if ($route->getDomain() === $domain && ($route->uri() === 'admin' || str_starts_with($route->uri(), 'admin/'))) {
            $routes[$route->getName() ?? $route->uri()] = [
                'methods' => array_values(array_filter($route->methods(), is_string(...))),
                'middleware' => array_values(array_filter($route->gatherMiddleware(), is_string(...))),
                'gated' => gatedAtDispatch($route),
            ];
        }
    }

    return $routes;
}

it('declares on every admin route one declaration naming one permission or more, or public on the door, the set-password link and the support link alone, or signed-in on the language write, the retired My calendar address and the 404 page, names permissions that exist, and reads them back as declared', function () {
    $routes = adminRoutes();

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $name => $route) {
        $permissions = array_values(array_filter($route['middleware'], fn (string $m): bool => str_starts_with($m, RequiresPermission::NAME.':')));
        $public = in_array(PublicRoute::NAME, $route['middleware'], true);
        $signedIn = in_array(RequiresSignIn::NAME, $route['middleware'], true);

        expect(count($permissions) + ($public ? 1 : 0) + ($signedIn ? 1 : 0))->toBe(1, "{$name} declares no access or more than one");

        foreach ($permissions as $permission) {
            $slugs = explode(',', substr($permission, strlen(RequiresPermission::NAME) + 1));

            foreach ($slugs as $slug) {
                expect(Permission::tryFrom($slug))->not->toBeNull("{$name} names a permission that does not exist");
            }

            expect(RequiresPermission::named($permission))->toBe(array_map(Permission::from(...), $slugs), "{$name} is not read back as it is declared");
        }
    }

    expect(array_keys(array_filter($routes, fn (array $route): bool => in_array(PublicRoute::NAME, $route['middleware'], true))))
        ->toBe(['admin.login', 'admin.login.attempt', 'admin.logout', 'admin.set-password', 'admin.set-password.malformed', 'admin.set-password.store', 'admin.support.open', 'admin.support.malformed']);
});

it('names GET /admin/api/availability and the move\'s read as the two routes under /admin/api, and refuses a third', function () {
    $json = array_filter(adminRoutes(), fn (array $route, string $name): bool => str_starts_with((string) Route::getRoutes()->getByName($name)?->uri(), 'admin/api/'), ARRAY_FILTER_USE_BOTH);

    expect(array_keys($json))->toBe(['admin.api.availability', 'admin.api.bookings.move'])
        ->and($json['admin.api.availability']['methods'])->toBe(['GET', 'HEAD'])
        ->and($json['admin.api.availability']['middleware'])->toContain('permission:manageBookings,viewOwnCalendar')
        ->and($json['admin.api.availability']['gated'])->toBeFalse()
        // The business's move: read under manageBookings as the move is written, ungated as
        // every read is.
        ->and($json['admin.api.bookings.move']['methods'])->toBe(['GET', 'HEAD'])
        ->and($json['admin.api.bookings.move']['middleware'])->toContain('permission:manageBookings')
        ->and($json['admin.api.bookings.move']['gated'])->toBeFalse();
});

it('gates no admin GET by the subscription, since no read is gated', function () {
    $gatedReads = array_keys(array_filter(
        adminRoutes(),
        fn (array $route): bool => in_array('GET', $route['methods'], true) && $route['gated'],
    ));

    expect($gatedReads)->toBe([]);
});

it('gates every configuration write of the catalog by the subscription, each under its own verb', function () {
    $routes = adminRoutes();
    $writes = [
        'admin.services.store' => ['POST'], 'admin.services.update' => ['PUT'], 'admin.services.destroy' => ['DELETE'], 'admin.staff.services' => ['PUT'],
        'admin.staff.store' => ['POST'], 'admin.staff.update' => ['PUT'], 'admin.staff.destroy' => ['DELETE'], 'admin.staff.photo.store' => ['POST'], 'admin.staff.photo.destroy' => ['DELETE'], 'admin.staff.colour' => ['PUT'],
        'admin.staff.blackouts.store' => ['POST'], 'admin.staff.blackouts.destroy' => ['DELETE'], 'admin.blackouts.store' => ['POST'], 'admin.blackouts.destroy' => ['DELETE'],
        'admin.shifts.pattern' => ['PUT'], 'admin.shifts.days.update' => ['PUT'], 'admin.shifts.days.destroy' => ['DELETE'],
        'admin.settings.update' => ['PUT'], 'admin.settings.colour-by' => ['PUT'], 'admin.opening-hours.update' => ['PUT'], 'admin.settings.logo.store' => ['POST'], 'admin.settings.logo.destroy' => ['DELETE'],
        'admin.settings.cover.store' => ['POST'], 'admin.settings.cover.destroy' => ['DELETE'], 'admin.settings.gallery.store' => ['POST'], 'admin.settings.gallery.destroy' => ['DELETE'],
        'admin.settings.payments.store' => ['POST'], 'admin.settings.payments.platform' => ['POST'], 'admin.settings.payments.platform.resume' => ['POST'], 'admin.settings.payments.test' => ['POST'], 'admin.settings.payments.rotate' => ['POST'],
        'admin.settings.payments.disable' => ['POST'], 'admin.settings.payments.enable' => ['POST'], 'admin.settings.payments.destroy' => ['DELETE'],
    ];

    foreach ($writes as $name => $methods) {
        expect($routes)->toHaveKey($name)
            ->and($routes[$name]['methods'])->toBe($methods)
            ->and($routes[$name]['gated'])->toBeTrue("{$name} does not run the subscription gate at dispatch");
    }
});

it('gates none of the booking lifecycle by the subscription, and posts each action under its own name', function () {
    $routes = adminRoutes();
    $lifecycle = ['admin.bookings.store', 'admin.bookings.approve', 'admin.bookings.reject', 'admin.bookings.cancel', 'admin.bookings.no-show', 'admin.bookings.complete', 'admin.bookings.move'];

    foreach ($lifecycle as $name) {
        expect($routes)->toHaveKey($name)
            ->and($routes[$name]['methods'])->toBe(['POST'])
            ->and($routes[$name]['gated'])->toBeFalse("{$name} runs the subscription gate at dispatch");
    }
});

/**
 * The admin surface under the /admin prefix: every route by verb and path, the one access it
 * declares, and whether the subscription gate runs on it. The walks above hold each rule on its
 * own; this holds the surface whole, in registration order, so a route added, moved, renamed,
 * re-permissioned or gated differently fails here until this list is amended with it. HEAD rides
 * on every GET and is not a row of the list, so it is left out. The gate column is read at
 * dispatch, gatedAtDispatch, so a write carrying the gate in its declaration and taking it off
 * again reads as ungated here.
 *
 * @return array<string, array{access: string, gated: bool}>
 */
function adminSurface(): array
{
    $domain = '{tenant}.'.app(TenantUrls::class)->centralHost();
    $surface = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if ($route->getDomain() !== $domain || ! ($route->uri() === 'admin' || str_starts_with($route->uri(), 'admin/'))) {
            continue;
        }

        $middleware = array_filter($route->gatherMiddleware(), is_string(...));
        $access = array_values(array_filter(
            $middleware,
            fn (string $name): bool => in_array($name, [PublicRoute::NAME, RequiresSignIn::NAME], true) || str_starts_with($name, RequiresPermission::NAME.':'),
        ));

        $methods = array_values(array_filter($route->methods(), is_string(...)));

        foreach (array_diff($methods, ['HEAD']) as $method) {
            $surface[$method.' /'.$route->uri()] = [
                'access' => implode(' and ', $access),
                'gated' => gatedAtDispatch($route),
            ];
        }
    }

    return $surface;
}

/**
 * One row of the table: the access a route declares and whether the subscription gate runs on it.
 *
 * @return array{access: string, gated: bool}
 */
function adminAccess(string $access, bool $gated = false): array
{
    return ['access' => $access, 'gated' => $gated];
}

it('spells the admin surface under the prefix, verb by path: the door, the set-password link and the support link public, every page and action under its one permission, the configuration writes alone behind the subscription gate, and the language write, the retired My calendar address and the 404 page signed-in', function () {
    $public = adminAccess(PublicRoute::NAME);
    $viewBookings = adminAccess('permission:viewBookings');
    $manageBookings = adminAccess('permission:manageBookings');
    $approveBookings = adminAccess('permission:approveBookings');
    $configures = adminAccess('permission:manageSettings', gated: true);

    expect(adminSurface())->toBe([
        'GET /admin/login' => $public,
        'POST /admin/login' => $public,
        'POST /admin/logout' => $public,
        // The set-password link the console mails a user: public, the token the credential, and
        // never behind the gate.
        'GET /admin/set-password/{token}' => $public,
        'GET /admin/set-password/{malformed}' => $public,
        'POST /admin/set-password/{token}' => $public,
        // The support session's link the console's press answers: public, the token the
        // credential, and never behind the gate.
        'GET /admin/support/{token}' => $public,
        'GET /admin/support/{malformed}' => $public,
        // Home, the landing, admitting viewOwnCalendar so a staff viewer is sent on to the Calendar
        // rather than refused; one Calendar for every role, and My calendar's retired address a
        // redirect to it for any principal signed in.
        'GET /admin' => adminAccess('permission:viewBookings,viewOwnCalendar'),
        'GET /admin/calendar' => adminAccess('permission:viewBookings,viewOwnCalendar'),
        'GET /admin/my-calendar' => adminAccess(RequiresSignIn::NAME),
        'GET /admin/bookings' => $viewBookings,
        'POST /admin/bookings' => $manageBookings,
        'GET /admin/api/availability' => adminAccess('permission:manageBookings,viewOwnCalendar'),
        'GET /admin/bookings/{uuid}' => $viewBookings,
        'POST /admin/bookings/{uuid}/approve' => $approveBookings,
        'POST /admin/bookings/{uuid}/reject' => $approveBookings,
        'POST /admin/bookings/{uuid}/cancel' => $manageBookings,
        'POST /admin/bookings/{uuid}/no-show' => $manageBookings,
        'POST /admin/bookings/{uuid}/complete' => $manageBookings,
        // The business's move and its read: whoever may cancel may move, never behind the gate,
        // as cancel is not.
        'POST /admin/bookings/{uuid}/move' => $manageBookings,
        'GET /admin/api/bookings/{uuid}/move' => $manageBookings,
        'GET /admin/services' => $viewBookings,
        'GET /admin/services/new' => $viewBookings,
        'GET /admin/services/{id}' => $viewBookings,
        'POST /admin/services' => $configures,
        'PUT /admin/services/{id}' => $configures,
        'DELETE /admin/services/{id}' => $configures,
        // The staff feature is not a declaration: without it the create page, the staff writes, the
        // per-member links and the per-member closures answer not-found from the controller,
        // FeatureGate, so each row here still declares its one permission and the subscription gate
        // alone. StoredEntitlementsTest proves the 404s.
        'PUT /admin/staff/{id}/services' => $configures,
        'GET /admin/staff' => $viewBookings,
        'GET /admin/staff/new' => $viewBookings,
        'GET /admin/staff/{id}' => $viewBookings,
        'POST /admin/staff' => $configures,
        'PUT /admin/staff/{id}' => $configures,
        'DELETE /admin/staff/{id}' => $configures,
        'POST /admin/staff/{id}/photo' => $configures,
        'DELETE /admin/staff/{id}/photo' => $configures,
        'PUT /admin/staff/{id}/colour' => $configures,
        'POST /admin/staff/{id}/blackouts' => $configures,
        'DELETE /admin/staff/{id}/blackouts' => $configures,
        'POST /admin/blackouts' => $configures,
        'DELETE /admin/blackouts' => $configures,
        // Shifts, read under manageSettings and written behind the gate, the staff feature again
        // the controller's not-found.
        'GET /admin/shifts' => adminAccess('permission:manageSettings'),
        'PUT /admin/shifts/{id}/pattern' => $configures,
        'PUT /admin/shifts/{id}/days/{date}' => $configures,
        'DELETE /admin/shifts/{id}/days/{date}' => $configures,
        'GET /admin/customers' => $manageBookings,
        'GET /admin/customers/csv' => $manageBookings,
        'GET /admin/customers/{email}' => $manageBookings,
        // The export and the erasure of a customer: data rights and no configuration, so never
        // behind the gate.
        'GET /admin/customers/{email}/export' => $manageBookings,
        'POST /admin/customers/{email}/erase' => $manageBookings,
        // A customer's profile: what the business keeps about its customer, no configuration of
        // the product, so never behind the gate.
        'PUT /admin/customers/{email}/profile' => $manageBookings,
        // A customer's notes and the block on their online booking, the same reason.
        'POST /admin/customers/{email}/notes' => $manageBookings,
        'DELETE /admin/customers/{email}/notes/{id}' => $manageBookings,
        'PUT /admin/customers/{email}/block' => $manageBookings,
        'DELETE /admin/customers/{email}/block' => $manageBookings,
        'GET /admin/reports' => $viewBookings,
        // Reports' Download CSV: the screen under its filters as a file, a read like the page.
        'GET /admin/reports/csv' => $viewBookings,
        // The team, the owner's alone under manageBilling, and never behind the gate: who may sign
        // in configures nothing.
        'GET /admin/team' => adminAccess('permission:manageBilling'),
        'POST /admin/team' => adminAccess('permission:manageBilling'),
        'PUT /admin/team/{id}' => adminAccess('permission:manageBilling'),
        'DELETE /admin/team/{id}' => adminAccess('permission:manageBilling'),
        'POST /admin/team/{id}/link' => adminAccess('permission:manageBilling'),
        'GET /admin/settings' => adminAccess('permission:manageSettings'),
        // The Danger zone's export of everything the tenant holds: a data right and no
        // configuration, so never behind the gate.
        'GET /admin/settings/export' => adminAccess('permission:manageSettings'),
        // The Danger zone's close of the account: a closing configures nothing, and its way back
        // must stay open to a lapsed owner, so never behind the gate.
        'POST /admin/settings/close' => adminAccess('permission:manageSettings'),
        'PUT /admin/settings' => $configures,
        'PUT /admin/settings/colour-by' => $configures,
        'PUT /admin/opening-hours' => $configures,
        'POST /admin/settings/logo' => $configures,
        'DELETE /admin/settings/logo' => $configures,
        'POST /admin/settings/cover' => $configures,
        'DELETE /admin/settings/cover' => $configures,
        'POST /admin/settings/gallery' => $configures,
        'DELETE /admin/settings/gallery/{file}' => $configures,
        // The press of a copy button, recorded for the setup checklist: it configures nothing,
        // so it is not behind the gate.
        'POST /admin/settings/link-copied' => adminAccess('permission:manageSettings'),
        // The Links card's QR codes: reads, so never behind the gate.
        'GET /admin/settings/links/page.svg' => adminAccess('permission:manageSettings'),
        'GET /admin/settings/links/{kind}/{id}.svg' => adminAccess('permission:manageSettings'),
        // The Payments section, present on every plan, each write a configuration write under
        // the settings permission and the gate.
        'POST /admin/settings/payments' => $configures,
        'POST /admin/settings/payments/platform' => $configures,
        'POST /admin/settings/payments/platform/resume' => $configures,
        'POST /admin/settings/payments/test' => $configures,
        'POST /admin/settings/payments/rotate' => $configures,
        'POST /admin/settings/payments/disable' => $configures,
        'POST /admin/settings/payments/enable' => $configures,
        'DELETE /admin/settings/payments' => $configures,
        // manageBilling on all three and deliberately never the subscription gate: this is
        // the way back.
        'GET /admin/billing' => adminAccess('permission:manageBilling'),
        'POST /admin/billing/plan' => adminAccess('permission:manageBilling'),
        'POST /admin/billing/portal' => adminAccess('permission:manageBilling'),
        'POST /admin/billing/checkout' => adminAccess('permission:manageBilling'),
        // The top bar's language control, the signed-in user's own language: every user owns it and
        // no permission does, and it is a person's preference, so never behind the gate.
        'PUT /admin/language/{language}' => adminAccess(RequiresSignIn::NAME),
        'GET /admin/{path}' => adminAccess(RequiresSignIn::NAME),
    ]);
});

/**
 * Every route registered on a tenant subdomain, by name, with the rate limits that actually run
 * on it. A route's gathered middleware is not that list on its own: middleware taken off with
 * withoutMiddleware is still gathered and is filtered at dispatch, so a route that stepped out
 * of the group's limit would be reported here as carrying both.
 *
 * @return array<string, list<string>>
 */
function tenantRouteLimits(): array
{
    $domain = '{tenant}.'.app(TenantUrls::class)->centralHost();
    $limits = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if ($route->getDomain() !== $domain) {
            continue;
        }

        $gathered = array_filter($route->gatherMiddleware(), is_string(...));
        $excluded = array_filter($route->excludedMiddleware(), is_string(...));
        $running = array_diff($gathered, $excluded);

        $limits[$route->getName() ?? $route->uri()] = array_values(array_filter($running, fn (string $middleware): bool => str_starts_with($middleware, 'throttle:')));
    }

    return $limits;
}

it('counts every API route under a limit and counts one page alone, the My bookings link POST, under a limit of its own', function () {
    expect(tenantRouteLimits())->toBe([
        'probe' => [],
        // A page's twins under /en and /el carry its bare route's limit, the link POST's alone.
        'hosted' => [],
        'en.hosted' => [],
        'el.hosted' => [],
        'embed' => [],
        'logo' => [],
        'images' => [],
        'manage' => [],
        'en.manage' => [],
        'el.manage' => [],
        'my-bookings' => [],
        'my-bookings.link' => ['throttle:my-bookings'],
        'my-bookings.sign-out' => [],
        'my-bookings.open' => [],
        'my-bookings.open.act' => [],
        'en.my-bookings' => [],
        'en.my-bookings.link' => ['throttle:my-bookings'],
        'en.my-bookings.sign-out' => [],
        'en.my-bookings.open' => [],
        'en.my-bookings.open.act' => [],
        'el.my-bookings' => [],
        'el.my-bookings.link' => ['throttle:my-bookings'],
        'el.my-bookings.sign-out' => [],
        'el.my-bookings.open' => [],
        'el.my-bookings.open.act' => [],
        'pay' => [],
        'en.pay' => [],
        'el.pay' => [],
        'pay.link' => [],
        'pay.link.act' => [],
        'en.pay.link' => [],
        'en.pay.link.act' => [],
        'el.pay.link' => [],
        'el.pay.link.act' => [],
        'decision' => [],
        'decision.decide' => [],
        'en.decision' => [],
        'en.decision.decide' => [],
        'el.decision' => [],
        'el.decision.decide' => [],
        'api.config' => ['throttle:api'],
        'api.services' => ['throttle:api'],
        'api.service' => ['throttle:api'],
        'api.availability' => ['throttle:availability'],
        'api.holds' => ['throttle:holds'],
        'api.hold.release' => ['throttle:api'],
        'api.booking' => ['throttle:api'],
        'api.booking.availability' => ['throttle:booking-availability'],
        'api.booking.confirm' => ['throttle:bookings'],
        'api.booking.cancel' => ['throttle:bookings'],
        'api.booking.reschedule' => ['throttle:bookings'],
        'admin.login' => [],
        'admin.login.attempt' => ['throttle:login'],
        'admin.logout' => [],
        'admin.set-password' => [],
        'admin.set-password.malformed' => [],
        'admin.set-password.store' => [],
        'admin.support.open' => [],
        'admin.support.malformed' => [],
        'admin.home' => [],
        'admin.calendar' => [],
        'admin.my-calendar' => [],
        'admin.bookings' => [],
        'admin.bookings.store' => [],
        'admin.api.availability' => [],
        'admin.bookings.show' => [],
        'admin.bookings.approve' => [],
        'admin.bookings.reject' => [],
        'admin.bookings.cancel' => [],
        'admin.bookings.no-show' => [],
        'admin.bookings.complete' => [],
        'admin.bookings.move' => [],
        'admin.api.bookings.move' => [],
        'admin.services' => [],
        'admin.services.create' => [],
        'admin.services.show' => [],
        'admin.services.store' => [],
        'admin.services.update' => [],
        'admin.services.destroy' => [],
        'admin.staff.services' => [],
        'admin.staff' => [],
        'admin.staff.create' => [],
        'admin.staff.show' => [],
        'admin.staff.store' => [],
        'admin.staff.update' => [],
        'admin.staff.destroy' => [],
        'admin.staff.photo.store' => [],
        'admin.staff.photo.destroy' => [],
        'admin.staff.colour' => [],
        'admin.staff.blackouts.store' => [],
        'admin.staff.blackouts.destroy' => [],
        'admin.blackouts.store' => [],
        'admin.blackouts.destroy' => [],
        'admin.shifts' => [],
        'admin.shifts.pattern' => [],
        'admin.shifts.days.update' => [],
        'admin.shifts.days.destroy' => [],
        'admin.customers' => [],
        'admin.customers.csv' => [],
        'admin.customers.show' => [],
        'admin.customers.export' => [],
        'admin.customers.erase' => [],
        'admin.customers.profile' => [],
        'admin.customers.notes.store' => [],
        'admin.customers.notes.destroy' => [],
        'admin.customers.block' => [],
        'admin.customers.unblock' => [],
        'admin.reports' => [],
        'admin.reports.csv' => [],
        'admin.team' => [],
        'admin.team.store' => [],
        'admin.team.update' => [],
        'admin.team.destroy' => [],
        'admin.team.link' => [],
        'admin.settings' => [],
        'admin.settings.export' => [],
        'admin.settings.close' => [],
        'admin.settings.update' => [],
        'admin.settings.colour-by' => [],
        'admin.opening-hours.update' => [],
        'admin.settings.logo.store' => [],
        'admin.settings.logo.destroy' => [],
        'admin.settings.cover.store' => [],
        'admin.settings.cover.destroy' => [],
        'admin.settings.gallery.store' => [],
        'admin.settings.gallery.destroy' => [],
        'admin.settings.link-copied' => [],
        'admin.settings.links.page' => [],
        'admin.settings.links.show' => [],
        'admin.settings.payments.store' => ['throttle:payment-checks'],
        'admin.settings.payments.platform' => [],
        'admin.settings.payments.platform.resume' => [],
        'admin.settings.payments.test' => ['throttle:payment-checks'],
        'admin.settings.payments.rotate' => [],
        'admin.settings.payments.disable' => [],
        'admin.settings.payments.enable' => [],
        'admin.settings.payments.destroy' => [],
        'admin.billing' => [],
        'admin.billing.plan' => ['throttle:billing'],
        'admin.billing.portal' => ['throttle:billing'],
        'admin.billing.checkout' => ['throttle:billing'],
        'admin.language' => [],
        'admin.not-found' => [],
    ]);
});

/**
 * The classes a file imports, by the short name it uses them under.
 *
 * @return array<string, string>
 */
function importsOf(string $file): array
{
    preg_match_all('/^use ([\w\\\\]+?)(?: as (\w+))?;$/m', (string) file_get_contents($file), $uses, PREG_SET_ORDER);
    $imports = [];

    foreach ($uses as $use) {
        $imports[($use[2] ?? '') !== '' ? $use[2] : substr((string) strrchr('\\'.$use[1], '\\'), 1)] = $use[1];
    }

    return $imports;
}

/**
 * The form requests each admin write takes, by route name, over the routes the surface pin holds:
 * those its action's signature names and those its body resolves by app(), as the gated staff
 * actions do once the feature has been asked. A write taking none is left out.
 *
 * @return array<string, list<string>>
 */
function adminWriteRequests(): array
{
    $domain = '{tenant}.'.app(TenantUrls::class)->centralHost();
    $writes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $admin = $route->uri() === 'admin' || str_starts_with($route->uri(), 'admin/');

        if ($route->getDomain() !== $domain || ! $admin || array_diff(array_filter($route->methods(), is_string(...)), ['GET', 'HEAD']) === []) {
            continue;
        }

        [$class, $method] = array_pad(explode('@', $route->getActionName()), 2, '__invoke');
        $action = new ReflectionMethod($class, $method);
        $file = (string) $action->getFileName();
        $body = implode('', array_slice(file($file) ?: [], $action->getStartLine() - 1, $action->getEndLine() - $action->getStartLine() + 1));
        preg_match_all('/\bapp\((\w+)::class\)/', $body, $resolved);
        $imports = importsOf($file);
        $named = [
            ...array_map(fn (ReflectionParameter $parameter): string => $parameter->getType() instanceof ReflectionNamedType ? $parameter->getType()->getName() : '', $action->getParameters()),
            ...array_map(fn (string $short): string => $imports[$short] ?? $action->getDeclaringClass()->getNamespaceName().'\\'.$short, $resolved[1]),
        ];
        $requests = array_values(array_unique(array_filter($named, fn (string $name): bool => is_subclass_of($name, FormRequest::class))));

        if ($requests !== []) {
            $writes[$route->getName() ?? $route->uri()] = $requests;
        }
    }

    return $writes;
}

it('takes every admin write\'s form request from the house\'s admin write request, the sign-in\'s and the set-password link\'s alone excepted, whether the action names it or resolves it', function () {
    $writes = adminWriteRequests();
    $plain = array_filter(array_merge(...array_values(array_diff_key($writes, ['admin.login.attempt' => true, 'admin.set-password.store' => true]))), fn (string $request): bool => ! is_subclass_of($request, AdminWriteRequest::class));

    expect($plain)->toBe([])
        ->and($writes)->toBe([
            'admin.login.attempt' => [LoginRequest::class],
            'admin.set-password.store' => [SetPasswordRequest::class],
            'admin.bookings.store' => [NewBookingRequest::class],
            'admin.bookings.reject' => [RejectRequest::class],
            'admin.bookings.move' => [MoveRequest::class],
            'admin.services.store' => [ServiceRequest::class],
            'admin.services.update' => [ServiceRequest::class],
            'admin.staff.services' => [StaffServicesRequest::class],
            'admin.staff.store' => [StaffRequest::class],
            'admin.staff.update' => [StaffRequest::class],
            'admin.staff.photo.store' => [StaffPhotoRequest::class],
            'admin.staff.colour' => [StaffColourRequest::class],
            'admin.staff.blackouts.store' => [BlackoutRequest::class],
            'admin.staff.blackouts.destroy' => [BlackoutRequest::class],
            'admin.blackouts.store' => [BlackoutRequest::class],
            'admin.blackouts.destroy' => [BlackoutRequest::class],
            'admin.shifts.pattern' => [ShiftPatternRequest::class],
            'admin.shifts.days.update' => [ShiftDayRequest::class],
            'admin.customers.profile' => [CustomerProfileRequest::class],
            'admin.customers.notes.store' => [CustomerNoteRequest::class],
            'admin.customers.block' => [CustomerBlockRequest::class],
            'admin.team.store' => [TeamInviteRequest::class],
            'admin.team.update' => [TeamRoleRequest::class],
            'admin.settings.update' => [SettingsRequest::class],
            'admin.settings.colour-by' => [ColourByRequest::class],
            'admin.opening-hours.update' => [OpeningHoursRequest::class],
            'admin.settings.logo.store' => [LogoRequest::class],
            'admin.settings.cover.store' => [CoverRequest::class],
            'admin.settings.gallery.store' => [GalleryPhotoRequest::class],
            'admin.settings.payments.store' => [PaymentCredentialsRequest::class],
            'admin.settings.payments.platform' => [PlatformConnectRequest::class],
            'admin.billing.plan' => [ChangePlanRequest::class],
            'admin.billing.checkout' => [CheckoutRequest::class],
        ]);
});

/*
 * The form requests under app/Http/Requests that are no admin write's and so stand on the
 * framework's FormRequest, each group with its reason. Every other form request there is an
 * admin write's and extends AdminWriteRequest, which the case below holds over the directory,
 * so a request an action reaches where the walk above cannot see it, through resolve(),
 * app()->make(), a fully qualified name or a helper, still answers the error envelope.
 */
const SURFACE_PLAIN_REQUESTS = [
    // The public API and the customer's and the approver's pages.
    AvailabilityRequest::class,
    PublicAvailabilityRequest::class,
    DecisionRequest::class,
    MoveAvailabilityRequest::class,
    MyBookingsLinkRequest::class,
    NewHoldRequest::class,
    RescheduleRequest::class,
    // The admin's reads, which read their query tolerantly and refuse nothing.
    BookingListRequest::class,
    CalendarRequest::class,
    CustomerRequest::class,
    HomeRequest::class,
    ReportRequest::class,
    // The wizard's availability read, the public read's query and its refusals, a malformed query
    // answered at 400 in the envelope through AvailabilityRequest.
    WizardAvailabilityRequest::class,
    // The sign-in's and the set-password link's, which answer the errors bag by design, 3.2.
    LoginRequest::class,
    SetPasswordRequest::class,
    // The console's, on its own host behind its own guard; the reason's two writes, the
    // suspension's and the close's, stand on the reason's own.
    CloseRequest::class,
    ConsoleAuditRequest::class,
    ConsoleMessagesRequest::class,
    ConsoleTenantsRequest::class,
    NewTenantRequest::class,
    OperatorReasonRequest::class,
    SuspendRequest::class,
    TenantFeaturesRequest::class,
    // The central host's signup and the site's contact form.
    ContactRequest::class,
    SignupRequest::class,
];

/**
 * Every form request a file under app/Http/Requests declares, by its class name, in name order.
 *
 * @return list<string>
 */
function formRequestsDeclared(): array
{
    $requests = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Http/Requests'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $class = 'App\\'.str_replace('/', '\\', substr($file->getPathname(), strlen(app_path().'/'), -4));

        if (class_exists($class) && is_subclass_of($class, FormRequest::class)) {
            $requests[] = $class;
        }
    }

    sort($requests);

    return $requests;
}

it('holds every form request under app/Http/Requests but the named plain ones to the admin write request, so a write the walk cannot see take one still answers the envelope', function () {
    $declared = formRequestsDeclared();
    $writes = array_diff($declared, [...SURFACE_PLAIN_REQUESTS, AdminWriteRequest::class]);

    expect(array_values(array_filter($writes, fn (string $request): bool => ! is_subclass_of($request, AdminWriteRequest::class))))->toBe([])
        // The walk's writes are among them, and every name the list holds is a form request
        // there, so the list cannot outlive what it names; that none of them is an admin write
        // the analyser proves from the classes themselves.
        ->and(array_diff(array_merge(...array_values(array_diff_key(adminWriteRequests(), ['admin.login.attempt' => true, 'admin.set-password.store' => true]))), $writes))->toBe([])
        ->and(array_values(array_diff(SURFACE_PLAIN_REQUESTS, $declared)))->toBe([]);
});

/**
 * Whether the cross-site guard stands down for a path, as the guard itself decides it: the
 * excluded patterns are read off the middleware rather than restated here, so a path added to
 * the exemption list in bootstrap/app.php is a path this test sees.
 */
function exemptFromForgeryGuard(string $url): bool
{
    foreach (app(PreventRequestForgery::class)->getExcludedPaths() as $excluded) {
        if (is_string($excluded) && Request::create($url, 'POST')->is($excluded === '/' ? $excluded : trim($excluded, '/'))) {
            return true;
        }
    }

    return false;
}

it('submits a decision, the payment link\'s act and the three My bookings POSTs through the cross-site guard every page of this host runs under, and exempts the JSON API alone', function () {
    // Three links of one chain: the guard is in the group, the route is in the group, and the path
    // is not on the list the guard stands down for. The API is on that list, because it is called
    // cross-origin from an embed with no token to send, and the one cookie it reads, a My bookings
    // session on the list's own origin, is SameSite Lax, so no cross-site POST carries it, and the
    // CORS case below keeps a script on another origin from reading what it earns. The two signed
    // guest POSTs are not.
    $decide = Route::getRoutes()->getByName('decision.decide');
    $pay = Route::getRoutes()->getByName('pay.link.act');
    $link = Route::getRoutes()->getByName('my-bookings.link');
    $signOut = Route::getRoutes()->getByName('my-bookings.sign-out');
    $open = Route::getRoutes()->getByName('my-bookings.open.act');

    expect(app(HttpKernel::class)->getMiddlewareGroups()['web'] ?? [])->toContain(PreventRequestForgery::class)
        ->and($decide?->gatherMiddleware())->toContain('web')
        ->and($pay?->gatherMiddleware())->toContain('web')
        ->and($pay?->methods())->toBe(['POST'])
        ->and($link?->gatherMiddleware())->toContain('web')
        ->and($link?->methods())->toBe(['POST'])
        ->and(exemptFromForgeryGuard(tenantUrl('luna', '/my-bookings/link')))->toBeFalse()
        ->and($signOut?->gatherMiddleware())->toContain('web')
        ->and($signOut?->methods())->toBe(['POST'])
        ->and(exemptFromForgeryGuard(tenantUrl('luna', '/my-bookings/sign-out')))->toBeFalse()
        ->and($open?->gatherMiddleware())->toContain('web')
        ->and($open?->methods())->toBe(['POST'])
        ->and($open?->uri())->toBe(Route::getRoutes()->getByName('my-bookings.open')?->uri())
        ->and(exemptFromForgeryGuard(tenantUrl('luna', '/my-bookings/'.str_repeat('ab', 32))))->toBeFalse()
        ->and(exemptFromForgeryGuard(tenantUrl('luna', '/decide/7b7f0e5a-0000-4000-8000-000000000001/approve')))->toBeFalse()
        ->and(exemptFromForgeryGuard(tenantUrl('luna', '/pay/7b7f0e5a-0000-4000-8000-000000000001/link')))->toBeFalse()
        ->and(exemptFromForgeryGuard(tenantUrl('luna', '/api/v1/holds')))->toBeTrue();
});

it('submits every POST twin under /en and /el through the same cross-site guard as its bare route, each in the web group and on no exempt path', function () {
    $posts = ['my-bookings.link' => '/my-bookings/link', 'my-bookings.sign-out' => '/my-bookings/sign-out', 'my-bookings.open.act' => '/my-bookings/'.str_repeat('ab', 32), 'pay.link.act' => '/pay/7b7f0e5a-0000-4000-8000-000000000001/link', 'decision.decide' => '/decide/7b7f0e5a-0000-4000-8000-000000000001/approve'];

    foreach ($posts as $page => $path) {
        foreach (SiteLanguage::cases() as $language) {
            $twin = Route::getRoutes()->getByName(TenantPages::routeName($page, $language));

            expect($twin?->gatherMiddleware())->toContain('web')
                ->and($twin?->methods())->toBe(['POST'])
                ->and($twin?->uri())->toBe($language->value.'/'.Route::getRoutes()->getByName($page)?->uri())
                ->and(exemptFromForgeryGuard(tenantUrl('luna', TenantPages::uri($path, $language))))->toBeFalse();
        }
    }
});

it('lets no script on another origin read what a My bookings cookie earns: the API answers any origin and allows no credentials, so a credentialed request from a foreign page fails its preflight and no answer of one is readable', function () {
    $preflight = $this->call('OPTIONS', tenantUrl('luna', '/api/v1/bookings/7b7f0e5a-0000-4000-8000-000000000001/cancel'), server: [
        'HTTP_ORIGIN' => 'https://www.salon-x.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
    ]);

    expect(config('cors.supports_credentials'))->toBeFalse()
        ->and(config('cors.paths'))->toBe(['api/*']);

    $preflight->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertHeaderMissing('Access-Control-Allow-Credentials');
});

/**
 * A booking with one item, built rather than read. What the two tests below are about is the
 * shape the payload class decides, which no row and no reader has a say in, so this needs
 * neither a tenant nor a schema.
 */
function payloadSubject(): BookingSnapshot
{
    $athens = new DateTimeZone('Europe/Athens');

    return new BookingSnapshot(
        '7b7f0e5a-0000-4000-8000-000000000001',
        BookingStatus::Confirmed,
        null,
        null,
        new Customer('Casey Customer', 'casey@example.com', '+30 210 0000000'),
        new Money(3500, Currency::of('EUR')),
        [new BookingItemSnapshot(
            0,
            7,
            'Haircut',
            3,
            'Elena Papadaki',
            false,
            new DateTimeImmutable('2026-11-03 09:00', $athens),
            new DateTimeImmutable('2026-11-03 09:45', $athens),
            new Money(3500, Currency::of('EUR')),
        )],
    );
}

it('answers the guest view as these keys in this order, and the hold as those, the manage token, and checkout_url or pays_at_venue where one applies', function () {
    $guest = BookingPayload::guest(payloadSubject());
    $hold = BookingPayload::hold(payloadSubject(), 'the-plaintext-token');

    expect(array_keys($guest))->toBe([
        'uuid', 'status', 'hold_class', 'hold_expires_at', 'customer_name', 'customer_email',
        'customer_phone', 'total_minor', 'currency', 'items',
    ])
        ->and(array_keys($guest['items'][0]))->toBe([
            'sort', 'service_id', 'service_name', 'staff_id', 'staff_name', 'start', 'end', 'price_minor',
        ])
        ->and(array_keys($hold))->toBe([...array_keys($guest), 'manage_token'])
        ->and($hold)->not->toHaveKey('checkout_url')
        ->and($hold)->not->toHaveKey('pays_at_venue')
        ->and(array_keys(BookingPayload::hold(payloadSubject(), 'the-plaintext-token', 'https://luna.example.test/pay/x')))->toBe([...array_keys($guest), 'manage_token', 'checkout_url'])
        ->and(array_keys(BookingPayload::hold(payloadSubject(), 'the-plaintext-token', null, true)))->toBe([...array_keys($guest), 'manage_token', 'pays_at_venue']);
});

/*
 * The declaration binds one route, and the walk above cannot see when it does not.
 * gatherMiddleware() folds a group's middleware into every route inside it, so a declaration
 * written on a group reads there exactly as a declaration written on each route, while it
 * actually hands its access to every route somebody registers inside that group afterwards.
 * That is the implicit allow the authorization rule forbids, and three docblocks make "never
 * on a group" load-bearing.
 *
 * So the place it can be caught is where groups are written. Each registration chain that ends
 * in ->group( is read back to the facade call it started at, arguments included, and a marker
 * named anywhere in it is a declaration the group would hand out. A marker inside the group's
 * body sits at the next depth and belongs to the route that spelled it, which is the whole
 * point of the distinction.
 */

/**
 * Every access declaration a source makes on a group rather than on a route, as "line: text".
 *
 * A chain is walked backwards from the group call through balanced parentheses, so a nested
 * call's own arguments are part of the chain and not a boundary. What ends the walk is the
 * first token that cannot continue a chain, which is the statement's start.
 *
 * @return list<string>
 */
function accessOnGroups(string $source): array
{
    $markers = ['PublicRoute', 'RequiresManageToken', 'RequiresPermission', 'RequiresSignIn', 'RequiresOperator', "'".PublicRoute::NAME."'", '"'.PublicRoute::NAME.'"', "'".RequiresManageToken::NAME."'", '"'.RequiresManageToken::NAME.'"', "'".RequiresSignIn::NAME."'", '"'.RequiresSignIn::NAME.'"', "'".RequiresOperator::NAME."'", '"'.RequiresOperator::NAME.'"'];
    $tokens = array_values(array_filter(PhpToken::tokenize($source), fn (PhpToken $token): bool => ! $token->isIgnorable()));
    $found = [];

    foreach ($tokens as $index => $token) {
        if (! $token->is(T_STRING) || $token->text !== 'group' || ! ($tokens[$index + 1] ?? null)?->is('(')) {
            continue;
        }

        foreach (routeChainBefore($tokens, $index) as $part) {
            if (str_starts_with($part->text, "'".RequiresPermission::NAME.':') || str_starts_with($part->text, '"'.RequiresPermission::NAME.':')) {
                $found[] = $part->line.': '.$part->text;
            }

            foreach ($markers as $marker) {
                if ($part->text === $marker || str_ends_with($part->text, '\\'.$marker)) {
                    $found[] = $part->line.': '.$part->text;
                }
            }
        }
    }

    return $found;
}

/**
 * The tokens of the registration chain ending at the given index, from the start of the
 * statement, in source order.
 *
 * @param  list<PhpToken>  $tokens
 * @return list<PhpToken>
 */
function routeChainBefore(array $tokens, int $index): array
{
    $at = $index - 1;
    $depth = 0;

    while ($at >= 0) {
        $token = $tokens[$at];

        if ($token->is(')')) {
            $depth++;
        } elseif ($token->is('(')) {
            if ($depth === 0) {
                break;
            }

            $depth--;
        } elseif ($depth === 0 && ! $token->is([
            T_STRING, T_VARIABLE, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_OBJECT_OPERATOR,
            T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_STATIC,
        ])) {
            break;
        }

        $at--;
    }

    return array_slice($tokens, $at + 1, $index - $at - 1);
}

it('declares no access on a group in any route file, because a group hands its access to every route registered inside it afterwards', function () {
    $files = glob(base_path('routes/*.php'));

    expect($files)->toBeArray()->not->toBeEmpty();
    assert(is_array($files));

    $offences = [];

    foreach ($files as $path) {
        foreach (accessOnGroups((string) file_get_contents($path)) as $offence) {
            $offences[] = basename((string) $path).' '.$offence;
        }
    }

    expect($offences)->toBe([]);
});

it('catches a declaration on a group whether it is spelled as a constant, a class, a string or one of several in an array, and leaves the declarations the routes inside that group make for themselves', function () {
    $source = <<<'PHP'
    <?php
    Route::domain($host)->middleware(ResolveTenant::class)->group(base_path('routes/tenant.php'));

    Route::prefix('api/v1')->name('api.')->middleware('throttle:api')->group(function (): void {
        Route::get('/config', ConfigController::class)->middleware(PublicRoute::NAME)->name('config');
        Route::get('/bookings/{uuid}', BookingsController::class)->middleware(RequiresManageToken::NAME)->name('booking');
    });

    Route::middleware(PublicRoute::NAME)->group(function (): void {
        Route::get('/services', ServicesController::class);
    });

    Route::prefix('x')->middleware([sprintf('throttle:%s', $name), \App\Http\Middleware\RequiresManageToken::NAME])->group(function (): void {
        Route::get('/y', YController::class);
    });

    Route::middleware(['manage-token'])->group(function (): void {
        Route::get('/z', ZController::class);
    });

    Route::prefix('admin')->middleware(RequiresPermission::of(Permission::ViewBookings))->group(function (): void {
        Route::get('/w', WController::class)->middleware(RequiresPermission::of(Permission::ManageSettings));
    });

    Route::middleware(['permission:viewBookings'])->group(function (): void {
        Route::get('/v', VController::class);
    });

    Route::middleware(RequiresOperator::NAME)->group(function (): void {
        Route::get('/u', UController::class);
    });

    Route::name('console.')->middleware([HandleConsoleInertiaRequests::class, 'operator'])->group(function (): void {
        Route::get('/t', TController::class)->middleware(RequiresOperator::NAME);
    });
    PHP;

    expect(accessOnGroups($source))->toBe([
        '9: PublicRoute',
        '13: \App\Http\Middleware\RequiresManageToken',
        '17: \'manage-token\'',
        '21: RequiresPermission',
        '25: \'permission:viewBookings\'',
        '29: RequiresOperator',
        '33: \'operator\'',
    ]);
});
