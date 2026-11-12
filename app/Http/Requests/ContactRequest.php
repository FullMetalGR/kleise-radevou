<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\Turnstile\TurnstileCheck;
use App\Http\TurnstileGate;
use App\Site\ContactMessage;
use App\Site\SiteLanguage;
use App\Site\SitePages;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Validation\Validator;

/**
 * The contact form, judged at the HTTP boundary: the name required, up to 200 characters; the
 * email required, an address, up to 254; the message required, up to 5000. Every refusal is a
 * sentence of contact.php in the page's language, which the route's locale has set, named under
 * its field, and a refused form goes back to the page in its language with the input flashed
 * back, the token never (bootstrap/app.php).
 *
 * With Cloudflare Turnstile on, both its keys set, the widget's token is a field of its own,
 * judged as the signup judges it: absent, empty or longer than any token Cloudflare issues, it is
 * refused with the check's sentence, and once every other field has passed the token is asked
 * about once, through the check's port, for the address that posted it, a failed verdict refused
 * with the same sentence on the same field. So the check runs after the limit and the trap, and a
 * refused check stores nothing and sends nothing. With either key unset the field is not asked
 * for and the check never runs.
 *
 * The trap, TRAP, is no field of the form's: the controller reads it before this request is
 * resolved, since a filled one is answered before any field is judged.
 */
final class ContactRequest extends FormRequest
{
    /** The contact page's English route name, its Greek one under el. */
    public const string PAGE = 'site.contact';

    /** The field a person never fills, hidden by style and from assistive technology. */
    public const string TRAP = 'website';

    public const int NAME_MAX = 200;

    public const int EMAIL_MAX = 254;

    public const int MESSAGE_MAX = 5000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(TurnstileGate $turnstile): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:'.self::NAME_MAX],
            'email' => ['required', 'string', 'email', 'max:'.self::EMAIL_MAX],
            'message' => ['required', 'string', 'max:'.self::MESSAGE_MAX],
        ];

        if ($turnstile->on()) {
            $rules[TurnstileGate::FIELD] = ['required', 'string', 'max:'.TurnstileGate::TOKEN_MAX];
        }

        return $rules;
    }

    /**
     * The anti-spam check, asked once every field has passed, so a form refused on a field
     * spends no token, and never while Turnstile is off.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(TurnstileGate $turnstile, TurnstileCheck $check): array
    {
        return [function (Validator $validator) use ($turnstile, $check): void {
            if (! $turnstile->on() || $validator->errors()->isNotEmpty()) {
                return;
            }

            if (! $check->passes($this->string(TurnstileGate::FIELD)->toString(), $this->ip())) {
                $validator->errors()->add(TurnstileGate::FIELD, $this->sentence('check'));
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => $this->sentence('name_required'),
            'name.string' => $this->sentence('name_required'),
            'name.max' => $this->sentence('name_long'),
            'email.required' => $this->sentence('email_required'),
            'email.string' => $this->sentence('email_required'),
            'email.email' => $this->sentence('email_invalid'),
            'email.max' => $this->sentence('email_long'),
            'message.required' => $this->sentence('message_required'),
            'message.string' => $this->sentence('message_required'),
            'message.max' => $this->sentence('message_long'),
            TurnstileGate::FIELD.'.*' => $this->sentence('check'),
        ];
    }

    /**
     * The message as the form judged it, in the language of the page it was sent from.
     */
    public function contactMessage(): ContactMessage
    {
        return new ContactMessage(
            name: $this->string('name')->trim()->toString(),
            email: $this->string('email')->trim()->toString(),
            message: $this->string('message')->toString(),
            language: self::languageOf($this->route()),
        );
    }

    /**
     * The language a contact route answers in, its route's: el.site.contact and
     * el.site.contact.send are Greek, the rest English.
     */
    public static function languageOf(mixed $route): SiteLanguage
    {
        return SiteLanguage::ofRouteName($route instanceof Route ? (string) $route->getName() : '');
    }

    /**
     * A refused form goes back to the page in its own language, whatever the browser sent as
     * its referrer.
     */
    protected function getRedirectUrl(): string
    {
        return $this->container->make(SitePages::class)->url(self::PAGE, self::languageOf($this->route()));
    }

    private function sentence(string $key): string
    {
        $sentence = __('contact.errors.'.$key);

        return is_string($sentence) ? $sentence : $key;
    }
}
