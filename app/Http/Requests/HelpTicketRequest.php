<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Help\HelpTickets;
use App\Http\HelpGate;

/**
 * A new help ticket: its subject, up to 150 characters, and its first message, up to 5000, both
 * required and plain text, which a string that is not UTF-8 or holds a NUL is not. Each field is
 * refused by its one sentence, whichever rule refused it. A support session and the demo account
 * are refused before either is read.
 */
final class HelpTicketRequest extends AdminWriteRequest
{
    public const string SUBJECT = 'admin/help.validation.subject';

    public const string BODY = 'admin/help.validation.body';

    public function authorize(): bool
    {
        app(HelpGate::class)->author($this, 'subject');

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'subject' => ['bail', 'required', 'string', PlainText::rule(self::subjectSentence()), 'max:'.HelpTickets::LONGEST_SUBJECT],
            'body' => ['bail', 'required', 'string', PlainText::rule(self::bodySentence()), 'max:'.HelpTickets::LONGEST_MESSAGE],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $subject = self::subjectSentence();
        $body = self::bodySentence();

        return [
            'subject.required' => $subject, 'subject.string' => $subject, 'subject.max' => $subject,
            'body.required' => $body, 'body.string' => $body, 'body.max' => $body,
        ];
    }

    /** The sentence a message is refused by, in the request's language: a reply's too. */
    public static function bodySentence(): string
    {
        return trans_choice(self::BODY, HelpTickets::LONGEST_MESSAGE);
    }

    private static function subjectSentence(): string
    {
        return trans_choice(self::SUBJECT, HelpTickets::LONGEST_SUBJECT);
    }

    public function subject(): string
    {
        $subject = $this->validated('subject');

        return is_string($subject) ? $subject : '';
    }

    public function body(): string
    {
        $body = $this->validated('body');

        return is_string($body) ? $body : '';
    }
}
