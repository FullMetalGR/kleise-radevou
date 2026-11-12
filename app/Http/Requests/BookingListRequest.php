<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\Dto\BookingFilter;
use App\Domain\Enum\BookingStatus;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The query of the bookings list: every filter a server parameter, read here at the boundary.
 * The parameter names are from, to, status, staff, service, search and page. A value of the
 * wrong shape is read as that field's default rather than refused, because the list is a read
 * and a stale bookmark must still land on the screen, the same rule the bar's memory applies in
 * the browser; each field is read by TolerantQuery, the console's tenant list's readers, so text
 * that is not UTF-8 or holds a NUL is no search.
 */
final class BookingListRequest extends FormRequest
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

    public function filter(): BookingFilter
    {
        return new BookingFilter(
            TolerantQuery::day($this, 'from'),
            TolerantQuery::day($this, 'to'),
            BookingStatus::tryFrom(TolerantQuery::text($this, 'status') ?? ''),
            TolerantQuery::id($this, 'staff'),
            TolerantQuery::id($this, 'service'),
            TolerantQuery::text($this, 'search'),
            TolerantQuery::page($this),
        );
    }
}
