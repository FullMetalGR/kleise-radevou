<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * The body of PUT /admin/setup/profile, the setup wizard's Business profile save: About, the
 * address's five fields and Phone, each present every time, null or empty where the owner left
 * it blank, an empty string landing as null. Each is held to the Settings save's own rule and
 * refused with its own sentence, read from the same place, so the step and Settings cannot part.
 * The body names nothing else, and nothing else is written.
 */
final class SetupProfileRequest extends AdminWriteRequest
{
    /** The profile's fields the step asks for, in the order its form lists them. */
    public const array FIELDS = ['about', 'address_line1', 'address_line2', 'city', 'postal_code', 'country', 'phone'];

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return SettingsRequest::profileRulesOf(self::FIELDS);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return SettingsRequest::profileRefusalsOf(self::FIELDS);
    }

    /**
     * The fields under their column names, trimmed, a blank one as null.
     *
     * @return array<string, string|null>
     */
    public function profile(): array
    {
        return SettingsRequest::profileOf($this->validated(), self::FIELDS);
    }
}
