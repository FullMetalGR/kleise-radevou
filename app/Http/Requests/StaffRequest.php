<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\AdminLines;
use App\Http\Controllers\Admin\StaffServicesController;
use App\Infrastructure\Db\ServiceRecords;
use App\Tenancy\CurrentTenant;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * The body of POST /admin/staff and PUT /admin/staff/{id}: the columns of the staff table with a
 * sentence per field, the title and the bio among them at the lengths the columns' CHECKs hold,
 * and the services performed as the whole list. The linked user is checked against this
 * business's own users, so an id from another tenant is refused in words rather than stored. A
 * member's hours are not this body's: they are set on Shifts, so a body naming `rules` is
 * refused in a sentence saying so, and a page left open from before cannot report a save that
 * dropped its hours.
 *
 * A service id outside the catalog is refused in the sentence the links route answers, before
 * the key on service_staff can make it a query error.
 *
 * Every refusal is a key of lang/{en,el}/admin/staff.php under validation, or under refusals for
 * the unknown service, read in the request's language.
 */
final class StaffRequest extends AdminWriteRequest
{
    public const string STATUSES = 'active,inactive';

    /** The longest title the column holds. */
    public const int TITLE_MAX = 60;

    /** The longest bio the column holds. */
    public const int BIO_MAX = 500;

    /** Five refusals' keys in staff.php's validation, by name. */
    public const string ONLINE_FLAG = 'admin/staff.validation.online';

    public const string TITLE_TEXT = 'admin/staff.validation.title_text';

    public const string TITLE_LENGTH = 'admin/staff.validation.title_length';

    public const string BIO_TEXT = 'admin/staff.validation.bio_text';

    public const string BIO_LENGTH = 'admin/staff.validation.bio_length';

    public const string RULES_ELSEWHERE = 'admin/staff.validation.rules_elsewhere';

    /** The two sentences on the services performed, which StaffServicesRequest reads too. */
    public const string SERVICE_IDS = 'admin/staff.validation.service_ids';

    public const string SERVICE_ID = 'admin/staff.validation.service_id';

    /** Every refusal's key by the field and the rule it answers. */
    public const array MESSAGES = [
        'name.required' => 'admin/staff.validation.name',
        'name.string' => 'admin/staff.validation.name',
        'email.email' => 'admin/staff.validation.email',
        'user_id.integer' => 'admin/staff.validation.user_id',
        'user_id.exists' => 'admin/staff.validation.user_exists',
        'status.in' => 'admin/staff.validation.status',
        'online.boolean' => self::ONLINE_FLAG,
        'title.string' => self::TITLE_TEXT,
        'title.max' => self::TITLE_LENGTH,
        'bio.string' => self::BIO_TEXT,
        'bio.max' => self::BIO_LENGTH,
        'service_ids.array' => self::SERVICE_IDS,
        'service_ids.*.integer' => self::SERVICE_ID,
        'service_ids.*.min' => self::SERVICE_ID,
    ];

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(CurrentTenant $current): array
    {
        $creating = $this->creating();
        $presence = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'string'],
            'email' => ['sometimes', 'nullable', 'email'],
            'user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $current->get()->id)],
            'status' => ['sometimes', 'in:'.self::STATUSES],
            'online' => ['sometimes', 'boolean'],
            'title' => ['sometimes', 'nullable', 'string', 'max:'.self::TITLE_MAX],
            'bio' => ['sometimes', 'nullable', 'string', 'max:'.self::BIO_MAX],
            'service_ids' => ['sometimes', 'array'],
            'service_ids.*' => ['integer', 'min:1'],
        ];
    }

    /**
     * The refusals in the request's language, the title's and the bio's longest filled from the
     * columns.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $values = [
            self::TITLE_LENGTH => ['n' => (string) self::TITLE_MAX],
            self::BIO_LENGTH => ['n' => (string) self::BIO_MAX],
        ];

        return array_map(fn (string $key): string => self::line($key, $values[$key] ?? []), self::MESSAGES);
    }

    /**
     * A key of staff.php in the request's language: the admin's one reader, AdminLines.
     *
     * @param  array<string, string>  $values
     */
    public static function line(string $key, array $values = []): string
    {
        return AdminLines::line($key, $values);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Present at all, an empty week included, which the framework's prohibited admits.
            if (array_key_exists('rules', $this->all())) {
                $validator->errors()->add('rules', self::line(self::RULES_ELSEWHERE));
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $serviceIds = $this->serviceIds();

            if ($serviceIds !== null && ! $this->container->make(ServiceRecords::class)->allExist($this->container->make(CurrentTenant::class)->get(), $serviceIds)) {
                $validator->errors()->add('service_ids', self::line(StaffServicesController::UNKNOWN_SERVICE));
            }
        });
    }

    public function creating(): bool
    {
        return $this->isMethod('POST');
    }

    /**
     * The columns to write: what the body named, validated, a blank email spelled as the null
     * the column holds for nobody, the title and the bio trimmed and an empty one landing as the
     * null of a member with none, and a new row starting active unless told otherwise. The
     * online flag is left to the column's default of true when a create does not name it.
     *
     * @return array<string, mixed>
     */
    public function patch(): array
    {
        $patch = array_intersect_key($this->validated(), array_flip(['name', 'email', 'user_id', 'status', 'online', 'title', 'bio']));

        if (array_key_exists('email', $patch) && (! is_string($patch['email']) || trim($patch['email']) === '')) {
            $patch['email'] = null;
        }

        foreach (['title', 'bio'] as $text) {
            if (array_key_exists($text, $patch)) {
                $trimmed = is_string($patch[$text]) ? trim($patch[$text]) : '';
                $patch[$text] = $trimmed === '' ? null : $trimmed;
            }
        }

        if ($this->creating() && ! array_key_exists('status', $patch)) {
            $patch['status'] = 'active';
        }

        return $patch;
    }

    /**
     * The services performed, the whole list, or null when the body did not name one.
     *
     * @return list<int>|null
     */
    public function serviceIds(): ?array
    {
        $validated = $this->validated();

        if (! array_key_exists('service_ids', $validated)) {
            return null;
        }

        $ids = $validated['service_ids'];
        assert(is_array($ids));

        return array_values(array_map(intval(...), array_filter($ids, is_numeric(...))));
    }
}
