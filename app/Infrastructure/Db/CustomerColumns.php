<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Dto\Customer;

/**
 * The three customer columns of a bookings row, as the readers that build a snapshot read them:
 * the customer, or null where the three are null together, the erased shape the CHECK
 * bookings_customer_check admits beside a name and an address set (tenant migration 000028). Any
 * other shape, a value of the wrong type or a name without an address, is the schema refused by
 * the database and refused here too, as the row's guard refuses it. BookingReader and
 * BookingRecords read through it, so the snapshot of one booking says the same of its customer
 * on every surface.
 */
final readonly class CustomerColumns
{
    public static function read(RowShape $shape, mixed $name, mixed $email, mixed $phone): ?Customer
    {
        if ($name === null && $email === null && $phone === null) {
            return null;
        }

        return new Customer($shape->string($name), $shape->string($email), $phone === null ? null : $shape->string($phone));
    }
}
