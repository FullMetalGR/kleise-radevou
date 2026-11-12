<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Http\Requests\ContactRequest;
use App\Http\TurnstileForm;
use App\Http\TurnstileGate;
use App\Notifications\ContactMessageReceived;
use App\Site\Brand;
use App\Site\ContactMessages;
use App\Site\SiteLanguage;
use App\Site\SitePages;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The contact page, at /contact and /el/contact: the name, the email address and the message,
 * the trap a person never fills, and Turnstile's widget while both its keys are set, every
 * sentence from contact.php in the page's language. The page keeps the session, for the
 * cross-site token, the flash and the errors.
 *
 * The POST runs in this order. The limit is the route's, five an hour per address, past which
 * tooMany answers. Then the trap: a filled one is answered exactly as a good message is, the
 * thank-you, with nothing stored, nothing sent and nothing logged, so a machine learns
 * nothing. Only then is the form resolved, which judges the fields and, once they have passed,
 * the anti-spam check, a refusal sending the person back to the page. Then the row, then the
 * mail through the platform's seam once the row stands, and the thank-you: a redirect to the
 * page with one sentence in the flash, in the page's language.
 */
final readonly class ContactController
{
    /** The session key of the page's one notice, the thank-you. */
    public const string FLASH = 'flash';

    public function __construct(
        private Brand $brand,
        private Factory $views,
        private SitePages $pages,
        private TurnstileGate $turnstile,
    ) {}

    public function show(Request $request): View
    {
        $notice = $request->session()->get(self::FLASH);

        return $this->page(
            ContactRequest::languageOf($request->route()),
            is_array($notice) && is_string($notice['message'] ?? null) ? $notice['message'] : null,
            fn (string $field): string => self::text($request->old($field)),
        );
    }

    public function send(Request $request, Container $container, ContactMessages $messages, ContactMessageReceived $mail): RedirectResponse
    {
        $language = ContactRequest::languageOf($request->route());

        if (! $request->filled(ContactRequest::TRAP)) {
            $message = $container->make(ContactRequest::class)->contactMessage();
            $messages->store($message);
            $mail->send($message);
        }

        $request->session()->flash(self::FLASH, ['kind' => 'success', 'message' => self::sentence('contact.sent')]);

        return new RedirectResponse($this->pages->url(ContactRequest::PAGE, $language));
    }

    /**
     * The answer past the limit: the page at 429 in the route's language with the refusal above
     * the form and the posted fields in it, the token aside. The limit runs before the route's
     * locale, which the framework sorts after it, so the locale is set here for the page alone
     * and put back once it is rendered.
     *
     * @param  array<string, string>  $headers
     */
    public static function tooMany(Request $request, array $headers): Response
    {
        $app = app(Application::class);
        $previous = $app->getLocale();
        $language = ContactRequest::languageOf($request->route());
        $app->setLocale($language->value);

        try {
            $page = app(self::class)->page($language, self::sentence('contact.too_many'), fn (string $field): string => self::text($request->input($field)));

            return new Response($page, Response::HTTP_TOO_MANY_REQUESTS, $headers);
        } finally {
            $app->setLocale($previous);
        }
    }

    /**
     * The page in a language with the notice given above the form, the thank-you or the limit's
     * refusal, and each field's value as the field given reads it.
     *
     * @param  Closure(string): string  $value
     */
    private function page(SiteLanguage $language, ?string $notice, Closure $value): View
    {
        return $this->views->make('site.contact', [
            'name' => $this->brand->name,
            'language' => $language,
            'action' => $this->pages->url(ContactRequest::PAGE, $language),
            'notice' => $notice,
            'input' => ['name' => $value('name'), 'email' => $value('email'), 'message' => $value('message')],
            'trap' => ContactRequest::TRAP,
            'token' => TurnstileGate::FIELD,
            'turnstile' => $this->turnstile->widget(TurnstileForm::Contact),
        ]);
    }

    private static function sentence(string $key): string
    {
        $sentence = __($key);

        return is_string($sentence) ? $sentence : $key;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
