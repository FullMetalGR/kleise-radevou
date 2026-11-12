<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\Dto\CustomerFilter;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The query of the Customers list and of a customer's page: search and page on the list, page on a
 * customer's page, the things each keeps in its URL; and booking, the uuid of the dialog a
 * customer's page has open, the page's own state asked for by a partial reload with the URL
 * preserved, so the address bar never carries it, as on the calendars. Read tolerantly by
 * TolerantQuery, as the Bookings list's query is: a value of the wrong shape reads as that field's
 * default rather than a 422, because these are reads and a stale bookmark must still land on the
 * screen, and text that is not UTF-8 or holds a NUL is no value.
 */
final class CustomerRequest extends FormRequest
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

    public function filter(): CustomerFilter
    {
        return new CustomerFilter(TolerantQuery::text($this, 'search'), $this->page());
    }

    /** The page asked for, counted from one, as TolerantQuery reads every list's. */
    public function page(): int
    {
        return TolerantQuery::page($this);
    }

    /** The booking the dialog is open on, or null; BookingRecords answers a malformed one missing. */
    public function booking(): ?string
    {
        return TolerantQuery::text($this, 'booking');
    }
}
