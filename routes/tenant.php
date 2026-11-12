<?php

use App\Domain\Enum\Decision;
use App\Http\Controllers\Admin\AccountCloseController;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\BlackoutController;
use App\Http\Controllers\Admin\BookingActionController;
use App\Http\Controllers\Admin\BookingListController;
use App\Http\Controllers\Admin\CalendarController;
use App\Http\Controllers\Admin\CoverController;
use App\Http\Controllers\Admin\CustomerBlockController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\CustomerNoteController;
use App\Http\Controllers\Admin\CustomerProfileController;
use App\Http\Controllers\Admin\GalleryController;
use App\Http\Controllers\Admin\HomeController;
use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\LinkQrController;
use App\Http\Controllers\Admin\LoginController;
use App\Http\Controllers\Admin\LogoController;
use App\Http\Controllers\Admin\LogoutController;
use App\Http\Controllers\Admin\MoveReadController;
use App\Http\Controllers\Admin\NewBookingController;
use App\Http\Controllers\Admin\NotFoundController;
use App\Http\Controllers\Admin\PaymentsController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\RetiredMyCalendarController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\SetPasswordController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\ShiftsController;
use App\Http\Controllers\Admin\StaffColourController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\StaffPhotoController;
use App\Http\Controllers\Admin\StaffServicesController;
use App\Http\Controllers\Admin\SupportController;
use App\Http\Controllers\Admin\TeamController;
use App\Http\Controllers\Admin\TenantExportController;
use App\Http\Controllers\Admin\WizardAvailabilityController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\BookingsController;
use App\Http\Controllers\Api\CancelBookingController;
use App\Http\Controllers\Api\ConfigController;
use App\Http\Controllers\Api\ConfirmBookingController;
use App\Http\Controllers\Api\HoldsController;
use App\Http\Controllers\Api\MoveAvailabilityController;
use App\Http\Controllers\Api\ReleaseHoldController;
use App\Http\Controllers\Api\RescheduleBookingController;
use App\Http\Controllers\Api\ServicesController;
use App\Http\Controllers\BrandLogoController;
use App\Http\Controllers\DecideController;
use App\Http\Controllers\DecisionPageController;
use App\Http\Controllers\EmbedScriptController;
use App\Http\Controllers\HostedPageController;
use App\Http\Controllers\ManagePageController;
use App\Http\Controllers\MyBookingsController;
use App\Http\Controllers\PayController;
use App\Http\Controllers\PayLinkController;
use App\Http\Controllers\ProbeController;
use App\Http\Controllers\TenantImageController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PublicRoute;
use App\Http\Middleware\RequiresActiveSubscription;
use App\Http\Middleware\RequiresDecisionLink;
use App\Http\Middleware\RequiresManageToken;
use App\Http\Middleware\RequiresPayLink;
use App\Http\Middleware\RequiresPermission;
use App\Http\Middleware\RequiresSignIn;
use App\Http\Requests\TolerantQuery;
use App\Infrastructure\Storage\TenantImages;
use App\Platform\PasswordLinks;
use App\Platform\SupportSessions;
use App\Site\SiteLanguage;
use App\Tenancy\DecisionLinks;
use App\Tenancy\PayLinks;
use App\Tenancy\Permission;
use App\Tenancy\TenantPages;
use Illuminate\Support\Facades\Route;

