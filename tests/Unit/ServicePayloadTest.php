<?php

use App\Application\Dto\CatalogService;
use App\Application\Dto\CatalogStaff;
use App\Domain\Chain\Chain;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Http\ServicePayload;

/*
 * The whitelist as a shape: what one service looks like on the wire and what the list carries
 * beside it. The Feature suite holds the same class to the live table's columns.
 */

/**
 * @param  list<CatalogStaff>  $staff
 */
function catalogService(int $id = 7, array $staff = [], ?string $category = null): CatalogService
{
    return new CatalogService($id, 'Cut and finish', 'A wash, a cut and a blow dry.', 45, new Money(3500, Currency::of('EUR')), $staff, $category, true);
}

it('names no column as both published and withheld', function () {
    expect(array_intersect(ServicePayload::PUBLISHED, ServicePayload::WITHHELD))->toBe([])
        ->and(array_intersect(ServicePayload::STAFF_PUBLISHED, ServicePayload::STAFF_WITHHELD))->toBe([])
        ->and(ServicePayload::STAFF_PUBLISHED)->toBe(array_unique(ServicePayload::STAFF_PUBLISHED))
        ->and(ServicePayload::PUBLISHED)->toBe(array_unique(ServicePayload::PUBLISHED))
        ->and(ServicePayload::WITHHELD)->toBe(array_unique(ServicePayload::WITHHELD));
});

it('shapes a service as id, name, description, duration_min, price_minor, currency, category, online and staff, in that order, the price as minor units beside its own currency and each performer as id, name, photo_url, title and bio', function () {
    $payload = ServicePayload::of(catalogService(staff: [new CatalogStaff(3, 'Sam', 'https://luna.example/images/staff-0123456789abcdef.png', 'Senior stylist', 'Ten years.'), new CatalogStaff(1, 'Alex')], category: 'Hair'));

    expect($payload)->toBe([
        'id' => 7,
        'name' => 'Cut and finish',
        'description' => 'A wash, a cut and a blow dry.',
        'duration_min' => 45,
        'price_minor' => 3500,
        'currency' => 'EUR',
        'category' => 'Hair',
        'online' => true,
        'staff' => [
            ['id' => 3, 'name' => 'Sam', 'photo_url' => 'https://luna.example/images/staff-0123456789abcdef.png', 'title' => 'Senior stylist', 'bio' => 'Ten years.'],
            ['id' => 1, 'name' => 'Alex', 'photo_url' => null, 'title' => null, 'bio' => null],
        ],
    ])->and(array_keys($payload))->toBe([...ServicePayload::PUBLISHED, 'staff']);
});

it('carries the chain cap beside the list, and an empty list with the cap still', function () {
    expect(ServicePayload::catalog([catalogService(1), catalogService(2)]))->toBe([
        'services' => [ServicePayload::of(catalogService(1)), ServicePayload::of(catalogService(2))],
        'max_chain_length' => 5,
    ])->and(ServicePayload::catalog([]))->toBe(['services' => [], 'max_chain_length' => Chain::MAX_SEGMENTS]);
});
