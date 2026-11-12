<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Chain\Chain;
use App\Infrastructure\Db\SettingsReader;
use App\Tenancy\CurrentTenant;

/**
 * The body of POST /admin/services and PUT /admin/services/{id}: the columns of the services
 * table with that table's checks spelled as a sentence per field, so a refusal names the field
 * in words an owner can act on.
 *
 * Creating names every column the form carries, the currency among them: the price label
 * promised the tenant's currency and the row is denominated in it from then on. Editing carries
 * whatever it carries and nothing else changes, so a patch of the status alone is a legal body;
 * the currency is refused outright, because a row keeps what it is denominated in, and a price a
 * customer already paid must not be restated by a setting.
 *
 * The duration is refused off the granularity grid rather than rounded, since the help beside
 * the field says it must be a multiple and a silent rounding would be an edit nobody made.
 *
 * Every refusal is a key of lang/{en,el}/admin/services.php under validation, read in the
 * request's language.
 *
 * The booking horizon runs from zero to 365 days, Chain::MAX_HORIZON_DAYS and the column's CHECK
 * (migration 000027), with a sentence of its own past the top, since "zero or more" says nothing
 * of a ceiling.
 *
 * The category is a heading of at most 60 characters, the column's CHECK, trimmed and landing as
 * null when empty, so a row under no heading is one the booking page lists under Other; the
 * online flag keeps a row off the public page without deactivating it.
 */
final class ServiceRequest extends AdminWriteRequest
{
    public const string PAYMENT_MODES = 'free,online,onsite';

    public const string TIMEOUTS = 'expire,auto_approve';

    public const string STATUSES = 'active,inactive';

    /** The longest category the column holds. */
    public const int CATEGORY_MAX = 60;

    /** Four refusals' keys in services.php's validation, by name. */
    public const string CATEGORY_LENGTH = 'admin/services.validation.category_length';

    public const string CATEGORY_TEXT = 'admin/services.validation.category_text';

    public const string ONLINE_FLAG = 'admin/services.validation.online';

    public const string HORIZON_TOO_FAR = 'admin/services.validation.horizon_max';

    /**
     * Every refusal's key by the field and the rule it answers. A count field has a sentence of
     * its own, never a subject spliced into a shared one.
     */
    public const array MESSAGES = [
        'name.required' => 'admin/services.validation.name',
        'name.string' => 'admin/services.validation.name',
        'description.string' => 'admin/services.validation.description',
        'duration_min.required' => 'admin/services.validation.duration',
        'duration_min.integer' => 'admin/services.validation.duration',
        'duration_min.min' => 'admin/services.validation.duration',
        'duration_min.multiple_of' => 'admin/services.validation.duration_grid',
        'processing_gap_min.integer' => 'admin/services.validation.processing_gap',
        'processing_gap_min.min' => 'admin/services.validation.processing_gap',
        'buffer_before_min.integer' => 'admin/services.validation.buffer_before',
        'buffer_before_min.min' => 'admin/services.validation.buffer_before',
        'buffer_after_min.integer' => 'admin/services.validation.buffer_after',
        'buffer_after_min.min' => 'admin/services.validation.buffer_after',
        'price_minor.integer' => 'admin/services.validation.price',
        'price_minor.min' => 'admin/services.validation.price',
        'currency.required' => 'admin/services.validation.currency',
        'currency.in' => 'admin/services.validation.currency',
        'currency.prohibited' => 'admin/services.validation.currency_fixed',
        'payment_mode.required' => 'admin/services.validation.payment_mode',
        'payment_mode.in' => 'admin/services.validation.payment_mode',
        'requires_approval.boolean' => 'admin/services.validation.requires_approval',
        'approval_hold_hours.integer' => 'admin/services.validation.approval_hold',
        'approval_hold_hours.min' => 'admin/services.validation.approval_hold',
        'on_approval_timeout.in' => 'admin/services.validation.timeout',
        'cancel_window_hours.integer' => 'admin/services.validation.cancel_window',
        'cancel_window_hours.min' => 'admin/services.validation.cancel_window',
        'reschedule_window_hours.integer' => 'admin/services.validation.reschedule_window',
        'reschedule_window_hours.min' => 'admin/services.validation.reschedule_window',
        'lead_time_min.integer' => 'admin/services.validation.lead_time',
        'lead_time_min.min' => 'admin/services.validation.lead_time',
        'horizon_days.integer' => 'admin/services.validation.horizon',
        'horizon_days.min' => 'admin/services.validation.horizon',
        'horizon_days.max' => self::HORIZON_TOO_FAR,
        'status.in' => 'admin/services.validation.status',
        'status.prohibited' => 'admin/services.validation.status_new',
        'category.string' => self::CATEGORY_TEXT,
        'category.max' => self::CATEGORY_LENGTH,
        'online.boolean' => self::ONLINE_FLAG,
    ];

