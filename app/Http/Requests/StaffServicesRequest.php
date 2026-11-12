<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * The body of PUT /admin/staff/{id}/services: the whole list of services one member performs,
 * replacing what was there. A service page saving its checklist sends one of these per member
 * whose tick changed, carrying that member's other services beside this one, so nothing the
 * page did not name is lost. Its two sentences are StaffRequest's for the same field, keys of
 * staff.php read in the request's language.
 */
final class StaffServicesRequest extends AdminWriteRequest
{
    public const string FIELD = 'service_ids';

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            self::FIELD => ['present', 'array'],
            self::FIELD.'.*' => ['integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_map(StaffRequest::line(...), [
            self::FIELD.'.present' => StaffRequest::SERVICE_IDS,
            self::FIELD.'.array' => StaffRequest::SERVICE_IDS,
            self::FIELD.'.*.integer' => StaffRequest::SERVICE_ID,
            self::FIELD.'.*.min' => StaffRequest::SERVICE_ID,
        ]);
    }

    /**
     * @return list<int>
     */
    public function serviceIds(): array
    {
        $ids = $this->validated(self::FIELD);
        assert(is_array($ids));

        return array_values(array_map(intval(...), array_filter($ids, is_numeric(...))));
    }
}
