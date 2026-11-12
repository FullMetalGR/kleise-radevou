<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Billing\Entitlements;
use App\Billing\Feature;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The console's Change features: the body is one list of feature slugs, the set the tenant is to
 * hold, the empty list the floor alone. Anything else, a slug outside Feature's cases, a value
 * that is not a list or no list at all, is refused on the field with one sentence through the
 * errors bag the console's adapter shares.
 *
 * Nothing here decides whether the tenant's set may change: that is the row's shape and its staff,
 * read again under the row's lock by App\Platform\TenantFeatures, whose refusal is the flash.
 */
final class TenantFeaturesRequest extends FormRequest
{
    public const string FIELD = 'features';

    public const string BAD_FEATURES = 'Choose the features from the list.';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The rules of the field as both the console's writes of a set read it: required present here,
     * where the body is the set, and optional on the free tenant's form, where none is the floor.
     * Every slug is judged in one rule on the field itself, so a refusal is the field's and not an
     * element's, and the form shows it under the checkboxes.
     *
     * @return array<string, list<mixed>>
     */
    public static function featureRules(bool $required): array
    {
        return [self::FIELD => ['bail', $required ? 'present' : 'sometimes', 'array', 'list', self::known(...)]];
    }

    /**
     * @return array<string, string>
     */
    public static function featureMessages(): array
    {
        return [self::FIELD.'.*' => self::BAD_FEATURES];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return self::featureRules(true);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::featureMessages();
    }

    /**
     * The set the body names. A refused body never reaches here.
     */
    public function features(): Entitlements
    {
        return self::featuresIn($this->input(self::FIELD));
    }

    /**
     * Refuses a list holding anything but a slug of Feature's cases.
     */
    private static function known(string $attribute, mixed $value, Closure $fail): void
    {
        foreach (is_array($value) ? $value : [] as $slug) {
            if (! is_string($slug) || Feature::tryFrom($slug) === null) {
                $fail(self::BAD_FEATURES);

                return;
            }
        }
    }

    /**
     * The set a checked field's value names, the empty set where it names none.
     */
    public static function featuresIn(mixed $value): Entitlements
    {
        return Entitlements::fromSlugs(is_array($value) ? array_values(array_filter($value, is_string(...))) : []);
    }
}
