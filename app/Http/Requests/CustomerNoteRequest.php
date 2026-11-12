<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * The body of POST /admin/customers/{email}/notes: the Notes card's New note, body, text that is
 * not blank and at most MOST characters, counted as characters, as customer_notes_body_check
 * holds it. Anything else, a missing key, an empty or blank note, a longer one or a value that is
 * no text, is the one sentence naming the bound, a key of lang/{locale}/admin/customers.php
 * worded in the request's language. The text is stored as the framework's trimming leaves it.
 */
final class CustomerNoteRequest extends AdminWriteRequest
{
    public const string BODY = 'admin/customers.validation.note';

    /** The most characters of a note, as customer_notes_body_check holds it. */
    public const int MOST = 2000;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:'.self::MOST]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $body = trans_choice(self::BODY, self::MOST);

        return ['body.required' => $body, 'body.string' => $body, 'body.max' => $body];
    }

    public function body(): string
    {
        $body = $this->validated('body');

        return is_string($body) ? $body : '';
    }
}
