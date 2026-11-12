<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\AdminLines;
use App\Infrastructure\Db\SettingsReader;
use App\Site\SiteLanguage;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Profile;
use App\Tenancy\Settings;
use App\Tenancy\Theme;
use LogicException;

/**
 * The body of PUT /admin/settings: the whole patch every time, never a partial one. The currency is
 * three uppercase letters, the tenant row's default for new services. The theme is one of Theme's
 * seven words, exactly as the column's CHECK spells them, the one colour a tenant chooses; an
 * accent the body still names is ignored, there being no column to hold it. The three holds are
 * positive whole numbers, the reminder lead zero or above with its own sentence: zero is its
 * meaningful value, and it deliberately does not share the holds' rule. The off list is a list of
 * message keys, any key, so a key this build does not know survives a save rather than refusing it.
 *
 * Start times are one of the six intervals dividing the hour and a multiple of the tenant's
 * granularity, read from its settings row, the rule the settings table enforces with a CHECK. The
 * CHECK is the backstop and this is the refusal: a value it would reject is answered here with a
 * sentence naming the granularity, so it never reaches the database as a 500. The page offers only
 * the intervals on the granularity, so the sentence is for a body the page did not build, or a
 * granularity changed under an open form.
 *
 * One key is held on while the tenant takes payments, the approved message: a save listing it off
 * is ignored for that key, Settings::storable, read off the same settings row the rules read, and
 * every other key is stored as given.
 *
 * The profile, rides the same patch: thirteen text fields, each present every time and each null or
 * empty where the owner left it blank, an empty string landing as null. The four links are https
 * URLs, the public email an address, the country two capital letters, and each text its length,
 * every refusal with its own sentence.
 *
 * The default language, is a code of SiteLanguage written to settings.language. A body that names
 * none leaves the saved one as it is, so a client built before the control saves as it did; the
 * screen sends it every time.
 *
 * Every refusal is a key of admin/settings.php's validation group read in the request's language,
 * the group the screen's model states its checks in before a save, so the two cannot part: each
 * length filled with its limit as :max from the named constants the rules read, and the start
 * interval's with the six intervals listed in the language and the granularity as :n.
 */
final class SettingsRequest extends AdminWriteRequest
{
    /**
     * The refusals, keys of admin/settings.php's validation group read in the request's
     * language.
     */
    public const string CURRENCY = 'admin/settings.validation.currency';

    public const string THEME = 'admin/settings.validation.theme';

    public const string LANGUAGE = 'admin/settings.validation.language';

    /** The themes' names, a key of each by the word stored, which the theme's refusal lists. */
    private const string THEME_NAMES = 'admin/settings.booking_page.themes.';

    public const string CHECKOUT = 'admin/settings.validation.checkout';

    public const string APPROVAL = 'admin/settings.validation.approval';

    public const string PAYMENT = 'admin/settings.validation.payment';

    public const string REMINDER = 'admin/settings.validation.reminder';

    /**
     * Filled with the six intervals as a list in the language (:intervals) and the tenant's
     * granularity in minutes (:n).
     */
    public const string START_INTERVAL = 'admin/settings.validation.start_interval';

    /**
     * The words a list of intervals is joined with: between the items, and before the last (:items,
     * :last).
     */
    public const string LIST_SEPARATOR = 'admin/settings.validation.list.separator';

    public const string LIST_LAST = 'admin/settings.validation.list.last';

    public const string EMAILS = 'admin/settings.validation.emails';

    public const string EMAIL_KEY = 'admin/settings.validation.email_key';

    /**
     * Each length's sentence below is filled with its field's longest as :max, from the limits
     * after them.
     */
    public const string ABOUT = 'admin/settings.validation.about';

    public const string ADDRESS_LINE = 'admin/settings.validation.address_line';

    public const string CITY = 'admin/settings.validation.city';

    public const string POSTAL_CODE = 'admin/settings.validation.postal_code';

    public const string COUNTRY = 'admin/settings.validation.country';

    public const string PHONE = 'admin/settings.validation.phone';

    public const string PUBLIC_EMAIL = 'admin/settings.validation.public_email';

    public const string WEBSITE = 'admin/settings.validation.website';

    public const string INSTAGRAM = 'admin/settings.validation.instagram';

    public const string FACEBOOK = 'admin/settings.validation.facebook';

    public const string GOOGLE_REVIEWS = 'admin/settings.validation.google_reviews';

    public const string IMPORTANT_INFO = 'admin/settings.validation.important_info';

    /**
     * The profile's longest values, which the rules state and the sentences name.
     */
    public const int ABOUT_MAX = 2000;

    public const int ADDRESS_LINE_MAX = 200;

    public const int CITY_MAX = 100;

    public const int POSTAL_CODE_MAX = 20;

    public const int PHONE_MAX = 40;

    public const int PUBLIC_EMAIL_MAX = 255;

    public const int LINK_MAX = 500;

    public const int IMPORTANT_INFO_MAX = 1000;

    public const string COUNTRY_PATTERN = '/\A[A-Z]{2}\z/';

    public const string CURRENCY_PATTERN = '/\A[A-Z]{3}\z/';

