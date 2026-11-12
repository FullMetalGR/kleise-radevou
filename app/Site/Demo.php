<?php

declare(strict_types=1);

namespace App\Site;

use App\Application\Dto\Customer;
use App\Application\Dto\HoldRequest;
use App\Application\Dto\Intake;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use Illuminate\Container\Attributes\Config;

/**
 * The demo tenant's guard: whether a tenant is the demo, read off the configured label and
 * nothing else, so every other tenant is untouched; and the one sample customer the demo keeps
 * in place of whatever a visitor typed.
 *
 * No row on the demo holds what a visitor typed, and no message of any kind leaves for it. The
 * guard answers in five places, each a boundary the application code never sees: the public
 * hold's controller swaps the visitor's details for the sample's after validation and before
 * the write; the My bookings link's controller answers the demo as it answers an address with
 * no booking; the two per-tenant publishers announce nothing for the demo, so no listener runs
 * and no job is queued; the drop in front of the platform seam's adapter sends nothing about it;
 * and the hosted page inlines the sample as its prefill. The label is the whole test: the
 * demo's switches are off as well (8.1), but a switch is a tenant's setting and the demo's
 * promise is not one, so a key switched on changes nothing here.
 */
final readonly class Demo
{
    public function __construct(
        #[Config('site.demo_slug')]
        private string $slug,
    ) {}

    public function is(Tenant $tenant): bool
    {
        return $tenant->slug === $this->slug;
    }

    /**
     * The demo's label as a slug, which its origin is spelled from: the home page's demo section
     * mounts the embed from that origin and links the demo's hosted page there. No guard reads
     * it; it names where the demo lives.
     */
    public function slug(): Slug
    {
        return Slug::of($this->slug);
    }

    /**
     * The sample customer, DemoSalon's one constant: the details every booking on the demo
     * holds and the ones its hosted page's details step starts from.
     */
    public function sample(): Customer
    {
        return new Customer(DemoSalon::SAMPLE_CUSTOMER['name'], DemoSalon::SAMPLE_CUSTOMER['email'], DemoSalon::SAMPLE_CUSTOMER['phone']);
    }

    /**
     * The hold as the demo writes it: the sample customer and no note in place of the visitor's
     * four details, and the chain, the start, the source, the channel, the language and the staff
     * choice as they were asked. Any other tenant's request is handed back as it came.
     */
    public function sampled(Tenant $tenant, HoldRequest $request): HoldRequest
    {
        if (! $this->is($tenant)) {
            return $request;
        }

        return new HoldRequest(
            $this->sample(),
            $request->items,
            $request->start,
            new Intake($request->intake->source, $request->intake->channel, language: $request->intake->language),
            $request->sameStaff,
        );
    }
}
