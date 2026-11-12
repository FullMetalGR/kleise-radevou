<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\Dto\AvailabilityQuery;
use App\Http\ApiLines;
use App\Http\ErrorEnvelope;
use App\Infrastructure\Db\Pool;
use App\Infrastructure\Db\PostgresServiceRepository;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * The query string of GET /availability, validated at the boundary: items as a JSON list of one to
 * max_chain_length objects, each with service_id and staff_id present and staff_id null or an
 * integer, since the booking page sends the null explicitly; from and to as calendar dates with to
 * after from and the span at most the query's cap; same_staff an optional boolean. Every refusal
 * is 400 invalid_request with a sentence naming the field or the item, the item by its position,
 * because the resource asked for is the query and not the service: an unknown service, an inactive
 * one, and a pick who does not perform the service are refused here, by a catalog read, before any
 * domain object is built. A pick outside a segment's eligible members would be refused by the
 * segment as a bug, and this is what keeps it from being one.
 *
 * What an item is, and every sentence about one, is ChainItems: the same chain reaches the hold
 * endpoint, and a customer who can ask must be able to book what they asked about.
 *
 * The two roads that take it are its two final subclasses, each naming the pool its catalog read
 * draws on and nothing else: PublicAvailabilityRequest, the public route's, over the services and
 * members offered online (Pool::Online), since anyone may ask when a tenant is free; and
 * WizardAvailabilityRequest, the admin's one JSON endpoint's, over the whole catalogue. A refusal
 * is answered here as the error envelope rather than left to the route's own handling, so the two
 * routes answer a bad query with one sentence: the public route would have rendered the same
 * envelope from the exception, and an admin page would have redirected back with a bag the wizard
 * never reads.
 */
abstract class AvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(ValidatorContract $validator): never
    {
        throw new HttpResponseException(ErrorEnvelope::for(new ValidationException($validator))->toResponse());
    }

    /**
     * The items arrive as one JSON string on the query string. Decoded here so the rules see
     * a list; a string that is not a JSON array stays a string and fails the array rule. The
     * preference arrives as a query string spells a boolean, true or false as words, which the
     * boolean rule does not read, so those two words become the values they name; every other
     * spelling is left for the rule to judge.
     */
    protected function prepareForValidation(): void
    {
        $items = $this->input('items');

        if (is_string($items)) {
            $decoded = json_decode($items, true);

            if (is_array($decoded)) {
                $this->merge(['items' => $decoded]);
            }
        }

        $sameStaff = $this->input('same_staff');

        if ($sameStaff === 'true' || $sameStaff === 'false') {
            $this->merge(['same_staff' => $sameStaff === 'true']);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...ChainItems::rules(),
            ...DayWindowFields::rules($this),
            'same_staff' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The sentences, one per refusal, each naming the field, keys of api.php read in the
     * request's language, the admin's in its user's: the chain's own are shared with the
     * route that holds one, and the window's with the per-booking read.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...ChainItems::messages(),
            ...DayWindowFields::messages(),
            'same_staff.boolean' => ApiLines::line('api.validation.same_staff'),
        ];
    }

    /**
     * The services and members the chain may draw on, the one thing the two roads part on.
     */
    abstract protected function pool(): Pool;

    /**
     * The catalog check, once the shape is right: every service active, every pick among the
     * staff members who perform its service, both read from the pool this request names. One
     * read for the whole chain.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(CurrentTenant $current, DatabaseManager $db): array
    {
        return [function (Validator $validator) use ($current, $db): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $items = ChainItems::pairs($validator->getData()['items'] ?? null);
            $services = (new PostgresServiceRepository($db, $current->get(), $this->pool()))->bookable(array_values(array_unique(array_column($items, 'service_id'))));

            foreach ($items as $index => $item) {
                $service = $services[$item['service_id']] ?? null;

                if ($service === null) {
                    $validator->errors()->add(sprintf('items.%d.service_id', $index), ChainItems::aboutItem('api.validation.item.service_unavailable', $index));

                    continue;
                }

                if ($item['staff_id'] !== null && ! $service->performedBy($item['staff_id'])) {
                    $validator->errors()->add(sprintf('items.%d.staff_id', $index), ChainItems::aboutItem('api.validation.item.staff_unqualified', $index));
                }
            }
        }];
    }

    /**
     * The validated query as the use case takes it. Domain objects see only what passed.
     */
    public function toQuery(): AvailabilityQuery
    {
        $validated = $this->validated();
        $window = DayWindowFields::of($validated);

        return new AvailabilityQuery(
            ChainItems::choices($validated['items'] ?? null),
            $window->from,
            $window->to,
            $this->boolean('same_staff'),
        );
    }
}
