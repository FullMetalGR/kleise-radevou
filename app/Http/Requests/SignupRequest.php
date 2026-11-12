<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\Turnstile\TurnstileCheck;
use App\Http\SignupStrings;
use App\Http\TurnstileGate;
use App\Site\SiteLanguage;
use App\Tenancy\BusinessCategory;
use App\Tenancy\InvalidSlug;
use App\Tenancy\Signup;
use App\Tenancy\SlugProblem;
use App\Tenancy\SlugRules;
use App\Tenancy\TermsAcceptance;
use Closure;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The signup form, judged at the HTTP boundary: every refusal is one sentence on its field, read
 * from signup.php in the request's language through SignupStrings, so no framework validation file
 * is published. The slug is judged by SlugRules, the one place its shape, the reserved list and
 * uniqueness are decided, so a row in any state holds it, and a signup in progress holds it with a
 * sentence of its own, each problem worded by its code. The category is one of the seven the table
 * allows. The currency is judged by the pattern Settings uses, with the signup's own copy of its
 * sentence, and no case is folded: the code is stored exactly as given. The password is judged by
 * PasswordRule, the one place its floor and its ceiling are decided, handed the two sentences of
 * the file; an empty one reads the short sentence. No plan is read: the signup starts a trial on
 * the full set.
 *
 * The terms' box is a field like every other, judged by the framework's accepted rule: absent, or
 * anything outside that rule's set, it is refused on its field with its sentence. A form that
 * passes carries the owner's acceptance of the terms, the version the terms file names, which the
 * reservation records on the row.
 *
 * With Cloudflare Turnstile on, both its keys set, the widget's token is a field of its own,
 * judged here like every other: absent, empty or longer than any token Cloudflare issues, it is
 * refused with the check's sentence, and once every field has passed the token is asked about
 * once, through the check's port, for the address that posted it, a failed verdict refused with
 * the same sentence on the same field. So the check runs before the reservation and inside the
 * signup limiter's count, and a refused check writes nothing and sends nothing. With either key
 * unset the field is not asked for and the check never runs.
 */
final class SignupRequest extends FormRequest
{
    /** The terms' box's field. */
    public const string TERMS = 'terms';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SlugRules $slugRules, TurnstileGate $turnstile, SignupStrings $strings): array
    {
        $rules = [
            'slug' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) use ($slugRules, $strings): void {
                try {
                    $slugRules->check(is_string($value) ? $value : '');
                } catch (InvalidSlug $refused) {
                    $fail($strings->slug($refused->problem));
                }
            }],
            'name' => ['required', 'string'],
            'category' => ['required', 'string', Rule::enum(BusinessCategory::class)],
            'timezone' => ['required', 'string', 'timezone:all'],
            'currency' => ['required', 'string', 'regex:'.SettingsRequest::CURRENCY_PATTERN],
            'owner_name' => ['required', 'string'],
            'owner_email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', new PasswordRule($strings->error('password_short'), $strings->error('password_long'))],
            self::TERMS => ['accepted'],
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
    public function after(TurnstileGate $turnstile, TurnstileCheck $check, SignupStrings $strings): array
    {
        return [function (Validator $validator) use ($turnstile, $check, $strings): void {
            if (! $turnstile->on() || $validator->errors()->isNotEmpty()) {
                return;
            }

            if (! $check->passes($this->string(TurnstileGate::FIELD)->toString(), $this->ip())) {
                $validator->errors()->add(TurnstileGate::FIELD, $strings->error('check'));
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $strings = $this->container->make(SignupStrings::class);

        return [
            'slug.required' => $strings->slug(SlugProblem::Empty),
            'slug.string' => $strings->slug(SlugProblem::Empty),
            'name.*' => $strings->error('name'),
            'category.*' => $strings->error('category'),
            'timezone.*' => $strings->error('timezone'),
            'currency.*' => $strings->error('currency'),
            'owner_name.*' => $strings->error('owner_name'),
            'owner_email.*' => $strings->error('owner_email'),
            'password.required' => $strings->error('password_short'),
            'password.string' => $strings->error('password_short'),
            self::TERMS.'.*' => $strings->error('terms'),
            TurnstileGate::FIELD.'.*' => $strings->error('check'),
        ];
    }

    /**
     * What the form asked for, in the language the form was served in, which the row keeps,
     * with the owner's acceptance of the terms in the version given, the day the terms file
     * names, since a form that passed has its box ticked.
     */
    public function signup(SiteLanguage $locale, string $termsVersion): Signup
    {
        return new Signup(
            slug: $this->string('slug')->toString(),
            name: $this->string('name')->trim()->toString(),
            timezone: new DateTimeZone($this->string('timezone')->toString()),
            currency: $this->string('currency')->toString(),
            ownerName: $this->string('owner_name')->trim()->toString(),
            ownerEmail: $this->string('owner_email')->trim()->toString(),
            ownerPassword: $this->string('password')->toString(),
            category: $this->string('category')->toString(),
            locale: $locale,
            terms: new TermsAcceptance($termsVersion),
        );
    }
}