    /** The tenant's granularity, read once when the rules are built; the sentences read it after. */
    private int $granularity = 0;

    /** The tenant's currency, read with the granularity. */
    private string $currency = '';

    /**
     * @return array<string, list<string>>
     */
    public function rules(CurrentTenant $current, SettingsReader $settings): array
    {
        $creating = $this->creating();
        $this->granularity = $settings->read($current->get())->granularityMin;
        $this->currency = $current->get()->currency;
        $granularity = $this->granularity;
        $presence = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'string'],
            'description' => ['sometimes', 'nullable', 'string'],
            'duration_min' => [$presence, 'integer', 'min:1', 'multiple_of:'.$granularity],
            'processing_gap_min' => ['sometimes', 'integer', 'min:0'],
            'buffer_before_min' => ['sometimes', 'integer', 'min:0'],
            'buffer_after_min' => ['sometimes', 'integer', 'min:0'],
            'price_minor' => ['sometimes', 'integer', 'min:0'],
            'currency' => $creating ? ['required', 'in:'.$this->currency] : ['prohibited'],
            'payment_mode' => [$presence, 'in:'.self::PAYMENT_MODES],
            'requires_approval' => ['sometimes', 'boolean'],
            'approval_hold_hours' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'on_approval_timeout' => ['sometimes', 'in:'.self::TIMEOUTS],
            'cancel_window_hours' => ['sometimes', 'integer', 'min:0'],
            'reschedule_window_hours' => ['sometimes', 'integer', 'min:0'],
            'lead_time_min' => ['sometimes', 'integer', 'min:0'],
            'horizon_days' => ['sometimes', 'integer', 'min:0', 'max:'.Chain::MAX_HORIZON_DAYS],
            'status' => [$creating ? 'prohibited' : 'sometimes', 'in:'.self::STATUSES],
            'category' => ['sometimes', 'nullable', 'string', 'max:'.self::CATEGORY_MAX],
            'online' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The refusals in the request's language, each code value inside a sentence filled from the
     * code: the granularity and the business's currency as the rules read them, the horizon's
     * ceiling from Chain and the category's longest from the column.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $values = [
            'admin/services.validation.duration_grid' => ['n' => (string) $this->granularity],
            'admin/services.validation.currency' => ['currency' => $this->currency],
            self::HORIZON_TOO_FAR => ['n' => (string) Chain::MAX_HORIZON_DAYS],
            self::CATEGORY_LENGTH => ['n' => (string) self::CATEGORY_MAX],
        ];

        return array_map(function (string $key) use ($values): string {
            $line = __($key, $values[$key] ?? []);

            return is_string($line) ? $line : $key;
        }, self::MESSAGES);
    }

    public function creating(): bool
    {
        return $this->isMethod('POST');
    }

    /**
     * The columns to write: what the body named, validated, with the empty description spelled
     * as the column's empty string, the category trimmed and an empty one as the null of a row
     * under no heading, and a new row starting active.
     *
     * @return array<string, mixed>
     */
    public function patch(): array
    {
        $patch = $this->validated();

        if (array_key_exists('description', $patch) && $patch['description'] === null) {
            $patch['description'] = '';
        }

        if (array_key_exists('category', $patch)) {
            $category = is_string($patch['category']) ? trim($patch['category']) : '';
            $patch['category'] = $category === '' ? null : $category;
        }

        if ($this->creating()) {
            $patch['status'] = 'active';
        }

        return $patch;
    }
}