/*
 * Routes on a tenant's subdomain, every one behind tenant resolution: the hosted booking page,
 * the public JSON API, the embed script and the admin, as they arrive.
 *
 * The probe answers current_schema() through the transaction runner, which is what an operator
 * hits to see a deployment resolve a tenant and switch to its schema. The hosted page is the
 * tenant origin's root, the URL the provisioning command prints. The public API lives under
 * /api/v1.
 *
 * The embed script is the fixed URL a tenant pastes, served with a short cache lifetime and a
 * validator by its controller.
 *
 * Every route here declares its authorization check, and the declaration is the check: public for
 * the routes anyone may reach, and manage-token for the routes a customer reaches with the one
 * credential they hold. Anyone may read a tenant's booking page, as anyone may read the price
 * list in the window, and anyone may book what they read; the booking they made afterwards is
 * theirs alone. The declaration sits on each route and never on the group, so a route registered
 * here later inherits no allow it did not ask for.
 *
 * The API group carries the general limit for everything else, 300 a minute per IP, through the
 * limiter named in the service provider. A route with a limit of its own steps out of the group's
 * and carries its own instead: the general limit is for everything else, so a request the
 * availability limit counts is not one the general limit counts as well, and a customer polling
 * the grid spends nothing of the budget the catalog reads run on. The hold's limit is the same
 * arrangement at 10 a minute, keyed on the tenant as well as the address, and the POST
 * /bookings/{uuid}/* family shares one at 20.
 *
 * The per-booking availability read has a limit of its own at 120 a minute, the same number as
 * the public read's and a separate budget: the two are kept apart, and a reschedule dialog open
 * beside the tenant's booking page must not spend what the website is using.
 *
 * Releasing a hold is not in that family and takes the group's limit. The twenty a minute belongs
 * to POST /bookings/{uuid}/*, and this is a DELETE on a hold: a browser sends it on page hide
 * with keepalive, so the count is the customer's own coming and going rather than anything they
 * are asking the tenant for.
 *
 * Every page outside the admin answers at its bare path, in the tenant default, and under /en and
 * /el: TenantPages::register registers what its closure registers three times, the twins named
 * with their prefix, en.manage and el.manage, each declaring the access, the limit and the
 * constraints the bare route declares, so a prefix changes no route's access. The POSTs are
 * twinned with their pages, since the link page's form and the decision form post back to the
 * address they were opened at. The probe, the embed script, the logo, the images, the API and the
 * admin take no prefix. The group's second middleware, SetTenantLocale, answers every route in
 * the request's language.
 *
 * A numeric path id is at most eighteen digits, TolerantQuery::ID_PATTERN, so a longer one is a
 * 404 at the router and never reaches a controller's integer parameter, where it would overflow.
 */
Route::get('/probe', ProbeController::class)->middleware(PublicRoute::NAME)->name('probe');

TenantPages::register(function (): void {
    Route::get('/', HostedPageController::class)->middleware(PublicRoute::NAME)->name('hosted');
});

Route::get('/embed.js', EmbedScriptController::class)->middleware(PublicRoute::NAME)->name('embed');

// The tenant's logo as the hosted page's header loads it, served under the one file name the
// settings row holds; public like the page.
Route::get('/logo/{file}', BrandLogoController::class)->where('file', TenantImages::FILE_NAME)->middleware(PublicRoute::NAME)->name('logo');

// The cover, the gallery and the staff photos, served under a name a row of the tenant holds as
// that kind, found through the image store's lookup; public like the page.
Route::get('/images/{file}', TenantImageController::class)->where('file', TenantImages::FILE_NAME)->middleware(PublicRoute::NAME)->name('images');

/*
 * Where the magic link lands. It is public because there is nothing here to authorize: the
 * page renders the mount element carrying the identifier and the token it was handed and
 * judges neither, and the read the booking app then makes is the one place a credential is
 * checked. A page that refused would be a second oracle, and an oracle that answers before the
 * guard does is one anybody can walk.
 *
 * It carries no limit, and neither does any other page on this host: the limits are the API's,
 * and the group below is the only place they are applied. What a page hands back is the same
 * markup whoever asks and says nothing about any booking, and the read the app makes a moment
 * later is counted by the everything-else limit like every other read. Should the pages ever
 * need a limit, it is one decision for all four of them rather than one bolted onto this one.
 */
TenantPages::register(function (): void {
    Route::get('/manage/{uuid}', ManagePageController::class)->middleware(PublicRoute::NAME)->name('manage');
});

/*
 * My bookings: the customer's implicit account, on the tenant's own origin and never on the
 * embed. The page lists the session's bookings or asks for an address; the link POST mints and
 * mails a one-time link where the address has a booking here and always answers the same
 * sentence; the link's GET is a page with one button and spends nothing, since a mail scanner
 * fetches every link, and the button's POST to the same URI opens the session once and goes on to
 * the page, two methods on one URI as the decision links have (P9F-L16-a); the sign-out ends it.
 * Public, each judging the one credential it takes and answering a miss with the form, and never
 * gated by the subscription, as no customer's route is.
 *
 * The link POST is the one page on this host under a limit: five a minute per address, keyed on
 * the address asked for and the host, since every request to it may mail somebody. Past it the
 * form answers at 429 with its refusal. The other four carry none, as no other page does. The two
 * static POSTs are registered before the link's, so their paths are never read as a token.
 */
