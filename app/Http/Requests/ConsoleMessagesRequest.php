<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Platform\InboxView;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The messages screen's query: the view and the page, the whole of the screen's state. Read
 * tolerantly, as the tenant list's and the Audit screen's are: a value of the wrong shape reads as
 * unset rather than a 422, because these are reads and a stale link must still land on the screen.
 * The view is one of its two words exactly as the URL spells them, and anything else is the open
 * messages.
 */
final class ConsoleMessagesRequest extends FormRequest
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

    public function view(): InboxView
    {
        return InboxView::tryFrom($this->text('view') ?? '') ?? InboxView::Open;
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
