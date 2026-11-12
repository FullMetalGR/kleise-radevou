<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\AdminLines;
use App\Tenancy\StaffColour;
use Illuminate\Validation\Rule;

/**
 * The body of PUT /admin/staff/{id}/colour: a member's own calendar colour, one word of the
 * palette, StaffColour, or null for Automatic. The key is required, so a body naming nothing is
 * refused rather than read as Automatic. Its one refusal is a key of
 * lang/{locale}/admin/staff.php, worded in the request's language, the palette's size filled from
 * StaffColour's cases.
 */
final class StaffColourRequest extends AdminWriteRequest
{
    public const string REFUSED = 'admin/staff.validation.colour';

    /** The Automatic option's label, which the refusal names by its own key. */
    public const string AUTOMATIC = 'admin/staff.colours.automatic';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'colour' => ['present', 'nullable', 'string', Rule::in(StaffColour::words())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $refused = self::refusal();

        return ['colour.present' => $refused, 'colour.string' => $refused, 'colour.in' => $refused];
    }

    /**
     * The one refusal, naming how many colours the palette holds, StaffColour's cases spelled in
     * words by ICU in the language the line is read in, so a colour added needs no copy edit,
     * and the Automatic option by its label in the same language.
     */
    public static function refusal(): string
    {
        return StaffRequest::line(self::REFUSED, [
            'automatic' => StaffRequest::line(self::AUTOMATIC),
            'count' => AdminLines::spelled(self::REFUSED, count(StaffColour::cases())),
        ]);
    }

    public function colour(): ?string
    {
        $colour = $this->validated('colour');

        return is_string($colour) ? $colour : null;
    }
}
