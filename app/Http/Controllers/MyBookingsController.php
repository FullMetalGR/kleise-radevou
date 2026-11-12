<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Clock;
use App\Http\BookingPage;
use App\Http\CustomerNotice;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SetTenantLocale;
use App\Http\MyBookingsCookie;
use App\Http\Requests\MyBookingsLinkRequest;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\CustomerAccess;
use App\Infrastructure\MyBookingsFactory;
use App\Site\Demo;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Languages;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantPages;
use App\Tenancy\TenantStatus;
use App\Tenancy\TenantUrls;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Translation\Translator;
use NumberFormatter;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * My bookings: the customer's implicit account at this tenant, an address and a link, on the
 * tenant's own origin and never on the embed.
 *
 * GET /my-bookings is the hosted page's view with the mount in list mode: the session's bookings
 * where the cookie names a live session, the form asking for a link where it names none; asked for
 * JSON, it answers the same data as JSON, the read the list mode makes after an action. POST
 * /my-bookings/link takes an address and always answers the same sentence on the way back to the
 * form; a link is minted and mailed only for an address with a booking here while the key is on,
 * and a malformed address sends nothing and reads the same sentence. GET /my-bookings/{token} is
 * the link's page: one button, and nothing spent, since a mail scanner fetches every link (the
 * decision links' pattern); the button's POST to the same address opens the session once inside
 * the link's fifteen minutes and goes on to the page. A used, lapsed or unknown token goes there
 * from either verb, with a sentence saying so. POST /my-bookings/sign-out ends the session and
 * clears the cookie.
 *
 * Public in the vocabulary of routes/tenant.php: the credential each route takes, the link's token
 * or the session's cookie, is judged here and a miss is an answer, never a refusal, because there
 * is nothing on the page for a stranger but the form. The three POSTs ride the cross-site guard
 * every page of this host runs under, with the token the page inlines for the app's forms or the
 * one the link's page writes into its own.
 *
 * Every way back to the page keeps the prefix the request came under, bare for none, so a customer
 * reading under /en or /el stays there through the form, the link's button and the sign-out.
 *
 * Every sentence here is a key of pages.php: a redirect flashes its key and the page it lands on
 * reads it in that page's language, the link page reads its three in the request's language, and
 * the limit's refusal is read in the language the path's prefix names, else this row's tenant
 * default, since the limit answers before the tenant group runs.
 *
 * Neither secret is ever written into an answer: the link's page posts to the address it was
 * opened at without naming it, the link's token is spent on the way in, and the session's secret
 * leaves only in the encrypted cookie. Every answer here is no-store, since the page carries a
 * customer's bookings or a token for the form, and the link's two verbs are no-referrer as the
 * manage page is, since their URL is a credential; the link's page is noindex as the decision
 * pages are.
 */
