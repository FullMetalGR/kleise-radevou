<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Billing\SubscriptionState;
use App\Platform\TenantListQuery;
use App\Platform\TenantPlan;
use App\Tenancy\TenantStatus;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The tenant list's query: search, status, state, plan, from, to and page, the whole of the list's
 * state. Read tolerantly, as the admin's list queries and the Audit screen's are: a value of the
 * wrong shape reads as unset rather than a 422, because these are reads and a stale link must still
 * land on the screen. A status, a state or a plan is one of its words exactly as the vocabulary
 * spells it; a date is a calendar date written YYYY-MM-DD; text that is not UTF-8 or holds a NUL is
 * no value at all, each by TolerantQuery, the readers the admin's lists share; and a pair whose
 * From falls after its To reads as no dates at all, since no signup answers it and either date
 * alone would answer something nobody asked.
 */
final class ConsoleTenantsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }

    public function filter(): TenantListQuery
    {
        $from = TolerantQuery::day($this, 'from');
        $to = TolerantQuery::day($this, 'to');

        if ($from !== null && $to !== null && $from > $to) {
            $from = $to = null;
        }

        return new TenantListQuery(
            search: TolerantQuery::text($this, 'search'),
            status: TenantStatus::tryFrom(TolerantQuery::text($this, 'status') ?? ''),
            state: SubscriptionState::tryFrom(TolerantQuery::text($this, 'state') ?? ''),
            plan: TenantPlan::tryFrom(TolerantQuery::text($this, 'plan') ?? ''),
            from: $from,
            to: $to,
            page: TolerantQuery::page($this),
        );
    }
}