TenantPages::register(function (): void {
    Route::get('/my-bookings', [MyBookingsController::class, 'show'])->middleware(PublicRoute::NAME)->name('my-bookings');
    Route::post('/my-bookings/link', [MyBookingsController::class, 'link'])->middleware(['throttle:my-bookings', PublicRoute::NAME])->name('my-bookings.link');
    Route::post('/my-bookings/sign-out', [MyBookingsController::class, 'signOut'])->middleware(PublicRoute::NAME)->name('my-bookings.sign-out');
    Route::get('/my-bookings/{token}', [MyBookingsController::class, 'linkPage'])->middleware(PublicRoute::NAME)->name('my-bookings.open');
    Route::post('/my-bookings/{token}', [MyBookingsController::class, 'open'])->middleware(PublicRoute::NAME)->name('my-bookings.open.act');
});

/*
 * The pay hop: the checkout_url a hold answers when it cannot be confirmed until it is paid. A
 * page the browser navigates to rather than an API call, so it sits at the root beside
 * /manage; behind the manage token's one guard and never the subscription's, since paying is a
 * guest route. It carries no limit, as the other pages here do not.
 */
TenantPages::register(function (): void {
    Route::get('/pay/{uuid}', PayController::class)->middleware(RequiresManageToken::NAME)->name('pay');
});

/*
 * The approved message's payment link: the credential an approved customer holds in place of the
 * manage token, signed by PayLinks over the booking and an outer life. Two methods on one URI,
 * as the decision links have and for their reason: the GET is the status page, which renders and
 * decides nothing, so a mail scanner's fetch changes nothing, and the provider returns the
 * customer to it; the POST its button makes is the hop. Behind the signature alone, never the
 * subscription's, and no limit, as no page on this host has one.
 */
TenantPages::register(function (): void {
    Route::get('/pay/{uuid}/link', [PayLinkController::class, 'show'])->middleware(RequiresPayLink::NAME)->name(PayLinks::ROUTE);
    Route::post('/pay/{uuid}/link', [PayLinkController::class, 'act'])->middleware(RequiresPayLink::NAME)->name(PayLinks::ROUTE.'.act');
});

/*
 * Where a signed decision link lands: the page an approval email's approve or reject link
 * opens, and the POST that page submits. Both on the tenant subdomain and outside the API,
 * because what they answer is a page for a person rather than JSON for a client.
 *
 * Two methods on one URI, deliberately. The GET renders and decides nothing, so a mail
 * security scanner that fetches every link in a message decides nothing either; the POST is
 * the decision. One URI is also what makes the signature cover both: the page is signed, and
 * the form posts back to a link minted the same way.
 *
 * The declared check is the signature plus the three facts that a signature cannot prove, and
 * the middleware that declares it is the one that runs them. Nothing here reads an admin
 * session.
 *
 * No limit, like every other page on this host. The two of them join the four already here,
 * since the limits are the API's; should the pages ever need one it is one decision for all of
 * them rather than one bolted onto these.
 */
TenantPages::register(function (): void {
    Route::get('/decide/{uuid}/{decision}', DecisionPageController::class)
        ->whereIn(DecisionLinks::DECISION, Decision::spellings())
        ->middleware(RequiresDecisionLink::NAME)
        ->name(DecisionLinks::ROUTE);

    Route::post('/decide/{uuid}/{decision}', DecideController::class)
        ->whereIn(DecisionLinks::DECISION, Decision::spellings())
        ->middleware(RequiresDecisionLink::NAME)
        ->name(DecisionLinks::ROUTE.'.decide');
});

Route::prefix('api/v1')->name('api.')->middleware('throttle:api')->group(function (): void {
    Route::get('/config', ConfigController::class)->middleware(PublicRoute::NAME)->name('config');
    Route::get('/services', [ServicesController::class, 'index'])->middleware(PublicRoute::NAME)->name('services');
    Route::get('/services/{id}', [ServicesController::class, 'show'])->where('id', TolerantQuery::ID_PATTERN)->middleware(PublicRoute::NAME)->name('service');
    Route::get('/availability', AvailabilityController::class)->withoutMiddleware('throttle:api')->middleware(['throttle:availability', PublicRoute::NAME])->name('availability');
    Route::post('/holds', HoldsController::class)->withoutMiddleware('throttle:api')->middleware(['throttle:holds', PublicRoute::NAME])->name('holds');
    Route::delete('/holds/{uuid}', ReleaseHoldController::class)->middleware(RequiresManageToken::NAME)->name('hold.release');
    Route::get('/bookings/{uuid}', BookingsController::class)->middleware(RequiresManageToken::NAME)->name('booking');
    Route::get('/bookings/{uuid}/availability', MoveAvailabilityController::class)->withoutMiddleware('throttle:api')->middleware(['throttle:booking-availability', RequiresManageToken::NAME])->name('booking.availability');
    Route::post('/bookings/{uuid}/confirm', ConfirmBookingController::class)->withoutMiddleware('throttle:api')->middleware(['throttle:bookings', RequiresManageToken::NAME])->name('booking.confirm');
    Route::post('/bookings/{uuid}/cancel', CancelBookingController::class)->withoutMiddleware('throttle:api')->middleware(['throttle:bookings', RequiresManageToken::NAME])->name('booking.cancel');
    Route::post('/bookings/{uuid}/reschedule', RescheduleBookingController::class)->withoutMiddleware('throttle:api')->middleware(['throttle:bookings', RequiresManageToken::NAME])->name('booking.reschedule');
});