final readonly class MyBookingsController
{
    /** A day in the minutes the session's cookie counts its life in. */
    private const int MINUTES_A_DAY = 1440;

    /**
     * The sentences of My bookings' server, each a key of lang/{en,el}/pages.php: the three its
     * redirects flash, flashed as their keys and read by the page that shows them in that page's
     * language (CustomerNotice), and the form's refusal past the link's limit, read in the
     * request's language.
     */
    public const string SENT = 'pages.my_bookings.flashes.sent';

    public const string INVALID = 'pages.my_bookings.flashes.invalid';

    public const string TOO_MANY = 'pages.my_bookings.refusals.too_many';

    public const string SIGNED_OUT = 'pages.my_bookings.flashes.signed_out';

    /** The link page's title, :business the business it names (P9F-L16-a). */
    public const string OPEN_TITLE = 'pages.link.title';

    /**
     * What the link page's one button does, above it, read as a choice by the session's length in
     * days, :count that length spelled out.
     */
    public const string OPEN_SENTENCE = 'pages.link.sentence';

    /** The link page's one button, whose POST opens the session. */
    public const string OPEN_LABEL = 'pages.link.label';

    /**
     * The flash a redirect carries the sentence in, as the signup's pending page reads it: the
     * customer's one notice key, which the pay hop's redirects back here flash too.
     */
    public const string FLASH = CustomerNotice::KEY;

    public function __construct(
        private CurrentTenant $current,
        private BookingPage $page,
        private CustomerAccess $access,
        private BookingReader $bookings,
        private MyBookingsCookie $cookie,
        private MyBookingsFactory $factory,
        private TenantUrls $urls,
        private Clock $clock,
        private Demo $demo,
        private ViewFactory $views,
        private CustomerNotice $notice,
        private Translator $translator,
        private Languages $languages,
    ) {}

    public function show(Request $request): SymfonyResponse
    {
        $tenant = $this->current->get();
        $now = $this->clock->now();
        $secret = MyBookingsCookie::read($request);
        $email = $secret === null ? null : $this->access->sessionAddress($tenant, $secret, $now);
        $listed = $email === null ? null : $this->bookings->ofAddress($tenant, $email, $now);
        $csrf = $request->session()->token();

        // The list mode's one JSON read, after a cancel or a move: the shape the page inlines,
        // behind the same session, from the app on this origin alone, since no CORS answers a path
        // outside the API's.
        $response = $request->expectsJson()
            ? new JsonResponse(BookingPage::listed($listed, $this->notice->read(), $csrf), Response::HTTP_OK, ['Cache-Control' => 'no-store'])
            : self::answer($this->page->myBookings($tenant, $listed, $this->notice->read(), $csrf)->render(), Response::HTTP_OK);

        // A cookie naming no live session, revoked, lapsed or never ours, is cleared on the way.
        if ($secret !== null && $email === null) {
            $this->cookie->forget($response);
        }

        return $response;
    }

    public function link(MyBookingsLinkRequest $request): RedirectResponse
    {
        $tenant = $this->current->get();
        $email = $request->address();

        // The demo answers as it answers an address with no booking: nothing is minted and nothing
        // is announced, by the label and not by the tenant's switch, so a key turned on changes
        // nothing, and the sentence is the one every address reads.
        if ($email !== null && ! $this->demo->is($tenant)) {
            // The message is written in the language this request is answered in, its prefix or the
            // tenant default, which no row records, so it rides to the job.
            $this->factory->requestLink($tenant)->request($email, $this->languages->ofRequest($request, $tenant)->value);
        }

        return $this->back($request, $tenant, 'success', self::SENT);
    }

    /**
     * The link's page, GET /my-bookings/{token}: a live link renders one button and spends
     * nothing, so a mail scanner's fetch leaves it live (P9F-L16-a); a used, lapsed or unknown
     * token goes on to the form with the sentence the POST answers it with.
     */
    public function linkPage(string $token, Request $request): SymfonyResponse
    {
        $tenant = $this->current->get();

        if (! $this->access->linkIsLive($tenant, $token, $this->clock->now())) {
            return $this->back($request, $tenant, 'warning', self::INVALID);
        }

        $page = $this->views->make('my-bookings.open', [
            'tenant' => $tenant->name,
            'title' => $this->line(self::OPEN_TITLE, ['business' => $tenant->name]),
            'sentence' => $this->sessionSentence(),
            'label' => $this->line(self::OPEN_LABEL),
        ])->render();

        return self::private(new Response($page, Response::HTTP_OK, ['X-Robots-Tag' => 'noindex']));
    }

    /**
     * The link page's button, POST /my-bookings/{token}: opens the session once, in one statement
     * (CustomerAccess::openSession), sets the cookie and goes on to the page.
     */
    public function open(string $token, Request $request): RedirectResponse
    {
        $tenant = $this->current->get();
        $now = $this->clock->now();
        $secret = $this->access->openSession($tenant, $token, $now);

        if ($secret === null) {
            return $this->back($request, $tenant, 'warning', self::INVALID);
        }

        // A session this browser already held, for this address or another one on a shared
        // device, ends as the new one opens: one cookie, one session.
        $held = MyBookingsCookie::read($request);

        if ($held !== null) {
            $this->access->revokeSession($tenant, $held, $now);
        }

        $response = self::private(new RedirectResponse($this->wayBack($request, $tenant)));
        $this->cookie->set($response, $secret);

        return $response;
    }

    public function signOut(Request $request): RedirectResponse
    {
        $tenant = $this->current->get();
        $held = MyBookingsCookie::read($request);

        if ($held !== null) {
            $this->access->revokeSession($tenant, $held, $this->clock->now());
        }

        $response = $this->back($request, $tenant, 'success', self::SIGNED_OUT);
        $this->cookie->forget($response);

        return $response;
    }

    /**
     * The answer past the link's limit: the form again at 429 with the refusal worded, never the
     * envelope, as the sign-in's and the signup's forms answer past theirs. The limiter answers
     * before tenant resolution has run, so the tenant is found here by the resolver's own rule
     * and answered as the resolver answers it: a host naming none is the 404 every such host is,
     * and a suspended tenant's is the resolver's suspended page at 503, the one answer its host
     * gives on every route.
     *
     * @param  array<string, string>  $headers
     */
    public static function tooMany(Request $request, array $headers): SymfonyResponse
    {
        $resolver = app(ResolveTenant::class);
        $tenant = $resolver->tenantOf($request->getHost());

        if ($tenant === null) {
            throw new NotFoundHttpException;
        }

        if ($tenant->status === TenantStatus::Suspended) {
            return $resolver->suspended($request, $tenant);
        }

        // In the request's language, as the tenant group's locale would have set it: the prefix of
        // the path, else this row's tenant default, the refusal read through the translator in it.
        $response = app(SetTenantLocale::class)->within($request, $tenant, fn (): Response => self::answer(
            app(BookingPage::class)->myBookings($tenant, null, self::sentence(app(Translator::class), self::TOO_MANY), $request->session()->token())->render(),
            Response::HTTP_TOO_MANY_REQUESTS,
        ));
        $response->headers->add($headers);

        return $response;
    }

    /**
     * Back to the page with a sentence above whatever it shows, in the flash's shape the signup's
     * pending page reads: a kind and the words, as their key, which the page reads in its own
     * language.
     *
     * @param  'success'|'warning'  $kind
     */
    private function back(Request $request, Tenant $tenant, string $kind, string $key): RedirectResponse
    {
        $this->notice->flash($kind, $key);

        return self::private(new RedirectResponse($this->wayBack($request, $tenant)));
    }

    /**
     * The page, under the prefix the request came under, bare for none.
     */
    private function wayBack(Request $request, Tenant $tenant): string
    {
        return $this->urls->myBookings(Slug::of($tenant->slug), TenantPages::prefixOf($request->path()));
    }

    /**
     * The link page's sentence: the session's length in days, chosen by and spelled in words by
     * ICU in the language the line is read in, the request's where pages.php holds the line in it
     * and else the translator's fallback, so a pending Greek file never puts a Greek number into
     * the English sentence.
     */
    private function sessionSentence(): string
    {
        $days = intdiv(MyBookingsCookie::lifetimeMinutes(), self::MINUTES_A_DAY);
        $locale = $this->translator->getLocale();
        $spelledIn = $this->translator->hasForLocale(self::OPEN_SENTENCE, $locale) ? $locale : $this->translator->getFallback();
        $spelled = (new NumberFormatter($spelledIn, NumberFormatter::SPELLOUT))->format($days);

        return $this->translator->choice(self::OPEN_SENTENCE, $days, ['count' => is_string($spelled) ? $spelled : (string) $days]);
    }

    /**
     * One line of pages.php in the request's language, its placeholders filled.
     *
     * @param  array<string, string>  $replace
     */
    private function line(string $key, array $replace = []): string
    {
        return self::sentence($this->translator, $key, $replace);
    }

    /**
     * One line of pages.php through the translator given, in its locale; a key the file lacks
     * reads as the key, as the translator answers it. Static for the limit's answer, which no
     * instance of this controller gives.
     *
     * @param  array<string, string>  $replace
     */
    private static function sentence(Translator $translator, string $key, array $replace = []): string
    {
        $line = $translator->get($key, $replace);

        return is_string($line) ? $line : $key;
    }

    private static function answer(string $page, int $status): Response
    {
        return new Response($page, $status, ['Cache-Control' => 'no-store']);
    }

    /**
     * @template T of SymfonyResponse
     *
     * @param  T  $response
     * @return T
     */
    private static function private(SymfonyResponse $response): SymfonyResponse
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
