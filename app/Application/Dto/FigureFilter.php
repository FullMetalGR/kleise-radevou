<?php

declare(strict_types=1);

namespace App\Application\Dto;

/**
 * Reports' Staff and Service filters over the six tiles: a member, a service, both or neither,
 * each an id the caller has already checked is offered. Home reads with neither.
 *
 * An item matches where it is the member's and the service's, as many of the two as are given; a
 * booking counts where one of its items matches, its revenue and its filled minutes those items'
 * alone. The offered minutes are the member's hours; with a service and no member, the hours of
 * the members who perform it (service_staff); with neither, every active member's.
 */
final readonly class FigureFilter
{
    public function __construct(public ?int $staffId = null, public ?int $serviceId = null) {}

    public function isNone(): bool
    {
        return $this->staffId === null && $this->serviceId === null;
    }

    /**
     * The filter as the page reads it back.
     *
     * @return array{staff: int|null, service: int|null}
     */
    public function toArray(): array
    {
        return ['staff' => $this->staffId, 'service' => $this->serviceId];
    }
}
