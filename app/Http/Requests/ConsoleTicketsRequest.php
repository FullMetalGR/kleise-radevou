<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The help tickets screen's query: the page, the whole of the list's state. Read tolerantly, as the
 * messages screen's is: a value of the wrong shape reads as the first page rather than a 422,
 * because this is a read and a stale link must still land on the screen.
 */
final class ConsoleTicketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * The page asked for, counted from one; anything else, or a number past nine digits that
     * would overflow the offset, reads as the first.
     */
    public function page(): int
    {
        $value = $this->query('page');

        return is_string($value) && preg_match('/^[1-9]\d{0,8}$/', trim($value)) === 1 ? (int) trim($value) : 1;
    }
}
