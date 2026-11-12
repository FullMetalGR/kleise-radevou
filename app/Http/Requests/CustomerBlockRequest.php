<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * The body of PUT /admin/customers/{email}/block: the Online booking card's Reason, for the
 * staff alone, text of at most MOST characters, or null, empty or absent for none. A longer one,
 * or a value that is no text, is the one sentence naming the bound, a key of
 * lang/{locale}/admin/customers.php worded in the request's language.
 */
final class CustomerBlockRequest extends AdminWriteRequest
{
    public const string REASON = 'admin/customers.validation.reason';

    /** The most characters of a reason. */
    public const int MOST = 500;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:'.self::MOST]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $reason = trans_choice(self::REASON, self::MOST);

        return ['reason.string' => $reason, 'reason.max' => $reason];
    }

    /** The reason as written, null where none was. */
    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) && trim($reason) !== '' ? $reason : null;
    }
}