/*
 * The admin, under /admin: the hosted page owns the root and the customer's pages own /manage and
 * /decide, so every admin route is read with the prefix. Inertia over the session serves it, and
 * the group's one middleware is the Inertia adapter handing every page the shared props.
 *
 * Each route declares its own access, never the group: the door, the set-password link's page and
 * the support session's link are public, and every other route names the one permission it needs,
 * which also sends a visitor who is not signed in to the door, a principal of either kind being
 * let through. A page added here comes with its permission and its rail entry in
 * App\Http\AdminNav. A configuration write carries the subscription gate beside its permission; no
 * read ever does.
 *
 * The sign-in POST carries a limit of its own, 10 a minute per address, and answers past it
 * with the form at 429 and a worded refusal; the three billing writes share one, 10 a minute per
 * address and tenant, answering the envelope at 429, and the Payments section's two live calls to
 * the provider, Save and test and Test connection, share another in the same shape. Every other
 * route carries none.
 *
 * The top bar's language control writes the signed-in user's own language, declared signed-in as
 * the 404 page is, since every user and no permission owns it; its controller refuses it to a
 * support session.
 *
 * The last route is the 404 inside the shell for any other path under the prefix, and is
 * registered last so it catches only what nothing above answered.
 */
Route::prefix('admin')->name('admin.')->middleware(HandleInertiaRequests::class)->group(function (): void {
    Route::get('/login', [LoginController::class, 'show'])->middleware(PublicRoute::NAME)->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware(['throttle:login', PublicRoute::NAME])->name('login.attempt');
    Route::post('/logout', LogoutController::class)->middleware(PublicRoute::NAME)->name('logout');

    // The set-password link the console mails a user: public, the token the credential, judged at
    // this boundary on the page, 64 hex characters reaching the page and any other shape the route
    // after it, which answers as for a link nobody holds; the POST's link judges its own token.
    // Neither is gated by the subscription nor under a limit.
    Route::get('/set-password/{token}', [SetPasswordController::class, 'show'])->where('token', PasswordLinks::TOKEN)->middleware(PublicRoute::NAME)->name('set-password');
    Route::get('/set-password/{malformed}', [SetPasswordController::class, 'malformed'])->middleware(PublicRoute::NAME)->name('set-password.malformed');
    Route::post('/set-password/{token}', [SetPasswordController::class, 'store'])->middleware(PublicRoute::NAME)->name('set-password.store');

    // The support session's link the console's press answers: public, the token the credential, 64
    // hex characters reaching the open and any other shape the route after it, which answers as for
    // a link nobody holds. Neither is gated by the subscription nor under a limit.
    Route::get('/support/{token}', [SupportController::class, 'open'])->where('token', SupportSessions::TOKEN)->middleware(PublicRoute::NAME)->name('support.open');
    Route::get('/support/{malformed}', [SupportController::class, 'malformed'])->middleware(PublicRoute::NAME)->name('support.malformed');

    // Home, the landing: a viewBookings holder's; a viewer holding viewOwnCalendar alone is
    // admitted and sent on to the Calendar by its route name.
    Route::get('/', HomeController::class)->middleware(RequiresPermission::of(Permission::ViewBookings, Permission::ViewOwnCalendar))->name('home');
    // One Calendar for every role: a viewer holding viewOwnCalendar alone opens it pinned to their
    // own row, and My calendar's address answers a redirect to it by name for any principal signed
    // in.
    Route::get('/calendar', CalendarController::class)->middleware(RequiresPermission::of(Permission::ViewBookings, Permission::ViewOwnCalendar))->name('calendar');
    Route::get('/my-calendar', RetiredMyCalendarController::class)->middleware(RequiresSignIn::NAME)->name('my-calendar');

    Route::get('/bookings', [BookingListController::class, 'index'])->middleware(RequiresPermission::of(Permission::ViewBookings))->name('bookings');
    // The manual booking: lands confirmed, never a hold, and is never gated by the
    // subscription, because the booking lifecycle is never frozen.
    Route::post('/bookings', NewBookingController::class)->middleware(RequiresPermission::of(Permission::ManageBookings))->name('bookings.store');
    // The wizard's admin JSON endpoint: the public availability read's request and answer shape
    // over the session, every start on the grid rather than the start interval's, for the wizard
    // that opens over any screen. It admits either permission and carries no limit; the move's
    // read beside the dialog's actions is the second route under /admin/api, and a third is a
    // design decision the surface test refuses.
    Route::get('/api/availability', WizardAvailabilityController::class)->middleware(RequiresPermission::of(Permission::ManageBookings, Permission::ViewOwnCalendar))->name('api.availability');
    Route::get('/bookings/{uuid}', [BookingListController::class, 'show'])->middleware(RequiresPermission::of(Permission::ViewBookings))->name('bookings.show');

    // The dialog's actions: approveBookings alone on the two decisions, with the staff scope run by
    // the controller on every press, and manageBookings on the rest. None of them carries the
    // subscription gate. A path that is not a uuid is no booking.
    Route::post('/bookings/{uuid}/approve', [BookingActionController::class, 'approve'])->whereUuid('uuid')->middleware(RequiresPermission::of(Permission::ApproveBookings))->name('bookings.approve');
    Route::post('/bookings/{uuid}/reject', [BookingActionController::class, 'reject'])->whereUuid('uuid')->middleware(RequiresPermission::of(Permission::ApproveBookings))->name('bookings.reject');
    Route::post('/bookings/{uuid}/cancel', [BookingActionController::class, 'cancel'])->whereUuid('uuid')->middleware(RequiresPermission::of(Permission::ManageBookings))->name('bookings.cancel');
    Route::post('/bookings/{uuid}/no-show', [BookingActionController::class, 'noShow'])->whereUuid('uuid')->middleware(RequiresPermission::of(Permission::ManageBookings))->name('bookings.no-show');
    Route::post('/bookings/{uuid}/complete', [BookingActionController::class, 'complete'])->whereUuid('uuid')->middleware(RequiresPermission::of(Permission::ManageBookings))->name('bookings.complete');
    // The business's move: whoever may cancel may move, ungated as cancel is, and the read the
    // Move form and the drag ask first, under the same permission; a second JSON route under
    // /admin/api, the move's own.
    Route::post('/bookings/{uuid}/move', [BookingActionController::class, 'move'])->whereUuid('uuid')->middleware(RequiresPermission::of(Permission::ManageBookings))->name('bookings.move');
    Route::get('/api/bookings/{uuid}/move', MoveReadController::class)->whereUuid('uuid')->middleware(RequiresPermission::of(Permission::ManageBookings))->name('api.bookings.move');

    // The catalog: viewBookings reads it, manageSettings plus an active subscription changes it.
    // The create route is registered above the numeric one so /new is a page and not an id; a path
    // that is neither is no service and falls to the 404 below.
    Route::get('/services', [ServiceController::class, 'index'])->middleware(RequiresPermission::of(Permission::ViewBookings))->name('services');
    Route::get('/services/new', [ServiceController::class, 'create'])->middleware(RequiresPermission::of(Permission::ViewBookings))->name('services.create');
    Route::get('/services/{id}', [ServiceController::class, 'show'])->where('id', TolerantQuery::ID_PATTERN)->middleware(RequiresPermission::of(Permission::ViewBookings))->name('services.show');
    Route::post('/services', [ServiceController::class, 'store'])->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('services.store');
    Route::put('/services/{id}', [ServiceController::class, 'update'])->where('id', TolerantQuery::ID_PATTERN)->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('services.update');
    Route::delete('/services/{id}', [ServiceController::class, 'destroy'])->where('id', TolerantQuery::ID_PATTERN)->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('services.destroy');

    // The services one member performs, replaced whole: written from the service page one
    // member at a time, and from the Staff screen when it lands.
    Route::put('/staff/{id}/services', StaffServicesController::class)->where('id', TolerantQuery::ID_PATTERN)->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('staff.services');

    // The staff: viewBookings reads the screen, and manageSettings plus an active subscription
    // writes a member, their photo (on its own two routes as the logo is), their closures, and the
    // tenant-wide closures. A closure is removed by the date and window in the body, never by a row
    // id the card never showed, so the DELETE carries no id in its path.
    Route::get('/staff', [StaffController::class, 'index'])->middleware(RequiresPermission::of(Permission::ViewBookings))->name('staff');
    Route::get('/staff/new', [StaffController::class, 'create'])->middleware(RequiresPermission::of(Permission::ViewBookings))->name('staff.create');
    Route::get('/staff/{id}', [StaffController::class, 'show'])->where('id', TolerantQuery::ID_PATTERN)->middleware(RequiresPermission::of(Permission::ViewBookings))->name('staff.show');
    Route::post('/staff', [StaffController::class, 'store'])->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('staff.store');
    Route::put('/staff/{id}', [StaffController::class, 'update'])->where('id', TolerantQuery::ID_PATTERN)->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('staff.update');
    Route::delete('/staff/{id}', [StaffController::class, 'destroy'])->where('id', TolerantQuery::ID_PATTERN)->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('staff.destroy');
    Route::post('/staff/{id}/photo', [StaffPhotoController::class, 'store'])->where('id', TolerantQuery::ID_PATTERN)->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('staff.photo.store');
    Route::delete('/staff/{id}/photo', [StaffPhotoController::class, 'destroy'])->where('id', TolerantQuery::ID_PATTERN)->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('staff.photo.destroy');
    Route::put('/staff/{id}/colour', StaffColourController::class)->where('id', TolerantQuery::ID_PATTERN)->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('staff.colour');
    Route::post('/staff/{id}/blackouts', [BlackoutController::class, 'storeForMember'])->where('id', TolerantQuery::ID_PATTERN)->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('staff.blackouts.store');
    Route::delete('/staff/{id}/blackouts', [BlackoutController::class, 'destroyForMember'])->where('id', TolerantQuery::ID_PATTERN)->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('staff.blackouts.destroy');
    Route::post('/blackouts', [BlackoutController::class, 'store'])->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('blackouts.store');
    Route::delete('/blackouts', [BlackoutController::class, 'destroy'])->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('blackouts.destroy');

    // Shifts: manageSettings reads the week, read-only without the staff feature, and
    // manageSettings plus an active subscription writes a member's pattern and one date of theirs,
    // each replace-all, the writes not-found without the feature as the staff writes are. A date
    // in the path that is not a calendar date YYYY-MM-DD is not-found from the controller.
    Route::get('/shifts', [ShiftsController::class, 'show'])->middleware(RequiresPermission::of(Permission::ManageSettings))->name('shifts');
    Route::put('/shifts/{id}/pattern', [ShiftsController::class, 'pattern'])->where('id', TolerantQuery::ID_PATTERN)->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('shifts.pattern');
    Route::put('/shifts/{id}/days/{date}', [ShiftsController::class, 'day'])->where('id', TolerantQuery::ID_PATTERN)->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('shifts.days.update');
    Route::delete('/shifts/{id}/days/{date}', [ShiftsController::class, 'dropDay'])->where('id', TolerantQuery::ID_PATTERN)->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('shifts.days.destroy');

    // The customers: manageBookings reads the list and a customer's page, the permission the
    // CONTACT rule reads, since a page is linked by the address it lists, and exports and erases
    // the customer, neither of which is configuration, so neither the reads, the export nor the
    // erasure are gated by the subscription. A customer is an address the bookings know, its page
    // named by the address as one percent-encoded segment, matched decoded: something, an at sign,
    // and no slash after it. A slash before the at sign is admitted, since the hold's address rule
    // admits one there. A segment with no at sign, nothing before it, or a slash after it falls to
    // the 404 below; an address no booking was made under is answered the same 404 by the
    // controller, and so is an export or an erasure of one. The list's Download CSV reads as the
    // list does and sits before the page, its segment holding no at sign.
    Route::get('/customers', [CustomerController::class, 'index'])->middleware(RequiresPermission::of(Permission::ManageBookings))->name('customers');
    Route::get('/customers/csv', [CustomerController::class, 'csv'])->middleware(RequiresPermission::of(Permission::ManageBookings))->name('customers.csv');
    Route::get('/customers/{email}', [CustomerController::class, 'show'])->where('email', '.+@[^/]+')->middleware(RequiresPermission::of(Permission::ManageBookings))->name('customers.show');
    Route::get('/customers/{email}/export', [CustomerController::class, 'export'])->where('email', '.+@[^/]+')->middleware(RequiresPermission::of(Permission::ManageBookings))->name('customers.export');
    Route::post('/customers/{email}/erase', [CustomerController::class, 'erase'])->where('email', '.+@[^/]+')->middleware(RequiresPermission::of(Permission::ManageBookings))->name('customers.erase');
    // A customer's profile: the Profile card's tags, birthday and language, written whole under
    // manageBookings and never gated by the subscription, since what the business keeps about
    // its customer configures nothing.
    Route::put('/customers/{email}/profile', CustomerProfileController::class)->where('email', '.+@[^/]+')->middleware(RequiresPermission::of(Permission::ManageBookings))->name('customers.profile');
    // A customer's notes and the block on their online booking: under the same permission and
    // never gated by the subscription, for the same reason.
    Route::post('/customers/{email}/notes', [CustomerNoteController::class, 'store'])->where('email', '.+@[^/]+')->middleware(RequiresPermission::of(Permission::ManageBookings))->name('customers.notes.store');
    Route::delete('/customers/{email}/notes/{id}', [CustomerNoteController::class, 'destroy'])->where('email', '.+@[^/]+')->middleware(RequiresPermission::of(Permission::ManageBookings))->name('customers.notes.destroy');
    Route::put('/customers/{email}/block', [CustomerBlockController::class, 'update'])->where('email', '.+@[^/]+')->middleware(RequiresPermission::of(Permission::ManageBookings))->name('customers.block');
    Route::delete('/customers/{email}/block', [CustomerBlockController::class, 'destroy'])->where('email', '.+@[^/]+')->middleware(RequiresPermission::of(Permission::ManageBookings))->name('customers.unblock');

    // The reports: viewBookings reads the figures over a preset or a custom range, nothing writes
    // and nothing is stored, and no read is gated by the subscription; the CSV is the same read as
    // a file.
    Route::get('/reports', [ReportController::class, 'index'])->middleware(RequiresPermission::of(Permission::ViewBookings))->name('reports');
    Route::get('/reports/csv', [ReportController::class, 'csv'])->middleware(RequiresPermission::of(Permission::ViewBookings))->name('reports.csv');

    // The team: the owner's alone, manageBilling being the one permission nobody else holds,
    // support included; the invitation, a role, a removal and a link sent again, none gated by
    // the subscription, since who may sign in configures nothing. A path id that is not a number
    // falls to the 404 below.
    Route::get('/team', [TeamController::class, 'index'])->middleware(RequiresPermission::of(Permission::ManageBilling))->name('team');
    Route::post('/team', [TeamController::class, 'store'])->middleware(RequiresPermission::of(Permission::ManageBilling))->name('team.store');
    Route::put('/team/{id}', [TeamController::class, 'update'])->where('id', TolerantQuery::ID_PATTERN)->middleware(RequiresPermission::of(Permission::ManageBilling))->name('team.update');
    Route::delete('/team/{id}', [TeamController::class, 'destroy'])->where('id', TolerantQuery::ID_PATTERN)->middleware(RequiresPermission::of(Permission::ManageBilling))->name('team.destroy');
    Route::post('/team/{id}/link', [TeamController::class, 'link'])->where('id', TolerantQuery::ID_PATTERN)->middleware(RequiresPermission::of(Permission::ManageBilling))->name('team.link');

    // The settings: the read, the Danger zone's export of everything the tenant holds and its close
    // of the account under manageSettings and never the subscription, 4.4, since none of them
    // configures anything and the close's way back must stay open to a lapsed owner; the whole
    // patch, the implicit member's week under a route naming no member, and the logo's, the cover's
    // and the gallery's upload and removal each under manageSettings plus the gate.
    Route::get('/settings', [SettingsController::class, 'show'])->middleware(RequiresPermission::of(Permission::ManageSettings))->name('settings');
    Route::get('/settings/export', TenantExportController::class)->middleware(RequiresPermission::of(Permission::ManageSettings))->name('settings.export');
    Route::post('/settings/close', AccountCloseController::class)->middleware(RequiresPermission::of(Permission::ManageSettings))->name('settings.close');
    Route::put('/settings', [SettingsController::class, 'update'])->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('settings.update');
    // Colour by, one word from the Calendar's filter card.
    Route::put('/settings/colour-by', [SettingsController::class, 'colourBy'])->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('settings.colour-by');
    Route::put('/opening-hours', [SettingsController::class, 'updateOpeningHours'])->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('opening-hours.update');
    Route::post('/settings/logo', [LogoController::class, 'store'])->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('settings.logo.store');
    Route::delete('/settings/logo', [LogoController::class, 'destroy'])->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('settings.logo.destroy');
    Route::post('/settings/cover', [CoverController::class, 'store'])->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('settings.cover.store');
    Route::delete('/settings/cover', [CoverController::class, 'destroy'])->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('settings.cover.destroy');
    Route::post('/settings/gallery', [GalleryController::class, 'store'])->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('settings.gallery.store');
    Route::delete('/settings/gallery/{file}', [GalleryController::class, 'destroy'])->where('file', TenantImages::FILE_NAME)->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('settings.gallery.destroy');

    // The press of a copy button that copies the booking link, recorded for the setup checklist:
    // body-less, under manageSettings and never the subscription gate, since it configures nothing.
    Route::post('/settings/link-copied', [SettingsController::class, 'linkCopied'])->middleware(RequiresPermission::of(Permission::ManageSettings))->name('settings.link-copied');

    // The Links card's QR codes: an SVG of the booking page's link and of each service's and
    // member's, under manageSettings and never the subscription gate, since drawing a code is a
    // read.
    Route::get('/settings/links/page.svg', [LinkQrController::class, 'page'])->middleware(RequiresPermission::of(Permission::ManageSettings))->name('settings.links.page');
    Route::get('/settings/links/{kind}/{id}.svg', [LinkQrController::class, 'show'])->whereIn('kind', ['service', 'staff'])->where('id', TolerantQuery::ID_PATTERN)->middleware(RequiresPermission::of(Permission::ManageSettings))->name('settings.links.show');

    // The Payments section: present on every plan, each write a configuration write under
    // manageSettings plus the gate. The save alone carries a body; the others act on the tenant's
    // one connection and carry none. The save and the test each make one live call to the provider
    // and share a limit of their own, ahead of the permission as the billing writes' is.
    Route::post('/settings/payments', [PaymentsController::class, 'store'])->middleware(['throttle:payment-checks', RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('settings.payments.store');
    Route::post('/settings/payments/platform', [PaymentsController::class, 'connect'])->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('settings.payments.platform');
    Route::post('/settings/payments/platform/resume', [PaymentsController::class, 'resume'])->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('settings.payments.platform.resume');
    Route::post('/settings/payments/test', [PaymentsController::class, 'test'])->middleware(['throttle:payment-checks', RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('settings.payments.test');
    Route::post('/settings/payments/rotate', [PaymentsController::class, 'rotate'])->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('settings.payments.rotate');
    Route::post('/settings/payments/disable', [PaymentsController::class, 'disable'])->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('settings.payments.disable');
    Route::post('/settings/payments/enable', [PaymentsController::class, 'enable'])->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('settings.payments.enable');
    Route::delete('/settings/payments', [PaymentsController::class, 'destroy'])->middleware([RequiresPermission::of(Permission::ManageSettings), RequiresActiveSubscription::NAME])->name('settings.payments.destroy');

    // Billing: manageBilling on every route and deliberately never the subscription gate, because
    // this is the way back. The three writes each ask the provider and share a limit of their
    // own.
    Route::get('/billing', [BillingController::class, 'show'])->middleware(RequiresPermission::of(Permission::ManageBilling))->name('billing');
    Route::post('/billing/plan', [BillingController::class, 'plan'])->middleware(['throttle:billing', RequiresPermission::of(Permission::ManageBilling)])->name('billing.plan');
    Route::post('/billing/portal', [BillingController::class, 'portal'])->middleware(['throttle:billing', RequiresPermission::of(Permission::ManageBilling)])->name('billing.portal');
    Route::post('/billing/checkout', [BillingController::class, 'checkout'])->middleware(['throttle:billing', RequiresPermission::of(Permission::ManageBilling)])->name('billing.checkout');

    // The user's own language: one of the two by the path's constraint, never the subscription
    // gate, a person's preference and no configuration, and under no limit.
    Route::put('/language/{language}', LanguageController::class)->whereIn('language', array_map(fn (SiteLanguage $language): string => $language->value, SiteLanguage::cases()))->middleware(RequiresSignIn::NAME)->name('language');

    Route::get('/{path}', NotFoundController::class)->where('path', '.*')->middleware(RequiresSignIn::NAME)->name('not-found');
});
