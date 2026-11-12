<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Platform\NewFreeTenant;
use App\Site\SiteLanguage;
use App\Tenancy\BusinessCategory;
use App\Tenancy\InvalidSlug;
use App\Tenancy\SlugProblem;
use App\Tenancy\SlugRules;
use Closure;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The console's free tenant form, judged at the HTTP boundary: every refusal is one sentence on its
 * field through the errors bag the console's adapter shares.
 *
 * The slug is judged by SlugRules, the one place its shape, the reserved list and uniqueness are
 * decided, as the signup's form judges it: a row in any state holds it, a signup in progress with a
 * sentence of its own. The form judges it rather than the provisioner because the console path
 * hands back an active row of that slug for a retried tenant:provision instead of refusing it. The
 * category is one of the seven or none; the timezone one the signup offers; the currency three
 * upper-case letters, the sentence Settings uses, no case folded; the features a list of Feature's
 * slugs, none the floor; the language one of the site's two, en or el, with a sentence of its own.
 * No password is read: the owner sets their own through the link the creation mails.
 */
final class NewTenantRequest extends FormRequest
{
    public const string NO_NAME = 'Enter the name of the business.';

    public const string BAD_CATEGORY = 'Choose the kind of business from the list.';

    public const string BAD_TIMEZONE = 'Choose the timezone the business keeps from the list.';

    public const string NO_OWNER_NAME = 'Enter the owner\'s name.';

    public const string BAD_EMAIL = 'Enter the owner\'s email address, such as owner@example.com.';

    public const string BAD_LOCALE = 'Choose the language from the list.';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SlugRules $slugRules): array
    {
        return [
            'slug' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) use ($slugRules): void {
                try {
                    $slugRules->check(is_string($value) ? $value : '');
                } catch (InvalidSlug $refused) {
                    $fail($refused->problem->detail());
                }
            }],
            'name' => ['required', 'string'],
            'category' => ['nullable', 'string', Rule::enum(BusinessCategory::class)],
            'timezone' => ['required', 'string', 'timezone:all'],
            'currency' => ['required', 'string', 'regex:'.SettingsRequest::CURRENCY_PATTERN],
            'owner_name' => ['required', 'string'],
            'owner_email' => ['required', 'string', 'email'],
            'locale' => ['required', 'string', Rule::enum(SiteLanguage::class)],
            ...TenantFeaturesRequest::featureRules(false),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.required' => SlugProblem::Empty->detail(),
            'slug.string' => SlugProblem::Empty->detail(),
            'name.*' => self::NO_NAME,
            'category.*' => self::BAD_CATEGORY,
            'timezone.*' => self::BAD_TIMEZONE,
            // The Settings screen's currency sentence, read in English: the console's copy is.
            'currency.*' => SettingsRequest::english(SettingsRequest::CURRENCY),
            'owner_name.*' => self::NO_OWNER_NAME,
            'owner_email.*' => self::BAD_EMAIL,
            'locale.*' => self::BAD_LOCALE,
            ...TenantFeaturesRequest::featureMessages(),
        ];
    }

    public function freeTenant(): NewFreeTenant
    {
        $category = $this->input('category');

        return new NewFreeTenant(
            slug: $this->string('slug')->toString(),
            name: $this->string('name')->trim()->toString(),
            timezone: new DateTimeZone($this->string('timezone')->toString()),
            currency: $this->string('currency')->toString(),
            category: is_string($category) ? $category : null,
            ownerName: $this->string('owner_name')->trim()->toString(),
            ownerEmail: $this->string('owner_email')->trim()->toString(),
            features: TenantFeaturesRequest::featuresIn($this->input(TenantFeaturesRequest::FIELD)),
            locale: SiteLanguage::from($this->string('locale')->toString()),
        );
    }
}
