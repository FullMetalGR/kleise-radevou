<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Middleware\HandleCentralInertiaRequests;
use App\Http\Requests\SignupRequest;
use App\Http\SignupHolder;
use App\Http\SignupStrings;
use App\Http\TurnstileForm;
use App\Http\TurnstileGate;
use App\Notifications\VerificationLink;
use App\Site\SignupLinks;
use App\Site\SiteLanguage;
use App\Site\TermsVersion;
use App\Tenancy\BusinessCategory;
use App\Tenancy\InvalidSlug;
use App\Tenancy\SignupVerification;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use DateTimeZone;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signup form on the central host: the slug and the business with its category, its clock and
 * its currency, and the owner. No plan and no card: a valid POST reserves the slug with a row in
 * state provisioning, the owner's acceptance of the terms on it, the owner under it and the hash
 * of a verification link beside the owner, then, the reservation committed, mails the link through
 * the platform's seam and sends the person to the pending page, marking this browser as the slug's
 * holder so the page names the address and the business to it alone. Nothing is built and no
 * provider is asked: the schema waits for the click. Every sentence answered here is a key of
 * signup.php, read in the request's language, and that language, the route's, is the row's: the
 * reservation writes it, and the redirects on from here follow the row.
 */
final readonly class SignupController
{
    public function __construct(
        private SignupVerification $verification,
        private VerificationLink $link,
        private TenantUrls $urls,
        private SignupLinks $links,
        private SignupHolder $holder,
        private TurnstileGate $turnstile,
        private SignupStrings $strings,
        private TermsVersion $terms,
    ) {}

    public function show(): InertiaResponse
    {
        return Inertia::render('signup/Signup', self::props($this->urls, $this->links, $this->turnstile, $this->strings, null));
    }

    public function store(SignupRequest $request): Response
    {
        $signup = $request->signup($this->strings->language(), $this->terms->day());

        try {
            ['tenant' => $tenant, 'token' => $token] = $this->verification->reserve($signup);
        } catch (InvalidSlug $refused) {
            throw ValidationException::withMessages(['slug' => $this->strings->slug($refused->problem)]);
        }

        $this->link->send($tenant, $signup->ownerEmail, $signup->ownerName, $token);
        $slug = Slug::of($tenant->slug);

        return $this->holder->mark(new RedirectResponse($this->links->pending($slug->value, $tenant->locale)), $slug);
    }

    /**
     * The pending page's resend, under the form's own limit: where the slug's row still waits on
     * its click a new link replaces the old and is mailed through the platform's seam once the
     * replacement has committed; whatever was found, built, unknown or past its 24 hours, the
     * answer is the pending page with the same one sentence, so the POST tells nobody what a slug
     * holds that the pending page itself does not. The slug's shape is judged here, at the
     * boundary, as the pending page judges it: a string that is no slug sends nothing and reads
     * the same sentence. The link goes in the row's language whichever language's page posted,
     * and so does the person, to the pending page in it; where nothing was found there is no row
     * to read a language from, and the pending page is the path's.
     */
    public function resend(Request $request, string $slug): Response
    {
        try {
            $resent = $this->verification->resend(Slug::of($slug));
        } catch (InvalidSlug) {
            $resent = null;
        }

        if ($resent !== null) {
            $this->link->send($resent['tenant'], $resent['email'], $resent['name'], $resent['token']);
        }

        $request->session()->flash('flash', ['kind' => 'success', 'message' => $this->strings->sentence('resent')]);

        return new RedirectResponse($this->links->pending($slug, $resent === null ? $this->strings->language() : $resent['tenant']->locale));
    }

    /**
     * The answer past the signup limit: the form again at 429 with the refusal worded, as the
     * sign-in POST answers past its own. The limiter answers before the route's locale middleware
     * has run, whatever order the route declares them in, since the framework sorts the throttle
     * ahead by priority; so the language is read from the route's name here, as the contact
     * form's 429 reads it, and set for the render, the frame's partials included, then put back.
     *
     * @param  array<string, string>  $headers
     */
    public static function tooMany(Request $request, array $headers): Response
    {
        $app = app(Application::class);
        $previous = $app->getLocale();
        $route = $request->route();
        $app->setLocale(SiteLanguage::ofRouteName($route instanceof RegisteredRoute ? (string) $route->getName() : '')->value);

        try {
            $response = self::refused($request, app(SignupStrings::class)->sentence('too_many'), 429);
            $response->headers->add($headers);

            return $response;
        } finally {
            $app->setLocale($previous);
        }
    }

    /**
     * The form again with a refusal above its button, at the status given: the limit's 429, and
     * the expired link's 410 the click answers. The limiter answers before the central adapter
     * has run, so the page is handed here what the adapter shares with every other, the brand,
     * the language and the strings, from the adapter's own hand; the 410, which the adapter did
     * see, carries the same values twice over.
     */
    public static function refused(Request $request, string $refusal, int $status): Response
    {
        Inertia::setRootView(HandleCentralInertiaRequests::ROOT_VIEW);

        $props = [
            ...self::props(app(TenantUrls::class), app(SignupLinks::class), app(TurnstileGate::class), app(SignupStrings::class), $refusal),
            ...app(HandleCentralInertiaRequests::class)->hostProps(),
        ];

        return Inertia::render('signup/Signup', $props)
            ->toResponse($request)
            ->setStatusCode($status);
    }

    /**
     * The categories with their labels from the file, the timezones a business may keep, the host
     * the slug becomes a subdomain of, where the form posts, the POST's path in the request's
     * language, the terms' and the privacy policy's addresses in it, which the box's sentence
     * links, and Turnstile's site key and the signup's script while both its keys are set, null
     * while either is unset: this form and the site's contact page are the two pages that carry
     * them.
     *
     * @return array<string, mixed>
     */
    private static function props(TenantUrls $urls, SignupLinks $links, TurnstileGate $turnstile, SignupStrings $strings, ?string $refusal): array
    {
        return [
            'categories' => array_map(fn (BusinessCategory $category): array => ['value' => $category->value, 'label' => $strings->sentence('categories.'.$category->value)], BusinessCategory::cases()),
            'timezones' => DateTimeZone::listIdentifiers(),
            'centralHost' => $urls->centralHost(),
            'action' => $links->storePath($strings->language()),
            'legal' => $links->legal($strings->language()),
            'refusal' => $refusal,
            'turnstile' => $turnstile->widget(TurnstileForm::Signup),
        ];
    }
}
