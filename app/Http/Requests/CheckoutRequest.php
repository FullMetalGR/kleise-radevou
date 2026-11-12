<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Billing\CatalogueUnreadable;
use App\Billing\Plan;
use App\Billing\PlanCatalogue;

/**
 * The body the Billing screen's checkout posts: the key of the plan to subscribe to, one the
 * catalogue in config/billing.php sells. A trial chooses it, and so does a tenant whose trial
 * ended unpaid; the way back from a subscription that ended posts the plan the screen offers it.
 *
 * A body naming no catalogue plan is refused as every admin write refuses a field, the 400
 * envelope carrying the sentence below, read in the request's language. A catalogue nobody can
 * read is not the body's fault: the rule then asks for a string alone, and the checkout reads the
 * catalogue again and words it as the Billing screen does.
 *
 * Nothing here decides who may post: the route declares manageBilling and the middleware runs it.
 */
final class CheckoutRequest extends AdminWriteRequest
{
    public const string PLAN = 'plan';

    /** The refusal's sentence, a key of admin/billing.php read in the request's language. */
    public const string NO_PLAN = 'admin/billing.validation.checkout_plan';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(PlanCatalogue $catalogue): array
    {
        try {
            $keys = array_map(fn (Plan $plan): string => $plan->key, $catalogue->plans());
        } catch (CatalogueUnreadable) {
            return [self::PLAN => ['required', 'string']];
        }

        return [self::PLAN => ['required', 'string', 'in:'.implode(',', $keys)]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [self::PLAN.'.*' => ChangePlanRequest::line(self::NO_PLAN)];
    }

    public function plan(): string
    {
        $plan = $this->input(self::PLAN);

        return is_string($plan) ? $plan : '';
    }
}
