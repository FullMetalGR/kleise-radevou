<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Money\Money;

/**
 * One service as the public catalog lists it: what a row shows, and the staff members a
 * customer may pick among for it. The price is Money, an integer of minor units beside its
 * own currency, so a row is formatted in the currency it carries and never in the tenant's
 * current default.
 *
 * Only the published columns are here. The engine's view of a service, its buffers, gap,
 * windows and rules, is a different read for a different caller.
 */
final readonly class CatalogService
{
    /**
     * @param  list<CatalogStaff>  $staff  the active, online, named staff members assigned to it, in sort order; never the implicit one
     * @param  ?string  $category  the heading the booking page groups it under, null for none
     * @param  bool  $online  whether it is offered online; the public catalogue lists no other
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $description,
        public int $durationMin,
        public Money $price,
        public array $staff,
        public ?string $category,
        public bool $online,
    ) {}
}
