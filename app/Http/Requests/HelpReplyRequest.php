<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Help\HelpTickets;
use App\Http\HelpGate;

/**
 * A reply in a help ticket: the message, up to 5000 characters, required and plain text, which a
 * string that is not UTF-8 or holds a NUL is not, refused by the one sentence a new ticket's message
 * is. A support session and the demo account are refused before it is read.
 */
final class HelpReplyRequest extends AdminWriteRequest
{
    public function authorize(): bool
    {
        app(HelpGate::class)->author($this, 'body');

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['body' => ['bail', 'required', 'string', PlainText::rule(HelpTicketRequest::bodySentence()), 'max:'.HelpTickets::LONGEST_MESSAGE]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $body = HelpTicketRequest::bodySentence();

        return ['body.required' => $body, 'body.string' => $body, 'body.max' => $body];
    }

    public function body(): string
    {
        $body = $this->validated('body');

        return is_string($body) ? $body : '';
    }
}
