<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * The two reads the recipient rule runs on, bound to one tenant by whoever built it.
 *
 * They come from two schemas, which is why they are two reads and not one join: users are
 * central and staff rows are the tenant's, related by id and never by a foreign key across
 * schemas. Nothing here adds a column to either.
 */
interface BusinessDirectory
{
    /**
     * Every user of the tenant, in a stable order.
     *
     * @return list<TenantUser>
     */
    public function users(): array;

    /**
     * The staff rows carrying an item of the booking, each once, in a stable order. A booking
     * that does not exist has none.
     *
     * @return list<StaffOnBooking>
     */
    public function staffOn(string $uuid): array;
}
