<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * The reason a rejection carries, spelled once for the two routes that take it: the decision
 * page's POST, and the booking dialog's Reject. The two differ in who may post and in how a
 * refused body is answered, a page's way or an admin write's, not in what is asked for.
 *
 * **The reason is optional and an empty one never blocks a rejection**. A form always submits
 * its textarea, so what arrives for an owner who typed nothing is an empty string, and the
 * framework trims it and turns it into no value before anything here sees it. So the two
 * shapes a form can produce are a sentence and nothing, and both are reasons the use case
 * stores as it was given: the note, or null.
 *
 * It carries no length rule. rejection_reason is text and no document sets a bound on it, and
 * inventing one here would be inventing the sentence an owner is refused for writing.
 */
final readonly class RejectionReason
{
    public const string FIELD = 'reason';

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [self::FIELD => ['nullable', 'string']];
    }

    /**
     * The owner's note, or null where they gave none. It is not trimmed again here: the
     * framework's own trimming has already run, and trimming twice is two rules about one
     * value.
     */
    public static function of(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