    public const string KEY_PATTERN = '/\A[a-z0-9_]{1,64}\z/';

    public const string START_INTERVALS = '5,10,15,20,30,60';

    /** The tenant's settings, read once when the rules are built: the sentence reads the granularity after, and the store reads what the off list keeps. */
    private ?Settings $settings = null;

    /**
     * @return array<string, list<string>>
     */
    public function rules(CurrentTenant $current, SettingsReader $settings): array
    {
        $this->settings = $settings->read($current->get());

        return [
            'currency' => ['required', 'string', 'regex:'.self::CURRENCY_PATTERN],
            'theme' => ['required', 'string', 'in:'.implode(',', Theme::words())],
            'language' => ['sometimes', 'required', 'string', 'in:'.implode(',', array_map(fn (SiteLanguage $language): string => $language->value, SiteLanguage::cases()))],
            'checkout_ttl_min' => ['required', 'integer', 'min:1'],
            'approval_ttl_hours' => ['required', 'integer', 'min:1'],
            'payment_ttl_hours' => ['required', 'integer', 'min:1'],
            'reminder_lead_hours' => ['required', 'integer', 'min:0'],
            'start_interval_min' => ['required', 'integer', 'in:'.self::START_INTERVALS, 'multiple_of:'.$this->settings->granularityMin],
            'emails_off' => ['present', 'array'],
            'emails_off.*' => ['string', 'regex:'.self::KEY_PATTERN],
            ...array_map(fn (array $rule): array => ['present', 'nullable', 'string', ...$rule], self::profileRules()),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $interval = self::startIntervalLine($this->settings()->granularityMin);
        $theme = self::themeLine();

        return array_map(fn (string $key): string => match ($key) {
            self::START_INTERVAL => $interval,
            self::THEME => $theme,
            default => self::line($key, self::valuesOf($key)),
        }, [
            'currency.required' => self::CURRENCY,
            'currency.string' => self::CURRENCY,
            'currency.regex' => self::CURRENCY,
            'theme.required' => self::THEME,
            'theme.string' => self::THEME,
            'theme.in' => self::THEME,
            'language.required' => self::LANGUAGE,
            'language.string' => self::LANGUAGE,
            'language.in' => self::LANGUAGE,
            'checkout_ttl_min.required' => self::CHECKOUT,
            'checkout_ttl_min.integer' => self::CHECKOUT,
            'checkout_ttl_min.min' => self::CHECKOUT,
            'approval_ttl_hours.required' => self::APPROVAL,
            'approval_ttl_hours.integer' => self::APPROVAL,
            'approval_ttl_hours.min' => self::APPROVAL,
            'payment_ttl_hours.required' => self::PAYMENT,
            'payment_ttl_hours.integer' => self::PAYMENT,
            'payment_ttl_hours.min' => self::PAYMENT,
            'reminder_lead_hours.required' => self::REMINDER,
            'reminder_lead_hours.integer' => self::REMINDER,
            'reminder_lead_hours.min' => self::REMINDER,
            'start_interval_min.required' => self::START_INTERVAL,
            'start_interval_min.integer' => self::START_INTERVAL,
            'start_interval_min.in' => self::START_INTERVAL,
            'start_interval_min.multiple_of' => self::START_INTERVAL,
            'emails_off.present' => self::EMAILS,
            'emails_off.array' => self::EMAILS,
            'emails_off.*.string' => self::EMAIL_KEY,
            'emails_off.*.regex' => self::EMAIL_KEY,
            ...self::profileMessages(),
        ]);
    }

    /**
     * A key of settings.php in the request's language: the admin's one reader, AdminLines.
     *
     * @param  array<string, string>  $values
     */
    public static function line(string $key, array $values = []): string
    {
        return AdminLines::line($key, $values);
    }

    /**
     * A key of settings.php in English, for the console, whose copy stays English.
     */
    public static function english(string $key): string
    {
        $line = __($key, [], 'en');

        return is_string($line) ? $line : $key;
    }

    /**
     * The theme's refusal: how many themes there are, Theme's cases spelled in words by ICU in the
     * language the line is read in, and their names from the booking page's themes listed by the
     * file's own list words, so a theme added or removed needs no copy edit.
     */
    public static function themeLine(): string
    {
        $names = array_map(fn (Theme $theme): string => self::line(self::THEME_NAMES.$theme->value), Theme::cases());
        $last = (string) array_pop($names);
        $listed = self::line(self::LIST_LAST, ['items' => implode(self::line(self::LIST_SEPARATOR), $names), 'last' => $last]);

        return self::line(self::THEME, ['count' => AdminLines::spelled(self::THEME, count(Theme::cases())), 'themes' => $listed]);
    }

    /**
     * The start interval's refusal for a granularity: the six intervals listed in the language by
     * the file's own list words, the ones the screen's model joins them with, and the granularity
     * in minutes.
     */
    public static function startIntervalLine(int $granularity): string
    {
        $intervals = explode(',', self::START_INTERVALS);
        $last = array_pop($intervals);
        $listed = self::line(self::LIST_LAST, ['items' => implode(self::line(self::LIST_SEPARATOR), $intervals), 'last' => $last]);

        return self::line(self::START_INTERVAL, ['intervals' => $listed, 'n' => (string) $granularity]);
    }

    /**
     * The values a refusal's sentence is filled with: a length's longest as :max, from the limit
     * its rule states; none for every other.
     *
     * @return array<string, string>
     */
    private static function valuesOf(string $key): array
    {
        $max = [
            self::ABOUT => self::ABOUT_MAX, self::ADDRESS_LINE => self::ADDRESS_LINE_MAX, self::CITY => self::CITY_MAX,
            self::POSTAL_CODE => self::POSTAL_CODE_MAX, self::PHONE => self::PHONE_MAX, self::IMPORTANT_INFO => self::IMPORTANT_INFO_MAX,
        ][$key] ?? null;

        return $max === null ? [] : ['max' => (string) $max];
    }

    /**
     * Each profile field's own rule beyond present, nullable and a string.
     *
     * @return array<string, list<string>>
     */
    private static function profileRules(): array
    {
        $link = ['max:'.self::LINK_MAX, 'url:https'];
        $line = ['max:'.self::ADDRESS_LINE_MAX];

        return [
            'about' => ['max:'.self::ABOUT_MAX], 'address_line1' => $line, 'address_line2' => $line, 'city' => ['max:'.self::CITY_MAX],
            'postal_code' => ['max:'.self::POSTAL_CODE_MAX], 'country' => ['regex:'.self::COUNTRY_PATTERN], 'phone' => ['max:'.self::PHONE_MAX],
            'public_email' => ['max:'.self::PUBLIC_EMAIL_MAX, 'email'], 'website' => $link, 'instagram' => $link, 'facebook' => $link,
            'google_reviews_url' => $link, 'important_info' => ['max:'.self::IMPORTANT_INFO_MAX],
        ];
    }

    /**
     * Every refusal of a profile field, each by that field's one sentence's key.
     *
     * @return array<string, string>
     */
    private static function profileMessages(): array
    {
        $sentences = [
            'about' => self::ABOUT, 'address_line1' => self::ADDRESS_LINE, 'address_line2' => self::ADDRESS_LINE, 'city' => self::CITY, 'postal_code' => self::POSTAL_CODE,
            'country' => self::COUNTRY, 'phone' => self::PHONE, 'public_email' => self::PUBLIC_EMAIL, 'website' => self::WEBSITE, 'instagram' => self::INSTAGRAM,
            'facebook' => self::FACEBOOK, 'google_reviews_url' => self::GOOGLE_REVIEWS, 'important_info' => self::IMPORTANT_INFO,
        ];
        $messages = [];

        foreach (self::profileRules() as $field => $rules) {
            foreach (['present', 'string', ...array_map(fn (string $rule): string => explode(':', $rule)[0], $rules)] as $rule) {
                $messages[$field.'.'.$rule] = $sentences[$field];
            }
        }

        return $messages;
    }

    public function currency(): string
    {
        $currency = $this->validated()['currency'] ?? null;
        assert(is_string($currency));

        return $currency;
    }

    /**
     * The settings row's columns as the body named them, the theme its word, the language its code
     * or null where the body named none, and the off list each key once with no key held on.
     *
     * The profile's fields follow under their column names, a blank one as null.
     *
     * @return array{theme: string, language: string|null, checkout_ttl_min: int, approval_ttl_hours: int, payment_ttl_hours: int, reminder_lead_hours: int, start_interval_min: int, emails_off: list<string>, profile: array<string, string|null>}
     */
    public function columns(): array
    {
        $validated = $this->validated();
        $keys = $validated['emails_off'] ?? [];
        assert(is_array($keys));

        return [
            'theme' => Theme::read($validated['theme'] ?? null)->value,
            'language' => is_string($validated['language'] ?? null) ? $validated['language'] : null,
            'checkout_ttl_min' => self::whole($validated['checkout_ttl_min'] ?? null),
            'approval_ttl_hours' => self::whole($validated['approval_ttl_hours'] ?? null),
            'payment_ttl_hours' => self::whole($validated['payment_ttl_hours'] ?? null),
            'reminder_lead_hours' => self::whole($validated['reminder_lead_hours'] ?? null),
            'start_interval_min' => self::whole($validated['start_interval_min'] ?? null),
            'emails_off' => $this->settings()->storable(array_values(array_filter($keys, is_string(...)))),
            'profile' => array_combine(Profile::FIELDS, array_map(function (string $field) use ($validated): ?string {
                $value = $validated[$field] ?? null;

                return is_string($value) && trim($value) !== '' ? trim($value) : null;
            }, Profile::FIELDS)),
        ];
    }

    /** The settings the rules read, for the sentence and the store, which the framework runs after them. */
    private function settings(): Settings
    {
        return $this->settings ?? throw new LogicException('The settings are read when the rules are built, before the sentence and the store.');
    }

    /** A validated integer field, which the integer rule lets through as an int or a numeric string. */
    private static function whole(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        assert(is_string($value) && is_numeric($value));

        return (int) $value;
    }
}
