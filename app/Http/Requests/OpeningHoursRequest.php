<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;

/**
 * The body of PUT /admin/opening-hours: the implicit member's whole week, replace-all, as
 * WeeklyWindows spells a window. The route names no member, which is what makes one implicit
 * member enforceable by its shape; the list is required to be present so that a body naming
 * no week cannot be read as one with none, and an empty list is a week with every day closed.
 */
final class OpeningHoursRequest extends AdminWriteRequest
{
    public const string FIELD = 'rules';

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return WeeklyWindows::rules(self::FIELD, 'present');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return WeeklyWindows::messages(self::FIELD);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isEmpty()) {
                WeeklyWindows::ordered($validator, $this->windows(), self::FIELD);
            }
        });
    }

    /**
     * @return list<array{weekday: int, start: string, end: string}>
     */
    public function windows(): array
    {
        return WeeklyWindows::windows($this->validated(), self::FIELD) ?? [];
    }
}
