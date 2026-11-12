<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\Dto\FigureRange;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The query of Home: range, the word of the range its figures cover, and booking, the uuid of the
 * dialog the page has open, the page's own state asked for by a partial reload with the URL
 * preserved, so the address bar never carries it. Read tolerantly by TolerantQuery, as every
 * screen's query is: a value of the wrong shape reads as unset rather than a 422, because Home is
 * a read and a stale link must still land on it.
 */
final class HomeRequest extends FormRequest
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
     * The range asked for, any word Home does not offer reading as this week.
     *
     * @param  string  $today  the tenant's today, Y-m-d
     * @param  int  $startOfWeek  ISO numbered, 1 for Monday
     */
    public function range(string $today, int $startOfWeek): FigureRange
    {
        return FigureRange::named(TolerantQuery::text($this, 'range'), $today, $startOfWeek);
    }

    /** The booking the dialog is open on, or null; BookingRecords answers a malformed one missing. */
    public function booking(): ?string
    {
        return TolerantQuery::text($this, 'booking');
    }
}
