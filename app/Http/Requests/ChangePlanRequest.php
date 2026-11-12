<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\AdminLines;

/**
 * The body the Billing screen's Change plan posts: the key of the plan to move to, as the
 * catalogue in config/billing.php spells it. Whether the catalogue holds that key is the plan
 * change's to answer, since only the catalogue knows its keys and it is read at the question.
 *
 * A body naming no plan is refused as every admin write refuses a field, the 400 envelope
 * carrying the sentence below, read in the request's language, never the framework's redirect
 * back with the errors bag. The plan change's own refusals are the controller's.
 *
 * Nothing here decides who may post: the route declares manageBilling and the middleware runs it.
 */
final class ChangePlanRequest extends AdminWriteRequest
{
    public const string PLAN = 'plan';

    /** The refusal's sentence, a key of admin/billing.php read in the request's language. */
    public const string NO_PLAN = 'admin/billing.validation.no_plan';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [self::PLAN => ['required', 'string']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $line = self::line(self::NO_PLAN);

        return [
            self::PLAN.'.required' => $line,
            self::PLAN.'.string' => $line,
        ];
    }

    /**
     * A line of the admin's language files by its key, in the request's language, its
     * placeholders filled: the Billing screen's requests read it, and it answers the admin's one
     * reader, AdminLines.
     *
     * @param  array<string, string>  $values
     */
    public static function line(string $key, array $values = []): string
    {
        return AdminLines::line($key, $values);
    }

    public function plan(): string
    {
        $plan = $this->input(self::PLAN);

        return is_string($plan) ? $plan : '';
    }
}
