<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\Dto\HoldRequest;
use App\Application\Dto\Intake;
use App\Domain\Enum\BookingChannel;
use App\Domain\Enum\BookingSource;
use App\Http\ApiLines;
use App\Site\SiteLanguage;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The body of POST /holds, validated at the boundary as HoldBody spells it, which is the same
 * body the admin's manual booking takes, and beside it the two keys the public hold alone
 * accepts: note, the customer's optional "Anything we should know?", trimmed, a blank one no
 * note, and refused past 500 characters with a sentence; and channel, the hosted page's link
 * tag, kept where it is one of the fixed five and the hold came through the hosted page, and
 * otherwise dropped, never refused. The source is not a key: the controller reads it off the
 * request's origin and hands it in, so no client names its own.
 *
 * Nothing here reads the catalog. Whether a service is still offered and whether a pick still
 * performs it are the planner's questions, asked once under the same read that builds the
 * chain, and answered on the wire by type: a service the catalog no longer offers is a 404 and
 * a pick who does not perform it a 400. Asking here as well would read the catalog twice per
 * hold and would let a service deactivated between the two reads answer with a 500.
 *
 * Client-side validation stops at the browser's native attributes, so this is the authority on
 * what a valid customer is and its sentences are written to be shown beside the form.
 *
 * Public by design: no key and no token on this surface. The protections are the limit of 10 a
 * minute per IP per tenant that the route carries and the fact that the write is re-validated
 * under lock.
 */
final class NewHoldRequest extends FormRequest
{
    /**
     * The note's two refusals, their keys of api.php, the first read as a choice by
     * Intake::NOTE_MAX.
     */
    public const string NOTE_TOO_LONG = 'api.validation.note.max';

    public const string NOTE_NOT_TEXT = 'api.validation.note.string';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(HoldBody::prepared(...$this->only(['customer_name', 'customer_email', 'customer_phone', 'start'])));

        $note = $this->input('note');

        if (is_string($note)) {
            $this->merge(['note' => trim($note) === '' ? null : trim($note)]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...HoldBody::rules(),
            'note' => ['sometimes', 'nullable', 'string', 'max:'.Intake::NOTE_MAX],
            'channel' => ['sometimes', 'nullable'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...HoldBody::messages(),
            'note.string' => ApiLines::line(self::NOTE_NOT_TEXT),
            'note.max' => ApiLines::choice(self::NOTE_TOO_LONG, Intake::NOTE_MAX),
        ];
    }

    /**
     * @param  SiteLanguage  $language  the hold request's language, which the booking records
     */
    public function toHoldRequest(BookingSource $source, SiteLanguage $language): HoldRequest
    {
        $validated = $this->validated();

        return HoldBody::toHoldRequest($validated, $this->boolean('same_staff'), self::intake($validated, $source, $language));
    }

    /**
     * The intake as the hold stores it: the source the controller read, the channel where the
     * hosted page sent one of the five, since the tag is read on the hosted page alone, the note
     * where one was written, and the request's language.
     *
     * @param  array<string, mixed>  $validated
     */
    private static function intake(array $validated, BookingSource $source, SiteLanguage $language): Intake
    {
        $channel = $validated['channel'] ?? null;
        $note = $validated['note'] ?? null;

        return new Intake(
            $source,
            $source === BookingSource::Hosted && is_string($channel) ? BookingChannel::tryFrom($channel) : null,
            is_string($note) ? $note : null,
            $language->value,
        );
    }
}
