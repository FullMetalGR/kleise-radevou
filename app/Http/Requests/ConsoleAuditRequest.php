<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Tenancy\InvalidSlug;
use App\Tenancy\Slug;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Audit screen's query: the tenant the list is narrowed to, by its slug, and the page. Read
 * tolerantly, as the admin's list queries are: a value of the wrong shape reads as that field's
 * default rather than a 422, because these are reads and a stale link must still land on the
 * screen. A slug of the wrong shape reads as unset here; one of the right shape that no tenant
 * holds reads as unset where the screen looks it up.
 */
final class ConsoleAuditRequest extends FormRequest
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

    /** The slug asked for, lowercased, or null where none was or it could name no tenant. */
    public function tenant(): ?string
    {
        $value = $this->text('tenant');

        if ($value === null) {
            return null;
        }

        try {
            return Slug::of($value)->value;
        } catch (InvalidSlug) {
            return null;
        }
    }

    /**
     * The page asked for, counted from one; anything else, or a number past nine digits that
     * would overflow the offset, reads as the first.
     */
    public function page(): int
    {
        $value = $this->text('page');

        return $value !== null && preg_match('/^[1-9]\d{0,8}$/', $value) === 1 ? (int) $value : 1;
    }

    private function text(string $key): ?string
    {
        $value = $this->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
