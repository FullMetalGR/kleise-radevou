<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\Dto\Customer;
use App\Application\Dto\HoldRequest;
use App\Application\Dto\Intake;
use App\Http\ApiLines;
use LogicException;

/**
 * The body that places a chain, spelled once for the two routes that take it: the public hold
 * and the admin's manual booking. The customer's name and email, a phone where one was given,
 * the chain as ChainItems defines it, the instant the chain starts, and whether one staff
 * member should carry every segment. The two routes differ in who may call and where the
 * booking lands, not in what is asked for, and a start the availability read offered under
 * this vocabulary must be accepted by either.
 *
 * Nothing here reads the catalog: whether a service is still offered and whether a pick still
 * performs it are the planner's questions, asked once under the same read that builds the
 * chain.
 */
final readonly class HoldBody
{
    /**
     * Long enough for any name, address or number a customer has, short enough that a column
     * of text is never asked to hold a document.
     */
    public const int NAME_MAX = 120;

    public const int EMAIL_MAX = 254;

    public const int PHONE_MAX = 40;

    /**
     * The three customer fields and the start as they arrive from a form: trimmed, and an
     * empty phone turned into no phone at all, since a customer who typed a space has not
     * given a number.
     *
     * @return array<string, string|null>
     */
    public static function prepared(mixed ...$fields): array
    {
        $trimmed = [];

        foreach (['customer_name', 'customer_email', 'customer_phone', 'start'] as $field) {
            $value = $fields[$field] ?? null;

            if (is_string($value)) {
                $trimmed[$field] = trim($value);
            }
        }

        if (($trimmed['customer_phone'] ?? null) === '') {
            $trimmed['customer_phone'] = null;
        }

        return $trimmed;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            ...ChainItems::rules(),
            'customer_name' => ['required', 'string', 'max:'.self::NAME_MAX],
            'customer_email' => ['required', 'string', 'email', 'max:'.self::EMAIL_MAX],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:'.self::PHONE_MAX],
            'start' => ['required', Instant::rule()],
            'same_staff' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The sentences, one per refusal, each written to be shown to whoever tripped it: keys of
     * api.php under validation, read in the request's language, the admin's in its user's, each
     * limit filled from its constant as a choice by it. The chain's are shared with the route
     * that asks when the chain is free.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            ...ChainItems::messages(),
            'customer_name.required' => ApiLines::line('api.validation.customer_name.required'),
            'customer_name.string' => ApiLines::line('api.validation.customer_name.required'),
            'customer_name.max' => ApiLines::choice('api.validation.customer_name.max', self::NAME_MAX),
            'customer_email.required' => ApiLines::line('api.validation.customer_email.required'),
            'customer_email.string' => ApiLines::line('api.validation.customer_email.required'),
            'customer_email.email' => ApiLines::line('api.validation.customer_email.email'),
            'customer_email.max' => ApiLines::choice('api.validation.customer_email.max', self::EMAIL_MAX),
            'customer_phone.string' => ApiLines::line('api.validation.customer_phone.string'),
            'customer_phone.max' => ApiLines::choice('api.validation.customer_phone.max', self::PHONE_MAX),
            'start.required' => ApiLines::line(Instant::SENTENCE),
            'same_staff.boolean' => ApiLines::line('api.validation.same_staff'),
        ];
    }

    /**
     * The validated body as the use case takes it. Domain objects see only what passed, and
     * the start is built from text the rule has already read character by character, so no
     * date rolls over into the month after it. The intake is the route's to name: the public
     * hold's from the request's origin and its body, the admin's create as admin.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function toHoldRequest(array $validated, bool $sameStaff, Intake $intake): HoldRequest
    {
        $name = $validated['customer_name'] ?? null;
        $email = $validated['customer_email'] ?? null;
        $phone = $validated['customer_phone'] ?? null;

        if (! is_string($name) || ! is_string($email) || ($phone !== null && ! is_string($phone))) {
            throw new LogicException('The customer was validated and is strings.');
        }

        return new HoldRequest(
            new Customer($name, $email, $phone),
            ChainItems::choices($validated['items'] ?? null),
            Instant::read($validated['start'] ?? null),
            $intake,
            $sameStaff,
        );
    }
}
