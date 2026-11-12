<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Dto\BookableService;
use App\Application\Repository\ServiceRepository;

/**
 * A service repository over an array, for a use case tested with no database.
 */
final readonly class ArrayServices implements ServiceRepository
{
    /** @var array<int, BookableService> */
    private array $services;

    /**
     * @param  list<BookableService>  $services
     */
    public function __construct(array $services)
    {
        $byId = [];

        foreach ($services as $service) {
            $byId[$service->id] = $service;
        }

        $this->services = $byId;
    }

    public function bookable(array $serviceIds): array
    {
        return array_filter($this->services, fn (BookableService $service): bool => in_array($service->id, $serviceIds, true));
    }
}
