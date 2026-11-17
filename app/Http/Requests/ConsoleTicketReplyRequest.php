<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Help\HelpTickets;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The operator's reply on a business's help ticket. The reply is required, as long at most as any
 * message of a ticket may be, a message from the business among them, trimmed by the framework
 * before it is read, and plain text as every free-text field is, which a string that is not UTF-8
 * or holds a NUL is not, since the column would refuse it outright. Its line breaks are kept as
 * written. A refusal comes back on the field through the errors bag the console's adapter shares,
 * each with its sentence, which the thread shows under the reply box.
 *
 * Nothing here decides whether the ticket exists: the write reads its row under its lock.
 */
final class ConsoleTicketReplyRequest extends FormRequest
{
    public const string FIELD = 'body';

    public const string REQUIRED = 'Write the reply before sending it.';

    public const string TOO_LONG = 'Keep the reply to 5,000 characters or fewer.';

    public const string NOT_TEXT = 'The reply must be plain text.';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [self::FIELD => ['bail', 'required', 'string', PlainText::rule(self::NOT_TEXT), 'max:'.HelpTickets::LONGEST_MESSAGE]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            self::FIELD.'.required' => self::REQUIRED,
            self::FIELD.'.string' => self::NOT_TEXT,
            self::FIELD.'.max' => self::TOO_LONG,
        ];
    }

    /** The operator's reply as the framework trimmed it. */
    public function reply(): string
    {
        return $this->string(self::FIELD)->toString();
    }
}
