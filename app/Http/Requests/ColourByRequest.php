<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Tenancy\Settings;
use Illuminate\Validation\Rule;

/**
 * The body of PUT /admin/settings/colour-by: the one word saying how every calendar grid colours
 * its blocks, staff, category or status. A route of its own, as the link marker's is, because the
 * settings save takes the whole patch and the Calendar's filter card sends this word alone. Its
 * one refusal is the calendar section's, beside the filter card's Colour by words, which a failed
 * write's toast shows.
 */
final class ColourByRequest extends AdminWriteRequest
{
    /** Any other word's refusal, a key of the calendar section read in the request's language. */
    public const string REFUSED = 'admin/calendar.validation.colour_by';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'colour_by' => ['required', 'string', Rule::in(Settings::COLOUR_BY)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $refused = __(self::REFUSED);
        $refused = is_string($refused) ? $refused : self::REFUSED;

        return [
            'colour_by.required' => $refused,
            'colour_by.string' => $refused,
            'colour_by.in' => $refused,
        ];
    }

    public function colourBy(): string
    {
        $word = $this->validated('colour_by');
        assert(is_string($word));

        return $word;
    }
}
